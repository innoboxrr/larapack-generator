<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\ControllerShape;
use Innoboxrr\LarapackGenerator\Support\SpaFallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La frontera de lo que `larapack:fix route-closure` reconoce como el
 * fallback de laravel-setup <= 7.2.0, una forma a una.
 *
 * Pueden variar el espacio en blanco, las comillas y el orden de los dos
 * operandos del `||`. Todo lo demás —un comentario dentro, un parámetro, otra
 * vista, otro código de estado, otra condición— es lógica del proyecto, y
 * reescribirla cambiaría lo que hace la aplicación.
 */
final class SpaFallbackTest extends TestCase
{
    private const FALLBACK = <<<'PHP'
Route::fallback(function () {
    abort_if(request()->is('api/*') || request()->expectsJson(), 404);

    return view('app');
});
PHP;

    private static function file(string $statement, string $imports = "use Illuminate\\Support\\Facades\\Route;\n"): string
    {
        return "<?php\n\n{$imports}\n{$statement}\n";
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function accepted(): array
    {
        return [
            'la de laravel-setup' => [self::FALLBACK],
            'comillas dobles' => [str_replace(["'api/*'", "'app'"], ['"api/*"', '"app"'], self::FALLBACK)],
            'los operandos al revés' => [str_replace("request()->is('api/*') || request()->expectsJson()", "request()->expectsJson() || request()->is('api/*')", self::FALLBACK)],
            'en una línea' => ["Route::fallback(function(){abort_if(request()->is('api/*')||request()->expectsJson(),404);return view('app');});"],
            'repartido en líneas' => ["Route::fallback(\n    function () {\n        abort_if(\n            request()->is('api/*')\n            || request()->expectsJson(),\n            404\n        );\n\n        return view('app');\n    }\n);"],
            'con tabuladores' => [str_replace('    ', "\t", self::FALLBACK)],
            'tras cadenas con llaves' => ['$a = "{$b}"; $c = "${d}";'."\n\n".self::FALLBACK],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejected(): array
    {
        $swap = static fn (string $from, string $to): string => str_replace($from, $to, self::FALLBACK);

        return [
            'un comentario dentro' => [$swap("\n    return", "\n    // la SPA\n    return")],
            'un parámetro' => [$swap('function ()', 'function (Request $request)')],
            'datos a la vista' => [$swap("view('app')", "view('app', ['x' => 1])")],
            'otra vista' => [$swap("view('app')", "view('spa')")],
            'otro código' => [$swap('404);', '403);')],
            'otra ruta de la API' => [$swap("'api/*'", "'v1/*'")],
            '&& en vez de ||' => [$swap('||', '&&')],
            'una condición más' => [$swap('request()->expectsJson()', 'request()->expectsJson() || request()->ajax()')],
            'una sola condición' => [$swap("request()->is('api/*') || ", '')],
            'abort en vez de abort_if' => [$swap('abort_if(', 'abort(')],
            'una sentencia más' => [$swap("\n    return", "\n    logger('spa');\n\n    return")],
            'static' => [$swap('function ()', 'static function ()')],
            'con tipo de retorno' => [$swap('function ()', 'function (): mixed')],
            'encadena name()' => [$swap('});', "})->name('spa');")],
            'con la fachada calificada' => [$swap('Route::', '\Illuminate\Support\Facades\Route::')],
            'una flecha' => ["Route::fallback(fn () => view('app'));"],
            'un escape en la cadena' => [$swap("'api/*'", "'api\\/*'")],
            'dentro de un grupo' => ["Route::middleware('web')->group(function () {\n".self::FALLBACK."\n});"],
            'dentro de un grupo, tras otra ruta' => ["Route::middleware('web')->group(function () {\n    Route::view('/x', 'x');\n".self::FALLBACK."\n});"],
            'dos veces' => [self::FALLBACK."\n\n".self::FALLBACK],
            'pasado a otra cosa' => [$swap('Route::fallback(', 'Route::any(')],
            'en la misma línea que otra sentencia' => ['$x = 1 + '.self::FALLBACK],
        ];
    }

    #[DataProvider('accepted')]
    public function test_reconoce_las_variantes_triviales(string $statement): void
    {
        $code = self::file($statement);

        $this->assertNotNull(SpaFallback::fallback($code));
        $fixed = SpaFallback::rewrite($code);
        $this->assertStringContainsString("\nRoute::fallback(SpaController::class);\n", $fixed);
        $this->assertNull(SpaFallback::fallback($fixed));
        $this->assertSame([], ControllerShape::flaggedClosures($fixed));
    }

    #[DataProvider('rejected')]
    public function test_no_reconoce_lo_que_hace_otra_cosa(string $statement): void
    {
        $code = self::file($statement);

        $this->assertNull(SpaFallback::fallback($code));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No está el fallback de laravel-setup 7.2.0 tal cual.');
        SpaFallback::rewrite($code);
    }

    /**
     * La línea es la del `function`, la que da `verify`, y las posiciones son
     * exactamente las de la sentencia.
     */
    public function test_sin_php_en_la_linea_del_use_no_sabe_donde_importar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Un `use` que comparte su línea con más código.');

        SpaFallback::rewrite("<?php use Illuminate\\Support\\Facades\\Route;\n\n".self::FALLBACK."\n");
    }

    /**
     * El `use` de un trait dentro de una clase del archivo no es un import:
     * el de SpaController no se pone a su lado.
     */
    public function test_sin_imports_el_use_va_detras_del_declare(): void
    {
        // Un `use` delante de `declare(strict_types=1)` parsea, pero PHP lo
        // rechaza al compilar: la aplicación entera dejaría de cargar.
        $fixed = SpaFallback::rewrite("<?php\n\n// rutas\ndeclare(strict_types=1);\n\n".self::FALLBACK."\n");

        $this->assertStringStartsWith("<?php\n\n// rutas\ndeclare(strict_types=1);\n\nuse App\\Http\\Controllers\\SpaController;\n", $fixed);
        $this->assertStringContainsString('Route::fallback(SpaController::class);', $fixed);

        $file = tempnam(sys_get_temp_dir(), 'spa');
        file_put_contents($file, $fixed);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);
        unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function test_un_declare_que_comparte_linea_no_se_toca(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declare');

        SpaFallback::rewrite("<?php\n\ndeclare(strict_types=1); \$x = 1;\n\n".self::FALLBACK."\n");
    }

    public function test_el_use_de_un_trait_no_es_un_import(): void
    {
        // Sin importar Route (vale el alias global) y con un import que va
        // antes: si `use Zeta;` contara, el de SpaController iría dentro de
        // la clase.
        $code = "<?php\n\nuse App\\Http\\Controllers\\HomeController;\n\nnew class {\n    public \$x;\n    use Zeta;\n};\n\n".self::FALLBACK."\n";
        $fixed = SpaFallback::rewrite($code);

        $this->assertStringStartsWith("<?php\n\nuse App\\Http\\Controllers\\HomeController;\nuse App\\Http\\Controllers\\SpaController;\n\nnew class {\n    public \$x;\n    use Zeta;\n};", $fixed);
    }

    /**
     * Con la raíz detrás del fallback, los cambios se aplican igual: del
     * final al principio, sin moverse unos a otros.
     */
    public function test_el_orden_de_las_rutas_no_importa(): void
    {
        $code = self::file(self::FALLBACK."\n\nRoute::get('/', fn () => view('app'))->name('app');");

        $this->assertSame(
            "<?php\n\nuse App\\Http\\Controllers\\SpaController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::fallback(SpaController::class);\n\nRoute::view('/', 'app')->name('app');\n",
            SpaFallback::rewrite($code)
        );
    }

    /**
     * La línea es la del `function` aunque el paréntesis vaya en la
     * siguiente.
     */
    public function test_la_linea_es_la_del_function(): void
    {
        $code = self::file("Route::fallback(function\n() {\n    abort_if(request()->is('api/*') || request()->expectsJson(), 404);\n\n    return view('app');\n});");

        $this->assertSame(5, SpaFallback::fallback($code)['line'] ?? null);
        $this->assertSame(5, ControllerShape::flaggedClosures($code)[0]['line']);
    }

    public function test_da_la_linea_de_verify_y_la_sentencia_exacta(): void
    {
        $code = self::file("// antes\nRoute::fallback(\n    function () {\n        abort_if(request()->is('api/*') || request()->expectsJson(), 404);\n\n        return view('app');\n    }\n); // después");
        $statement = SpaFallback::fallback($code);

        $this->assertNotNull($statement);
        $this->assertSame(7, $statement['line']);
        $this->assertSame(ControllerShape::flaggedClosures($code)[0]['line'], $statement['line']);
        $this->assertStringStartsWith('Route::fallback(', substr($code, $statement['start']));
        $this->assertSame('); // después', substr($code, $statement['end'] - 2, strlen('); // después')));
        $this->assertStringEndsWith("// antes\nRoute::fallback(SpaController::class); // después\n", SpaFallback::rewrite($code));
    }

    /**
     * La raíz de laravel-setup pasa a Route::view(); cualquier otra raíz se
     * queda como está.
     */
    public function test_la_raiz_de_laravel_setup_pasa_a_route_view(): void
    {
        foreach ([
            "Route::get('/', fn () => view('app'))->name('app');",
            "Route::get(\"/\", function () {\n    return view(\"app\");\n})->name(\"app\");",
        ] as $root) {
            $fixed = SpaFallback::rewrite(self::file($root."\n\n".self::FALLBACK));

            $this->assertStringContainsString("\nRoute::view('/', 'app')->name('app');\n\nRoute::fallback(SpaController::class);\n", $fixed, $root);
        }

        foreach ([
            "Route::get('/', fn () => view('welcome'))->name('app');",
            "Route::get('/', fn () => view('app'));",
            "Route::get('/', fn () => view('app'))->name('home');",
            "Route::get('/', [HomeController::class, 'show'])->name('app');",
        ] as $root) {
            $fixed = SpaFallback::rewrite(self::file($root."\n\n".self::FALLBACK));

            $this->assertStringContainsString("\n{$root}\n", $fixed, $root);
            $this->assertStringNotContainsString('Route::view(', $fixed, $root);
        }
    }

    /**
     * El `use` va en orden alfabético entre los de clases, como lo deja Pint,
     * y no se repite.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function imports(): array
    {
        $spa = "use App\\Http\\Controllers\\SpaController;\n";

        return [
            'sin ninguno' => ['', "<?php\n\n{$spa}\n"],
            'delante de Illuminate' => ["use Illuminate\\Support\\Facades\\Route;\n", "\n{$spa}use Illuminate\\Support\\Facades\\Route;\n\nRoute::fallback"],
            'entre dos' => ["use App\\Http\\Controllers\\HomeController;\nuse Illuminate\\Support\\Facades\\Route;\n", "use App\\Http\\Controllers\\HomeController;\n{$spa}use Illuminate\\Support\\Facades\\Route;\n"],
            'detrás de todos' => ["use App\\Http\\Controllers\\HomeController;\n", "use App\\Http\\Controllers\\HomeController;\n{$spa}\nRoute::fallback"],
            'ya importado' => ["{$spa}use Illuminate\\Support\\Facades\\Route;\n", "<?php\n\n{$spa}use Illuminate\\Support\\Facades\\Route;\n\nRoute::fallback"],
            'importado con otras mayúsculas' => ["use app\\http\\controllers\\spacontroller as SpaController;\n", "<?php\n\nuse app\\http\\controllers\\spacontroller as SpaController;\n\nRoute::fallback"],
            'sin clases, delante de un use function' => ["use function Illuminate\\Support\\now;\n", "<?php\n\n{$spa}\nuse function Illuminate\\Support\\now;\n"],
            'un use function delante de las clases' => ["use function Illuminate\\Support\\now;\nuse Illuminate\\Support\\Facades\\Route;\n", "use function Illuminate\\Support\\now;\n{$spa}use Illuminate\\Support\\Facades\\Route;\n"],
            'con espacios al final de la línea' => ["use App\\Http\\Controllers\\HomeController; \t\n", "use App\\Http\\Controllers\\HomeController; \t\n{$spa}\nRoute::fallback"],
            'con la barra delante' => ["use \\Illuminate\\Support\\Facades\\Route;\n", "\n{$spa}use \\Illuminate\\Support\\Facades\\Route;\n"],
            'sangrados' => ["    use Illuminate\\Support\\Facades\\Route;\n", "\n{$spa}    use Illuminate\\Support\\Facades\\Route;\n"],
        ];
    }

    #[DataProvider('imports')]
    public function test_importa_spa_controller_en_su_sitio(string $imports, string $expected): void
    {
        $fixed = SpaFallback::rewrite(self::file(self::FALLBACK, $imports));

        $this->assertStringContainsString($expected, $fixed);
        $this->assertSame(1, substr_count(strtolower($fixed), 'use app\\http\\controllers\\spacontroller'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafe(): array
    {
        return [
            'otro SpaController importado' => ["use App\\Spa\\SpaController;\n", 'Ya importa otra clase como SpaController (App\Spa\SpaController).'],
            'un espacio de nombres' => ["namespace App\\Routes;\n\nuse Illuminate\\Support\\Facades\\Route;\n", 'routes/web.php declara un espacio de nombres: el `use` de SpaController no se sabe dónde va.'],
            'otro Route' => ["use App\\Routing\\Route;\n", '`Route` es App\Routing\Route en este archivo, no la fachada de Laravel.'],
            'un use con un comentario detrás' => ["use Illuminate\\Support\\Facades\\Route; // la fachada\n", 'Un `use` que comparte su línea con más código.'],
            'un use con más código en su línea' => ["use Illuminate\\Support\\Facades\\Route; \$x = 1;\n", 'Un `use` que comparte su línea con más código.'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_lo_que_no_admite_el_cambio_con_certeza_lo_dice(string $imports, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        SpaFallback::rewrite(self::file(self::FALLBACK, $imports));
    }

    public function test_sin_php_en_su_propia_linea_no_sabe_donde_importar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('routes/web.php no empieza con `<?php` en su propia línea.');

        SpaFallback::rewrite('<?php '.self::FALLBACK."\n");
    }

    /**
     * Igual es igual sin contar el espacio en blanco; un comentario o una
     * sentencia distintos ya no.
     */
    public function test_same_compara_sin_espacio_en_blanco(): void
    {
        $stub = SpaFallback::CONTROLLER_CODE;

        $this->assertTrue(SpaFallback::same($stub, $stub));
        $this->assertTrue(SpaFallback::same(str_replace("\n", "\r\n", $stub), $stub));
        $this->assertTrue(SpaFallback::same(str_replace('    ', "\t", $stub), $stub));
        $this->assertFalse(SpaFallback::same(str_replace('no lleva lógica', 'no lleva nada', $stub), $stub));
        $this->assertFalse(SpaFallback::same(str_replace('return $request->handle();', 'return $request->handle(1);', $stub), $stub));
        // Más o menos espacio entre tokens sigue siendo el mismo archivo.
        $this->assertTrue(SpaFallback::same(str_replace('return $request->handle();', 'return $request -> handle ( ) ;', $stub), $stub));
        $this->assertTrue(SpaFallback::same(str_replace("Controller\n{", 'Controller {', $stub), $stub));
        $this->assertFalse(SpaFallback::same(SpaFallback::REQUEST_CODE, $stub));
    }

    /**
     * Los archivos que se crean son PHP válido y tienen la forma que exige
     * verify: el controlador delega, la request tiene handle().
     */
    public function test_lo_que_se_crea_parsea(): void
    {
        foreach ([SpaFallback::CONTROLLER_CODE, SpaFallback::REQUEST_CODE] as $code) {
            $this->assertIsArray(token_get_all($code, TOKEN_PARSE));
        }

        $this->assertStringContainsString("    public function __invoke(SpaRequest \$request)\n    {\n        return \$request->handle();\n    }", SpaFallback::CONTROLLER_CODE);
        $this->assertStringContainsString("        abort_if(\$this->is('api/*') || \$this->expectsJson(), 404);\n\n        return view('app');", SpaFallback::REQUEST_CODE);
    }

    /**
     * El mensaje de verify ofrece el arreglo sólo cuando hay arreglo.
     */
    public function test_el_mensaje_de_verify(): void
    {
        // La lista empieza en 0 aunque antes haya una ruta que sólo pinta una
        // vista, que no se marca.
        $closure = ControllerShape::flaggedClosures(self::file("Route::get('/', fn () => view('app'));\n\n".self::FALLBACK))[0];

        $this->assertStringStartsWith('La ruta de la línea 7 lleva su acción en un closure (fallback()): ', ControllerShape::routeClosureMessage($closure, true));

        $this->assertStringContainsString('larapack:fix lo corrige', ControllerShape::routeClosureMessage($closure, true));
        $this->assertStringNotContainsString('Llévala a un controlador', ControllerShape::routeClosureMessage($closure, true));
        $this->assertStringNotContainsString('larapack:fix', ControllerShape::routeClosureMessage($closure, false));
        $this->assertStringContainsString('Llévala a un controlador', ControllerShape::routeClosureMessage($closure, false));
    }
}
