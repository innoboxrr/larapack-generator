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
}
