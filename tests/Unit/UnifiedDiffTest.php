<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\UnifiedDiff;
use PHPUnit\Framework\TestCase;

final class UnifiedDiffTest extends TestCase
{
    public function test_iguales_no_dan_diff(): void
    {
        $this->assertSame('', UnifiedDiff::of("a\nb\n", "a\nb\n", 'x.php'));
    }

    public function test_un_cambio_con_tres_lineas_de_contexto(): void
    {
        $old = implode("\n", ['1', '2', '3', '4', '5', '6', '7', '8', '9'])."\n";
        $new = implode("\n", ['1', '2', '3', '4', 'cinco', 'cinco y medio', '6', '7', '8', '9'])."\n";

        $this->assertSame(
            "--- a/x.php\n+++ b/x.php\n@@ -2,7 +2,8 @@\n 2\n 3\n 4\n-5\n+cinco\n+cinco y medio\n 6\n 7\n 8\n",
            UnifiedDiff::of($old, $new, 'x.php')
        );
    }

    public function test_cambios_lejanos_van_en_trozos_distintos(): void
    {
        $old = implode("\n", range(1, 20));
        $new = str_replace(["\n2\n", "\n19\n"], ["\ndos\n", "\ndiecinueve\n"], $old);

        $diff = UnifiedDiff::of($old, $new, 'x.php');

        $this->assertSame(2, substr_count($diff, "\n@@ "));
        $this->assertStringContainsString("@@ -1,5 +1,5 @@\n 1\n-2\n+dos\n", $diff);
        $this->assertStringContainsString("@@ -16,5 +16,5 @@\n 16\n 17\n 18\n-19\n+diecinueve\n 20\n", $diff);
    }

    /**
     * Dos cambios a miles de líneas: la tabla de la subsecuencia común pedía
     * cientos de megas. El diff tiene que seguir siendo exacto —aplicado al
     * original da el nuevo—, caber en memoria y seguir siendo legible: dos
     * trozos pequeños, no el archivo entero quitado y puesto.
     */
    public function test_un_archivo_enorme_con_cambios_lejanos_no_agota_la_memoria(): void
    {
        $body = array_map(fn (int $i): string => "    // línea {$i}", range(1, 3000));
        $old = implode("\n", ['<?php', 'primera', ...$body, 'última'])."\n";
        $new = implode("\n", ['<?php', 'PRIMERA', ...$body, 'ÚLTIMA'])."\n";

        $before = memory_get_usage();
        memory_reset_peak_usage();

        $diff = UnifiedDiff::of($old, $new, 'x.php');

        $this->assertLessThan(48 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame($new, self::apply($old, $diff));
        $this->assertSame(2, substr_count($diff, "\n@@ "));
        $this->assertStringContainsString("@@ -1,5 +1,5 @@\n <?php\n-primera\n+PRIMERA\n     // línea 1\n", $diff);
        $this->assertStringContainsString("     // línea 3000\n-última\n+ÚLTIMA\n \n", $diff);
    }

    /**
     * Un archivo grande sin una sola línea única en el medio no tiene por
     * dónde partirse: sale entero, quitado y puesto, y sigue siendo exacto.
     */
    public function test_un_archivo_enorme_sin_lineas_unicas_sale_entero_y_exacto(): void
    {
        $body = array_fill(0, 2500, '}');
        $old = implode("\n", ['a', ...$body, 'b']);
        $new = implode("\n", ['A', ...$body, 'B']);

        $diff = UnifiedDiff::of($old, $new, 'x.php');

        $this->assertSame($new, self::apply($old, $diff));
        $this->assertSame(1, substr_count($diff, "\n@@ "));
    }

    /**
     * Por propiedades, con semilla fija: archivos por encima del límite de la
     * tabla, con líneas repetidas (llaves, líneas en blanco) y cambios al
     * azar por todo el archivo. El diff aplicado da el nuevo, y es siempre el
     * mismo para los mismos textos.
     */
    public function test_los_diffs_grandes_son_exactos_y_deterministas(): void
    {
        mt_srand(20261126);

        try {
            for ($round = 0; $round < 6; $round++) {
                $old = [];

                for ($i = 0; $i < 2400; $i++) {
                    $old[] = match (mt_rand(0, 5)) {
                        0 => '}',
                        1 => '',
                        default => "    \$x{$i} = ".mt_rand(0, 50).';',
                    };
                }

                $new = $old;

                for ($edit = mt_rand(1, 40); $edit > 0; $edit--) {
                    $at = mt_rand(0, count($new) - 1);

                    match (mt_rand(0, 2)) {
                        0 => array_splice($new, $at, mt_rand(1, 30)),
                        1 => array_splice($new, $at, 0, array_map(fn (int $k): string => "nueva {$round}.{$edit}.{$k}", range(1, mt_rand(1, 30)))),
                        default => $new[$at] = "cambiada {$round}.{$edit}",
                    };
                }

                $a = implode("\n", $old)."\n";
                $b = implode("\n", $new)."\n";
                $diff = UnifiedDiff::of($a, $b, 'x.php');

                $this->assertSame($b, self::apply($a, $diff), "Ronda {$round}");
                $this->assertSame($diff, UnifiedDiff::of($a, $b, 'x.php'), "Ronda {$round}");
                $this->assertLessThan(count($old), substr_count($diff, "\n"), "Ronda {$round}: el diff no debía reescribir el archivo entero.");
            }
        } finally {
            mt_srand();
        }
    }

    /**
     * Aplica un diff unificado de UnifiedDiff al texto viejo, comprobando que
     * cada línea de contexto y cada «-» coinciden con lo que hay.
     */
    private static function apply(string $old, string $diff): string
    {
        $source = explode("\n", $old);
        $lines = explode("\n", $diff);
        array_pop($lines);
        $output = [];
        $cursor = 0;

        foreach (array_slice($lines, 2) as $line) {
            if (preg_match('/^@@ -(\d+),(\d+) \+\d+,\d+ @@$/', $line, $match) === 1) {
                $start = (int) $match[2] === 0 ? (int) $match[1] : (int) $match[1] - 1;

                while ($cursor < $start) {
                    $output[] = $source[$cursor++];
                }

                continue;
            }

            $op = $line[0];
            $text = substr($line, 1);

            if ($op === '+') {
                $output[] = $text;

                continue;
            }

            self::assertSame($source[$cursor] ?? null, $text, 'El diff no casa con el original.');
            $cursor++;

            if ($op === ' ') {
                $output[] = $text;
            }
        }

        while ($cursor < count($source)) {
            $output[] = $source[$cursor++];
        }

        return implode("\n", $output);
    }
}
