<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
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
 * Opciones:
 *
 *  - `reason` (obligatoria): por qué se deja así, en una línea. Es lo que
 *    lee el dueño al aprobar y lo que queda en el candado.
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

        foreach ($context->steps as $step) {
            foreach ($this->elements($step, $context->root) as $element) {
                if (isset($element['skip'])) {
                    $result->skipped($element['file'], $element['skip'], ['step' => $step['id']]);

                    continue;
                }

                $target = $element['target'];
                $approval = $approvals[$target] ?? null;

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
                    // El hallazgo, para que el dueño lo vea al aprobar. No
                    // aprueba nada: lo escribe quien pide (Exceptions).
                    'message' => $element['message'] ?? null,
                    'reason' => $reason,
                    'fingerprint' => $element['fingerprint'],
                    'requested_at' => $now,
                ];

                $result->fixed($element['file'], ($context->dryRun() ? 'Se solicitaría' : 'Solicitada').": {$target}.", ['step' => $step['id'], 'target' => $target]);
            }
        }

        if ($new !== [] && ! $context->dryRun()) {
            $exceptions->saveRequests($new);
        }

        $result->note('Una excepción solicitada no cubre nada hasta que la apruebe el dueño del proyecto: él corre `larapack:hand-rolled list` y '
            .'`larapack:hand-rolled approve <objetivo>`, revisa '.HandRolledLock::PATH.' y lo comitea. Un agente no la aprueba ni edita el candado.');

        return $result;
    }

    /**
     * Cada cosa que el paso señala, con su objetivo exacto y su huella, o
     * por qué no se puede nombrar.
     *
     * @param  array<string, mixed>  $step
     * @return list<array{target: string, file: string, check: string|null, subject: string|null, line: int|null, fingerprint: string|null, message?: string|null}|array{skip: string, file: string|null}>
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
            $message = is_string($finding['message'] ?? null) ? $finding['message'] : null;

            if ($type === 'route-closure') {
                $elements[] = $this->closure($finding, $root) + ['message' => $message];

                continue;
            }

            if ($file !== null) {
                $elements[] = $this->element(ExceptionTargets::forFile($type, $check, $file), $file, $check, null, null, $root) + ['message' => $message];

                continue;
            }

            $model = is_string($finding['model'] ?? null) ? $finding['model'] : null;

            if ($model === null || $message === null) {
                $elements[] = ['skip' => 'El hallazgo no dice de qué archivo ni de qué modelo es: no se puede nombrar con exactitud.', 'file' => null];

                continue;
            }

            $target = ExceptionTargets::forStatement($type, $check, $model, $message);
            $elements[] = ['target' => $target, 'file' => 'model:'.$model, 'check' => $check, 'subject' => null, 'line' => null,
                'fingerprint' => ExceptionTargets::statementFingerprint($message), 'message' => $message];
        }

        foreach ([...(array) ($details['files'] ?? []), ...(array) ($details['unmanaged'] ?? [])] as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $elements[] = $this->element(ExceptionTargets::forFile($type, null, $entry['file']), $entry['file'], null, null, null, $root);
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
