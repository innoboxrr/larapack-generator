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
     * original da el nuevo— y caber en memoria.
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

        // Las líneas de contexto y las «+» son el archivo nuevo; las de
        // contexto y las «-», el viejo. Con un solo trozo, desde la línea 1.
        $this->assertSame(1, substr_count($diff, "\n@@ "));
        $lines = array_slice(explode("\n", rtrim($diff, "\n")), 3);
        $this->assertSame(explode("\n", $old), array_values(array_map(fn (string $l): string => substr($l, 1), array_filter($lines, fn (string $l): bool => $l[0] !== '+'))));
        $this->assertSame(explode("\n", $new), array_values(array_map(fn (string $l): string => substr($l, 1), array_filter($lines, fn (string $l): bool => $l[0] !== '-'))));
    }
}
