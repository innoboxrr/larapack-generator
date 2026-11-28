<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use RuntimeException;

/**
 * `larapack:realign except <paso>… --option reason="…"`: pide que lo que
 * señala un paso se deje como está.
 *
 * **Pide; no aprueba.** Escribe una solicitud por cada elemento del paso
 * —cada closure, cada archivo, cada hallazgo— en
 * `.larapack/realign/exceptions.json`, con su objetivo exacto
 * (ExceptionTargets), la razón y la huella de lo que hay hoy. Una solicitud
 * no cubre nada: la cubre la aprobación del dueño del proyecto
 * (`larapack:hand-rolled approve`), que es lo que un agente no puede hacer
 * (Exceptions). Por eso esta operación la puede correr cualquiera, y por
 * eso el paso no se da por hecho hasta que la aprobación existe.
 *
 * No toca código ni el candado. Lo que no sabe nombrar con exactitud —un
 * closure que ya no está, un hallazgo sin archivo ni modelo— lo salta con
 * su razón.
 *
 * **Pide por lo de hoy, no por lo del plan.** Antes de pedir vuelve a mirar
 * cada elemento: los hallazgos, con las mismas comprobaciones de verify
 * sobre el código de ahora; los archivos, con la misma clasificación
 * (ScopeReference). Lo que ya no está —un archivo que se arregló después de
 * planificar— no se pide: sale en `unchanged` diciéndolo. Antes se pedía
 * por los archivos del plan, y el dueño acababa aprobando una excepción
 * para algo que ya cumplía.
 *
 * Opciones:
 *
 *  - `reason` (obligatoria): por qué se deja así, en una línea. Es lo que
 *    lee el dueño al aprobar y lo que queda en el candado.
 *  - `--target` (repetible): sólo esos elementos, por su objetivo
 *    (`realign:…`) o por su archivo. Uno que no es de ningún elemento de los
 *    pasos es un error.
 */
final class ExceptOperation implements RealignOperation
{
    /**
     * Los tipos de paso que se pueden exceptuar. Lo que tiene su propio
     * mecanismo no: un controlador que no puede delegar lleva #[HandRolled]
     * en el código (controller-shape, controller-concern, hand-rolled), y lo
     * que sólo pone el manifiesto al día no tiene nada que aceptar.
     */
    public const HANDLES = [
        'route-closure', 'inconsistent-entity', 'missing', 'untracked-edited', 'unmanaged-http', 'unmanaged-models', 'no-contract',
        'fix', 'declared-routes', 'security', 'edited-outside', 'model-lists', 'endpoint-tests', 'verify-other',
    ];

    public function name(): string
    {
        return 'except';
    }

    public function description(): string
    {
        return Operations::DESCRIPTIONS['except'];
    }

    public function handles(): array
    {
        return self::HANDLES;
    }

    public function run(OperationContext $context): OperationResult
    {
        $reason = Exceptions::sanitise((string) $context->option('reason', ''));

        if ($reason === '') {
            throw new RuntimeException('except necesita la razón: --option reason="por qué se deja así". Es lo que lee el dueño del proyecto al aprobarla, y lo que queda en '.HandRolledLock::PATH.'.');
        }

        $exceptions = new Exceptions($context->state);
        $existing = $exceptions->requests();
        $approvals = $exceptions->approvals();
        $result = new OperationResult;
        $new = [];
        $now = gmdate('c');

        $selected = $this->selected($context);
        $gone = $this->gone($context, $selected);

        foreach ($selected as [$step, $element]) {
            if (isset($element['skip'])) {
                $result->skipped($element['file'], $element['skip'], ['step' => $step['id']]);

                continue;
            }

            $target = $element['target'];
            $approval = $approvals[$target] ?? null;

            if (isset($gone[$target])) {
                $result->unchanged($element['file'], "{$target}: {$gone[$target]} No hace falta excepción; el plan es de antes (larapack:realign diagnose && larapack:realign plan lo ponen al día).", ['step' => $step['id'], 'target' => $target]);

                continue;
            }

            if ($approval !== null && $approval['reason'] === $reason && $approval['fingerprint'] === $element['fingerprint']) {
                $result->unchanged($element['file'], "{$target}: ya está aprobada con esa razón.", ['step' => $step['id'], 'target' => $target]);

                continue;
            }

            if (($existing[$target]['reason'] ?? null) === $reason && ($existing[$target]['fingerprint'] ?? null) === $element['fingerprint']) {
                $result->unchanged($element['file'], "{$target}: ya está solicitada; falta que la apruebe el dueño del proyecto.", ['step' => $step['id'], 'target' => $target]);

                continue;
            }

            $new[] = [
                'target' => $target,
                'type' => (string) $step['type'],
                'check' => $element['check'],
                'scope' => (string) ($step['scope']['path'] ?? '.'),
                'file' => $element['file'],
                'subject' => $element['subject'],
                'line' => $element['line'],
                'step' => (string) $step['id'],
                'reason' => $reason,
                'fingerprint' => $element['fingerprint'],
                'requested_at' => $now,
            ];

            $result->fixed($element['file'], ($context->dryRun() ? 'Se solicitaría' : 'Solicitada').": {$target}.", ['step' => $step['id'], 'target' => $target]);
        }

        if ($new !== [] && ! $context->dryRun()) {
            $exceptions->saveRequests($new);
        }

        $result->note('Una excepción solicitada no cubre nada hasta que la apruebe el dueño del proyecto: él corre `larapack:hand-rolled list` y '
            .'`larapack:hand-rolled approve <objetivo>`, revisa '.HandRolledLock::PATH.' y lo comitea. Un agente no la aprueba ni edita el candado.');

        return $result;
    }

