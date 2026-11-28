<?php

namespace Innoboxrr\LarapackGenerator\Support\Merge;

use Innoboxrr\LarapackGenerator\Support\Manifest;

/**
 * Recompone el manifiesto de una fusión a partir de los de las dos ramas.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ: el manifiesto es un registro de hashes, y un hash no se fusiona:
 * o es el de una rama o el de la otra, y el archivo fusionado puede no ser
 * ninguno de los dos. Resolver ese conflicto a mano —quedarse con un lado—
 * deja un manifiesto que miente: registra un archivo con un hash que no es
 * el suyo (pasa a «editado a mano» sin que nadie lo editara, y `--force` ya
 * no lo regenera), o pierde los modelos que añadió la otra rama (pasan a ser
 * archivos que el generador «no escribió»). Con diez ramas, eso es el
 * manifiesto entero.
 * ────────────────────────────────────────────────────────────────────────
 *
 * Se fusiona por entradas, de tres vías: lo que cambió una sola rama es de
 * esa rama; lo que añadieron las dos, la unión. Lo único que no se decide
 * solo es el archivo que las DOS ramas volvieron a generar con contenidos
 * distintos, y ahí lo que cuenta es si en cada rama estaba tal como salió
 * del generador (su contenido en esa rama tiene el hash que anotó esa rama):
 *
 *  - en las dos: no lleva trabajo de nadie. Se devuelve en `regenerate`: el
 *    contenido correcto es el que genere el contrato fusionado, y quien
 *    llama lo deja para que `import --force` lo reescriba;
 *  - en una sola: la otra lo editó a mano después de generarlo. Se queda el
 *    registro de la que lo editó —así sigue contando como editado y nadie
 *    lo pisa— y va a `review`;
 *  - en ninguna: lo mismo, con el registro nuestro.
 *
 * No escribe nada ni mira el disco: lo que había en cada rama se lo da
 * `$contentAt`.
 *
 * @phpstan-type Review array{file: string, reason: string}
 */
final class ManifestMerge
{
    /**
     * @var list<string>
     */
    private array $regenerate = [];

    /**
     * @var list<Review>
     */
    private array $review = [];

    /**
     * @param  \Closure(string, string): (string|null)  $contentAt
     */
    private function __construct(private readonly \Closure $contentAt) {}

