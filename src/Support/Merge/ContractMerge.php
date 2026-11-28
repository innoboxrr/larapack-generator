<?php

namespace Innoboxrr\LarapackGenerator\Support\Merge;

use stdClass;

/**
 * Fusiona dos versiones de un laraimport.json por lo que significan, no por
 * sus líneas.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ: git fusiona texto. Dos ramas que añaden cada una un modelo al
 * final de `models`, o cada una una columna al final de `props`, chocan
 * siempre —tocan las mismas líneas—, aunque los dos cambios sean
 * independientes; y dos que añaden la misma columna en sitios distintos se
 * fusionan «limpias» y dejan la columna declarada dos veces. El contrato es
 * una estructura con nombres: se fusiona por nombre.
 * ────────────────────────────────────────────────────────────────────────
 *
 * Fusión de tres vías, recursiva:
 *
 *  - un objeto, clave a clave;
 *  - una lista de piezas con `name` (`models`, `props`, `endpoints`,
 *    `controllers`, `pivots`, `load_relations`…), por nombre: en el orden de
 *    «lo nuestro», con lo que añadió la otra rama detrás;
 *  - una lista de valores sueltos (`except`, `middleware`), como conjunto:
 *    lo nuestro, más lo que la otra añadió, menos lo que quitó;
 *  - lo demás, si sólo cambió en una rama, de esa rama.
 *
 * Lo que las dos ramas cambiaron de forma distinta NO se decide aquí: es un
 * conflicto de verdad (un endpoint que una rama declara `post` y otra `put`),
 * se devuelve con su ruta y los dos valores y lo decide una persona. En el
 * resultado queda el valor nuestro, pero quien llama no debe escribirlo
 * mientras haya conflictos.
 *
 * @phpstan-type Conflict array{path: string, ours: mixed, theirs: mixed}
 */
final class ContractMerge
{
    /**
     * @var list<Conflict>
     */
    private array $conflicts = [];

    /**
     * @return array{merged: mixed, conflicts: list<Conflict>}
     */
    public static function merge(mixed $base, mixed $ours, mixed $theirs): array
    {
        $merge = new self;
        $merged = $merge->value($base, $ours, $theirs, '');

        return ['merged' => $merged, 'conflicts' => $merge->conflicts];
    }

    /**
     * Fusiona tres textos JSON. `$base` null: el archivo no existía.
     *
     * @return array{json: string|null, conflicts: list<Conflict>, error: string|null}
     */
    public static function mergeJson(?string $base, string $ours, string $theirs): array
    {
        $decoded = [];

        foreach (['base' => $base, 'ours' => $ours, 'theirs' => $theirs] as $side => $json) {
            $decoded[$side] = $json === null ? null : json_decode($json);

            if ($json !== null && ! $decoded[$side] instanceof stdClass) {
                // Una base ilegible no impide fusionar: sin base, todo lo
                // distinto es conflicto, que es lo prudente.
                if ($side === 'base') {
                    $decoded[$side] = null;

                    continue;
                }

                return ['json' => null, 'conflicts' => [], 'error' => "la versión de {$side} no es un objeto JSON (".json_last_error_msg().')'];
            }
        }

        ['merged' => $merged, 'conflicts' => $conflicts] = self::merge($decoded['base'], $decoded['ours'], $decoded['theirs']);

        return ['json' => self::encode($merged), 'conflicts' => $conflicts, 'error' => null];
    }

