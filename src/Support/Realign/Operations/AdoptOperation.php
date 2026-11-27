<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\FileComparison;

/**
 * `larapack:realign adopt`: pone el manifiesto al día con lo que hay, sin
 * cambiar el contenido de un solo archivo.
 *
 *  - `adopt`: lo que es idéntico, o igual salvo formato, a lo que generaría
 *    hoy el contrato. Si el manifiesto ya lo conoce, se vuelve a anotar con
 *    su hash actual y la versión del generador, conservando su estado; si no
 *    lo conoce, se registra con `state: adopted`. Desde entonces `verify` lo
 *    da por no editado y `--force` lo puede regenerar.
 *  - `relink`: lo renombrado a mano (una migración con otra fecha) pasa a la
 *    ruta nueva, con su hash y su stub.
 *  - `prune`: lo que ya no está en disco y el contrato no volvería a generar
 *    (de un modelo retirado, una alteración borrada, una migración
 *    registrada dos veces) se olvida.
 *  - `untracked-edited`, sólo si se decidió `adopt`: lo escrito a mano en la
 *    ruta de un archivo generado se registra con el hash de lo que generaría
 *    el contrato. Queda «personalizado»: `verify` sigue su deriva y `--force`
 *    no lo sobrescribe jamás.
 *
 * Y, en cada ámbito que toca, anota la versión del generador en lo heredado
 * de un manifiesto v1 que sigue siendo, sin editar, lo que generaría hoy el
 * contrato (Manifest::stamp): desde ahí un cambio de plantilla se distingue
 * de un cambio de contrato.
 *
 * Antes de tocar cada archivo lo vuelve a clasificar (ScopeReference): si ya
 * no es lo que dice el paso —alguien lo editó después del diagnóstico—, lo
 * salta diciéndolo. El manifiesto se escribe una sola vez (Manifest::batch).
 */
final class AdoptOperation implements RealignOperation
{
    /**
     * Las causas de un `missing` que se olvidan sin preguntar.
     */
    private const PRUNABLE = ['retired', 'not_regenerated', 'superseded'];

    public function name(): string
    {
        return 'adopt';
    }

    public function description(): string
    {
        return Operations::DESCRIPTIONS['adopt'];
    }

    public function handles(): array
    {
        return ['adopt', 'relink', 'prune', 'untracked-edited'];
    }