    /**
     * Los elementos de los pasos, acotados a `--target`, con su paso.
     *
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function selected(OperationContext $context): array
    {
        $selected = [];
        $matched = [];
        $known = [];

        foreach ($context->steps as $step) {
            foreach ($this->elements($step, $context->root) as $element) {
                $names = array_values(array_filter([$element['target'] ?? null, $element['file'] ?? null], 'is_string'));
                array_push($known, ...$names);

                if ($context->only !== [] && array_intersect($names, $context->only) === []) {
                    continue;
                }

                foreach (array_intersect($names, $context->only) as $name) {
                    $matched[$name] = true;
                }

                $selected[] = [$step, $element];
            }
        }

        $unknown = array_values(array_filter($context->only, fn (string $name): bool => ! isset($matched[$name])));

        if ($unknown !== []) {
            $known = array_values(array_unique($known));

            throw new RuntimeException('--target '.implode(', ', $unknown).': no es ningún elemento de '.(count($context->steps) === 1 ? 'ese paso' : 'esos pasos')
                .'. Se nombra por su objetivo o por su archivo'.($known === [] ? '' : '; son: '.implode(', ', array_slice($known, 0, 20)).(count($known) > 20 ? '…' : '')).'.');
        }

        return $selected;
    }

    /**
     * Lo que el plan señalaba y hoy ya no: objetivo => por qué. Los
     * hallazgos se vuelven a buscar con las mismas comprobaciones de verify
     * (sólo esas, en todo el proyecto); los archivos, con la misma
     * clasificación que el diagnóstico.
     *
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $selected
     * @return array<string, string>
     */
    private function gone(OperationContext $context, array $selected): array
    {
        $checks = [];

        foreach ($selected as [, $element]) {
            if (is_string($element['verify'] ?? null) && in_array($element['check'], Verifier::CHECKS, true)) {
                $checks[$element['check']] = true;
            }
        }

        $current = [];

        if ($checks !== []) {
            foreach ((new ProjectVerification($context->root))->run(array_keys($checks))['findings'] as $finding) {
                $current[self::findingKey($finding)] = true;
            }
        }

        $gone = [];

        foreach ($selected as [$step, $element]) {
            if (! isset($element['target'])) {
                continue;
            }

            if (is_string($element['verify'] ?? null) && isset($checks[$element['check']]) && ! isset($current[$element['verify']])) {
                $gone[$element['target']] = "verify ya no lo señala ({$element['check']}).";

                continue;
            }

            if (is_string($element['class'] ?? null)) {
                $reference = ScopeReference::for($context->root, (string) ($step['scope']['path'] ?? '.'), $context->state);
                $now = $reference?->classify($element['file'])['class'] ?? null;

                if ($reference !== null && $now !== $element['class']) {
                    $gone[$element['target']] = "ya no es {$element['class']}: hoy es ".($now ?? 'nada').'.';
                }

                continue;
            }

            if (($element['unmanaged'] ?? false) === true && ! is_file(rtrim($context->root, '/').'/'.$element['file'])) {
                $gone[$element['target']] = 'ya no existe.';
            }
        }

        return $gone;
    }

    /**
     * Cómo se reconoce un hallazgo entre el plan y verify de hoy: su
     * comprobación y su archivo, o su modelo y su frase.
     *
     * @param  array<string, mixed>  $finding
     */
    private static function findingKey(array $finding): string
    {
        $check = (string) ($finding['check'] ?? '');

        return is_string($finding['file'] ?? null)
            ? $check.'|file|'.$finding['file']
            : $check.'|model|'.($finding['model'] ?? '').'|'.($finding['message'] ?? '');
    }

