<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que el laraimport aprendió a declarar en 9.4.0 para poder ADOPTAR un
 * proyecto real sin perder nada de su esquema ni de su contrato con el front:
 * longitudes, precisiones, `useCurrent`, índices, la tabla explícita, el
 * `morphTo` sin modelo y el nombre y la URI de una ruta que ya existe.
 *
 * Lo que no se declara sale como en 9.3.1: lo prueban las instantáneas
 * (GeneratedOutputSnapshotTest), que no cambiaron.
 */
final class ContractExtensionsTest extends TestCase
{
    public function test_las_medidas_y_los_indices_van_en_la_migracion_de_creacion(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->import(['models' => [[
            'name' => 'Product',
            'props' => [
                ['name' => 'sku', 'type' => 'string', 'length' => 40],
                ['name' => 'code', 'type' => 'char', 'length' => 3],
                ['name' => 'price', 'type' => 'decimal', 'precision' => 16, 'scale' => 4],
                ['name' => 'rate', 'type' => 'decimal', 'precision' => 5],
                ['name' => 'seen_at', 'type' => 'timestamp', 'use_current' => true],
                ['name' => 'store_id', 'type' => 'unsignedBigInteger'],
            ],
            'indexes' => [
                ['columns' => ['sku'], 'unique' => true],
                ['columns' => ['store_id', 'created_at']],
                ['columns' => ['code', 'store_id'], 'unique' => true, 'name' => 'products_code_store_unique'],
            ],
        ]]]);

        $code = $project->read((string) $project->glob('database/migrations/*_create_products_table.php'));

        foreach ([
            "\$table->string('sku', 40);",
            "\$table->char('code', 3);",
            "\$table->decimal('price', 16, 4);",
            "\$table->decimal('rate', 5, 2);",
            "\$table->timestamp('seen_at')->useCurrent();",
            "\$table->unique('sku');",
            "\$table->index(['store_id', 'created_at']);",
            "\$table->unique(['code', 'store_id'], 'products_code_store_unique');",
        ] as $expected) {
            $this->assertStringContainsString($expected, $code);
        }

        $this->assertGeneratedPhpCompiles();

        // Reimportar lee los índices y las medidas que ya escribió: no altera.
        $this->import(json_decode($project->read('laraimport.json'), true));

        $this->assertNull($project->glob('database/migrations/*_alter_products_table.php'), 'Reimportar sin cambios escribió una alteración.');
    }

    public function test_un_indice_nuevo_en_una_tabla_que_existe_va_en_una_alteracion_y_no_se_repite(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $declaration = ['models' => [[
            'name' => 'Order',
            'props' => [
                ['name' => 'number', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
        ]]];

        $this->import($declaration);

        // Una persona ya le había puesto un único a `email` en otra migración.
        file_put_contents($project->path.'/database/migrations/2099_01_01_000000_email_is_unique.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
PHP);

        $declaration['models'][0]['indexes'] = [['columns' => ['number']], ['columns' => ['email'], 'unique' => true]];
        $this->import($declaration);

        $alter = $project->glob('database/migrations/*_alter_orders_table.php');

        $this->assertNotNull($alter, 'El índice declarado no se añadió.');
        $code = $project->read($alter);

        $this->assertStringContainsString("\$table->index('number');", $code);
        $this->assertStringContainsString("\$table->dropIndex(['number']);", $code);
        $this->assertStringNotContainsString("unique('email')", $code, 'El único que ya existía se volvió a escribir.');

        // Y una vez escrito, se lee: el siguiente import no lo repite.
        $this->import($declaration);

        $this->assertCount(1, glob($project->path.'/database/migrations/*_alter_orders_table.php') ?: [], 'Un índice que ya existe se volvió a añadir.');
    }

    public function test_la_tabla_explicita_va_al_modelo_a_la_migracion_y_a_las_llaves_sin_prefijo(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->import([
            'table_prefix' => 'shop_',
            'models' => [
                ['name' => 'Person', 'table' => 'legacy_people', 'props' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Ticket', 'props' => [
                    ['name' => 'person_id', 'type' => 'foreignId', 'constraint' => 'legacy_people'],
                ]],
            ],
        ]);

        $migration = $project->glob('database/migrations/*_create_legacy_people_table.php');
        $this->assertNotNull($migration, 'La migración no lleva el nombre de la tabla declarada.');
        $this->assertStringContainsString("Schema::create('legacy_people'", $project->read($migration));

        $model = $project->read('src/Models/Person.php');
        $this->assertStringContainsString("protected \$table = 'legacy_people';", $model);
        $this->assertSame(1, substr_count($model, 'protected $table'), 'El modelo declara $table dos veces.');

        // El prefijo sigue valiendo para el resto, y la llave no se prefija.
        $this->assertStringContainsString("protected \$table = 'shop_tickets';", $project->read('src/Models/Ticket.php'));
        $tickets = $project->read((string) $project->glob('database/migrations/*_create_shop_tickets_table.php'));
        $this->assertStringContainsString("->constrained('legacy_people')", $tickets);

        $this->assertGeneratedPhpCompiles();
    }

    public function test_un_morph_to_sin_modelo_se_genera_sin_import(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->import(['models' => [[
            'name' => 'Comment',
            'props' => [['name' => 'commentable', 'type' => 'morphs']],
            'load_relations' => [['type' => 'morphTo', 'name' => 'commentable']],
        ]]]);

        $relations = $project->read('src/Models/Traits/Relations/CommentRelations.php');

        $this->assertStringContainsString('return $this->morphTo();', $relations);
        $this->assertStringNotContainsString('::class', $relations);
        $this->assertGeneratedPhpCompiles();
    }

    public function test_route_name_y_uri_conservan_la_ruta_que_ya_existe(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->import(['models' => [['name' => 'Item', 'props' => [['name' => 'name', 'type' => 'string']]]], 'controllers' => [[
            'name' => 'Clinic',
            'endpoints' => [
                ['name' => 'saveMetric', 'method' => 'post', 'uri' => 'encounter_metric/save', 'route_name' => 'encounter_metric.save', 'authorize' => 'authenticated'],
                ['name' => 'canvas', 'method' => 'get', 'uri' => 'lienzo/{patientId}', 'route_name' => 'lienzo', 'authorize' => 'authenticated'],
            ],
        ]]]);

        $routes = $project->read('routes/api/models/clinic.php');

        $this->assertStringContainsString("Route::post('encounter_metric/save', [ClinicController::class, 'saveMetric'])", $routes);
        $this->assertStringContainsString("->name('encounter_metric.save')", $routes);
        $this->assertStringContainsString("Route::get('lienzo/{patientId}', [ClinicController::class, 'canvas'])", $routes);
        $this->assertStringContainsString("->name('lienzo')", $routes);
        $this->assertGeneratedPhpCompiles();

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:verify', ['--root' => $project->path, '--format' => 'json']), $this->lastOutput);
    }

    /**
     * @param  array<string, mixed>  $declaration
     */
    private function import(array $declaration): void
    {
        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode($declaration));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }
}
