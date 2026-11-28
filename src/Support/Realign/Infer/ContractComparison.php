<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Infer;

use Innoboxrr\LarapackGenerator\Support\TablePrefix;

/**
 * Lo inferido frente al laraimport que ya existe, modelo por modelo y prop
 * por prop: es «el diff que se propone» cuando el ámbito ya tiene contrato
 * (nunca se sobrescribe), y es también cómo se mide la precisión del
 * inferidor contra un contrato escrito por una persona.
 *
 * Compara sólo lo que se puede inferir del código —que la prop exista, su
 * tipo, si admite nulos, a qué tabla apunta su llave y su default— y lo que
 * el laraimport declaraba sin que el esquema lo respaldara se lista como
 * diferencia, no como error: puede ser el contrato el que se quedó atrás.
 */
final class ContractComparison
{
    public const FIELDS = ['type', 'nullable', 'constraint', 'default'];

    /**
     * @param  array<string, mixed>  $existing  El laraimport tal como está escrito.
     * @param  array<string, mixed>  $inferred  El borrador.
     * @return array{stats: array<string, int>, models: list<array<string, mixed>>}
     */
    public static function compare(array $existing, array $inferred): array
    {
        $byName = [];

        foreach ((array) ($inferred['models'] ?? []) as $model) {
            $byName[(string) $model['name']] = $model;
        }

        $stats = ['models' => 0, 'models_found' => 0, 'props' => 0, 'props_found' => 0, 'relations' => 0, 'relations_found' => 0, 'props_extra' => 0, 'models_extra' => 0];

        foreach (self::FIELDS as $field) {
            $stats[$field.'_equal'] = 0;
        }

        $models = [];
        $seen = [];

        foreach ((array) ($existing['models'] ?? []) as $model) {
            $name = (string) $model['name'];
            $stats['models']++;
            $mine = $byName[$name] ?? null;
            $seen[$name] = true;
            $entry = ['model' => $name, 'found' => $mine !== null, 'missing_props' => [], 'extra_props' => [], 'differences' => [], 'missing_relations' => []];

            $props = [];

            foreach (self::expanded((array) ($mine['props'] ?? [])) as $prop) {
                $props[(string) $prop['name']] = $prop;
            }

            if ($mine !== null) {
                $stats['models_found']++;
            }

            foreach (self::expanded((array) ($model['props'] ?? [])) as $prop) {
                $stats['props']++;
                $other = $props[(string) $prop['name']] ?? null;
                unset($props[(string) $prop['name']]);

                if ($other === null) {
                    $entry['missing_props'][] = $prop['name'];

                    continue;
                }

                $stats['props_found']++;

                foreach (self::FIELDS as $field) {
                    $theirs = self::value($prop, $field, $existing);
                    $ours = self::value($other, $field, $inferred);

                    if ($theirs === $ours) {
                        $stats[$field.'_equal']++;
                    } else {
                        $entry['differences'][] = ['prop' => $prop['name'], 'field' => $field, 'contract' => $theirs, 'inferred' => $ours];
                    }
                }
            }

            if ($mine !== null) {
                $entry['extra_props'] = array_keys($props);
                $stats['props_extra'] += count($props);
            }

            $relations = array_column((array) ($mine['load_relations'] ?? []), 'name');

            foreach ((array) ($model['load_relations'] ?? []) as $relation) {
                $stats['relations']++;

                if (in_array($relation['name'] ?? null, $relations, true)) {
                    $stats['relations_found']++;
                } else {
                    $entry['missing_relations'][] = $relation['name'] ?? '?';
                }
            }

            $models[] = $entry;
        }

        foreach (array_keys($byName) as $name) {
            if (! isset($seen[$name])) {
                $stats['models_extra']++;
                $models[] = ['model' => $name, 'found' => true, 'only_inferred' => true];
            }
        }

        return ['stats' => $stats, 'models' => $models];
    }

    /**
     * `morphs` es dos columnas: se comparan como tales, porque un contrato
     * puede declararlas sueltas (`x_type`, `x_id`) y el inferidor juntas.
     *
     * @param  array<int|string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private static function expanded(array $props): array
    {
        $expanded = [];

        foreach ($props as $prop) {
            $prop = (array) $prop;

            if (in_array($prop['type'] ?? null, ['morphs', 'nullableMorphs'], true)) {
                $nullable = $prop['type'] === 'nullableMorphs' || ! empty($prop['nullable']);
                $expanded[] = ['name' => $prop['name'].'_type', 'type' => 'string', 'nullable' => $nullable];
                $expanded[] = ['name' => $prop['name'].'_id', 'type' => 'unsignedBigInteger', 'nullable' => $nullable];

                continue;
            }

            $expanded[] = $prop;
        }

        return $expanded;
    }

    /**
     * El valor comparable de un campo, con las omisiones del esquema: sin
     * `nullable` es false; un foreignId y un unsignedBigInteger sin llave son
     * columnas distintas, pero `type` de un foreignId se compara tal cual.
     *
     * @param  array<string, mixed>  $prop
     * @param  array<string, mixed>  $document
     */
    private static function value(array $prop, string $field, array $document): ?string
    {
        return match ($field) {
            'type' => (string) $prop['type'],
            'nullable' => ! empty($prop['nullable']) ? 'true' : 'false',
            'constraint' => ($prop['type'] ?? null) === 'foreignId' ? self::physicalTable((string) ($prop['constraint'] ?? ''), $document) : null,
            'default' => self::defaultOf($prop['default'] ?? null),
            default => null,
        };
    }

    /**
     * La tabla a la que apunta de verdad un `constraint`: con `table_prefix`,
     * la de un modelo del propio archivo lleva el prefijo
     * (TablePrefix::constraint). Un contrato que ya la escribe con prefijo y
     * otro que no apuntan a la misma tabla.
     *
     * @param  array<string, mixed>  $document
     */
    private static function physicalTable(string $constraint, array $document): string
    {
        $prefix = (string) ($document['table_prefix'] ?? '');
        $prefix = $prefix === '' ? '' : rtrim($prefix, '_').'_';

        foreach ((array) ($document['models'] ?? []) as $model) {
            if (! isset($model['table']) && TablePrefix::tableOf((string) $model['name']) === $constraint) {
                return $prefix.$constraint;
            }
        }

        return $constraint;
    }

    private static function defaultOf(mixed $default): ?string
    {
        return match (true) {
            $default === null => null,
            is_bool($default) => $default ? '1' : '0',
            is_float($default), is_int($default) => rtrim(rtrim(number_format((float) $default, 6, '.', ''), '0'), '.'),
            is_numeric($default) => rtrim(rtrim(number_format((float) $default, 6, '.', ''), '0'), '.'),
            is_scalar($default) => (string) $default,
            default => json_encode($default) ?: null,
        };
    }
}
