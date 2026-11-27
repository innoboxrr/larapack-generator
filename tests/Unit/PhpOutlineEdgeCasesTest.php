<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\PhpOutline;
use PHPUnit\Framework\TestCase;

/**
 * Los bordes de la lectura de PhpOutline: lo que PHP admite y un archivo de
 * verdad acaba teniendo —un método que devuelve por referencia, un atributo
 * en un parámetro, un espacio de nombres global con llaves, varias
 * importaciones en un `use`—.
 *
 * Cada caso es una forma de escribir lo mismo que el outline tiene que leer
 * igual; si lo lee distinto, ControllerShape confunde una acción, un tipo o
 * una excepción aprobada. Con los avisos convertidos en excepciones, como
 * bajo Artisan.
 */
final class PhpOutlineEdgeCasesTest extends TestCase
{
    private int $errorReporting;

    protected function setUp(): void
    {
        // Todos los avisos, como Laravel (error_reporting(-1)): la
        // configuración de PHP de cada máquina no decide qué se ve.
        $this->errorReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $level, string $message): bool {
            // Lo silenciado con @ es parte del código probado, no un aviso.
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $level);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        error_reporting($this->errorReporting);
    }

    /**
     * @return array<string, mixed>
     */
    private function only(string $code): array
    {
        $classes = PhpOutline::ofCode($code)->classes();
        $this->assertCount(1, $classes);

        return $classes[0];
    }

    /**
     * @return array<string, string|null>
     */
    private function types(array $method): array
    {
        return array_column($method['params'], 'type', 'name');
    }

    // CLASES

    public function test_un_metodo_que_devuelve_por_referencia_conserva_su_nombre(): void
    {
        $class = $this->only("<?php class A {\n    public function &items(): array { return \$this->items; }\n    public static function &shared() {}\n}");

        $this->assertSame(['items', 'shared'], array_column($class['methods'], 'name'));
        $this->assertSame([true, true], array_column($class['methods'], 'byRef'));
        $this->assertFalse($this->only('<?php class A { public function items() {} }')['methods'][0]['byRef']);
    }

    public function test_extends_solo_es_el_padre_de_una_clase(): void
    {
        $this->assertNull($this->only('<?php class A implements B, C {}')['extends']);
        $this->assertNull($this->only('<?php interface I extends J, K {}')['extends']);
        $this->assertSame('P', $this->only('<?php class A extends P implements B {}')['extends']);
    }

    public function test_las_palabras_clave_no_distinguen_mayusculas(): void
    {
        $class = $this->only("<?php Final Class A {\n    Public Static Function make(INT \$id, SELF \$other, Mixed \$any) {}\n    static private function hidden() {}\n}");

        $this->assertSame('class', $class['kind']);
        $this->assertSame('public', $class['methods'][0]['visibility']);
        $this->assertTrue($class['methods'][0]['static']);
        $this->assertSame(['$id' => 'int', '$other' => 'self', '$any' => 'mixed'], $this->types($class['methods'][0]));
        // Los modificadores, en cualquier orden.
        $this->assertSame('private', $class['methods'][1]['visibility']);
    }

    public function test_una_funcion_suelta_no_es_una_clase(): void
    {
        $classes = PhpOutline::ofCode("<?php\n\nnamespace App;\n\nfunction helper() {}\n\nclass Real {}\n")->classes();

        $this->assertSame(['App\Real'], array_column($classes, 'fqcn'));
    }

    public function test_el_espacio_de_nombres_global_con_llaves(): void
    {
        $classes = PhpOutline::ofCode("<?php\n\nnamespace App { class A { public function a() {} } }\n\nnamespace { class B { public function b(A \$a) {} } }\n")->classes();

        $this->assertSame(['App\A', 'B'], array_column($classes, 'fqcn'));
        $this->assertSame(['$a' => 'A'], $this->types($classes[1]['methods'][0]));
    }

    public function test_todos_los_atributos_de_la_clase_y_del_metodo(): void
    {
        $class = $this->only(<<<'PHP'
        <?php

        namespace App;

        #[First]
        #[Second, Third('x')]
        final class A
        {
            #[One] #[Two, Three]
            public function a() {}

            #[Gone];

            public function b() {}
        }
        PHP);

        $this->assertSame(['App\First', 'App\Second', 'App\Third'], array_column($class['attributes'], 'name'));
        $this->assertSame([5, 6, 6], array_column($class['attributes'], 'line'));
        $this->assertSame(['App\One', 'App\Two', 'App\Three'], array_column($class['methods'][0]['attributes'], 'name'));
        $this->assertSame([], $class['methods'][1]['attributes']);
    }

    // IMPORTACIONES

    public function test_varias_importaciones_en_un_use_y_la_barra_inicial(): void
    {
        $class = $this->only(<<<'PHP'
        <?php

        namespace App\Http;

        use Illuminate\Http\Request as BaseRequest;
        use \App\Models\Post, App\Models\Comment as Note;
        use App\Http\Requests\{IndexRequest, ShowRequest as Show};
        use function App\helper;
        use const App\LIMIT;

        class A
        {
            public function a(BaseRequest $base, Post $post, Note $note, IndexRequest $index, Show $show, Helper $helper, Limit $limit, Requests\StoreRequest $store) {}
        }
        PHP);

        $this->assertSame([
            '$base' => 'Illuminate\Http\Request',
            '$post' => 'App\Models\Post',
            '$note' => 'App\Models\Comment',
            '$index' => 'App\Http\Requests\IndexRequest',
            '$show' => 'App\Http\Requests\ShowRequest',
            // `use function` y `use const` no nombran clases.
            '$helper' => 'App\Http\Helper',
            '$limit' => 'App\Http\Limit',
            // El prefijo del grupo no es un alias.
            '$store' => 'App\Http\Requests\StoreRequest',
        ], $this->types($class['methods'][0]));
    }

    // MÉTODOS Y PARÁMETROS

    public function test_la_firma_y_los_parametros(): void
    {
        $method = $this->only(<<<'PHP'
        <?php

        namespace App;

        class A
        {
            public function __construct(
                #[CurrentUser] private readonly User $user,
                protected ?Team $team = null,
                int|string $key = 1,
                Post &...$posts,
            ): void {}

            abstract public function z(): ?string;
        }
        PHP)['methods'];

        $this->assertSame(['$user' => 'App\User', '$team' => 'App\Team', '$key' => null, '$posts' => 'App\Post'], $this->types($method[0]));
        $this->assertSame(['User', '?Team', 'int|string', 'Post'], array_column($method[0]['params'], 'raw'));
        $this->assertSame(['(', ')', ':', '?', 'string'], array_column($method[1]['signature'], 1));
        $this->assertNull($method[1]['body']);
    }

    public function test_cada_token_lleva_su_linea(): void
    {
        $method = $this->only("<?php class A {\n    public function a()\n    {\n        return 1\n            ;\n    }\n}")['methods'][0];

        $this->assertSame([[T_RETURN, 4], [T_LNUMBER, 4], [';', 5]], array_map(fn (array $token): array => [$token[0], $token[2]], $method['body']));
    }

    // ARGUMENTOS

    public function test_un_argumento_por_posicion_o_por_nombre(): void
    {
        $args = fn (string $code): ?array => PhpOutline::ofCode("<?php #[A({$code})] class X {}")->classes()[0]['attributes'][0]['args'];

        $this->assertSame('b', PhpOutline::stringArgument($args("'a', 'b'"), 1, 'reason'));
        $this->assertSame('b', PhpOutline::stringArgument($args("other: 'a', reason: 'b'"), 0, 'reason'));
        $this->assertSame('abc', PhpOutline::stringArgument($args("'a' . 'b' . \"c\""), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument($args("'a' . B"), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument($args("'a' ."), 0, 'reason'));
        // Las comillas simples no interpretan escapes; las dobles, sí.
        $this->assertSame('a\nb', PhpOutline::stringArgument($args("'a\\nb'"), 0, 'reason'));
        $this->assertSame("a\tb", PhpOutline::stringArgument($args('"a\\tb"'), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument(null, 0, 'reason'));
    }

    // CLOSURES

    public function test_closures_por_su_forma(): void
    {
        $closures = PhpOutline::ofCode(<<<'PHP'
        <?php

        Route::GET('/a', fn (Request $request): array => [$request->all()]);
        Route::get('/b', function &() { return $x; });
        get('/c', function () { return 1; });
        Route::get('/d', static
            function (int $id) { return $id; });
        PHP)->closuresPassedTo(['get']);

        $this->assertSame(['GET', 'get', 'get'], array_column($closures, 'call'));
        $this->assertSame([3, 4, 7], array_column($closures, 'line'));
        $this->assertSame(['[', '$request', '->', 'all', '(', ')', ']'], array_column($closures[0]['body'], 1));
        $this->assertSame([], $closures[1]['params']);
        $this->assertSame(['int', '$id'], array_column($closures[2]['params'], 1));
    }

    public function test_un_closure_cortado_sigue_siendo_un_closure(): void
    {
        $function = PhpOutline::ofCode("<?php\n\nRoute::get('/', function (\$a)")->closuresPassedTo(['get']);
        $arrow = PhpOutline::ofCode("<?php\n\nRoute::get('/', fn (\$a)")->closuresPassedTo(['get']);

        $this->assertSame([['call' => 'get', 'line' => 3, 'arrow' => false, 'params' => [[T_VARIABLE, '$a', 3]], 'body' => []]], $function);
        $this->assertSame([['call' => 'get', 'line' => 3, 'arrow' => true, 'params' => [[T_VARIABLE, '$a', 3]], 'body' => []]], $arrow);
    }
}
