<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * La interfaz Vue y React de los endpoints declarados (SPEC 02, sección 7).
 *
 * Antes un endpoint generaba backend y test, y la pantalla no sabía que
 * existía: había que escribir a mano la llamada, el botón y la comprobación de
 * permisos, que es justo el código que se sale del contrato. Ahora cada
 * endpoint tiene su función en el contrato JS; los que cambian algo salen como
 * acción —en la barra de la tabla o en el menú de la fila y de la ficha—,
 * preguntan antes y se habilitan por la política. Los de `controllers[]` sólo
 * tienen su función: no hay pantalla que pintar.
 */
final class DeclaredEndpointsInterfaceTest extends TestCase
{
    private string $laraimport;

    private const VUE = 'resources/vue/src/models/course';

    private const REACT = 'resources/react/src/models/course';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\School\\'));

        $this->laraimport = $this->project->path.'/laraimport.json';

        file_put_contents($this->laraimport, json_encode([
            'models' => [
                ['name' => 'Course', 'props' => [['name' => 'title', 'type' => 'string', 'datatable' => true]], 'endpoints' => [
                    ['name' => 'publish', 'method' => 'post'],
                    ['name' => 'archive', 'method' => 'post', 'ability' => 'update'],
                    ['name' => 'importRoster', 'method' => 'post', 'target' => 'collection'],
                    ['name' => 'certificate', 'method' => 'get', 'uri' => '{course_id}/certificate', 'public' => true, 'middleware' => ['throttle:30,1']],
                    ['name' => 'purge', 'method' => 'delete', 'uri' => '{course_id}/purge'],
                    ['name' => 'moveTo', 'method' => 'patch', 'uri' => '{course_id}/move-to/{slot}'],
                ]],
                ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
            ],
            'controllers' => [
                ['name' => 'Desk', 'endpoints' => [
                    ['name' => 'overview', 'method' => 'get'],
                    ['name' => 'import', 'method' => 'post', 'uri' => 'import/{batch}', 'ability' => 'desk.import'],
                ]],
            ],
        ]));

