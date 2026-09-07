<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class GeneratesFullModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:full-model', ['name' => 'Product', '--metas' => true]),
            "larapack:full-model terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_todo_el_php_generado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    public function test_genera_el_conjunto_completo_de_un_modelo(): void
    {
        foreach ([
            'src/Models/Product.php',
            'src/Models/ProductMeta.php',
            'src/Http/Controllers/Controller.php',
            'src/Http/Controllers/ProductController.php',
            'src/Http/Resources/Models/ProductResource.php',
            'src/Policies/ProductPolicy.php',
            'src/Observers/ProductObserver.php',
            'src/Exports/ProductsExports.php',
            'src/Notifications/Product/ExportNotification.php',
            'database/factories/ProductFactory.php',
            'routes/api/models/product.php',
        ] as $expected) {
            $this->assertGenerated($expected);
        }

        foreach (['Create', 'Update', 'Delete', 'Index', 'Show', 'Export', 'Policies', 'Policy', 'Restore', 'ForceDelete'] as $request) {
            $this->assertGenerated("src/Http/Requests/Product/{$request}Request.php");
        }
    }

    public function test_el_modelo_declara_casts_como_metodo_y_enlaza_por_atributos(): void
    {
        $model = $this->project->read('src/Models/Product.php');

        $this->assertStringContainsString('protected function casts(): array', $model);
        $this->assertStringNotContainsString('protected $casts', $model);

        $this->assertStringContainsString('#[ObservedBy(ProductObserver::class)]', $model);
        $this->assertStringContainsString('#[UsePolicy(ProductPolicy::class)]', $model);
        $this->assertStringContainsString('#[UseFactory(ProductFactory::class)]', $model);
    }

    public function test_el_controlador_usa_has_middleware_en_lugar_del_constructor(): void
    {
        $controller = $this->project->read('src/Http/Controllers/ProductController.php');

        $this->assertStringContainsString('implements HasMiddleware', $controller);
        $this->assertStringContainsString('public static function middleware(): array', $controller);
        $this->assertStringNotContainsString('$this->middleware(', $controller);
    }

    public function test_las_rutas_usan_callables_y_conservan_los_nombres(): void
    {
        $routes = $this->project->read('routes/api/models/product.php');

        $this->assertStringContainsString("[ProductController::class, 'index']", $routes);
        $this->assertStringNotContainsString('ProductController@', $routes);

        // El front resuelve las URLs por nombre de ruta (API_ROUTE_PREFIX +
        // acción), así que renombrarlas rompería el contrato con el módulo JS.
        foreach (['policies', 'policy', 'index', 'show', 'create', 'update', 'delete', 'restore', 'force.delete', 'export'] as $name) {
            $this->assertStringContainsString("->name('{$name}')", $routes);
        }
    }

    public function test_la_migracion_declara_tipos_de_retorno(): void
    {
        $migration = $this->project->glob('database/migrations/*_create_products_table.php');

        $this->assertNotNull($migration, 'No se generó la migración de la tabla products.');

        $contents = $this->project->read($migration);

        $this->assertStringContainsString('public function up(): void', $contents);
        $this->assertStringContainsString('public function down(): void', $contents);
    }

    /**
     * El nombre del archivo decide en que orden corre `php artisan migrate`,
     * y la tabla de metas tiene una clave foranea a la del modelo. Antes el
     * orden se conseguia durmiendo dos segundos por migracion; ahora lo
     * garantiza un contador monotonico.
     */
    public function test_la_migracion_de_metas_corre_despues_que_la_del_modelo(): void
    {
        $modelo = $this->project->glob('database/migrations/*_create_products_table.php');
        $metas = $this->project->glob('database/migrations/*_create_product_metas_table.php');

        $this->assertNotNull($modelo);
        $this->assertNotNull($metas);

        $this->assertLessThan(
            basename($metas),
            basename($modelo),
            'La migracion de metas quedo antes que la del modelo al que apunta.'
        );
    }

    public function test_los_namespaces_se_sustituyen_por_el_del_proyecto(): void
    {
        $model = $this->project->read('src/Models/Product.php');

        $this->assertStringContainsString('namespace TestVendor\TestPkg\Models;', $model);
        $this->assertStringNotContainsString('Namespace\\', $model);
        $this->assertStringNotContainsString('PascalCaseModelName', $model);
    }
}
