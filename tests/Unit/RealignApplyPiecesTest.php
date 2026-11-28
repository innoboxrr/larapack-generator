<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Apply\Changes;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\ConcernMove;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\EndpointTests;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\JsonEdit;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\PhpMembers;
use PHPUnit\Framework\TestCase;

/**
 * Las piezas de `larapack:realign apply`, cada una con lo que hace y con lo
 * que se niega a hacer: un cambio entero o ninguno, un método con su
 * docblock, un test completado sin tocar lo de la persona, una referencia
 * reescrita por tokens, un JSON editado sin reformatearlo.
 */
final class RealignApplyPiecesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/realign-pieces-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function test_un_cambio_que_no_parsea_se_deshace_entero(): void
    {
        file_put_contents($this->directory.'/a.php', "<?php\necho 1;\n");
        $changes = new Changes($this->directory);
        $changes->put($this->directory.'/a.php', "<?php\necho 2;\n");
        $changes->put($this->directory.'/b.php', "<?php\nfunction (\n");
        $changes->delete($this->directory.'/a.php');

        $problem = $changes->commit();

        $this->assertNotNull($problem);
        $this->assertStringContainsString('b.php no parsearía', $problem);
        $this->assertSame("<?php\necho 1;\n", file_get_contents($this->directory.'/a.php'));
        $this->assertFileDoesNotExist($this->directory.'/b.php');
    }

    public function test_un_cambio_que_parsea_se_escribe_y_su_diff_se_ve_antes(): void
    {
        $changes = new Changes($this->directory);
        $changes->put($this->directory.'/c.php', "<?php\necho 3;\n");

        $this->assertStringContainsString('+echo 3;', $changes->diff());
        $this->assertFileDoesNotExist($this->directory.'/c.php');
        $this->assertNull($changes->commit());
        $this->assertFileExists($this->directory.'/c.php');
    }

    public function test_un_metodo_se_lee_con_su_docblock_sus_atributos_y_su_sangria(): void
    {
        $code = "<?php\n\nnamespace A;\n\nuse B\\C;\nuse D\\E as F;\n\nclass X\n{\n    /** Doc. */\n    #[Attr(['a' => [1]])]\n    public static function uno(): string\n    {\n        return \"{\$a} }\";\n    }\n\n    abstract protected function dos(): void;\n}\n";
        $members = PhpMembers::of($code);

        $this->assertNotNull($members);
        $this->assertSame('A', $members->namespace);
        $this->assertSame([['B\\C', 'C'], ['D\\E', 'F']], array_map(fn (array $import): array => [$import['name'], $import['alias']], $members->imports));
        $this->assertSame('D\\E', $members->imported('f'));
        $this->assertStringStartsWith("    /** Doc. */\n    #[Attr", $members->methods['uno']['code']);
        $this->assertStringEndsWith("return \"{\$a} }\";\n    }", $members->methods['uno']['code']);
        $this->assertSame('protected', $members->methods['dos']['visibility']);
        $this->assertSame('}', $code[$members->classClose]);
    }

    public function test_lo_que_no_se_reconoce_con_certeza_no_se_lee(): void
    {
        $this->assertNull(PhpMembers::of("<?php\nuse A\\{B, C};\nclass X {}\n"));
        $this->assertNull(PhpMembers::of("<?php\nclass X {}\nclass Y {}\n"));
        $this->assertNull(PhpMembers::of("<?php\nclass X {\n"));
        $this->assertNotNull(PhpMembers::of("<?php\nuse function strlen;\n\$x = Foo::class;\nclass X {}\n"));
    }

    public function test_el_test_se_completa_con_lo_que_falta_y_sus_auxiliares(): void
    {
        $theirs = "<?php\n\nnamespace T;\n\nuse A\\Gate;\nuse A\\Registry;\nuse A\\Unused;\n\nclass PostEndpointsTest extends TestCase\n{\n    public function test_post_cada_accion_requiere_sesion(): void\n    {\n        \$this->signInApplicationUser();\n        Gate::check();\n    }\n\n    public function test_post_index_pasa_por_su_managed_filter(): void\n    {\n        Registry::x();\n    }\n\n    public function test_post_cada_accion_pregunta_a_la_politica(): void\n    {\n    }\n\n    private function signInApplicationUser(): void\n    {\n    }\n}\n";
        $mine = "<?php\n\nnamespace T;\n\nuse A\\Gate;\n\nclass PostEndpointsTest extends TestCase\n{\n    public function test_post_cada_accion_pregunta_a_la_politica(): void\n    {\n        // lo mío\n    }\n\n    public function test_mio(): void\n    {\n    }\n}\n";

        $completion = EndpointTests::complete($mine, $theirs);

        $this->assertIsArray($completion);
        $this->assertSame(['test_post_cada_accion_requiere_sesion', 'test_post_index_pasa_por_su_managed_filter', 'signInApplicationUser'], $completion['added']);
        $this->assertSame(['use A\\Registry;'], $completion['imports']);
        $this->assertStringContainsString('// lo mío', $completion['content']);
        $this->assertNotNull(PhpMembers::of($completion['content']));
        $this->assertSame([], EndpointTests::complete($completion['content'], $theirs)['added'] ?? null);
    }

    public function test_el_test_no_se_completa_si_un_nombre_choca(): void
    {
        $theirs = "<?php\nnamespace T;\nuse A\\Registry;\nclass PostEndpointsTest\n{\n    public function test_post_cada_accion_requiere_sesion(): void\n    {\n        Registry::x();\n    }\n}\n";
        $mine = "<?php\nnamespace T;\nuse Otra\\Registry;\nclass PostEndpointsTest\n{\n}\n";

        $this->assertStringContainsString('ya importa ese nombre para Otra\\Registry', (string) EndpointTests::complete($mine, $theirs));
        $this->assertStringContainsString('no es la que generaría', (string) EndpointTests::complete(str_replace('namespace T;', 'namespace U;', $mine), $theirs));
    }

    public function test_las_referencias_al_trait_se_reescriben_por_tokens(): void
    {
        $old = 'App\\Http\\Controllers\\Concerns\\Scoped';
        $new = 'App\\Http\\Requests\\Concerns\\Scoped';
        $ns = 'App\\Http\\Controllers\\Concerns';

        $request = "<?php\nnamespace App\\Http\\Requests;\nuse {$old};\nclass R { use Scoped; public function x() { return \\{$old}::class; } }\n// {$old}\n";
        $this->assertSame(
            "<?php\nnamespace App\\Http\\Requests;\nuse {$new};\nclass R { use Scoped; public function x() { return \\{$new}::class; } }\n// {$old}\n",
            ConcernMove::rewrite($request, $old, $new, $ns, 'Scoped')
        );

        // Relativo al namespace del archivo: se escribe completo.
        $relative = "<?php\nnamespace App\\Http\\Controllers;\nclass C { public function x() { return Concerns\\Scoped::class; } }\n";
        $this->assertStringContainsString("return \\{$new}::class", (string) ConcernMove::rewrite($relative, $old, $new, $ns, 'Scoped'));

        $this->assertSame(['lo importa en un `use` agrupado: reescríbelo a mano.'], ConcernMove::rewrite("<?php\nuse {$ns}\\{Scoped, Otro};\n", $old, $new, $ns, 'Scoped'));
        $this->assertSame(['lo nombra en una cadena (¿configuración?): no se cambia a ciegas.'], ConcernMove::rewrite("<?php\nreturn ['".str_replace('\\', '\\\\', $old)."'];\n", $old, $new, $ns, 'Scoped'));
        $this->assertSame(['lo usa por su nombre corto desde su mismo namespace: muévelos juntos a mano.'], ConcernMove::rewrite("<?php\nnamespace {$ns};\ntrait Otro { use Scoped; }\n", $old, $new, $ns, 'Scoped'));
        // Otro trait con el mismo nombre corto en otro sitio no es éste.
        $other = "<?php\nnamespace App;\nuse Vendor\\Scoped;\nclass Y { use Scoped; }\n";
        $this->assertSame($other, ConcernMove::rewrite($other, $old, $new, $ns, 'Scoped'));
    }

    public function test_un_json_se_edita_en_su_sitio_sin_reformatear(): void
    {
        $json = "{\n  \"models\": [\n    {\n      \"name\": \"Post\",\n      \"props\": [\n        { \"name\": \"a\", \"type\": \"string\" },\n        {\n          \"name\": \"b\",\n          \"enum\": [\n            \"x\"\n          ]\n        },\n        {}\n      ]\n    }\n  ]\n}\n";
        $edit = JsonEdit::of($json);

        $this->assertNotNull($edit);
        $edited = $edit->add([
            'models.0.props.0' => ['on_delete' => 'cascade'],
            'models.0.props.1' => ['on_delete' => 'set null', 'on_update' => 'cascade'],
            'models.0.props.2' => ['k' => 'v'],
        ]);

        $this->assertSame("{\n  \"models\": [\n    {\n      \"name\": \"Post\",\n      \"props\": [\n        { \"name\": \"a\", \"type\": \"string\", \"on_delete\": \"cascade\" },\n        {\n          \"name\": \"b\",\n          \"enum\": [\n            \"x\"\n          ],\n          \"on_delete\": \"set null\",\n          \"on_update\": \"cascade\"\n        },\n        {\"k\": \"v\"}\n      ]\n    }\n  ]\n}\n", $edited);
        $this->assertIsArray(json_decode((string) $edited, true));
        $this->assertNull($edit->add(['models.0.props.9' => ['a' => 'b']]));
        $this->assertNull(JsonEdit::of('{"a": }'));
        $this->assertNotNull(JsonEdit::of('{"a\\"b": "c\\\\", "n": -1.5e3, "t": [true, null]}'));
    }
}
