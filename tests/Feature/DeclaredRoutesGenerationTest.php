<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Quitar una acción quita todo lo que cuelga de ella, en el lado PHP.
 *
 * Tres formas que el generador no sabía producir, junto a una normal para
 * comprobar que no se contaminan entre sí:
 *
 *   AuditEvent  sólo se lee          routes.only [index, show]
 *   Grant       se otorga y revoca   routes.except [update, restore, forceDelete]
 *   Consent     nace y no cambia     immutable
 */
final class DeclaredRoutesGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Audit\\'));

        $path = $this->project->path . '/laraimport.json';

        file_put_contents($path, json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
            ['name' => 'AuditEvent', 'props' => [['name' => 'action', 'type' => 'string']], 'routes' => ['only' => ['index', 'show']]],
            ['name' => 'Grant', 'props' => [['name' => 'scope', 'type' => 'string']], 'routes' => ['except' => ['update', 'restore', 'forceDelete']]],
            ['name' => 'Consent', 'props' => [['name' => 'accepted', 'type' => 'boolean']], 'immutable' => true],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_todo_el_php_generado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    /**
     * El criterio de aceptación literal: `only: [index, show]` genera dos
     * rutas y dos requests.
     */
    public function test_only_index_show_genera_dos_rutas_y_dos_requests(): void
    {
        $routes = $this->project->read('routes/api/models/audit_event.php');

        $this->assertSame(2, substr_count($routes, '->name('));
        $this->assertStringContainsString("->name('index')", $routes);
        $this->assertStringContainsString("->name('show')", $routes);

        $this->assertSame(['IndexRequest.php', 'ShowRequest.php'], $this->filesIn('src/Http/Requests/AuditEvent'));
    }

    public function test_el_controlador_solo_tiene_los_metodos_declarados(): void
    {
        $controller = $this->project->read('src/Http/Controllers/AuditEventController.php');

        preg_match_all('/public function (\w+)\(\w+Request \$request\)/', $controller, $methods);

        $this->assertSame(['index', 'show'], $methods[1]);

        foreach (['CreateRequest', 'UpdateRequest', 'DeleteRequest', 'ExportRequest'] as $absent) {
            $this->assertStringNotContainsString($absent, $controller);
        }
    }

    public function test_sin_escrituras_no_hay_eventos_ni_exportacion(): void
    {
        $this->assertFalse($this->project->has('src/Http/Events/AuditEvent/Events/UpdateEvent.php'));
        $this->assertFalse($this->project->has('src/Exports/AuditEventsExports.php'));
        $this->assertFalse($this->project->has('src/Notifications/AuditEvent/ExportNotification.php'));
        $this->assertFalse($this->project->has('resources/views/excel/audit_event.blade.php'));

        // Y tampoco quedan los directorios vacíos que habría creado resolver
        // la ruta antes de preguntar.
        $this->assertDirectoryDoesNotExist($this->project->path . '/src/Notifications/AuditEvent');
    }

    public function test_la_politica_solo_tiene_las_habilidades_de_sus_acciones(): void
    {
        $policy = $this->project->read('src/Policies/AuditEventPolicy.php');

        foreach (['index', 'viewAny', 'view'] as $present) {
            $this->assertStringContainsString("public function {$present}(", $policy);
        }

        foreach (['create', 'update', 'delete', 'restore', 'forceDelete', 'export'] as $absent) {
            $this->assertStringNotContainsString("public function {$absent}(", $policy);
        }
    }

    public function test_el_resource_no_ofrece_acciones_que_no_existen(): void
    {
        $resource = $this->project->read('src/Http/Resources/Models/AuditEventResource.php');

        $this->assertStringContainsString('$this->view()', $resource);
        $this->assertStringNotContainsString('$this->edit()', $resource);
        $this->assertStringNotContainsString('$this->delete()', $resource);
        $this->assertStringNotContainsString('private function edit()', $resource);
    }

    public function test_el_test_del_proyecto_solo_prueba_los_endpoints_que_existen(): void
    {
        $test = $this->project->read('tests/Feature/Models/AuditEventEndpointsTest.php');

        preg_match_all('/public function (test_\w+)\(\)/', $test, $tests);

        $this->assertSame([
            'test_audit_event_index_auth_endpoint',
            'test_audit_event_index_guest_endpoint',
            'test_audit_event_show_auth_endpoint',
            'test_audit_event_show_guest_endpoint',
        ], $tests[1]);
    }

    public function test_except_quita_solo_lo_que_nombra(): void
    {
        $this->assertSame(
            ['CreateRequest.php', 'DeleteRequest.php', 'ExportRequest.php', 'IndexRequest.php', 'PoliciesRequest.php', 'PolicyRequest.php', 'ShowRequest.php'],
            $this->filesIn('src/Http/Requests/Grant')
        );

        $this->assertTrue($this->project->has('src/Exports/GrantsExports.php'));
    }

    /**
     * Grant se borra: la columna deleted_at tiene quien la escriba.
     */
    public function test_con_delete_se_conserva_soft_deletes(): void
    {
        $this->assertStringContainsString('SoftDeletes', $this->project->read('src/Models/Grant.php'));
        $this->assertStringContainsString('$table->softDeletes();', $this->migrationOf('grants'));
    }

    /**
     * Consent no se borra de ninguna forma: un deleted_at ahí sería una columna
     * que nadie puede escribir nunca.
     */
    public function test_sin_ninguna_forma_de_borrar_desaparece_soft_deletes(): void
    {
        $this->assertStringNotContainsString('SoftDeletes', $this->project->read('src/Models/Consent.php'));
        $this->assertStringNotContainsString('softDeletes', $this->migrationOf('consents'));
    }

    public function test_el_observer_solo_escucha_lo_que_puede_ocurrir(): void
    {
        $observer = $this->project->read('src/Observers/ConsentObserver.php');

        $this->assertStringContainsString('public function created(', $observer);

        foreach (['updated', 'deleted', 'restored', 'forceDeleted'] as $absent) {
            $this->assertStringNotContainsString("public function {$absent}(", $observer);
        }
    }

    public function test_immutable_conserva_crear(): void
    {
        $this->assertTrue($this->project->has('src/Http/Requests/Consent/CreateRequest.php'));
        $this->assertFalse($this->project->has('src/Http/Requests/Consent/UpdateRequest.php'));
    }

    /**
     * El modelo normal del mismo archivo no se contagia de los demás.
     */
    public function test_el_modelo_normal_conserva_sus_diez_acciones(): void
    {
        $this->assertCount(10, $this->filesIn('src/Http/Requests/Post'));
        $this->assertSame(10, substr_count($this->project->read('routes/api/models/post.php'), '->name('));
        $this->assertStringContainsString('SoftDeletes', $this->project->read('src/Models/Post.php'));
    }

    /**
     * Ningún marcador llega al proyecto.
     */
    public function test_no_queda_ningun_marcador_en_lo_generado(): void
    {
        $leaks = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project->path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), '@larapack:')) {
                $leaks[] = $file->getPathname();
            }
        }

        $this->assertSame([], $leaks);
    }

    /**
     * @return array<int, string>
     */
    private function filesIn(string $relative): array
    {
        $files = array_map('basename', glob($this->project->path . '/' . $relative . '/*.php') ?: []);

        sort($files);

        return $files;
    }

    private function migrationOf(string $table): string
    {
        $migration = $this->project->glob("database/migrations/*_create_{$table}_table.php");

        $this->assertNotNull($migration, "No se generó la migración de {$table}.");

        return $this->project->read($migration);
    }
}