    /**
     * El formato en que se escribe: sangría de cuatro espacios, sin escapar
     * barras ni acentos, y con salto de línea final. Siempre el mismo, para
     * que dos reconciliaciones de lo mismo den el mismo archivo.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function value(mixed $base, mixed $ours, mixed $theirs, string $path): mixed
    {
        if (self::same($ours, $theirs)) {
            return $ours;
        }

        if (self::same($ours, $base)) {
            return $theirs;
        }

        if (self::same($theirs, $base)) {
            return $ours;
        }

        if ($ours instanceof stdClass && $theirs instanceof stdClass) {
            return $this->object($base instanceof stdClass ? $base : null, $ours, $theirs, $path);
        }

        if (is_array($ours) && is_array($theirs)) {
            $base = is_array($base) ? $base : [];

            if (self::named($ours) && self::named($theirs) && self::named($base)) {
                return $this->namedList($base, $ours, $theirs, $path);
            }

            if (self::scalars($ours) && self::scalars($theirs) && self::scalars($base)) {
                return self::set($base, $ours, $theirs);
            }
        }

        $this->conflicts[] = ['path' => $path === '' ? '/' : $path, 'ours' => $ours, 'theirs' => $theirs];

        return $ours;
    }

    private function object(?stdClass $base, stdClass $ours, stdClass $theirs, string $path): stdClass
    {
        $merged = new stdClass;
        $absent = new Absent;
        $done = [];

        foreach ([...array_keys(get_object_vars($ours)), ...array_keys(get_object_vars($theirs))] as $key) {
            $key = (string) $key;

            if (isset($done[$key])) {
                continue;
            }

            $done[$key] = true;

            $value = $this->entry(
                $base !== null && property_exists($base, $key) ? $base->{$key} : $absent,
                property_exists($ours, $key) ? $ours->{$key} : $absent,
                property_exists($theirs, $key) ? $theirs->{$key} : $absent,
                $path.'/'.$key,
            );

            if (! $value instanceof Absent) {
                $merged->{$key} = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  list<mixed>  $base
     * @param  list<mixed>  $ours
     * @param  list<mixed>  $theirs
     * @return list<mixed>
     */
    private function namedList(array $base, array $ours, array $theirs, string $path): array
    {
        $index = fn (array $list): array => array_column(array_map(fn (stdClass $item): array => ['name' => $item->name, 'item' => $item], $list), 'item', 'name');
        [$byBase, $byOurs, $byTheirs] = [$index($base), $index($ours), $index($theirs)];
        $absent = new Absent;
        $merged = [];
        $done = [];

        foreach ([...array_keys($byOurs), ...array_keys($byTheirs)] as $name) {
            if (isset($done[$name])) {
                continue;
            }

            $done[$name] = true;

            $value = $this->entry($byBase[$name] ?? $absent, $byOurs[$name] ?? $absent, $byTheirs[$name] ?? $absent, $path.'/'.$name);

            if (! $value instanceof Absent) {
                $merged[] = $value;
            }
        }

        return $merged;
    }

    /**
     * Una clave o una pieza que puede no estar en alguna de las tres.
     */
    private function entry(mixed $base, mixed $ours, mixed $theirs, string $path): mixed
    {
        $absent = fn (mixed $value): bool => $value instanceof Absent;

        if ($absent($ours) && $absent($theirs)) {
            return $ours;
        }

        // La quitó una rama: se va si la otra no la tocó; si la otra la
        // cambió, quitarla perdería ese trabajo sin que nadie lo decidiera.
        if ($absent($ours) || $absent($theirs)) {
            $kept = $absent($ours) ? $theirs : $ours;

            if ($absent($base)) {
                return $kept; // la añadió una sola rama
            }

            if (self::same($kept, $base)) {
                return new Absent;
            }

            $this->conflicts[] = ['path' => $path, 'ours' => $absent($ours) ? '(quitado)' : $ours, 'theirs' => $absent($theirs) ? '(quitado)' : $theirs];

            return $kept;
        }

        return $this->value($absent($base) ? null : $base, $ours, $theirs, $path);
    }

    /**
     * @param  list<mixed>  $base
     * @param  list<mixed>  $ours
     * @param  list<mixed>  $theirs
     * @return list<mixed>
     */
    private static function set(array $base, array $ours, array $theirs): array
    {
        $removed = array_filter($base, fn (mixed $value): bool => ! in_array($value, $theirs, true));
        $merged = array_filter($ours, fn (mixed $value): bool => ! in_array($value, $removed, true));

        foreach ($theirs as $value) {
            if (! in_array($value, $base, true) && ! in_array($value, $merged, true)) {
                $merged[] = $value;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<mixed>  $list
     */
    private static function named(array $list): bool
    {
        $names = [];

        foreach ($list as $item) {
            if (! $item instanceof stdClass || ! is_string($item->name ?? null) || isset($names[$item->name])) {
                return false;
            }

            $names[$item->name] = true;
        }

        return array_is_list($list);
    }

    /**
     * @param  array<mixed>  $list
     */
    private static function scalars(array $list): bool
    {
        return array_is_list($list) && array_filter($list, fn (mixed $value): bool => ! is_scalar($value) && $value !== null) === [];
    }

    /**
     * Igualdad de contenido: el orden de las claves de un objeto no cuenta.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof Absent || $b instanceof Absent) {
            return $a instanceof Absent && $b instanceof Absent;
        }

        return json_encode(self::canonical($a)) === json_encode(self::canonical($b));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $vars = array_map(self::canonical(...), get_object_vars($value));
            ksort($vars);

            // Un objeto vacío no es una lista vacía.
            return $vars === [] ? new stdClass : $vars;
        }

        return is_array($value) ? array_map(self::canonical(...), $value) : $value;
    }
}
