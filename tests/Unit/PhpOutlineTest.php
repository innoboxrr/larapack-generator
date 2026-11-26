<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\PhpOutline;
use PHPUnit\Framework\TestCase;

/**
 * Lo que controller-shape necesita leer de un archivo sin cargarlo: si se
 * equivoca al resolver un nombre, confunde una request propia con la base.
 */
final class PhpOutlineTest extends TestCase
{
    public function test_resuelve_los_tipos_por_los_use_del_archivo(): void
    {
        $classes = PhpOutline::ofCode(<<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Http\Requests\Post\{IndexRequest, ShowRequest as Show};
        use Illuminate\Http\Request as BaseRequest;
        use function collect;

        final class PostController extends Controller
        {
            public function index(IndexRequest $request) { return $request->handle(); }

            public function show(?Show $request, \Illuminate\Http\Request $raw, BaseRequest $base, Local $local, int $id, A|B $union) {}
        }
        PHP)->classes();

        $this->assertCount(1, $classes);
        $this->assertSame('App\Http\Controllers\PostController', $classes[0]['fqcn']);
        $this->assertSame('App\Http\Controllers\Controller', $classes[0]['extends']);

        $types = array_column($classes[0]['methods'][1]['params'], 'type', 'name');

        $this->assertSame([
            '$request' => 'App\Http\Requests\Post\ShowRequest',
            '$raw' => 'Illuminate\Http\Request',
            '$base' => 'Illuminate\Http\Request',
            '$local' => 'App\Http\Controllers\Local',
            '$id' => 'int',
            '$union' => null,
        ], $types);
    }

    public function test_no_confunde_class_ni_las_clases_anonimas_con_una_declaracion(): void
    {
        $classes = PhpOutline::ofCode(<<<'PHP'
        <?php

        namespace App;

        class Real
        {
            protected $map = [Other::class => 'x'];

            public function make()
            {
                $x = Real::class;

                return new class { public function hidden() {} };
            }

            abstract public function nothing(): void;
        }
        PHP)->classes();

        $this->assertCount(1, $classes);
        $this->assertSame(['make', 'nothing'], array_column($classes[0]['methods'], 'name'));
        $this->assertNull($classes[0]['methods'][1]['body']);
    }

    public function test_lee_traits_atributos_y_espacios_de_nombres_con_llaves(): void
    {
        $classes = PhpOutline::ofCode(<<<'PHP'
        <?php

        namespace App\Http {
            use Some\Attr;

            #[Attr('uno' . ' dos'), \Other\Thing(reason: "tres")]
            trait Helps
            {
                use \Shared\Trait1, Local {
                    Local::a insteadof Trait1;
                }

                #[Attr]
                private static function helper() {}
            }
        }
        PHP)->classes();

        $this->assertSame('trait', $classes[0]['kind']);
        $this->assertSame('App\Http\Helps', $classes[0]['fqcn']);
        $this->assertSame(['Shared\Trait1', 'App\Http\Local'], $classes[0]['traits']);

        [$first, $second] = $classes[0]['attributes'];

        $this->assertSame('Some\Attr', $first['name']);
        $this->assertSame('uno dos', PhpOutline::stringArgument($first['args'], 0, 'reason'));
        $this->assertSame('Other\Thing', $second['name']);
        $this->assertSame('tres', PhpOutline::stringArgument($second['args'], 0, 'reason'));

        $method = $classes[0]['methods'][0];

        $this->assertSame('private', $method['visibility']);
        $this->assertTrue($method['static']);
        $this->assertNull($method['attributes'][0]['args']);
    }

    public function test_marca_las_clases_abstractas(): void
    {
        $classes = PhpOutline::ofCode(<<<'PHP'
        <?php

        abstract class Base {}
        final readonly class Concrete {}
        readonly abstract class Odd {}
        trait Helps {}
        PHP)->classes();

        $this->assertSame([true, false, true, false], array_column($classes, 'abstract'));
    }

