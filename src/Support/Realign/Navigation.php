<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * Moverse por el plan sin rehacer trabajo: qué decisiones condicionan lo
 * guiado, lo decidido elemento por elemento, lo aplazado y lo que se le pasa
 * al dueño.
 *
 * Lo encontraron dos pruebas de autonomía sobre copias de proyectos. La
 * decisión de declarar en el contrato los controladores escritos a mano
 * salía al final del plan; un agente llevaba antes cada controlador a su
 * request (lo guiado), y si el dueño elegía «declarar» ese trabajo se tiraba.
 * Y la decisión valía para los 84 controladores a la vez, sin poder dejar
 * unos y declarar otros.
 *
 * Una decisión condiciona lo guiado que toca sus mismos archivos (CONDITIONS):
 * va antes en el plan y está en su `depends_on`. La dependencia se cumple
 * cuando la decisión está hecha, o cuando cada archivo compartido está
 * decidido con una opción con la que lo guiado sigue valiendo («dejarlos
 * como código propio» sí; «declararlos», no: los regenera el contrato).
 *
 * @phpstan-type Step array<string, mixed>
 */
final class Navigation
{
    /**
     * Tipo de decisión => las opciones con las que lo guiado sobre sus
     * archivos sigue valiendo. Con cualquier otra, lo guiado espera a que la
     * decisión esté hecha (y el diagnóstico siguiente lo resuelve o no).
     */
    public const CONDITIONS = [
        'unmanaged-http' => ['keep'],
        'unmanaged-models' => ['keep'],
        'hand-rolled' => ['refactor'],
        'route-closure' => ['refactor'],
    ];

    /**
     * Las opciones de un guiado que se le pasa al dueño (`escalate`): hacerlo
     * como dice su playbook, o dejarlo como está. Un controlador no se deja
     * con una excepción del realineamiento: se marca `#[HandRolled]` y lo
     * aprueba el dueño.
     *
     * @var array<string, list<array{key: string, label: string, consequences: string, run?: string}>>
     */
    private const ESCALATED = [
        'controller-shape' => [
            ['key' => 'hand-rolled', 'label' => 'Dejarlo escrito a mano', 'consequences' => 'Se marca la clase con #[HandRolled(reason: "…")] y el dueño la aprueba con larapack:hand-rolled approve; verify deja de pedir que delegue mientras el código no cambie.'],
        ],
        'default' => [
            ['key' => 'except', 'label' => 'Dejarlo como está', 'consequences' => 'Se pide una excepción por elemento, con su razón y su huella; cuenta cuando la aprueba el dueño del proyecto (larapack:hand-rolled approve).', 'run' => 'larapack:realign except {id} --option reason="por qué"'],
        ],
    ];

    public static function conditions(string $type): bool
    {
        return isset(self::CONDITIONS[$type]);
    }

    /**
     * Los pasos guiados que una decisión condiciona: los que tocan alguno de
     * sus archivos.
     *
     * @param  Step  $decision
     * @param  list<Step>  $steps
     * @return list<string>
     */
    public static function dependents(array $decision, array $steps): array
    {
        if (! self::conditions((string) ($decision['type'] ?? ''))) {
            return [];
        }

        $files = self::files($decision);
        $ids = [];

        foreach ($steps as $step) {
            if (($step['category'] ?? null) === Category::GUIDED && array_intersect(self::files($step), $files) !== []) {
                $ids[] = (string) $step['id'];
            }
        }

        return $ids;
    }

    /**
     * Una decisión tomada que condiciona lo guiado se cumple después: si se
     * decidió dejar los controladores como código propio, la excepción se
     * pide sobre el código ya llevado a su request (pedirla antes la
     * caducaría al cambiar el archivo). Mientras quede algo guiado que
     * dependa de ella y siga valiendo, `next` no la da. Si alguno espera a
     * que se cumpla («declararlos»), se cumple ya: si no, se esperarían el
     * uno al otro.
     *
     * @param  Step  $step
     * @param  array<string, Step>  $byId
     */
    public static function waitsForGuided(array $step, array $byId): bool
    {
        if (! self::conditions((string) ($step['type'] ?? '')) || ! self::decided($step)) {
            return false;
        }

        $waits = false;

        foreach ($byId as $other) {
            if (($other['status'] ?? null) !== Planner::DONE && in_array($step['id'] ?? null, (array) ($other['depends_on'] ?? []), true)
                && ($other['category'] ?? null) === Category::GUIDED) {
                if (! self::allows($other, $step)) {
                    return false;
                }

                $waits = true;
            }
        }

        return $waits;
    }

    /**
     * La opción que rige un elemento: la suya, si se decidió por elemento, o
     * la del paso.
     *
     * @param  Step  $step
     */
    public static function optionFor(array $step, string $file): ?string
    {
        $own = $step['decisions'][$file]['option'] ?? null;

        if (is_string($own)) {
            return $own;
        }

        $all = $step['decision']['option'] ?? null;

        return is_string($all) ? $all : null;
    }

    /**
     * Los elementos de una decisión que nadie decidió.
     *
     * @param  Step  $step
     * @return list<string>
     */
    public static function undecided(array $step): array
    {
        if (is_array($step['decision'] ?? null)) {
            return [];
        }

        $files = self::files($step);

        // Sin archivos (el contrato de un ámbito) sólo se decide entero.
        if ($files === []) {
            return ['*'];
        }

        return array_values(array_filter($files, fn (string $file): bool => self::optionFor($step, $file) === null));
    }

    /**
     * @param  Step  $step
     */
    public static function decided(array $step): bool
    {
        return self::undecided($step) === [];
    }

