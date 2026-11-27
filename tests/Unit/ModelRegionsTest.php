<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\ModelLists;
use Innoboxrr\LarapackGenerator\Support\ModelRegions;
use PHPUnit\Framework\TestCase;

/**
 * ModelRegions completa las listas de un modelo editado a mano y no toca nada
 * más. Las formas salen de modelos reales de school-management.
 */
final class ModelRegionsTest extends TestCase
{
    private const EMPTY_LISTS = [
        'fillable' => [], 'hidden' => [], 'creatable' => [], 'updatable' => [], 'export_cols' => [],
        'loadable_relations' => [], 'loadable_counts' => [], 'casts' => [],
    ];

    /**
     * @param  array<string, mixed>  $lists
     * @return array<string, mixed>
     */
    private static function expected(array $lists): array
    {
        return $lists + self::EMPTY_LISTS;
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Fixtures/real/'.$name);
    }

    public function test_charge_line_de_school_finance_recibe_las_tres_columnas_que_le_faltaban(): void
    {
        $real = self::fixture('ChargeLine.php.txt');
        ['document' => $document] = ImportDocument::fromFile(dirname(__DIR__).'/Fixtures/real/school-finance-charge-line.json');
        $this->assertNotNull($document);

        $result = ModelRegions::sync($real, ModelLists::expected($document->models()[0]), 'src/Models/ChargeLine.php');

        $this->assertSame(['export_cols' => ["'discount_id'", "'scholarship_award_id'", "'late_fee_rule_id'"]], $result['added']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(str_replace(
            "'period_key', 'created_by_user_id',\n",
            "'period_key', 'created_by_user_id', 'discount_id', 'scholarship_award_id', 'late_fee_rule_id',\n",
            $real
        ), $result['code']);

        // Una segunda pasada no añade nada.
        $again = ModelRegions::sync($result['code'], ModelLists::expected($document->models()[0]), 'src/Models/ChargeLine.php');
        $this->assertSame([], $again['added']);
        $this->assertSame($result['code'], $again['code']);
    }

    public function test_una_lista_vacia_con_la_linea_en_blanco_de_pint_recibe_una_linea(): void
    {
        $real = self::fixture('ChargeLine.php.txt');

        $result = ModelRegions::sync($real, self::expected([
            'fillable' => ['kind', 'amount'],
            'export_cols' => ['charge_id', 'kind', 'amount', 'description', 'period_key', 'created_by_user_id'],
            'casts' => ['amount' => "'decimal:2'"],
        ]), 'm.php');

        $this->assertStringContainsString("    protected \$fillable = [\n        'kind', 'amount',\n    ];\n", $result['code']);
        $this->assertSame(['fillable' => ["'kind'", "'amount'"]], $result['added']);
    }

    public function test_una_lista_en_una_linea_sin_coma_final_y_otra_una_por_linea(): void
    {
        $code = <<<'PHP'
<?php

class Post
{
    protected $fillable = ['title'];

    protected $creatable = [
        'title',
        'body', // el cuerpo
    ];

    protected function casts(): array
    {
        return ['title' => 'string'];
    }
}
PHP;

        $result = ModelRegions::sync($code, self::expected([
            'fillable' => ['title', 'body'],
            'creatable' => ['title', 'body', 'slug'],
            'casts' => ['title' => "'string'", 'published' => "'boolean'"],
        ]), 'm.php');

        $this->assertStringContainsString("protected \$fillable = ['title', 'body'];", $result['code']);
        $this->assertStringContainsString("        'body', // el cuerpo\n        'slug',\n    ];", $result['code']);
        $this->assertStringContainsString("return ['title' => 'string', 'published' => 'boolean'];", $result['code']);
    }

    public function test_un_array_vacio_en_una_linea(): void
    {
        $code = "<?php\n\nclass Post\n{\n    protected \$fillable = [];\n}\n";

        $result = ModelRegions::sync($code, self::expected(['fillable' => ['title']]), 'm.php');

        $this->assertSame("<?php\n\nclass Post\n{\n    protected \$fillable = [\n        'title',\n    ];\n}\n", $result['code']);
    }

    public function test_casts_como_propiedad_tambien_se_completa(): void
    {
        $code = "<?php\n\nclass Post\n{\n    protected \$casts = [\n        'a' => 'json',\n    ];\n}\n";

        $result = ModelRegions::sync($code, self::expected(['casts' => ['a' => "'json'", 'b' => "'boolean'"]]), 'm.php');

        $this->assertStringContainsString("        'a' => 'json', 'b' => 'boolean',\n", $result['code']);
    }

    public function test_lo_que_no_se_reconoce_con_certeza_no_se_toca(): void
    {
        $code = <<<'PHP'
<?php

class Post
{
    protected $guarded = [];

    public static $export_cols = [...self::BASE, 'title'];

    protected $creatable = array('title');

    protected function casts(): array
    {
        $casts = ['a' => 'json'];

        return $casts;
    }
}
PHP;

        $result = ModelRegions::sync($code, self::expected([
            'fillable' => ['title'],
            'export_cols' => ['title', 'body'],
            'creatable' => ['title'],
            'casts' => ['a' => "'json'"],
        ]), 'app/Models/Post.php');

        $this->assertSame($code, $result['code']);
        $this->assertSame([], $result['added']);
        $this->assertSame(['fillable', 'creatable', 'export_cols', 'casts'], array_column($result['skipped'], 'region'));
        $this->assertSame("Añade a mano en app/Models/Post.php, dentro de \$export_cols: 'title', 'body'", $result['skipped'][2]['manual']);
    }

    public function test_un_cast_cambiado_a_mano_no_se_cambia_y_lo_de_mas_no_se_quita(): void
    {
        $code = "<?php\n\nclass Post\n{\n    protected \$fillable = ['title', 'legacy'];\n\n    protected function casts(): array\n    {\n        return [\n            'meta' => AsArrayObject::class,\n            'flag' => \"boolean\",\n        ];\n    }\n}\n";

        $result = ModelRegions::sync($code, self::expected([
            'fillable' => ['title'],
            'casts' => ['meta' => "'json'", 'flag' => "'boolean'"],
        ]), 'm.php');

        $this->assertSame($code, $result['code']);
        $this->assertSame(['meta' => ['expected' => "'json'", 'actual' => 'AsArrayObject::class']], $result['differs']);
        $this->assertSame(['fillable' => ['legacy']], $result['extra']);
    }

    public function test_una_propiedad_declarada_dos_veces_no_se_toca(): void
    {
        $code = "<?php\n\nclass A\n{\n    protected \$fillable = ['a'];\n}\n\nclass B\n{\n    protected \$fillable = ['b'];\n}\n";

        $result = ModelRegions::sync($code, self::expected(['fillable' => ['a', 'c']]), 'm.php');

        $this->assertSame($code, $result['code']);
        $this->assertStringContainsString('más de una vez', $result['skipped'][0]['reason']);
    }

    public function test_las_cadenas_literales_se_leen_con_sus_comillas(): void
    {
        $this->assertSame("it's", ModelRegions::literal("'it\\'s'"));
        $this->assertSame('a', ModelRegions::literal('"a"'));
        $this->assertNull(ModelRegions::literal('self::A'));
        $this->assertNull(ModelRegions::literal("'a' . 'b'"));
    }
}
