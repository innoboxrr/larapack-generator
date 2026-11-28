<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Apply\ForeignKeyPins;
use PHPUnit\Framework\TestCase;

/**
 * Las acciones que se fijan en el contrato se leen de la tabla del modelo:
 * la que declara `table` (tal cual, sin prefijo) o, si no declara ninguna,
 * la que se deduce de su nombre con el prefijo del contrato.
 */
final class ForeignKeyPinsTableTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-pins-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/database/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /**
     * Con `table: legacy_people` se buscaba `people`: no se fijaba la cascada
     * de la tabla de verdad, o se fijaban las acciones de OTRA tabla.
     */
    public function test_la_tabla_declarada_es_la_que_se_lee(): void
    {
        $this->migration('2024_01_01_000000_create_teams_table.php', 'teams', '');
        $this->migration('2024_01_01_000001_create_legacy_people_table.php', 'legacy_people', "\$table->foreignId('team_id')->constrained('teams')->onUpdate('cascade')->onDelete('cascade');");
        $this->migration('2024_01_01_000002_create_people_table.php', 'people', "\$table->foreignId('team_id')->constrained('teams')->onDelete('set null');");

        $this->contract(['table_prefix' => 'acme', 'models' => [
            ['name' => 'Team', 'table' => 'teams', 'props' => [['name' => 'name', 'type' => 'string']]],
            ['name' => 'Person', 'table' => 'legacy_people', 'props' => [['name' => 'team_id', 'type' => 'foreignId', 'constraint' => 'teams']]],
        ]]);

        $pins = ForeignKeyPins::find($this->root);

        $this->assertCount(1, $pins, json_encode($pins) ?: '');
        $this->assertSame('legacy_people', $pins[0]['table']);
        $this->assertSame('cascade', $pins[0]['on_delete'] ?? null);
        $this->assertSame('cascade', $pins[0]['on_update'] ?? null);
    }

    public function test_sin_tabla_declarada_se_deduce_con_el_prefijo(): void
    {
        $this->migration('2024_01_01_000000_create_acme_people_table.php', 'acme_people', "\$table->foreignId('team_id')->constrained('teams')->onDelete('cascade');");

        $this->contract(['table_prefix' => 'acme', 'models' => [
            ['name' => 'Person', 'props' => [['name' => 'team_id', 'type' => 'foreignId', 'constraint' => 'teams']]],
        ]]);

        $pins = ForeignKeyPins::find($this->root);

        $this->assertCount(1, $pins, json_encode($pins) ?: '');
        $this->assertSame('acme_people', $pins[0]['table']);
        $this->assertSame('cascade', $pins[0]['on_delete'] ?? null);
    }

    private function migration(string $file, string $table, string $columns): void
    {
        file_put_contents($this->root.'/database/migrations/'.$file, <<<PHP
            <?php

            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Database\\Schema\\Blueprint;
            use Illuminate\\Support\\Facades\\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('{$table}', function (Blueprint \$table) {
                        \$table->id();
                        {$columns}
                        \$table->timestamps();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('{$table}');
                }
            };
            PHP);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function contract(array $contract): void
    {
        file_put_contents($this->root.'/laraimport.json', (string) json_encode($contract, JSON_PRETTY_PRINT));
    }
}
