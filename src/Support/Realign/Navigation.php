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
            if (! Planner::finished($other) && in_array($step['id'] ?? null, (array) ($other['depends_on'] ?? []), true)
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
     * Si una solicitud de excepción sigue pedida por lo decidido hoy. Al
     * cambiar de opción un elemento (decide --target … declare), su
     * solicitud anterior deja de contar: no hay que aprobar una excepción
     * que se decidió no dar. Un paso que no es una decisión la sigue pidiendo.
     *
     * @param  Step  $step
     * @param  array<string, mixed>  $request
     */
    public static function stillRequested(array $step, array $request): bool
    {
        if (($step['category'] ?? null) !== Category::DECISION) {
            return true;
        }

        $option = self::optionFor($step, (string) ($request['file'] ?? ''));

        return $option === null || in_array($option, self::optionsRunning($step, 'except'), true);
    }

    /**
     * El siguiente paso que puede hacer quien sigue el plan: el primero, en
     * su orden, que no está hecho ni bloqueado, ni aplazado, ni en manos del
     * dueño (handedOver()), ni es una decisión tomada que espera a lo guiado
     * (waitsForGuided()). Una decisión sin tomar sí se da: hay que
     * presentársela al dueño.
     *
     * @param  list<Step>  $steps  los del ámbito pedido, en el orden del plan
     * @param  array<string, Step>  $byId  todos los del plan
     * @return Step|null
     */
    public static function next(array $steps, array $byId): ?array
    {
        return self::firstActionable($steps, $byId, [Planner::DONE, Planner::UNTESTED, Planner::BLOCKED]);
    }

    /**
     * Lo primero que se puede hacer sin esperar al dueño: como next(), sin
     * las decisiones por tomar.
     *
     * @param  list<Step>  $steps
     * @param  array<string, Step>  $byId
     * @return Step|null
     */
    public static function meanwhile(array $steps, array $byId): ?array
    {
        return self::firstActionable($steps, $byId, [Planner::DONE, Planner::UNTESTED, Planner::BLOCKED, Planner::AWAITING_DECISION]);
    }

    /**
     * Por ámbito, las decisiones del dueño sin tomar que detienen lo guiado,
     * y lo guiado que detienen.
     *
     * @param  list<Step>  $steps
     * @param  array<string, Step>  $byId
     * @return list<array{scope: string, package: string|null, decisions: list<string>, guided: list<string>}>
     */
    public static function awaitingOwner(array $steps, array $byId): array
    {
        $by = [];

        foreach ($steps as $step) {
            if ($step['category'] !== Category::GUIDED || isset($step['deferred'])) {
                continue;
            }

            foreach (Planner::blockedBy($step, $byId) as $id) {
                $decision = $byId[$id];
                $shared = array_intersect(self::files($step), self::files($decision));
                $pending = array_filter($shared, fn (string $file): bool => self::optionFor($decision, $file) === null);

                if (! self::conditions((string) $decision['type']) || $pending === []) {
                    continue;
                }

                $package = is_string($step['scope']['package'] ?? null) ? $step['scope']['package'] : null;
                $key = $package ?? 'app';
                $by[$key] ??= ['scope' => $key, 'package' => $package, 'decisions' => [], 'guided' => []];
                $by[$key]['decisions'][$id] = $id;
                $guided = (string) $step['id'];
                $by[$key]['guided'][$guided] = $guided;
            }
        }

        return array_values(array_map(function (array $entry): array {
            // sort() reindexa: salen como listas.
            $decisions = $entry['decisions'];
            $guided = $entry['guided'];
            sort($decisions);
            sort($guided);

            return ['scope' => $entry['scope'], 'package' => $entry['package'], 'decisions' => $decisions, 'guided' => $guided];
        }, $by));
    }

    /**
     * Las excepciones que no cubren lo que deberían: pedidas y sin aprobar, o
     * aprobadas sobre otro código, con otra razón o sobre algo que ya no
     * existe. Es una sola lista para `done` (Completion), que falla mientras
     * quede alguna, y para `brief` (OwnerBrief), que se las presenta al
     * dueño: si cada uno las contara a su manera, `done` pediría aprobar lo
     * que el brief no enseña.
     *
     * No cuenta la pedida bajo una decisión que después cambió para ese
     * elemento (stillRequested()), ni, con `--package`, la de otro ámbito.
     * Las aprobadas sin solicitud (las de antes, o de otra rama) también
     * caducan; con `--package` no se sabe de qué ámbito son y no se miran.
     *
     * @param  list<string>  $paths  rutas de ámbito; vacío, todo el proyecto
     * @param  array<string, Step>  $byId
     * @return list<array{target: string, status: string, request: array<string, mixed>|null, reason: string|null}>
     */
    public static function pendingExceptions(Exceptions $exceptions, array $paths, array $byId): array
    {
        $rows = [];

        foreach ($exceptions->requests() as $request) {
            if ($paths !== [] && ! in_array($request['scope'], $paths, true)) {
                continue;
            }

            $step = $request['step'] === null ? null : ($byId[$request['step']] ?? null);

            if (is_array($step) && ! self::stillRequested($step, $request)) {
                continue;
            }

            $target = $request['target'];
            $status = $exceptions->status($target);

            if ($status !== Exceptions::APPROVED) {
                $rows[$target] = ['target' => $target, 'status' => $status, 'request' => $request, 'reason' => $request['reason']];
            }
        }

        foreach ($paths === [] ? $exceptions->all() : [] as $approval) {
            if ($approval['status'] !== Exceptions::APPROVED && ! isset($rows[$approval['target']])) {
                $rows[$approval['target']] = ['target' => $approval['target'], 'status' => $approval['status'], 'request' => null, 'reason' => $approval['reason']];
            }
        }

        return array_values($rows);
    }

    /**
     * Lo que está sin probar: cada paso con la orden para darlo por probado y
     * la del dueño para aceptarlo así.
     *
     * @param  list<Step>  $steps
     * @return list<array{id: string, title: string, scope: string, at: string|null, check: string, accept: string}>
     */
    public static function untested(array $steps): array
    {
        $rows = [];

        foreach ($steps as $step) {
            if (($step['status'] ?? null) === Planner::UNTESTED) {
                $history = array_values(array_filter((array) ($step['history'] ?? []), fn (mixed $event): bool => is_array($event) && in_array($event['event'] ?? null, ['untested', 'resolved'], true)));
                $rows[] = ['id' => (string) $step['id'], 'title' => (string) $step['title'], 'scope' => (string) ($step['scope']['path'] ?? '.'),
                    'at' => $history === [] ? null : (string) ($history[count($history) - 1]['at'] ?? ''),
                    'check' => 'larapack:realign check '.$step['id'].' --tested="<orden>"', 'accept' => 'larapack:realign accept-untested '.$step['id'].' --note="por qué"'];
            }
        }

        return $rows;
    }

    /**
     * @param  list<Step>  $steps
     * @param  array<string, Step>  $byId
     * @param  list<string>  $skip  los estados (Planner::effectiveStatus()) que no se dan
     * @return Step|null
     */
    private static function firstActionable(array $steps, array $byId, array $skip): ?array
    {
        foreach ($steps as $step) {
            if (! isset($step['deferred']) && ! self::handedOver($step) && ! self::waitsForGuided($step, $byId)
                && ! in_array(Planner::effectiveStatus($step, $byId), $skip, true)) {
                return $step;
            }
        }

        return null;
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
