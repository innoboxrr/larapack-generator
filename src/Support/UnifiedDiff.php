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
     * La parte que cambia, por subsecuencia común más larga.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
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
