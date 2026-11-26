<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Un diff unificado entre dos textos, sin depender de que haya un `diff` en
 * la máquina: en Windows, donde se desarrolla buena parte de los proyectos,
 * no lo hay.
 *
 * Se recortan las líneas iguales del principio y del final antes de comparar:
 * un arreglo toca unas pocas líneas de un archivo de cien, y la tabla de la
 * subsecuencia común sólo se hace sobre lo que cambió.
 */
final class UnifiedDiff
{
    private const CONTEXT = 3;

    public static function of(string $old, string $new, string $label): string
    {
        if ($old === $new) {
            return '';
        }

        $a = explode("\n", $old);
        $b = explode("\n", $new);
        $prefix = 0;

        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix
            && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]
        ) {
            $suffix++;
        }

        // Cada línea: [' ', '-' o '+', texto, línea en a, línea en b].
        $lines = [];

        for ($i = 0; $i < $prefix; $i++) {
            $lines[] = [' ', $a[$i], $i, $i];
        }

        foreach (self::middle(array_slice($a, $prefix, count($a) - $prefix - $suffix), array_slice($b, $prefix, count($b) - $prefix - $suffix)) as [$op, $text, $i, $j]) {
            $lines[] = [$op, $text, $prefix + $i, $prefix + $j];
        }

        for ($i = $suffix; $i > 0; $i--) {
            $lines[] = [' ', $a[count($a) - $i], count($a) - $i, count($b) - $i];
        }

        return "--- a/{$label}\n+++ b/{$label}\n".self::hunks($lines);
    }

    /**
     * Celdas de la tabla de la subsecuencia común a partir de las cuales no
     * se calcula: unas 70 MB en PHP.
     */
    private const MAX_CELLS = 4_000_000;

    /**
     * La parte que cambia, por subsecuencia común más larga.
     *
     * La tabla ocupa n·m: un archivo con dos cambios a miles de líneas uno
     * del otro —una propiedad al principio de la clase y un bloque al final—
     * pedía cientos de megas, y `larapack:fix --dry-run` moría sin memoria a
     * mitad de la lista. Pasado MAX_CELLS se parte por anclas (anchored()):
     * las líneas que aparecen una sola vez en cada lado. Si no hay ninguna, la
     * parte del medio sale entera, quitada y puesta: el diff es más largo pero
     * sigue siendo exacto.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n * $m > self::MAX_CELLS) {
            return self::anchored($a, $b);
        }

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $lines[] = [' ', $a[$i], $i++, $j++];
            } elseif ($i < $n && ($j === $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                // Ante un empate, lo que se quita va antes que lo que se pone.
                $lines[] = ['-', $a[$i], $i++, $j];
            } else {
                $lines[] = ['+', $b[$j], $i, $j++];
            }
        }

        return $lines;
    }

    /**
     * La parte que cambia de un archivo grande, partida por anclas, como el
     * diff «patience» de git: las líneas únicas en los dos lados, en el orden
     * más largo en que aparecen en ambos, son las que no cambiaron. Entre dos
     * anclas queda un trozo pequeño, que vuelve a middle(). Memoria lineal y
     * el mismo resultado cada vez: depende sólo de los dos textos.
     *
     * Sin ninguna ancla —un archivo de líneas repetidas— la parte sale entera,
     * quitada y puesta.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private static function anchored(array $a, array $b): array
    {
        $anchors = self::anchors($a, $b);

        if ($anchors === []) {
            $n = count($a);

            return [
                ...array_map(fn (string $line, int $i): array => ['-', $line, $i, 0], $a, array_keys($a)),
                ...array_map(fn (string $line, int $j): array => ['+', $line, $n, $j], $b, array_keys($b)),
            ];
        }

        $lines = [];
        $i = 0;
        $j = 0;

        foreach ([...$anchors, [count($a), count($b)]] as [$ai, $bj]) {
            $gap = self::middle(array_slice($a, $i, $ai - $i), array_slice($b, $j, $bj - $j));

            foreach ($gap as [$op, $text, $gi, $gj]) {
                $lines[] = [$op, $text, $i + $gi, $j + $gj];
            }

            if ($ai < count($a)) {
                $lines[] = [' ', $a[$ai], $ai, $bj];
            }

            $i = $ai + 1;
            $j = $bj + 1;
        }

        return $lines;
    }

    /**
     * Las anclas: pares [línea en a, línea en b] de una línea que aparece una
     * sola vez en cada lado, y de ellos la secuencia más larga creciente en
     * los dos (patience sorting, n·log n).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: int, 1: int}>
     */
    private static function anchors(array $a, array $b): array
    {
        $count = [];

        foreach ($a as $i => $line) {
            $count[$line] = isset($count[$line]) ? [$count[$line][0] + 1, $i, -1, 0] : [1, $i, -1, 0];
        }

        foreach ($b as $j => $line) {
            if (isset($count[$line])) {
                $count[$line][2] = $j;
                $count[$line][3]++;
            }
        }

        // Los candidatos en el orden de a; se busca la subsecuencia más larga
        // creciente en b.
        $pairs = [];

        foreach ($count as [$inA, $i, $j, $inB]) {
            if ($inA === 1 && $inB === 1) {
                $pairs[] = [$i, $j];
            }
        }

        usort($pairs, fn (array $x, array $y): int => $x[0] <=> $y[0]);

        $tails = [];
        $previous = [];

        foreach ($pairs as $k => [, $j]) {
            $low = 0;
            $high = count($tails);

            while ($low < $high) {
                $mid = intdiv($low + $high, 2);

                if ($pairs[$tails[$mid]][1] < $j) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            $previous[$k] = $low > 0 ? $tails[$low - 1] : null;
            $tails[$low] = $k;
        }

        $anchors = [];

        for ($k = $tails === [] ? null : $tails[count($tails) - 1]; $k !== null; $k = $previous[$k]) {
            $anchors[] = $pairs[$k];
        }

        return array_reverse($anchors);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $lines
     */
    private static function hunks(array $lines): string
    {
        $changed = array_keys(array_filter($lines, fn (array $line): bool => $line[0] !== ' '));
        $output = '';
        $k = 0;

        while ($k < count($changed)) {
            $from = max(0, $changed[$k] - self::CONTEXT);
            $to = $changed[$k];

            // Se juntan los cambios cuyo contexto se toca.
            while ($k + 1 < count($changed) && 2 * self::CONTEXT >= $changed[$k + 1] - $to) {
                $to = $changed[++$k];
            }

            $to = min(count($lines) - 1, $to + self::CONTEXT);
            $k++;

            $slice = array_slice($lines, $from, $to - $from + 1);
            $oldCount = count(array_filter($slice, fn (array $line): bool => $line[0] !== '+'));
            $newCount = count(array_filter($slice, fn (array $line): bool => $line[0] !== '-'));

            $output .= sprintf("@@ -%d,%d +%d,%d @@\n", $slice[0][2] + ($oldCount > 0 ? 1 : 0), $oldCount, $slice[0][3] + ($newCount > 0 ? 1 : 0), $newCount);

            foreach ($slice as [$op, $text]) {
                $output .= $op.$text."\n";
            }
        }

        return $output;
    }
}