        $this->import();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(array $options = ['--vue' => true, '--react' => true]): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $this->laraimport] + $options),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    public function test_el_contrato_tiene_una_funcion_por_endpoint_y_es_el_mismo_en_vue_y_react(): void
    {
        $contract = $this->project->read(self::VUE.'/index.js');

        $this->assertSame($contract, $this->project->read(self::REACT.'/index.js'));

        foreach (['publishModel', 'archiveModel', 'importRosterModel', 'certificateModel', 'purgeModel', 'moveToModel'] as $function) {
            $this->assertMatchesRegularExpression('/^export const '.$function.' = /m', $contract);
        }

        $this->assertJsParses($contract);
    }

    public function test_cada_funcion_llama_a_su_ruta_por_nombre_con_el_verbo_declarado(): void
    {
        $publish = $this->functionOf($this->project->read(self::VUE.'/index.js'), 'publishModel');

        // Escribe: token, sin reintentos, y el registro en el cuerpo porque la
        // URI no lo lleva.
        $this->assertStringContainsString("makeHttpRequest('post', route(API_ROUTE_PREFIX + 'publish'), {", $publish);
        $this->assertStringContainsString('_token: csrfToken(),', $publish);
        $this->assertStringContainsString('course_id: id,', $publish);
        $this->assertStringContainsString('}, {}, 0, 1500)', $publish);

        // Lee: sin token —acabaría en la URL— y con reintentos, como index.
        // El registro va en la URI, así que no se repite en la query.
        $certificate = $this->functionOf($this->project->read(self::VUE.'/index.js'), 'certificateModel');
        $this->assertStringContainsString("makeHttpRequest('get', route(API_ROUTE_PREFIX + 'certificate', parameters), {", $certificate);
        $this->assertStringContainsString('const parameters = { course_id: id }', $certificate);
        $this->assertStringNotContainsString('csrfToken', $certificate);
        $this->assertStringNotContainsString('confirm', $certificate);
        $this->assertStringContainsString('}, {}, 3, 1500)', $certificate);

        // Un parámetro de la URI sale de `data` por su nombre y no viaja
        // también en el cuerpo.
        $move = $this->functionOf($this->project->read(self::VUE.'/index.js'), 'moveToModel');
        $this->assertStringContainsString("route(API_ROUTE_PREFIX + 'move.to', parameters)", $move);
        $this->assertStringContainsString('const parameters = { course_id: id, slot: payload.slot }', $move);
        $this->assertStringContainsString('delete payload.slot', $move);

        $import = $this->functionOf($this->project->read(self::VUE.'/index.js'), 'importRosterModel');
        $this->assertStringContainsString("route(API_ROUTE_PREFIX + 'import.roster')", $import);
        $this->assertStringNotContainsString('course_id', $import);
    }

    public function test_lo_que_escribe_pregunta_antes_como_borrar(): void
    {
        $contract = $this->project->read(self::VUE.'/index.js');

        $publish = $this->functionOf($contract, 'publishModel');
        $this->assertStringContainsString('async (data = {}, { confirm = true } = {}) => {', $publish);
        $this->assertStringContainsString("title: t('Publish'),", $publish);
        $this->assertStringNotContainsString("variant: 'danger'", $publish);

        $this->assertStringContainsString("variant: 'danger',", $this->functionOf($contract, 'purgeModel'));

        // El diálogo del tema, el mismo de borrar.
        $this->assertStringContainsString('const confirmOrCancel = async (options) => {', $contract);
        $this->assertStringContainsString("import { confirmAction } from 'innoboxrr-form-core'", $contract);
    }

    public function test_los_de_coleccion_salen_en_la_barra_de_la_tabla(): void
    {
        $crud = $this->exportOf($this->project->read(self::VUE.'/index.js'), 'crudActions');

        $this->assertStringContainsString("id: 'importRoster',", $crud);
        $this->assertStringContainsString("name: t('Import roster'),", $crud);
        $this->assertStringContainsString("callback: 'importRosterModel',", $crud);

        // La barra no ofrece lo que es de un registro.
        $this->assertStringNotContainsString("'publish'", $crud);
    }

    public function test_los_de_registro_salen_en_la_ficha_y_en_cada_fila_de_la_tabla(): void
    {
        $record = $this->exportOf($this->project->read(self::VUE.'/index.js'), 'recordActions');

        foreach (['publish', 'archive', 'purge'] as $name) {
            $this->assertStringContainsString("id: '{$name}',", $record);
            $this->assertStringContainsString("callback: '{$name}Model',", $record);
        }

        // Un get devuelve datos que alguien tiene que pintar; un parámetro de
        // más en la URI no tiene de dónde salir. Ninguno es una acción.
        $this->assertStringNotContainsString("'certificate'", $record);
        $this->assertStringNotContainsString("'moveTo'", $record);

        // La tabla recibe las acciones por fila del Resource.
        $resource = $this->project->read('src/Http/Resources/Models/CourseResource.php');

        foreach (['publish', 'archive', 'purge'] as $name) {
            $this->assertStringContainsString("'id' => '{$name}',", $resource);
            $this->assertStringContainsString("'callback' => '{$name}Model',", $resource);
        }

        $this->assertStringNotContainsString("'certificate'", $resource);
        $this->assertStringNotContainsString("'moveTo'", $resource);
        $this->assertStringContainsString("'danger' => true,", $resource);

        $this->assertGeneratedPhpCompiles();
    }

    public function test_la_politica_decide_con_la_habilidad_que_pregunta_el_endpoint(): void
    {
        // `archive` pregunta por `update`: preguntar por `archive` lo negaba
        // siempre, porque la política no tiene ese método.
        $policies = $this->project->read('src/Http/Requests/Course/PoliciesRequest.php');

        $this->assertStringContainsString("'archive' => 'update',", $policies);
        $this->assertStringNotContainsString("'publish' =>", $policies);
    }

    public function test_la_ficha_vue_ofrece_las_acciones_habilitadas_por_la_politica(): void
    {
        $card = $this->project->read(self::VUE.'/widgets/ModelCard.vue');

        $this->assertStringContainsString("import * as model from '../index'", $card);
        $this->assertStringContainsString('model.getPolicies(id)', $card);
        $this->assertStringContainsString('model.recordActions().map((action) => ({', $card);
        $this->assertStringContainsString('disabled: allowed.value[action.id] !== true,', $card);
        $this->assertStringContainsString('...endpointItems.value,', $card);
        $this->assertStringContainsString('await store.fetchOne(id)', $card);
        $this->assertStringNotContainsString('@larapack:', $card);
    }

    public function test_la_ficha_react_ofrece_las_mismas_acciones(): void
    {
        $card = $this->project->read(self::REACT.'/widgets/ModelCard.jsx');

        $this->assertStringContainsString("import * as model from '../index'", $card);
        $this->assertStringContainsString('model.getPolicies(course.id)', $card);
        $this->assertStringContainsString('model.recordActions().map((action) => ({', $card);
        $this->assertStringContainsString('disabled: allowed[action.id] !== true,', $card);
        $this->assertStringContainsString('<ActionMenu items={[...actions, ...endpointItems]} label={t(\'Actions\')} />', $card);
        $this->assertSame(1, substr_count($card, '<ActionMenu'));
        $this->assertStringNotContainsString('@larapack:', $card);
    }

    public function test_un_modelo_sin_endpoints_no_cambia(): void
    {
        $this->assertStringNotContainsString('recordActions', $this->project->read('resources/vue/src/models/post/index.js'));
        $this->assertStringNotContainsString('recordActions', $this->project->read('resources/vue/src/models/post/widgets/ModelCard.vue'));
        $this->assertStringNotContainsString('endpointItems', $this->project->read('resources/react/src/models/post/widgets/ModelCard.jsx'));
        $this->assertStringNotContainsString("'callback' => 'publishModel'", $this->project->read('src/Http/Resources/Models/PostResource.php'));
    }

    public function test_los_textos_llegan_a_los_idiomas(): void
    {
        $es = json_decode($this->project->read('resources/vue/src/locales/es.json'), true);

        $this->assertSame('¿Seguro que quieres continuar?', $es['Are you sure you want to continue?']);
        $this->assertSame('La acción se completó.', $es['The action was completed.']);
        // El nombre del endpoint es del dominio: queda pendiente, a la vista.
        $this->assertSame('', $es['Import roster']);

        $lang = json_decode($this->project->read('lang/es.json'), true);
        $this->assertSame('La acción se completó.', $lang['The action was completed.']);
    }

    public function test_un_controlador_solo_tiene_su_contrato_sin_pantalla(): void
    {
        foreach (['vue', 'react'] as $framework) {
            $contract = $this->project->read("resources/{$framework}/src/controllers/desk/index.js");

            $this->assertStringContainsString("export const API_ROUTE_PREFIX = 'api.acme.school.desk.'", $contract);
            $this->assertMatchesRegularExpression('/^export const overviewEndpoint = \(data = \{\}\) => \{$/m', $contract);
            $this->assertMatchesRegularExpression('/^export const importEndpoint = \(data = \{\}\) => \{$/m', $contract);
            $this->assertStringContainsString('const parameters = { batch: payload.batch }', $contract);

            // Sin pantalla nadie lo lanza desde un menú: no pregunta.
            $this->assertStringNotContainsString('confirm', $contract);
            $this->assertJsParses($contract);

            $this->assertDirectoryDoesNotExist($this->project->path."/resources/{$framework}/src/models/desk");
        }
    }

    public function test_avisa_del_endpoint_que_la_interfaz_no_sabe_lanzar(): void
    {
        $this->assertStringContainsString('Course.moveTo lleva en la URI parámetros que la interfaz no sabe rellenar', $this->lastOutput);
        $this->assertStringNotContainsString('Course.certificate lleva', $this->lastOutput);
    }

    public function test_respeta_vue_y_react_por_separado(): void
    {
        $laraimport = (string) file_get_contents($this->laraimport);

        $this->project->cleanup();
        $this->useProject(FakeProject::library('Acme\\School\\'));

        $this->laraimport = $this->project->path.'/laraimport.json';
        file_put_contents($this->laraimport, $laraimport);

        $this->import(['--vue' => true]);

        $this->assertFileExists($this->project->path.'/resources/vue/src/controllers/desk/index.js');
        $this->assertDirectoryDoesNotExist($this->project->path.'/resources/react');
    }

    public function test_regenerar_dos_veces_no_cambia_nada(): void
    {
        $before = $this->snapshot();

        $this->import(['--vue' => true, '--react' => true, '--force' => true]);
        $this->import(['--vue' => true, '--react' => true, '--force' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_verify_echa_en_falta_la_funcion_del_contrato(): void
    {
        [, $report] = $this->verify();
        $this->assertSame([], $this->findingsOf($report, 'endpoint-missing'));
        $this->assertSame([], $this->findingsOf($report, 'route-prefix'));

        // Un contrato editado a mano no se regenera: el endpoint añadido
        // después llega sin función y la pantalla no lo ve.
        $file = $this->project->path.'/'.self::VUE.'/index.js';
        file_put_contents($file, str_replace('export const publishModel = ', 'const publishModel = ', (string) file_get_contents($file)));

        $controller = $this->project->path.'/resources/react/src/controllers/desk/index.js';
        file_put_contents($controller, str_replace("'api.acme.school.desk.'", "'api.acme.school.dashboard.'", (string) file_get_contents($controller)));

        [, $report] = $this->verify();

        $missing = $this->findingsOf($report, 'endpoint-missing');
        $this->assertCount(1, $missing);
        $this->assertSame(self::VUE.'/index.js', $missing[0]['file']);
        $this->assertStringContainsString('falta `export const publishModel`', $missing[0]['message']);
        $this->assertStringContainsString('editado a mano', $missing[0]['message']);

        $prefix = $this->findingsOf($report, 'route-prefix');
        $this->assertCount(1, $prefix);
        $this->assertSame('resources/react/src/controllers/desk/index.js', $prefix[0]['file']);
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];

        foreach (['resources', 'src/Http'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->project->path.'/'.$directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json']);

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function findingsOf(array $report, string $check): array
    {
        return array_values(array_filter($report['findings'], fn (array $finding): bool => $finding['check'] === $check));
    }

    /**
     * Del `export const <name> =` hasta la llave de cierre a principio de
     * línea.
     */
    private function functionOf(string $source, string $name): string
    {
        $this->assertMatchesRegularExpression('/^export const '.$name.' = .*?^\}$/ms', $source);
        preg_match('/^export const '.$name.' = .*?^\}$/ms', $source, $match);

        return $match[0];
    }

    private function exportOf(string $source, string $name): string
    {
        $this->assertMatchesRegularExpression('/^export const '.$name.' = \(\) => \[.*?^\]$/ms', $source);
        preg_match('/^export const '.$name.' = \(\) => \[.*?^\]$/ms', $source, $match);

        return $match[0];
    }

    private function assertJsParses(string $source): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        // Sin node no se comprueba nada: que conste como omitido y no como
        // pasado, o el test daría verde según la máquina.
        if ($node === '') {
            $this->markTestSkipped('node no disponible: no se puede comprobar que el JS generado parsea.');
        }

        $file = sys_get_temp_dir().'/larapack-js-'.bin2hex(random_bytes(6)).'.mjs';
        file_put_contents($file, $source);

        exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $output, $code);

        @unlink($file);

        $this->assertSame(0, $code, "El módulo JS generado no se puede analizar:\n".implode("\n", $output));
    }
}