    /**
     * Una decisión tomada cuya orden ya se ejecutó después de decidirla: lo
     * que falta (aprobar las excepciones pedidas, por ejemplo) es del dueño,
     * no de quien sigue el plan. `next` no la vuelve a dar.
     *
     * @param  Step  $step
     */
    public static function handedOver(array $step): bool
    {
        if (($step['category'] ?? null) !== Category::DECISION || ! self::decided($step)) {
            return false;
        }

        $decided = null;
        $applied = null;

        foreach ((array) ($step['history'] ?? []) as $event) {
            if (is_array($event) && ($event['event'] ?? null) === 'decided') {
                $decided = (string) ($event['at'] ?? '');
            } elseif (is_array($event) && ($event['event'] ?? null) === 'applied' && $decided !== null) {
                $applied = (string) ($event['at'] ?? '');
            }
        }

        return $decided !== null && $applied !== null;
    }

    /**
     * Opción => archivos, de lo decidido por elemento (y lo que rige la del
     * paso).
     *
     * @param  Step  $step
     * @return array<string, list<string>>
     */
    public static function byOption(array $step): array
    {
        $by = [];

        foreach (self::files($step) as $file) {
            $option = self::optionFor($step, $file);

            if ($option !== null) {
                $by[$option][] = $file;
            }
        }

        return $by;
    }

    /**
     * Las opciones elegidas en el paso, por elemento o entero.
     *
     * @param  Step  $step
     * @return list<string>
     */
    public static function chosen(array $step): array
    {
        $chosen = array_keys(self::byOption($step));
        $all = $step['decision']['option'] ?? null;

        if (is_string($all) && ! in_array($all, $chosen, true)) {
            $chosen[] = $all;
        }

        return array_map('strval', $chosen);
    }

    /**
     * Si una dependencia de lo guiado en una decisión que lo condiciona ya
     * no lo detiene.
     *
     * @param  Step  $guided
     * @param  Step  $decision
     */
    public static function allows(array $guided, array $decision): bool
    {
        $continues = self::CONDITIONS[(string) ($decision['type'] ?? '')] ?? [];
        $shared = array_values(array_intersect(self::files($guided), self::files($decision)));

        if ($shared === []) {
            return false;
        }

        foreach ($shared as $file) {
            if (! in_array(self::optionFor($decision, $file), $continues, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * El paso que ve una operación: si se decidió por elemento, sólo los
     * elementos cuya opción ejecuta esa operación, y su decisión es esa
     * opción. Así `except` pide excepciones sólo de lo que se decidió dejar.
     * `infer` escribe igual el borrador del ámbito entero, que no toca nada.
     *
     * @param  Step  $step
     * @return Step
     */
    public static function narrow(array $step, string $action): array
    {
        if (! is_array($step['decisions'] ?? null) || $step['decisions'] === []) {
            return $step;
        }

        $runs = self::optionsRunning($step, $action);
        $keep = array_values(array_filter(self::files($step), fn (string $file): bool => in_array(self::optionFor($step, $file), $runs, true)));
        $only = fn (array $entries): array => array_values(array_filter($entries, fn (mixed $entry): bool => ! is_array($entry) || ! is_string($entry['file'] ?? null) || in_array($entry['file'], $keep, true)));

        $step['files'] = $keep;

        foreach (['files', 'findings', 'unmanaged', 'contract'] as $key) {
            if (is_array($step['details'][$key] ?? null)) {
                $step['details'][$key] = $only($step['details'][$key]);
            }
        }

        if ($runs !== []) {
            $step['decision'] = ['option' => $runs[0], 'note' => null, 'at' => null];
        }

        return $step;
    }

    /**
     * Si algún elemento del paso se decidió con una opción que ejecuta esa
     * operación.
     *
     * @param  Step  $step
     */
    public static function decidedFor(array $step, string $action): bool
    {
        return array_intersect(self::chosen($step), self::optionsRunning($step, $action)) !== [];
    }

    /**
     * Convierte un paso guiado en una decisión del dueño, con su nota. Las
     * opciones: hacerlo como dice su playbook, o dejarlo como está.
     *
     * @param  Step  $step
     * @param  array{note: string, at: string}  $escalation
     * @return Step
     */
    public static function escalate(array $step, array $escalation): array
    {
        $playbook = is_string($step['playbook'] ?? null) ? $step['playbook'] : Playbooks::GENERIC;
        $keep = self::ESCALATED[(string) $step['type']] ?? self::ESCALATED['default'];

        $step['escalated'] = ['from' => Category::GUIDED, 'note' => $escalation['note'], 'at' => $escalation['at']];
        $step['category'] = Category::DECISION;
        $step['options'] = [
            ['key' => 'refactor', 'label' => 'Hacerlo como dice el playbook', 'consequences' => 'Se sigue el playbook del paso ('.$playbook.') hasta que su comprobación pase.', 'playbook' => $playbook],
            ...array_map(fn (array $option): array => isset($option['run']) ? ['run' => strtr($option['run'], ['{id}' => (string) $step['id']])] + $option : $option, $keep),
        ];

        return $step;
    }

    /**
     * @param  Step  $step
     * @return list<string>
     */
    public static function files(array $step): array
    {
        return array_values(array_filter((array) ($step['files'] ?? []), 'is_string'));
    }

    /**
     * @param  Step  $step
     * @return list<string>
     */
    private static function optionsRunning(array $step, string $action): array
    {
        $keys = [];

        foreach ((array) ($step['options'] ?? []) as $option) {
            if (is_array($option) && is_string($option['run'] ?? null) && str_starts_with($option['run'], 'larapack:realign '.$action.' ')) {
                $keys[] = (string) $option['key'];
            }
        }

        return $keys;
    }
}
