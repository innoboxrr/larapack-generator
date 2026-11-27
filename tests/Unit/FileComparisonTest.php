<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\FileComparison;
use Innoboxrr\LarapackGenerator\Support\Realign\Holes;
use PHPUnit\Framework\TestCase;

/**
 * Lo que el diagnóstico da por «igual salvo formato» y lo que no. El riesgo
 * de equivocarse es asimétrico: dar por formato una edición la adopta, y un
 * `--force` posterior la borra; dar por edición un cambio de formato sólo deja
 * un archivo sin adoptar. Por eso los comentarios cuentan.
 */
final class FileComparisonTest extends TestCase
{
    private const CODE = <<<'PHP'
        <?php

        namespace Acme\Blog\Http\Requests\Post;

        use Illuminate\Foundation\Http\FormRequest;
        use Acme\Blog\Models\Post;

        class ShowRequest extends FormRequest
        {
            public function authorize(): bool
            {
                return $this->user()->can('view', Post::class);
            }

            public function rules(): array
            {
                return [
                    'id' => 'required',
                ];
            }
        }
        PHP;

    public function test_los_mismos_bytes_son_identicos_aunque_cambie_el_final_de_linea(): void
    {
        $this->assertSame(FileComparison::IDENTICAL, FileComparison::compare(self::CODE, self::CODE, 'php'));
        $this->assertSame(FileComparison::IDENTICAL, FileComparison::compare(str_replace("\n", "\r\n", self::CODE), self::CODE, 'php'));
    }

    public function test_espacios_coma_final_y_orden_de_imports_son_formato(): void
    {
        $formatted = str_replace(
            ["use Illuminate\\Foundation\\Http\\FormRequest;\nuse Acme\\Blog\\Models\\Post;", "'id' => 'required',\n", '    '],
            ["use Acme\\Blog\\Models\\Post;\nuse Illuminate\\Foundation\\Http\\FormRequest;", "'id' => 'required'\n", "\t"],
            self::CODE
        );

        $this->assertNotSame(self::CODE, $formatted);
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare($formatted, self::CODE, 'php'));
    }

    public function test_un_comentario_anadido_se_distingue_del_formato(): void
    {
        $commented = str_replace('return [', "// a mano\n        return [", self::CODE);

        // El mismo código: «formato» según el encargo, pero dicho aparte,
        // porque adoptarlo deja que --force reemplace el comentario.
        $this->assertSame(FileComparison::COMMENTS, FileComparison::compare($commented, self::CODE, 'php'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare(str_replace("'required'", "'nullable'", $commented), self::CODE, 'php'));
        // Pero el mismo comentario con otros espacios es formato.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare(
            str_replace('// a mano', '//   a   mano', $commented),
            $commented,
            'php'
        ));
    }

    public function test_un_use_de_trait_dentro_de_la_clase_no_se_reordena(): void
    {
        $a = "<?php\nclass X\n{\n    use B;\n    use A;\n}\n";
        $b = "<?php\nclass X\n{\n    use A;\n    use B;\n}\n";

        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare($a, $b, 'php'));
    }

    public function test_json_por_valor_y_texto_sin_espacios(): void
    {
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare('{"b": 1, "a": [1, 2]}', "{\n  \"a\": [1,2],\n  \"b\": 1\n}", 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": [2, 1]}', '{"a": [1, 2]}', 'json'));
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("export default {\n  a: 1\n}", 'export default { a: 1 }', 'text'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('a: 1', 'a: 2', 'text'));
        $this->assertSame('json', FileComparison::kindOf('package.json'));
        $this->assertSame('text', FileComparison::kindOf('index.jsx'));
    }

    public function test_las_piezas_distintas_se_nombran_por_metodo(): void
    {
        $authorize = str_replace("can('view', Post::class)", "can('update', Post::class)", self::CODE);
        $rules = str_replace("'required'", "'required|integer'", self::CODE);
        $added = str_replace('    public function rules', "    private \$x = 1;\n\n    public function helper(): void {}\n\n    public function rules", self::CODE);

        $this->assertSame(['method:authorize'], FileComparison::members($authorize, self::CODE));
        $this->assertSame(['method:rules'], FileComparison::members($rules, self::CODE));
        $this->assertSame(['method:helper', 'outside'], FileComparison::members($added, self::CODE));
        $this->assertSame([], FileComparison::members(self::CODE, self::CODE));

        // Un `use` nuevo en la cabecera es `imports`, no `outside`; reordenar
        // los que había no es nada.
        $imported = str_replace('use Acme\\Blog\\Models\\Post;', "use Acme\\Blog\\Models\\Post;\nuse Acme\\Blog\\Support\\Scope;", $rules);
        $this->assertSame(['imports', 'method:rules'], FileComparison::members($imported, self::CODE));
        $reordered = str_replace("use Illuminate\\Foundation\\Http\\FormRequest;\nuse Acme\\Blog\\Models\\Post;", "use Acme\\Blog\\Models\\Post;\nuse Illuminate\\Foundation\\Http\\FormRequest;", self::CODE);
        $this->assertSame([], FileComparison::members($reordered, self::CODE));
    }

    public function test_un_closure_o_una_clase_anonima_no_parten_mal_los_metodos(): void
    {
        $code = "<?php\nclass X\n{\n    public function a()\n    {\n        return new class {\n            public function b() {}\n        };\n    }\n\n    public function c() { return fn () => X::class; }\n}\n";
        $changed = str_replace('public function b() {}', 'public function b() { return 1; }', $code);

        $this->assertSame(['method:a'], FileComparison::members($changed, $code));
    }

    public function test_los_huecos_de_cada_stub(): void
    {
        $this->assertTrue(Holes::onlyHoles('Requests/CreateRequest.txt', ['method:rules']));
        $this->assertFalse(Holes::onlyHoles('Requests/CreateRequest.txt', ['method:rules', 'method:authorize']));
        $this->assertFalse(Holes::onlyHoles('Requests/CreateRequest.txt', ['outside']));
        $this->assertTrue(Holes::onlyHoles('Filters/ManagedFilterTemplate.txt', ['method:canview']));
        $this->assertTrue(Holes::onlyHoles('Filters/ManagedFilterTemplate.txt', ['imports', 'method:canview']));
        $this->assertFalse(Holes::onlyHoles('Controller/ControllerTemplate.txt', ['imports']));
        $this->assertTrue(Holes::onlyHoles('Policy/PolicyTemplate.txt', ['outside', 'method:view']));
        $this->assertTrue(Holes::onlyHoles('ModelTraits/Operations/OperationsTemplate.txt', ['outside']));
        $this->assertFalse(Holes::onlyHoles('Controller/ControllerTemplate.txt', ['method:index']));
        // Sin diferencias no hay nada que esté «sólo en los huecos».
        $this->assertFalse(Holes::onlyHoles('Requests/CreateRequest.txt', []));
    }
}