    /**
     * Un gancho de propiedad (PHP 8.4) cierra con `}`: lo que llevaba
     * delante no es del método siguiente.
     */
    public function test_los_ganchos_de_propiedad_no_pasan_atributos_ni_modificadores(): void
    {
        $methods = PhpOutline::ofCode(<<<'PHP'
        <?php

        class Hooks
        {
            #[Cached('x')]
            public string $label {
                get => 'x';
            }

            function store() {}

            private string $secret {
                get => 'y';
            }

            function destroy() {}
        }
        PHP)->classes()[0]['methods'];

        $this->assertSame(['store', 'destroy'], array_column($methods, 'name'));
        $this->assertSame([[], []], array_column($methods, 'attributes'));
        $this->assertSame(['public', 'public'], array_column($methods, 'visibility'));
    }

    /**
     * Un archivo a medio escribir no puede avisar: bajo Artisan el aviso es
     * una excepción. Se corta un archivo en cada posición.
     */
    public function test_un_archivo_cortado_en_cualquier_punto_no_avisa(): void
    {
        $code = <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Http\Requests\{IndexRequest, ShowRequest as Show};
        use Illuminate\Support\Facades\Route as Router;

        #[Attr('a' . 'b')]
        abstract class Cut extends Controller
        {
            use Shared, Other { Other::a insteadof Shared; }

            public string $hooked { get => 'x'; }

            public function index(IndexRequest $request, ?Show $show = null): mixed
            {
                Router::get('/x', static fn (int $id) => view('x'));

                return $request->handle(post: $show);
            }
        }
        PHP;

        set_error_handler(function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            for ($length = 0; $length <= strlen($code); $length++) {
                $outline = PhpOutline::ofCode(substr($code, 0, $length));
                $outline->classes();
                $outline->closuresPassedTo(['get']);
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame(strlen($code) + 1, $length);
    }

    public function test_encuentra_los_closures_pasados_a_una_llamada(): void
    {
        $closures = PhpOutline::ofCode(<<<'PHP'
        <?php

        Route::get('/', [Home::class, 'index']);

        Route::middleware('auth')->group(function () use ($x) {
            Route::match(['get', 'post'], '/a', static function (Request $request): Response {
                return $request->all();
            });

            Route::post('/b', action: fn () => view('b'));
        });

        Cache::remember('k', 60, fn () => 1);
        PHP)->closuresPassedTo(['get', 'post', 'match', 'group']);

        $this->assertSame(['group', 'match', 'post'], array_column($closures, 'call'));
        $this->assertSame([5, 6, 10], array_column($closures, 'line'));
        $this->assertSame([false, false, true], array_column($closures, 'arrow'));
        $this->assertSame('$request', $closures[1]['params'][1][1]);
        $this->assertSame([T_RETURN, T_VARIABLE, T_OBJECT_OPERATOR, T_STRING, '(', ')', ';'], array_column($closures[1]['body'], 0));
        $this->assertSame(['view', '(', "'b'", ')'], array_column($closures[2]['body'], 1));
    }

    /**
     * El `use` de un closure no es un import: si se leyera como tal, lo que
     * viniera hasta el `;` acabaría resolviendo nombres.
     */
    public function test_el_use_de_un_closure_no_importa_nada(): void
    {
        $classes = PhpOutline::ofCode(<<<'PHP'
        <?php

        namespace App;

        $f = function () use ($x) { Helper::run(); };

        class Uses
        {
            public function a(Helper $helper) {}
        }
        PHP)->classes();

        $this->assertSame('App\Helper', $classes[0]['methods'][0]['params'][0]['type']);
    }

    public function test_solo_acepta_una_razon_literal(): void
    {
        $args = fn (string $code): ?array => PhpOutline::ofCode("<?php #[A({$code})] class X {}")->classes()[0]['attributes'][0]['args'];

        $this->assertSame("it's", PhpOutline::stringArgument($args("'it\\'s'"), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument($args('self::REASON'), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument($args("'a' . self::B"), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument($args("other: 'x'"), 0, 'reason'));
        $this->assertNull(PhpOutline::stringArgument($args(''), 0, 'reason'));
    }
}
