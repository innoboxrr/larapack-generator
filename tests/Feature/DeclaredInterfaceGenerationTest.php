<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

/**
 * Quitar una acción quita todo lo que cuelga de ella, en la interfaz.
 *
 *   AuditEvent  sólo se lee               only [policies, index, show]
 *   Grant       se otorga y se revoca     except [update, restore, forceDelete]
 *   Bare        el criterio del spec      only [index, show]
 */
final class DeclaredInterfaceGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Audit\\'));

        $path = $this->project->path . '/laraimport.json';

        file_put_contents($path, json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string', 'datatable' => true]]],
            ['name' => 'AuditEvent', 'props' => [['name' => 'action', 'type' => 'string', 'datatable' => true]], 'routes' => ['only' => ['policies', 'index', 'show']]],
            ['name' => 'Grant', 'props' => [['name' => 'scope', 'type' => 'string', 'form' => true, 'form_component' => 'TextInputComponent', 'form_submit' => true]], 'routes' => ['except' => ['update', 'restore', 'forceDelete']]],
            ['name' => 'Bare', 'props' => [['name' => 'name', 'type' => 'string']], 'routes' => ['only' => ['index', 'show']]],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true, '--react' => true]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function frameworks(): array
    {
        return [
            'vue' => ['vue', 'vue'],
            'react' => ['react', 'jsx'],
        ];
    }

    #[DataProvider('frameworks')]
    public function test_un_modelo_de_solo_lectura_no_trae_alta_ni_edicion(string $framework, string $ext): void
    {
        $module = "resources/{$framework}/src/models/audit-event";

        foreach (['views/AdminView', 'views/ShowView', 'widgets/DataTable', 'widgets/ModelCard', 'widgets/ModelProfile', 'forms/FilterForm'] as $present) {
            $this->assertTrue($this->project->has("{$module}/{$present}.{$ext}"), "Falta {$present}.");
        }

        foreach (['views/CreateView', 'views/EditView', 'forms/CreateForm', 'forms/EditForm'] as $absent) {
            $this->assertFalse($this->project->has("{$module}/{$absent}.{$ext}"), "Sobra {$absent}.");
        }

        $routes = $this->project->read("{$module}/routes/index.js");

        $this->assertStringContainsString("path: ':id'", $routes);
        $this->assertStringNotContainsString("path: 'create'", $routes);
        $this->assertStringNotContainsString("path: 'edit'", $routes);
    }

    #[DataProvider('frameworks')]
    public function test_la_tarjeta_solo_ofrece_lo_que_se_puede_hacer(string $framework, string $ext): void
    {
        $readOnly = $this->project->read("resources/{$framework}/src/models/audit-event/widgets/ModelCard.{$ext}");

        $this->assertStringNotContainsString('AdminEditAuditEvent', $readOnly);
        $this->assertStringNotContainsString("t('Delete')", $readOnly);
        $this->assertStringNotContainsString('remove', $readOnly);

        $grant = $this->project->read("resources/{$framework}/src/models/grant/widgets/ModelCard.{$ext}");

        $this->assertStringNotContainsString('AdminEditGrant', $grant);
        $this->assertStringContainsString("t('Delete')", $grant);
    }

    /**
     * En React las dependencias del useMemo nombran `remove` y `navigate`: sin
     * delete, esa línea tiene que ser otra o la tarjeta revienta al montarse.
     */
    public function test_las_dependencias_de_la_tarjeta_react_no_nombran_lo_que_no_existe(): void
    {
        $readOnly = $this->project->read('resources/react/src/models/audit-event/widgets/ModelCard.jsx');

        $this->assertStringContainsString('], [auditEvent.id])', $readOnly);
        $this->assertStringNotContainsString('navigate', $readOnly);

        $grant = $this->project->read('resources/react/src/models/grant/widgets/ModelCard.jsx');

        $this->assertStringContainsString('], [grant.id, remove, navigate])', $grant);
    }

    /**
     * El criterio de aceptación literal: el index.js no exporta create ni
     * update.
     */
    public function test_el_contrato_no_exporta_lo_que_no_esta_declarado(): void
    {
        $contract = $this->project->read('resources/vue/src/models/bare/index.js');

        foreach (['indexModel', 'showModel'] as $present) {
            $this->assertStringContainsString("export const {$present} =", $contract);
        }

        foreach (['createModel', 'updateModel', 'deleteModel', 'restoreModel', 'forceDeleteModel', 'exportModel', 'getPolicies', 'getPolicy'] as $absent) {
            $this->assertStringNotContainsString("export const {$absent} =", $contract);
        }

        $this->assertStringNotContainsString("id: 'create'", $contract);
        $this->assertStringNotContainsString("id: 'export'", $contract);

        // Y es el mismo archivo en los dos frameworks.
        $this->assertSame($contract, $this->project->read('resources/react/src/models/bare/index.js'));
    }

    /**
     * Sin policies no hay tabla —la tabla las consulta— y sin tabla no hay
     * vistas; el contrato y el store se generan igual.
     */
    #[DataProvider('frameworks')]
    public function test_sin_policies_solo_se_generan_el_contrato_y_el_store(string $framework, string $ext): void
    {
        $module = "resources/{$framework}/src/models/bare";

        $this->assertTrue($this->project->has("{$module}/index.js"));
        $this->assertTrue($this->project->has("{$module}/store/index.js"));
        $this->assertFalse($this->project->has("{$module}/routes/index.js"));
        $this->assertFalse($this->project->has("{$module}/views/AdminView.{$ext}"));
    }

    #[DataProvider('frameworks')]
    public function test_el_store_no_llama_a_funciones_que_el_contrato_no_exporta(string $framework, string $ext): void
    {
        $store = $this->project->read("resources/{$framework}/src/models/audit-event/store/index.js");

        foreach (['createModel', 'updateModel', 'deleteModel'] as $absent) {
            $this->assertStringNotContainsString($absent, $store);
        }

        foreach (['indexModel', 'showModel', 'getPolicies'] as $present) {
            $this->assertStringContainsString($present, $store);
        }
    }

    public function test_el_importador_avisa_de_lo_que_la_interfaz_no_puede_generar(): void
    {
        $this->assertStringContainsString('Bare tiene index pero no policies', $this->lastOutput);
    }

    /**
     * Una red barata para lo que estos tests no leen uno a uno: un bloque mal
     * delimitado casi siempre deja un corchete o una llave sin pareja.
     */
    public function test_ningun_archivo_de_interfaz_queda_con_delimitadores_desparejados(): void
    {
        $problems = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project->path . '/resources', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! in_array($file->getExtension(), ['js', 'jsx', 'vue'], true)) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            foreach (['{' => '}', '[' => ']', '(' => ')'] as $open => $close) {
                if (substr_count($content, $open) !== substr_count($content, $close)) {
                    $problems[] = basename(dirname($file->getPathname(), 2)) . '/' . basename(dirname($file->getPathname())) . '/' . $file->getFilename() . " {$open}{$close}";
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
