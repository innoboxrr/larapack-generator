<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que se hace con varias filas a la vez y sin salir de la tabla.
 *
 * La tabla ya sabía seleccionar filas, pintar una barra de acciones masivas y
 * poner un componente en una celda, y el generador no usaba nada de eso:
 * borrar diez registros eran diez confirmaciones, y cambiar un título, abrir
 * el formulario. La suite EndToEnd ejecuta los endpoints; aquí se fija lo que
 * se genera.
 */
final class BulkActionsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function importProduct(array $extra = []): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $flags = ['datatable' => true, 'form' => true, 'form_submit' => true, 'fillable' => true, 'creatable' => true, 'updatable' => true];

        $path = $this->project->path . '/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => [[
            'name' => 'Product',
            'props' => [
                ['name' => 'title', 'type' => 'string', 'form_component' => 'TextInputComponent', ...$flags],
                ['name' => 'notes', 'type' => 'text', 'form_component' => 'TextareaInputComponent', ...$flags],
                ['name' => 'status', 'type' => 'string', 'form_component' => 'SelectInputComponent', 'enum' => ['draft' => 'Draft', 'published' => 'Published'], ...$flags],
            ],
            ...$extra,
        ]]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true, '--react' => true]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    // API

    public function test_las_masivas_tienen_ruta_metodo_y_request(): void
    {
        $this->importProduct();

        $routes = $this->project->read('routes/api/models/product.php');

        $this->assertStringContainsString("Route::put('bulk-update', [ProductController::class, 'bulkUpdate'])", $routes);
        $this->assertStringContainsString("->name('bulk.update')", $routes);
        $this->assertStringContainsString("Route::delete('bulk-delete', [ProductController::class, 'bulkDelete'])", $routes);
        $this->assertStringContainsString("->name('bulk.delete')", $routes);

        $controller = $this->project->read('src/Http/Controllers/ProductController.php');

        $this->assertStringContainsString('public function bulkUpdate(BulkUpdateRequest $request)', $controller);
        $this->assertStringContainsString('public function bulkDelete(BulkDeleteRequest $request)', $controller);
        $this->assertStringNotContainsString('@larapack:', $controller);

        $update = $this->project->read('src/Http/Requests/Product/BulkUpdateRequest.php');

        $this->assertStringContainsString("\$this->user()->can('update', \$record)", $update);
        $this->assertStringContainsString("'sometimes|' . \$rule", $update);
        $this->assertStringContainsString("\$field === 'product_id'", $update);

        $delete = $this->project->read('src/Http/Requests/Product/BulkDeleteRequest.php');

        $this->assertStringContainsString("\$this->user()->can('delete', \$record)", $delete);
        $this->assertStringContainsString('DB::transaction', $delete);
    }

    /**
     * La API de políticas responde por cada método del controlador: sin el
     * mapeo, la barra preguntaría por una habilidad que ninguna política tiene.
     */
    public function test_la_api_de_politicas_usa_la_habilidad_de_la_individual(): void
    {
        $this->importProduct();

        $policies = $this->project->read('src/Http/Requests/Product/PoliciesRequest.php');

        $this->assertStringContainsString("'bulkUpdate' => 'update',", $policies);
        $this->assertStringContainsString("'bulkDelete' => 'delete',", $policies);
    }

    public function test_sin_la_individual_no_hay_masiva(): void
    {
        $this->assertSame(
            ['policies', 'policy', 'index', 'show', 'create', 'delete', 'restore', 'forceDelete', 'export', 'bulkDelete'],
            Actions::resolve(['routes' => ['except' => ['update']]])
        );

        $this->assertSame([], array_intersect(['bulkUpdate', 'bulkDelete'], Actions::resolve(['immutable' => true])));

        $this->importProduct(['routes' => ['except' => ['update']]]);

        $this->assertFileDoesNotExist($this->project->path . '/src/Http/Requests/Product/BulkUpdateRequest.php');
        $this->assertStringNotContainsString('bulk.update', $this->project->read('routes/api/models/product.php'));

        $contract = $this->project->read('resources/vue/src/models/product/index.js');

        $this->assertStringNotContainsString('bulkUpdateModels', $contract);
        $this->assertStringNotContainsString("component: 'ClickToEdit'", $contract);
        $this->assertStringContainsString('bulkDeleteModels', $contract);
    }

    public function test_pedir_una_masiva_sin_su_individual_es_un_error(): void
    {
        ['errors' => $errors] = ImportDocument::fromArray(['models' => [[
            'name' => 'Product',
            'props' => [['name' => 'title', 'type' => 'string']],
            'routes' => ['only' => ['index', 'bulkDelete']],
        ]]]);

        $messages = implode("\n", array_column($errors, 'message'));

        $this->assertStringContainsString('bulkDelete sin delete', $messages);
    }

    // INTERFAZ

    public function test_la_barra_ofrece_borrar_y_un_cambio_por_cada_valor_del_enum(): void
    {
        $this->importProduct();

        $contract = $this->project->read('resources/vue/src/models/product/index.js');

        $this->assertStringContainsString('export const bulkActions = () => [', $contract);
        $this->assertStringContainsString("name: t('Status') + ': ' + t('Published'),", $contract);
        $this->assertStringContainsString("params: { status: 'published' },", $contract);
        $this->assertStringContainsString("params: { status: 'draft' },", $contract);
        $this->assertStringContainsString("callback: 'bulkDeleteModels',", $contract);
        $this->assertStringContainsString("route(API_ROUTE_PREFIX + 'bulk.update')", $contract);
        $this->assertStringContainsString("route(API_ROUTE_PREFIX + 'bulk.delete')", $contract);
        $this->assertStringNotContainsString('//BULK_UPDATE_ACTIONS//', $contract);
    }

    /**
     * Texto de una línea sí; el texto largo no cabe en una celda y un enum ya
     * tiene sus acciones.
     */
    public function test_las_columnas_de_texto_corto_se_editan_en_su_celda(): void
    {
        $this->importProduct();

        $contract = $this->project->read('resources/vue/src/models/product/index.js');

        $this->assertStringContainsString(
            "component: 'ClickToEdit',\n        parser: (value, row) => ({ value, label: t('Title'), save: (next) => updateField(row.id, 'title', next) }),",
            $contract
        );
        $this->assertSame(1, substr_count($contract, "component: 'ClickToEdit'"));
    }

    public function test_cada_tabla_pone_su_componente_y_activa_la_seleccion(): void
    {
        $this->importProduct();

        $vue = $this->project->read('resources/vue/src/models/product/widgets/DataTable.vue');

        $this->assertStringContainsString(':selectable="selectable"', $vue);
        $this->assertStringContainsString('dataTableComponents: () => ({ ClickToEdit: ClickToEditComponent })', $vue);

        $react = $this->project->read('resources/react/src/models/product/widgets/DataTable.jsx');

        $this->assertStringContainsString('selectable={selectable}', $react);
        $this->assertStringContainsString('<ClickToEditComponent {...props} onSave={save} />', $react);
    }

    public function test_exportar_avisa_desde_la_barra_y_desde_la_paleta(): void
    {
        $this->importProduct();

        $this->assertStringContainsString(
            "success: t('The export is being prepared. You will be notified when it is ready.'),",
            $this->project->read('resources/vue/src/models/product/index.js')
        );

        foreach (['resources/vue/src/models/product/views/AdminView.vue', 'resources/react/src/models/product/views/AdminView.jsx'] as $view) {
            $source = $this->project->read($view);

            $this->assertStringContainsString("id: 'export',", $source, "{$view} no ofrece exportar en la paleta.");
            $this->assertStringContainsString('action: requestExport,', $source);
            $this->assertStringNotContainsString('@larapack:', $source);
        }
    }
}