    /**
     * @param  array<string, mixed>|null  $base
     * @param  array<string, mixed>|null  $ours
     * @param  array<string, mixed>|null  $theirs
     * @param  \Closure(string, string): (string|null)  $contentAt  el contenido de un archivo (ruta relativa al ámbito) en `ours` o en `theirs`; null si no existía
     * @return array{manifest: array<string, mixed>|null, regenerate: list<string>, review: list<Review>}
     */
    public static function merge(?array $base, ?array $ours, ?array $theirs, \Closure $contentAt): array
    {
        if ($ours === null || $theirs === null) {
            return ['manifest' => $ours ?? $theirs, 'regenerate' => [], 'review' => []];
        }

        $merge = new self($contentAt);
        $base ??= [];

        $merged = [
            'version' => max((int) ($ours['version'] ?? 1), (int) ($theirs['version'] ?? 1)),
            'package' => [
                'namespace' => (string) (($ours['package']['namespace'] ?? '') ?: ($theirs['package']['namespace'] ?? '')),
                'files' => $merge->files(
                    self::map($base['package']['files'] ?? null),
                    self::map($ours['package']['files'] ?? null),
                    self::map($theirs['package']['files'] ?? null),
                ),
            ],
        ];

        $pivots = $merge->plain(self::map($base['pivots'] ?? null), self::map($ours['pivots'] ?? null), self::map($theirs['pivots'] ?? null));

        if ($pivots !== []) {
            $merged['pivots'] = $pivots;
        }

        $merged['models'] = $merge->models(self::map($base['models'] ?? null), self::map($ours['models'] ?? null), self::map($theirs['models'] ?? null));

        // Lo que esta versión no conoce, de quien lo traiga.
        $merged += array_diff_key($ours + $theirs, array_flip(['version', 'package', 'pivots', 'models']));

        return ['manifest' => $merged, 'regenerate' => array_values(array_unique($merge->regenerate)), 'review' => $merge->review];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @return array<string, mixed>
     */
    private function models(array $base, array $ours, array $theirs): array
    {
        $merged = [];

        foreach (array_keys($ours + $theirs) as $model) {
            $model = (string) $model;
            [$b, $o, $t] = [$base[$model] ?? null, $ours[$model] ?? null, $theirs[$model] ?? null];

            if ($o === null || $t === null) {
                $kept = $o ?? $t;

                // Lo retiró una rama (`remove-full-model`) y la otra no lo
                // tocó: retirado. Si la otra lo cambió, se conserva.
                if ($b === null || ! self::same($kept, $b)) {
                    $merged[$model] = $kept;
                }

                continue;
            }

            [$b, $o, $t] = [self::map($b), self::map($o), self::map($t)];
            $entry = [];

            foreach (array_keys($o + $t) as $key) {
                $key = (string) $key;

                if ($key === 'files') {
                    $entry['files'] = $this->files(self::map($b['files'] ?? null), self::map($o['files'] ?? null), self::map($t['files'] ?? null));

                    continue;
                }

                // La huella del contrato y la declaración, si las dos ramas
                // las cambiaron, se quedan con la nuestra: no son las del
                // contrato fusionado, `verify` lo dice (contract-outdated) y
                // la importación que sigue las vuelve a anotar.
                $value = self::three($b[$key] ?? null, $o[$key] ?? null, $t[$key] ?? null)['value'];

                if ($value !== null) {
                    $entry[$key] = $value;
                }
            }

            $merged[$model] = $entry;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @return array<string, mixed>
     */
    private function plain(array $base, array $ours, array $theirs): array
    {
        $merged = [];

        foreach (array_keys($ours + $theirs) as $key) {
            $value = self::three($base[$key] ?? null, $ours[$key] ?? null, $theirs[$key] ?? null)['value'];

            if ($value !== null) {
                $merged[(string) $key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @return array<string, mixed>
     */
    private function files(array $base, array $ours, array $theirs): array
    {
        $merged = [];

        foreach (array_keys($ours + $theirs) as $file) {
            $file = (string) $file;
            [$b, $o, $t] = [$base[$file] ?? null, $ours[$file] ?? null, $theirs[$file] ?? null];
            $result = self::three($b, $o, $t);

            if (! $result['contested']) {
                if ($result['value'] !== null) {
                    $merged[$file] = $result['value'];
                }

                continue;
            }

            // Una rama lo dejó de registrar y la otra lo cambió: se conserva.
            if ($o === null || $t === null) {
                $merged[$file] = $o ?? $t;

                continue;
            }

            $oursPristine = $this->pristine('ours', $file, $o);
            $theirsPristine = $this->pristine('theirs', $file, $t);

            if ($oursPristine && $theirsPristine) {
                $merged[$file] = $o;
                $this->regenerate[] = $file;

                continue;
            }

            $merged[$file] = $oursPristine ? $t : $o;
            $this->review[] = ['file' => $file, 'reason' => match (true) {
                $oursPristine => 'las dos ramas lo regeneraron y la otra además lo editó a mano',
                $theirsPristine => 'las dos ramas lo regeneraron y la nuestra además lo editó a mano',
                default => 'las dos ramas lo regeneraron y las dos lo editaron a mano',
            }.': se conserva como editado; comprueba que lleve lo que el contrato fusionado pide'];
        }

        return $merged;
    }

    private function pristine(string $side, string $file, mixed $entry): bool
    {
        $content = ($this->contentAt)($side, $file);

        return $content !== null && is_array($entry) && Manifest::hashOf($content) === ($entry['hash'] ?? null);
    }

    /**
     * Tres vías sobre un valor entero; null es «no está».
     *
     * @return array{value: mixed, contested: bool}
     */
    private static function three(mixed $base, mixed $ours, mixed $theirs): array
    {
        if (self::same($ours, $theirs) || self::same($theirs, $base)) {
            return ['value' => $ours, 'contested' => false];
        }

        if (self::same($ours, $base)) {
            return ['value' => $theirs, 'contested' => false];
        }

        return ['value' => $ours, 'contested' => true];
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return json_encode(self::canonical($a)) === json_encode(self::canonical($b));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