    /**
     * Cada cosa que el paso señala, con su objetivo exacto y su huella, o
     * por qué no se puede nombrar.
     *
     * @param  array<string, mixed>  $step
     * @return list<array{target: string, file: string, check: string|null, subject: string|null, line: int|null, fingerprint: string|null}|array{skip: string, file: string|null}>
     */
    private function elements(array $step, string $root): array
    {
        $type = (string) $step['type'];
        $details = is_array($step['details'] ?? null) ? $step['details'] : [];
        $elements = [];

        if ($type === 'no-contract') {
            $file = ExceptionTargets::contractOf((string) ($step['scope']['path'] ?? '.'));

            return [$this->element(ExceptionTargets::forFile($type, null, $file), $file, null, null, null, $root)];
        }

        foreach ((array) ($details['findings'] ?? []) as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $check = is_string($finding['check'] ?? null) ? $finding['check'] : null;
            $file = is_string($finding['file'] ?? null) ? $finding['file'] : null;

            if ($type === 'route-closure') {
                $elements[] = $this->closure($finding, $root);

                continue;
            }

            if ($file !== null) {
                $elements[] = $this->element(ExceptionTargets::forFile($type, $check, $file), $file, $check, null, null, $root)
                    + ['verify' => $check === null ? null : self::findingKey($finding)];

                continue;
            }

            $model = is_string($finding['model'] ?? null) ? $finding['model'] : null;
            $message = is_string($finding['message'] ?? null) ? $finding['message'] : null;

            if ($model === null || $message === null) {
                $elements[] = ['skip' => 'El hallazgo no dice de qué archivo ni de qué modelo es: no se puede nombrar con exactitud.', 'file' => null];

                continue;
            }

            $target = ExceptionTargets::forStatement($type, $check, $model, $message);
            $elements[] = ['target' => $target, 'file' => 'model:'.$model, 'check' => $check, 'subject' => null, 'line' => null,
                'fingerprint' => ExceptionTargets::statementFingerprint($message), 'verify' => $check === null ? null : self::findingKey($finding)];
        }

        foreach ((array) ($details['files'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $elements[] = $this->element(ExceptionTargets::forFile($type, null, $entry['file']), $entry['file'], null, null, null, $root)
                    + ['class' => is_string($entry['class'] ?? null) ? $entry['class'] : null];
            }
        }

        foreach ((array) ($details['unmanaged'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $elements[] = $this->element(ExceptionTargets::forFile($type, null, $entry['file']), $entry['file'], null, null, null, $root) + ['unmanaged' => true];
            }
        }

        // Un mismo archivo puede salir por dos hallazgos de la misma
        // comprobación: es una sola excepción.
        $unique = [];

        foreach ($elements as $element) {
            $unique[$element['target'] ?? 'skip:'.count($unique)] = $element;
        }

        return array_values($unique);
    }

    /**
     * @return array{target: string, file: string, check: string|null, subject: string|null, line: int|null, fingerprint: string|null}
     */
    private function element(string $target, string $file, ?string $check, ?string $subject, ?int $line, string $root): array
    {
        return ['target' => $target, 'file' => $file, 'check' => $check, 'subject' => $subject, 'line' => $line,
            'fingerprint' => ExceptionTargets::fingerprint($root, $target)];
    }

    /**
     * El closure de la ruta de un hallazgo `route-closure`, por la línea que
     * dice su mensaje.
     *
     * @param  array<string, mixed>  $finding
     * @return array{target: string, file: string, check: string|null, subject: string|null, line: int|null, fingerprint: string|null}|array{skip: string, file: string|null}
     */
    private function closure(array $finding, string $root): array
    {
        $file = is_string($finding['file'] ?? null) ? $finding['file'] : null;
        $line = preg_match('/línea (\d+)/u', (string) ($finding['message'] ?? ''), $match) === 1 ? (int) $match[1] : null;
        $path = $file === null ? null : rtrim($root, '/').'/'.$file;

        if ($file === null || $line === null || $path === null || ! is_file($path)) {
            return ['skip' => 'No se encontró el closure del hallazgo (sin archivo o sin línea).', 'file' => $file];
        }

        foreach (ExceptionTargets::routeClosures($file, Files::read($path)) as $closure) {
            if ($closure['line'] === $line) {
                return ['target' => $closure['target'], 'file' => $file, 'check' => 'route-closure', 'subject' => $closure['label'],
                    'line' => $line, 'fingerprint' => $closure['fingerprint']];
            }
        }

        return ['skip' => "La línea {$line} ya no tiene un closure de ruta: vuelve a diagnosticar (larapack:realign diagnose && larapack:realign plan).", 'file' => $file];
    }
}