    public function run(OperationContext $context): OperationResult
    {
        $result = new OperationResult;

        Manifest::batch(function () use ($context, $result): void {
            $scopes = [];

            foreach ($context->steps as $step) {
                $path = (string) ($step['scope']['path'] ?? '.');
                $reference = ScopeReference::for($context->root, $path, $context->state);

                if ($reference === null) {
                    $result->skipped(null, "{$step['id']}: el ámbito {$path} ya no existe.", ['step' => $step['id']]);

                    continue;
                }

                if (! $reference->hasReference() && $step['type'] !== 'prune') {
                    $result->skipped(null, "{$step['id']}: no se pudo regenerar el contrato de {$path} para compararlo; corre `larapack:realign diagnose`.", ['step' => $step['id']]);

                    continue;
                }

                if ($step['type'] === 'untracked-edited' && ($step['decision']['option'] ?? null) !== 'adopt') {
                    $decided = $step['decision']['option'] ?? null;
                    $result->skipped(null, $decided === null
                        ? "{$step['id']}: falta decidir (larapack:realign decide {$step['id']} adopt)."
                        : "{$step['id']}: se decidió `{$decided}`, no adoptarlo.", ['step' => $step['id']]);

                    continue;
                }

                $scopes[$path] = $reference;

                foreach ($this->filesOf($step) as $file) {
                    $this->one((string) $step['type'], $file, $reference, $context, $result, (string) $step['id']);
                }
            }

            foreach ($scopes as $path => $reference) {
                $this->stamp($path, $reference, $context, $result);
            }
        });

        if (! $context->dryRun()) {
            ScopeReference::forget();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function filesOf(array $step): array
    {
        return array_values(array_filter((array) ($step['files'] ?? []), 'is_string'));
    }

    private function one(string $type, string $file, ScopeReference $reference, OperationContext $context, OperationResult $result, string $id): void
    {
        $entry = $reference->classify($file);
        $manifest = $reference->manifest();
        $absolute = $reference->absolute($file);
        $relative = $reference->relative($file);
        $extra = ['step' => $id];
        $now = "es {$entry['class']} ({$entry['reason']})";

        match ($type) {
            'adopt' => match (true) {
                ! in_array($entry['class'], [FileClass::IDENTICAL, FileClass::FORMAT_ONLY], true) => $result->skipped($file, "Ya no es igual a lo regenerado: {$now}. Vuelve a diagnosticar.", $extra),
                $entry['tracked'] && $entry['hash_current'] === true => $result->unchanged($file, 'El manifiesto ya lo registra con su hash.', $extra),
                $entry['tracked'] => $this->write($context, $result, $file, $extra,
                    fn (): bool => $manifest->refresh($absolute),
                    'Se vuelve a anotar con su hash y la versión '.GeneratorVersion::current().' ('.FileClass::DESCRIPTIONS[$entry['class']].')'.$this->comments($entry).'.'),
                default => $this->adoptNew($reference, $relative, $file, $absolute, null, $context, $result, $extra,
                    'Se registra como adoptado: '.FileClass::DESCRIPTIONS[$entry['class']].$this->comments($entry).'.'),
            },
            'relink' => match (true) {
                $entry['class'] === FileClass::RENAMED && is_string($entry['to'] ?? null) => $this->write($context, $result, $file, $extra + ['to' => $entry['to']],
                    fn (): bool => $manifest->relink($absolute, $reference->absolute($entry['to'])),
                    "Se registra en su ruta nueva, {$entry['to']}, con su stub y su hash."),
                $entry['class'] === FileClass::ABSENT || (! $entry['tracked'] && is_file($absolute)) => $result->unchanged($file, 'El manifiesto ya no lo registra con esta ruta.', $extra),
                default => $result->skipped($file, "Ya no se reconoce como renombrado: {$now}.", $extra),
            },
            'prune' => match (true) {
                is_file($absolute) => $result->skipped($file, 'Vuelve a estar en disco: no se olvida.', $extra),
                ! $entry['tracked'] => $result->unchanged($file, 'El manifiesto ya no lo registra.', $extra),
                $entry['class'] === FileClass::MISSING && in_array($entry['cause'] ?? null, self::PRUNABLE, true) => $this->write($context, $result, $file, $extra,
                    fn (): bool => $this->drop($manifest, $reference, $absolute, $entry['cause'] === 'retired' ? (string) $entry['model'] : null),
                    "Se olvida en el manifiesto: {$entry['reason']}."),
                default => $result->skipped($file, "No se olvida: {$now}. El contrato lo sigue pidiendo; es una decisión (`missing`).", $extra),
            },
            'untracked-edited' => match (true) {
                $entry['tracked'] => $result->unchanged($file, 'El manifiesto ya lo registra.', $extra),
                $entry['class'] !== FileClass::UNTRACKED => $result->skipped($file, "Ya no es un archivo escrito a mano en la ruta de uno generado: {$now}.", $extra),
                default => $this->adoptNew($reference, $relative, $file, $absolute, $reference->regeneratedFile($relative), $context, $result, $extra,
                    'Se registra como adoptado y editado (con el hash de lo que generaría el contrato): verify lo ve personalizado y --force no lo toca.'),
            },
            default => $result->skipped($file, "adopt no sabe hacer pasos {$type}.", $extra),
        };
    }

    /**
     * Olvida un archivo y, si es de un modelo que ya no está en el contrato,
     * pasa lo que le queda y hoy genera otro (el módulo compartido de la
     * interfaz, que registra el último modelo que se generó) a ese dueño; sin
     * archivos, olvida el modelo entero. Un modelo retirado que conserva
     * algunos archivos es para `verify` una entidad a la que le falta todo lo
     * demás (inconsistent-entity).
     */
    private function drop(Manifest $manifest, ScopeReference $reference, string $absolute, ?string $retiredModel): bool
    {
        $dropped = $manifest->drop($absolute);

        if (! $dropped || $retiredModel === null || $retiredModel === '') {
            return $dropped;
        }

        $package = (string) $manifest->namespaceOf('');
        $namespace = $package !== '' ? $package : (string) $manifest->namespaceOf($retiredModel);

        foreach (array_keys($manifest->filesFor($retiredModel)) as $relative) {
            $absolute = $reference->scope['root'].'/'.$relative;

            // Lo que todavía falta en disco lo olvida su propio paso.
            if (! is_file($absolute)) {
                continue;
            }

            // Lo que hoy genera otro, a ese dueño; lo que no genera nadie
            // (una vista de exportación que se quedó) sigue registrado, sin
            // dueño: lo vigila verify y no cuenta como una entidad.
            $owner = $reference->producedBy((string) $relative);
            $manifest->move($absolute, $owner['model'] ?? '', $owner === null ? $namespace : $owner['namespace']);
        }

        if ($manifest->filesFor($retiredModel) === []) {
            $manifest->forget($retiredModel);
        }

        return $dropped;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function adoptNew(ScopeReference $reference, string $relative, string $file, string $absolute, ?string $hashOf, OperationContext $context, OperationResult $result, array $extra, string $reason): void
    {
        $produced = $reference->producedBy($relative);

        if ($produced === null) {
            $result->skipped($file, 'Lo regenerado no lo registra: no se sabe con qué modelo ni con qué stub anotarlo.', $extra);

            return;
        }

        $this->write($context, $result, $file, $extra + ['model' => $produced['model'] === '' ? null : $produced['model'], 'stub' => $produced['stub']],
            function () use ($reference, $produced, $absolute, $hashOf): bool {
                $reference->manifest()->adopt($produced['model'], $produced['namespace'], $absolute, $produced['stub'], $hashOf);

                return true;
            }, $reason);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  callable(): bool  $write
     */
    private function write(OperationContext $context, OperationResult $result, string $file, array $extra, callable $write, string $reason): void
    {
        if ($context->dryRun()) {
            $result->fixed($file, $reason, $extra);

            return;
        }

        $write()
            ? $result->fixed($file, $reason, $extra)
            : $result->unchanged($file, 'El manifiesto ya estaba así.', $extra);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function comments(array $entry): string
    {
        return ($entry['comments'] ?? false) === true ? '; sus comentarios difieren de los de la plantilla y un `import --force` los reemplazaría' : '';
    }

    /**
     * Anota la versión actual en lo heredado del ámbito que sigue siendo,
     * sin editar, lo que generaría hoy el contrato. Sólo lo que se decide
     * sin Pint (idéntico o igual salvo espacios por tokens): lo que necesita
     * la Pint del proyecto para saberse igual se queda sin versión, y el
     * diagnóstico lo sigue tratando como de una versión anterior.
     */
    private function stamp(string $path, ScopeReference $reference, OperationContext $context, OperationResult $result): void
    {
        if (! $reference->hasReference()) {
            return;
        }

        $manifest = $reference->manifest();
        $stamped = 0;

        foreach (['', ...$manifest->models()] as $model) {
            foreach (array_keys($manifest->filesFor((string) $model)) as $relative) {
                $relative = (string) $relative;
                $entry = $manifest->entryOf($relative);
                $absolute = $reference->scope['root'].'/'.$relative;
                $regenerated = $reference->regeneratedFile($relative);

                if ($entry === null || $entry['generator'] !== null || $regenerated === null || ! is_file($absolute)
                    || $manifest->hash($absolute) !== $entry['hash']
                    || ! in_array(FileComparison::compareFiles($absolute, $regenerated), [FileComparison::IDENTICAL, FileComparison::FORMAT], true)) {
                    continue;
                }

                if (! $context->dryRun()) {
                    $manifest->stamp($absolute);
                }

                $stamped++;
            }
        }

        if ($stamped > 0) {
            $result->fixed(null, sprintf('%s: %d %s heredados sin editar e iguales a lo que generaría hoy el contrato %s la versión %s.',
                $path === '.' ? 'app (núcleo)' : $path, $stamped, $stamped === 1 ? 'archivo' : 'archivos',
                $context->dryRun() ? 'se anotarían con' : 'quedan anotados con', GeneratorVersion::current()), ['scope' => $path, 'stamped' => $stamped]);
        }
    }
}
