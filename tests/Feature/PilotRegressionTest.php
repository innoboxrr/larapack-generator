<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Innoboxrr\LarapackGenerator\Tools\Package\PackageTool;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que encontró el piloto: una aplicación Laravel 13 nueva, con un paquete
 * creado con larapack:new y generado siguiendo el README al pie de la letra.
 *
 * Ninguno de estos fallos lo veía la suite, porque importaba una sola vez, no
 * simulaba antes, y montaba el módulo de interfaz sin pasar por su package.json.
 */
final class PilotRegressionTest extends TestCase
{
    private const DOCUMENT = [
        'models' => [
            [
                'name' => 'Category',
                'props' => [['name' => 'name', 'type' => 'string', 'datatable' => true]],
            ],
            [
                'name' => 'Product',
                'metas' => true,
                'editable_metas' => ['seo_title'],
                'props' => [
                    ['name' => 'title', 'type' => 'string', 'datatable' => true],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'datatable' => true,
                        'form' => true,
                        'form_component' => 'SelectInputComponent',
                        'form_submit' => true,
                        'enum' => ['draft' => 'Draft', 'published' => 'Published'],
                    ],
                ],
            ],
        ],
        'pivots' => [
            [
                'name' => 'category_product',
                'props' => [
                    ['name' => 'category_id', 'type' => 'foreignId', 'constraint' => 'categories'],
                    ['name' => 'product_id', 'type' => 'foreignId', 'constraint' => 'products'],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Catalogo\\'));

        file_put_contents($this->project->path . '/laraimport.json', json_encode(self::DOCUMENT));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(array $options = []): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $this->project->path . '/laraimport.json', '--vue' => true, ...$options]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    // SIMULAR, VOLVER A IMPORTAR, FORZAR

    /**
     * El README pide simular antes de importar. La simulación escribía todo:
     * importar no le pasaba --dry-run a cada modelo, y cada modelo reiniciaba
     * las opciones.
     */
    public function test_simular_no_escribe_nada(): void
    {
        $composer = (string) file_get_contents($this->project->path . '/composer.json');

        $this->import(['--dry-run' => true]);

        foreach ([
            'src/Models/Product.php',
            'src/Models/ProductMeta.php',
            'src/Models/Traits/Storage/ProductStorage.php',
            'database/factories/ProductFactory.php',
            'tests/Feature/Models/ProductEndpointsTest.php',
            'resources/vue/src/models/product/index.js',
            '.larapack/manifest.json',
        ] as $file) {
            $this->assertFileDoesNotExist($this->project->path . '/' . $file, "La simulación escribió {$file}.");
        }

        $this->assertSame([], glob($this->project->path . '/database/migrations/*.php') ?: []);
        $this->assertSame($composer, (string) file_get_contents($this->project->path . '/composer.json'), 'La simulación cambió composer.json.');
        $this->assertStringContainsString('src/Models/Traits/Storage/ProductStorage.php', $this->lastOutput, 'La simulación no dice que crearía los traits.');
    }

    /**
     * Cada importación añadía otra `create_<tabla>_table` con la hora nueva, y
     * `migrate` —o los tests del propio paquete— fallaban con "table already
     * exists".
     */
    public function test_importar_otra_vez_no_duplica_migraciones(): void
    {
        $this->import();
        $this->import();
        $this->import(['--force' => true]);

        foreach (['create_categories_table', 'create_products_table', 'create_product_metas_table', 'create_category_product_table'] as $migration) {
            $this->assertCount(
                1,
                glob($this->project->path . "/database/migrations/*_{$migration}.php") ?: [],
                "Hay más de una migración {$migration}."
            );
        }
    }

    public function test_forzar_regenera_lo_que_no_se_edito(): void
    {
        $this->import();
        $this->import(['--force' => true]);

        $this->assertMatchesRegularExpression('/\b([1-9]\d*) regenerados/', $this->lastOutput, "--force no regeneró nada:\n" . $this->lastOutput);
    }

    /**
     * Un modelo con metas y otro sin ellas es una decisión del laraimport.
     * verify lo daba por deriva.
     */
    public function test_verify_no_confunde_las_metas_con_deriva(): void
    {
        $this->import();

        $this->runCommand('larapack:verify', ['--format' => 'json']);

        $this->assertStringNotContainsString('inconsistent-entity', $this->lastOutput);
    }

    // EXPORTAR

    /**
     * Exportar enseñaba `Class "Maatwebsite\Excel\Facades\Excel" not found`:
     * el paquete ofrecía el botón, pero Excel era opcional y el disco, S3.
     */
    public function test_la_exportacion_funciona_en_una_aplicacion_nueva(): void
    {
        $composer = PackageTool::composerJson('acme/catalogo', 'Acme\\Catalogo', 'Catálogo', 'MIT');

        $this->assertArrayHasKey('maatwebsite/excel', $composer['require']);
        $this->assertArrayNotHasKey('maatwebsite/excel', $composer['require-dev'] ?? []);

        $config = (string) file_get_contents(stubs_path('Config/ConfigTemplate.txt'));

        $this->assertStringContainsString("'export_disk' => 'local'", $config);
        $this->assertStringContainsString("'notification_via' => ['mail']", $config);

        $this->import();

        $notification = $this->project->read('src/Notifications/Product/ExportNotification.php');

        $this->assertStringContainsString('temporaryUrl', $notification);
        $this->assertStringNotContainsString("url('/notification/read/'", $notification);
        $this->assertStringContainsString("__('The export could not be generated.')", $this->project->read('src/Http/Requests/Product/ExportRequest.php'));
    }

    // LA PANTALLA

    public function test_la_tabla_ensena_la_etiqueta_del_enum(): void
    {
        $this->import();

        $this->assertStringContainsString(
            "parser: (value) => ({ 'draft': t('Draft'), 'published': t('Published') })[value] ?? value,",
            $this->project->read('resources/vue/src/models/product/index.js')
        );
    }

    public function test_la_ficha_ensena_la_fecha_legible(): void
    {
        $this->import();

        $this->assertStringContainsString('formatDate(record.created_at)', $this->project->read('resources/vue/src/models/product/widgets/ModelProfile.vue'));
    }

    public function test_borrar_desde_la_tabla_avisa(): void
    {
        $this->import();

        $this->assertStringContainsString("'success' => __('Record deleted')", $this->project->read('src/Http/Resources/Models/ProductResource.php'));
        $this->assertSame('Registro eliminado', json_decode($this->project->read('lang/es.json'), true)['Record deleted'] ?? null);
    }
}
