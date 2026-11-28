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

    public function test_en_un_texto_el_espacio_dentro_de_una_linea_no_es_formato(): void
    {
        // Dentro de una cadena, en el texto de JSX o entre operadores, un
        // espacio cambia lo que hace el código.
        $pairs = [
            ["notifySuccess(t('Changes saved'))\n", "notifySuccess(t('Changessaved'))\n"],
            ["const line = parts.join(' ')\n", "const line = parts.join('')\n"],
            ["const total = a - -b\n", "const total = a--b\n"],
            ["<p>Hola mundo</p>\n", "<p>Holamundo</p>\n"],
            ["<p>Hola mundo</p>\n", "<p>Hola\nmundo</p>\n"],
        ];

        foreach ($pairs as [$current, $regenerated]) {
            $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare($current, $regenerated, 'text'), $current);
        }
    }

    public function test_en_un_texto_finales_de_linea_indentacion_y_espacio_final_son_formato(): void
    {
        $code = "export default function Show() {\n    return <p>{t('Changes saved')}</p>\n}\n";
        $formatted = "export default function Show() {  \r\n\treturn <p>{t('Changes saved')}</p>\r\n}\r\n\r\n";

        $this->assertSame(FileComparison::FORMAT, FileComparison::compare($formatted, $code, 'text'));
    }

    public function test_un_json_que_no_se_puede_leer_se_compara_como_texto(): void
    {
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("{\"a\": \"x y\",}\n", "{\"a\": \"xy\",}\n", 'json'));
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("{\n\t\"a\": \"x y\",\n}\n", "{\n  \"a\": \"x y\",\n}", 'json'));
    }

    public function test_un_use_de_trait_dentro_de_la_clase_no_se_reordena(): void
    {
        $a = "<?php\nclass X\n{\n    use B;\n    use A;\n}\n";
        $b = "<?php\nclass X\n{\n    use A;\n    use B;\n}\n";

        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare($a, $b, 'php'));
    }

    public function test_json_por_valor_y_texto_por_lineas(): void
    {
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare('{"b": 1, "a": [1, 2]}', "{\n  \"a\": [1,2],\n  \"b\": 1\n}", 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": [2, 1]}', '{"a": [1, 2]}', 'json'));
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("export default {\n\ta: 1\n}\n", "export default {\n  a: 1\n}", 'text'));
        // Juntar o partir líneas no se da por formato: sin analizar el
        // lenguaje no se sabe si el salto estaba dentro de una cadena.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("export default {\n  a: 1\n}", 'export default { a: 1 }', 'text'));
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

    public function test_los_comentarios_cuentan_y_la_coma_final_no(): void
    {
        $code = "<?php\n\nreturn [\n    'a' => 1, // el primero\n    'b' => [2, 3],\n];\n";

        // Sin la coma final de una lista, de una llamada o de unos parámetros: formato.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("<?php\n\nreturn [\n    'a' => 1, // el primero\n    'b' => [2, 3,]\n];\n", $code, 'php'));
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("<?php\nfoo(\n    \$a,\n    \$b,\n);\n", "<?php\nfoo(\$a, \$b);\n", 'php'));
        // Una coma que no es la final separa dos cosas: quitarla no es formato.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("<?php\nreturn [1, 2];\n", "<?php\nreturn [1 2];\n", 'php'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("<?php\nreturn [1, [2]];\n", "<?php\nreturn [1, 2];\n", 'php'));

        // Otro comentario, uno menos o un docblock distinto: el mismo código, dicho aparte.
        $this->assertSame(FileComparison::COMMENTS, FileComparison::compare(str_replace('// el primero', '// el único', $code), $code, 'php'));
        $this->assertSame(FileComparison::COMMENTS, FileComparison::compare(str_replace(' // el primero', '', $code), $code, 'php'));
        $this->assertSame(FileComparison::COMMENTS, FileComparison::compare("<?php\n/** Uno. */\nfunction f() {}\n", "<?php\n/** Otro. */\nfunction f() {}\n", 'php'));
        $this->assertSame(FileComparison::COMMENTS, FileComparison::compare("<?php\n# nota\nfunction f() {}\n", "<?php\nfunction f() {}\n", 'php'));
        // El mismo docblock con otra sangría es formato.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("<?php\n    /**\n     * Uno.\n     */\nfunction f() {}\n", "<?php\n/**\n * Uno.\n */\nfunction f() {}\n", 'php'));
        // «Sólo comentarios» es de PHP: en un texto, otra línea es otra cosa.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("a: 1 // x\n", "a: 1\n", 'text'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": 1, "//": "x"}', '{"a": 1}', 'json'));
        // Y el texto de una cadena no es un comentario ni un espacio.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("<?php\nreturn 'a  b';\n", "<?php\nreturn 'a b';\n", 'php'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("<?php\nreturn '// x';\n", "<?php\nreturn '';\n", 'php'));
    }

    public function test_la_cabecera_de_un_php_se_compara_sin_el_orden_de_sus_imports(): void
    {
        $a = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nuse B\\Two;\nuse A\\One;\nuse function C\\three;\n\nfinal class X {}\n";
        $b = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nuse A\\One;\nuse function C\\three;\nuse B\\Two;\n\nfinal class X {}\n";

        $this->assertSame(FileComparison::FORMAT, FileComparison::compare($a, $b, 'php'));
        // Un import de más no es formato, ni otro namespace.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare($a, str_replace("use B\\Two;\n", '', $b), 'php'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare($a, str_replace('namespace Acme;', 'namespace Otro;', $b), 'php'));
        // Un import agrupado termina en su `;`, no en la llave.
        $grouped = "<?php\nuse A\\{One, Two};\nuse B\\Three;\nclass X {}\n";
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare($grouped, "<?php\nuse B\\Three;\nuse A\\{One, Two};\nclass X {}\n", 'php'));
        // Lo que va después de la cabecera no se reordena: ni dos sentencias ni dos imports tardíos.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("<?php\nuse A;\na();\nb();\n", "<?php\nuse A;\nb();\na();\n", 'php'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare("<?php\na();\nuse B;\nuse A;\n", "<?php\na();\nuse A;\nuse B;\n", 'php'));
        // `USE` en mayúsculas es el mismo import.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("<?php\nUSE B;\nuse A;\nclass X {}\n", "<?php\nuse A;\nUSE B;\nclass X {}\n", 'php'));
    }

    public function test_un_objeto_json_no_es_una_lista_aunque_este_vacio(): void
    {
        // Decodificados a arrays de PHP, los dos lados de cada par eran
        // iguales: el cambio se daba por formato y se adoptaba.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": {}}', '{"a": []}', 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"0": "x", "1": "y"}', '["x", "y"]', 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": 1.0}', '{"a": 1}', 'json'));
        // Lo que sí es formato sigue siéndolo.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare('{"a": {}, "b": []}', "{\n  \"b\": [],\n  \"a\": {}\n}\n", 'json'));
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare('{"a": "ñ/x"}', '{"a":"\\u00f1\\/x"}', 'json'));
    }

    public function test_un_json_se_compara_por_su_valor_hasta_el_fondo(): void
    {
        // El orden de las claves no cambia el valor, tampoco dentro de un objeto anidado o de una lista.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare('{"a": {"y": 1, "x": [{"q": 1, "p": 2}]}}', '{"a": {"x": [{"p": 2, "q": 1}], "y": 1}}', 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": {"y": 1, "x": 2}}', '{"a": {"y": 2, "x": 1}}', 'json'));
        // Un número no es su texto, ni una lista la misma con otro orden.
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": 1}', '{"a": "1"}', 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('{"a": [{"p": 1}, {"p": 2}]}', '{"a": [{"p": 2}, {"p": 1}]}', 'json'));
        // Un escalar es un valor: `null` con espacios alrededor es `null`.
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare("\nnull\n", 'null', 'json'));
        $this->assertSame(FileComparison::FORMAT, FileComparison::compare(' 7 ', '7', 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('null', '0', 'json'));
        $this->assertSame(FileComparison::DIFFERENT, FileComparison::compare('null', 'nul', 'json'));
    }

    public function test_el_tipo_de_comparacion_lo_da_la_extension(): void
    {
        $this->assertSame('php', FileComparison::kindOf('src/Models/Post.php'));
        $this->assertSame('php', FileComparison::kindOf('LEGACY.PHP'));
        $this->assertSame('json', FileComparison::kindOf('composer.JSON'));
        $this->assertSame('text', FileComparison::kindOf('resources/views/post.blade.php.bak'));
        $this->assertSame('text', FileComparison::kindOf('Makefile'));
        $this->assertSame('text', FileComparison::kindOf('php'));

        $directory = sys_get_temp_dir().'/larapack-compare-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents($directory.'/a.php', "<?php\nreturn [1,];\n");
            file_put_contents($directory.'/b.php', "<?php\r\nreturn [1];\r\n");
            file_put_contents($directory.'/a.json', '{"a": 1, "b": 2}');
            file_put_contents($directory.'/b.json', '{"b": 2, "a": 1}');
            file_put_contents($directory.'/a.txt', '{"a": 1, "b": 2}');
            file_put_contents($directory.'/b.txt', '{"b": 2, "a": 1}');

            $this->assertSame(FileComparison::FORMAT, FileComparison::compareFiles($directory.'/a.php', $directory.'/b.php'));
            $this->assertSame(FileComparison::IDENTICAL, FileComparison::compareFiles($directory.'/a.php', $directory.'/a.php'));
            $this->assertSame(FileComparison::FORMAT, FileComparison::compareFiles($directory.'/a.json', $directory.'/b.json'));
            // El tipo es el del archivo del proyecto: como texto, otro orden es otra línea.
            $this->assertSame(FileComparison::DIFFERENT, FileComparison::compareFiles($directory.'/a.txt', $directory.'/b.json'));
            $this->assertSame(FileComparison::FORMAT, FileComparison::compareFiles($directory.'/a.json', $directory.'/b.txt'));
        } finally {
            exec('rm -rf '.escapeshellarg($directory));
        }
    }

    public function test_las_piezas_de_una_clase_con_metodos_abstractos_por_referencia_y_con_llaves_en_cadenas(): void
    {
        $code = <<<'PHP'
            <?php

            abstract class X
            {
                public const A = [1, 2,];

                abstract protected function contract(array $rules = [],);

                public function &reference(): array
                {
                    return $this->items;
                }

                public function Interpolated(string $name): string
                {
                    return "{$name} y ${name}: }";
                }

                public function last(): int
                {
                    return 1;
                }
            }

            function outside()
            {
                return 0;
            }
            PHP;

        $pieces = FileComparison::pieces($code);

        // Un método por nombre, en minúsculas; el abstracto acaba en su `;`.
        $this->assertSame(['imports', 'outside', 'method:contract', 'method:reference', 'method:interpolated', 'method:last'], array_keys($pieces));
        $this->assertSame('function contract ( array $rules = [ ] ) ;', $pieces['method:contract']);
        $this->assertSame('function & reference ( ) : array { return $this -> items ; }', $pieces['method:reference']);
        $this->assertSame('function last ( ) : int { return 1 ; }', $pieces['method:last']);
        $this->assertSame('', $pieces['imports']);
        // Lo de fuera de los métodos, con sus modificadores y sin la coma final.
        $this->assertSame('abstract class X { public const A = [ 1 , 2 ] ; abstract protected public public public } function outside ( ) { return 0 ; }', $pieces['outside']);

        // Una llave dentro de una cadena no cierra el método: lo que cambia
        // después sigue siendo del suyo.
        $this->assertSame(['method:last'], FileComparison::members(str_replace('return 1;', 'return 2;', $code), $code));
        $this->assertSame(['method:interpolated'], FileComparison::members(str_replace(' y ', ' o ', $code), $code));
        $this->assertSame(['method:contract'], FileComparison::members(str_replace('array $rules', 'iterable $rules', $code), $code));
        // Una función de fuera de la clase no es un método: es `outside`.
        $this->assertSame(['outside'], FileComparison::members(str_replace('return 0;', 'return 9;', $code), $code));
        // Quitar un método también lo nombra (y su modificador, fuera).
        $this->assertSame(['method:last', 'outside'], FileComparison::members((string) preg_replace('/\s+public function last.*?\n    }\n/s', "\n", $code), $code));
        // Los comentarios y el formato no son una diferencia de ninguna pieza.
        $this->assertSame([], FileComparison::members(str_replace('return 1;', "// nota\n        return   1;", $code), $code));
    }

    public function test_las_piezas_de_un_trait_un_enum_y_una_interfaz(): void
    {
        foreach (['trait T', 'enum E', 'interface I', 'final class C'] as $declaration) {
            $code = "<?php\n{$declaration}\n{\n    public function a() { return X::class; }\n\n    public function b() { return new class {}; }\n}\n";

            $this->assertSame(['imports', 'outside', 'method:a', 'method:b'], array_keys(FileComparison::pieces($code)), $declaration);
            $this->assertSame(['method:b'], FileComparison::members(str_replace('new class {}', 'new class { public $x; }', $code), $code), $declaration);
        }

        // Sin clase no hay métodos: todo es `outside`.
        $this->assertSame(['imports', 'outside'], array_keys(FileComparison::pieces("<?php\nuse A;\nfunction f() {}\n")));
        $this->assertSame('use A ;', FileComparison::pieces("<?php\nuse A;\nfunction f() {}\n")['imports']);
        $this->assertSame(['imports', 'outside'], array_keys(FileComparison::pieces('')));
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
