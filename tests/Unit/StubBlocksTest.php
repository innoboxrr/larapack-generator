<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\StubBlocks;
use PHPUnit\Framework\TestCase;

final class StubBlocksTest extends TestCase
{
    private function apply(string $content, array $true): string
    {
        return StubBlocks::apply($content, fn (string $condition): bool => in_array($condition, $true, true));
    }

    /**
     * Es la propiedad de la que depende la retrocompatibilidad: con todas las
     * condiciones cumplidas, la salida es el stub sin las líneas de marcador,
     * y nada más.
     */
    public function test_con_la_condicion_cumplida_solo_desaparecen_los_marcadores(): void
    {
        $stub = "a\n// @larapack:if update\nb\n// @larapack:endif\nc\n";

        $this->assertSame("a\nb\nc\n", $this->apply($stub, ['update']));
    }

    public function test_sin_la_condicion_desaparece_el_bloque_entero(): void
    {
        $stub = "a\n// @larapack:if update\nb\n\n// @larapack:endif\nc\n";

        $this->assertSame("a\nc\n", $this->apply($stub, []));
    }

    public function test_la_sintaxis_del_comentario_da_igual(): void
    {
        $stub = "<div>\n    <!-- @larapack:if show -->\n    <p/>\n    <!-- @larapack:endif -->\n    {/* @larapack:if create */}\n    <i/>\n    {/* @larapack:endif */}\n</div>\n";

        $this->assertSame("<div>\n    <i/>\n</div>\n", $this->apply($stub, ['create']));
    }

    public function test_barra_vertical_es_cualquiera(): void
    {
        $stub = "// @larapack:if delete|restore\nx\n// @larapack:endif\n";

        $this->assertSame("x\n", $this->apply($stub, ['restore']));
        $this->assertSame('', $this->apply($stub, []));
    }

    public function test_ampersand_es_todas(): void
    {
        $stub = "// @larapack:if update&show\nx\n// @larapack:endif\n";

        $this->assertSame('', $this->apply($stub, ['update']));
        $this->assertSame("x\n", $this->apply($stub, ['update', 'show']));
    }

    public function test_un_bloque_anidado_no_sobrevive_a_su_padre(): void
    {
        $stub = "// @larapack:if show\na\n// @larapack:if update\nb\n// @larapack:endif\n// @larapack:endif\n";

        $this->assertSame('', $this->apply($stub, ['update']));
        $this->assertSame("a\n", $this->apply($stub, ['show']));
        $this->assertSame("a\nb\n", $this->apply($stub, ['show', 'update']));
    }

    public function test_conserva_los_finales_de_linea_crlf(): void
    {
        $stub = "a\r\n// @larapack:if x\r\nb\r\n// @larapack:endif\r\nc\r\n";

        $this->assertSame("a\r\nb\r\nc\r\n", $this->apply($stub, ['x']));
    }

    public function test_la_exclamacion_niega(): void
    {
        $stub = "// @larapack:if delete\nlargo\n// @larapack:endif\n// @larapack:if !delete\ncorto\n// @larapack:endif\n";

        $this->assertSame("largo\n", $this->apply($stub, ['delete']));
        $this->assertSame("corto\n", $this->apply($stub, []));
    }

    public function test_un_endif_sin_if_es_un_error(): void
    {
        $this->expectException(MakerException::class);

        $this->apply("a\n// @larapack:endif\n", []);
    }

    public function test_un_if_sin_cerrar_es_un_error(): void
    {
        $this->expectException(MakerException::class);

        $this->apply("// @larapack:if show\na\n", ['show']);
    }

    public function test_mezclar_y_con_o_es_un_error(): void
    {
        $this->expectException(MakerException::class);

        $this->apply("// @larapack:if a&b|c\nx\n// @larapack:endif\n", []);
    }

    /**
     * Un stub con un bloque mal cerrado sólo fallaría al generar el modelo que
     * tuviera esa acción. Aquí falla antes, para todos los stubs a la vez.
     */
    public function test_todos_los_stubs_estan_equilibrados_y_solo_usan_condiciones_conocidas(): void
    {
        $known = [...Actions::ALL, ...Declaration::FLAGS];
        $problems = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Stubs', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $content = (string) file_get_contents($file->getPathname());
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1));

            foreach (array_diff(StubBlocks::conditions($content), $known) as $unknown) {
                $problems[] = "{$name}: condición desconocida '{$unknown}'";
            }

            try {
                StubBlocks::apply($content, fn (): bool => true);
            } catch (MakerException $e) {
                $problems[] = "{$name}: {$e->getMessage()}";
            }
        }

        $this->assertSame([], $problems);
    }
}
