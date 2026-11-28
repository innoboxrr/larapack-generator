<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\MigrationReader;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\SchemaHistory;
use PHPUnit\Framework\TestCase;

/**
 * Los índices de una tabla, leídos de TODAS sus migraciones (9.4.0): el
 * generador los necesita para no añadir uno que ya existe, y el inferidor
 * para declararlos. Hasta 9.3.1 el lector los ignoraba.
 */
final class SchemaIndexesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        MigrationReader::reset();
        $this->directory = sys_get_temp_dir().'/larapack-indexes-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        MigrationReader::reset();
    }

    public function test_lee_indices_de_sentencia_y_de_columna_con_su_nombre(): void
    {
        $this->migration('2026_01_01_000000_create_orders_table.php', "Schema::create('orders', function (Blueprint \$table) {\n"
            ."    \$table->id();\n    \$table->string('number')->unique();\n    \$table->string('email')->index('orders_mail');\n"
            ."    \$table->unsignedBigInteger('store_id');\n    \$table->index(['store_id', 'created_at']);\n    \$table->timestamps();\n});");

        $state = (new SchemaHistory($this->directory))->table('orders');

        $this->assertSame([
            ['unique', ['number'], 'orders_number_unique'],
            ['index', ['email'], 'orders_mail'],
            ['index', ['store_id', 'created_at'], 'orders_store_id_created_at_index'],
        ], array_map(fn (array $index): array => [$index['type'], $index['columns'], $index['name']], $state->indexes));
        $this->assertTrue($state->hasIndex('index', ['number']), 'Un único ya sirve como índice normal.');
        $this->assertFalse($state->hasIndex('unique', ['email']));
        $this->assertSame([], $state->indexOpaque);
    }

    public function test_quitar_renombrar_y_quitar_la_columna_mueven_los_indices(): void
    {
        $this->migration('2026_01_01_000000_create_orders_table.php', "Schema::create('orders', function (Blueprint \$table) {\n"
            ."    \$table->id();\n    \$table->string('number')->unique();\n    \$table->string('code');\n    \$table->string('note');\n"
            ."    \$table->index('code');\n    \$table->index('note', 'orders_note');\n});");
        $this->migration('2026_02_01_000000_alter_orders.php', "Schema::table('orders', function (Blueprint \$table) {\n"
            ."    \$table->dropUnique(['number']);\n    \$table->renameIndex('orders_note', 'orders_note_idx');\n"
            ."    \$table->dropColumn('code');\n    \$table->renameColumn('note', 'remark');\n});");

        $state = (new SchemaHistory($this->directory))->table('orders');

        $this->assertSame([['index', ['remark'], 'orders_note_idx']], array_map(fn (array $index): array => [$index['type'], $index['columns'], $index['name']], $state->indexes));
    }

    public function test_un_indice_que_no_se_lee_lo_dice_y_no_se_adivina(): void
    {
        $this->migration('2026_01_01_000000_create_orders_table.php', "Schema::create('orders', function (Blueprint \$table) {\n"
            ."    \$table->id();\n    \$table->string('number');\n    \$table->index(\$columns);\n});");
        $this->migration('2026_02_01_000000_alter_orders.php', "if (app()->isProduction()) {\n    Schema::table('orders', function (Blueprint \$table) {\n        \$table->index('number');\n    });\n}");

        $state = (new SchemaHistory($this->directory))->table('orders');

        $this->assertNotSame([], $state->indexOpaque);
        $this->assertSame([], $state->indexes);
    }

    public function test_varios_directorios_corren_contra_la_misma_base_y_se_listan_las_tablas(): void
    {
        $other = $this->directory.'/otro';
        mkdir($other);
        file_put_contents($other.'/2026_01_02_000000_create_tags_table.php', $this->code("Schema::create('tags', function (Blueprint \$table) {\n    \$table->id();\n});"));
        $this->migration('2026_01_01_000000_create_orders_table.php', "Schema::create('orders', function (Blueprint \$table) {\n    \$table->id();\n});");
        $this->migration('2026_01_03_000000_drop_tmp.php', "Schema::create('tmp', function (Blueprint \$table) {\n    \$table->id();\n});\nSchema::dropIfExists('tmp');");

        $this->assertSame(['orders'], (new SchemaHistory($this->directory))->tables());
        $this->assertSame(['orders', 'tags'], (new SchemaHistory($this->directory, [$other]))->tables());

        unlink($other.'/2026_01_02_000000_create_tags_table.php');
        rmdir($other);
    }

    private function migration(string $name, string $body): void
    {
        file_put_contents($this->directory.'/'.$name, $this->code($body));
    }

    private function code(string $body): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\n"
            ."return new class extends Migration\n{\n    public function up(): void\n    {\n".$body."\n    }\n};\n";
    }
}
