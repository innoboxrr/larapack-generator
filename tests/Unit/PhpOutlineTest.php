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
