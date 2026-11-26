<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que sale de `endpoints` y de `controllers` (SPEC 02).
 *
 * Lo que el laraimport no sabía declarar se escribía a mano, y lo escrito a
 * mano fue donde se perdió el patrón del controlador que sólo llama a
 * `$request->handle()`. Aquí se comprueba que cada pieza sale con ese patrón y
 * que nada de lo que el proyecto rellena se pisa al regenerar.
 */
final class DeclaredEndpointsGenerationTest extends TestCase
{
    private string $laraimport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\School\\'));

        $this->laraimport = $this->project->path.'/laraimport.json';

        file_put_contents($this->laraimport, json_encode([
            'models' => [
                ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
                ['name' => 'Course', 'props' => [['name' => 'title', 'type' => 'string']], 'endpoints' => [
                    ['name' => 'publish', 'method' => 'post'],
                    ['name' => 'archive', 'method' => 'post', 'ability' => 'update'],
                    ['name' => 'importRoster', 'method' => 'post', 'target' => 'collection'],
                    ['name' => 'certificate', 'method' => 'get', 'uri' => '{course_id}/certificate', 'public' => true, 'middleware' => ['throttle:30,1']],
                ]],
            ],
            'controllers' => [
                ['name' => 'Desk', 'endpoints' => [['name' => 'overview', 'method' => 'get']]],
                ['name' => 'Oauth', 'endpoints' => [
                    ['name' => 'token', 'method' => 'post', 'public' => true, 'middleware' => ['throttle:6,1']],
                    ['name' => 'revoke', 'method' => 'post', 'ability' => 'oauth.revoke'],
                ]],
            ],
        ]));

        $this->import();
    }

    private function import(bool $force = false): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $this->laraimport] + ($force ? ['--force' => true] : [])),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    public function test_todo_el_php_generado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    public function test_el_controlador_solo_devuelve_la_request_de_cada_endpoint(): void
    {
        $controller = $this->project->read('src/Http/Controllers/CourseController.php');

        foreach (['publish' => 'PublishRequest', 'archive' => 'ArchiveRequest', 'importRoster' => 'ImportRosterRequest', 'certificate' => 'CertificateRequest'] as $method => $request) {
            $this->assertMatchesRegularExpression(
                '/public function '.$method.'\('.$request.' \$request\)\s*\{\s*return \$request->handle\(\);\s*\}/',
                $controller
            );
            // En el grupo `use ...\Course\{ ... }` del stub; Pint lo separa
            // después si el proyecto lo tiene.
            $this->assertMatchesRegularExpression('/^\s+'.$request.',$/m', $controller);
        }

        // Lo público sale de la sesión por su nombre, y sólo eso.
        $this->assertStringContainsString("new Middleware('auth:sanctum', except: ['certificate'])", $controller);
    }

    public function test_las_rutas_van_detras_de_las_crud_con_el_mismo_nombre_que_ellas(): void
    {
        $routes = $this->project->read('routes/api/models/course.php');

        $this->assertStringContainsString("Route::post('publish', [CourseController::class, 'publish'])", $routes);
        $this->assertStringContainsString("Route::post('import-roster', [CourseController::class, 'importRoster'])", $routes);
        $this->assertStringContainsString("->name('import.roster')", $routes);
        $this->assertMatchesRegularExpression(
            "/Route::get\\('\\{course_id\\}\\/certificate', \\[CourseController::class, 'certificate'\\]\\)\\s*->name\\('certificate'\\)\\s*->whereNumber\\('course_id'\\)\\s*->middleware\\(\\['throttle:30,1'\\]\\);/",
            $routes
        );
        $this->assertLessThan(strpos($routes, "'publish'"), strpos($routes, "->name('bulk.delete')"));
    }

    public function test_la_request_pregunta_a_la_politica_y_delega_en_la_operacion(): void
    {
        $publish = $this->project->read('src/Http/Requests/Course/PublishRequest.php');

        $this->assertStringContainsString("return \$this->user()->can('publish', \$course);", $publish);
        $this->assertStringContainsString("'course_id' => 'required|numeric'", $publish);
        $this->assertStringContainsString('return $course->publishModel($this);', $publish);

        $archive = $this->project->read('src/Http/Requests/Course/ArchiveRequest.php');
        $this->assertStringContainsString("can('update', \$course)", $archive);

        $import = $this->project->read('src/Http/Requests/Course/ImportRosterRequest.php');
        $this->assertStringContainsString("can('importRoster', Course::class)", $import);
        $this->assertStringContainsString('return (new Course)->importRosterModel($this);', $import);
        $this->assertStringNotContainsString('course_id', $import);

        // El identificador en la URI lo exige la ruta: rules() no ve los
        // parámetros de ruta y lo rechazaría siempre.
        $certificate = $this->project->read('src/Http/Requests/Course/CertificateRequest.php');
        $this->assertStringContainsString("Gate::forUser(\$this->user())->allows('certificate', \$course)", $certificate);
        $this->assertStringNotContainsString("'course_id' => 'required", $certificate);
    }

    public function test_la_politica_nace_negando_y_reutiliza_las_habilidades_crud(): void
    {
        $policy = $this->project->read('src/Policies/CoursePolicy.php');

        $this->assertMatchesRegularExpression('/public function publish\(User \$user, Course \$course\): Response\|bool\s*\{\s*return false;\s*\}/', $policy);
        $this->assertMatchesRegularExpression('/public function importRoster\(User \$user\): Response\|bool\s*\{\s*return false;\s*\}/', $policy);
        $this->assertMatchesRegularExpression('/public function certificate\(\?User \$user, Course \$course\): Response\|bool/', $policy);

        // archive pregunta por update: no hay método archive, ni dos update.
        $this->assertStringNotContainsString('function archive(', $policy);
        $this->assertSame(1, substr_count($policy, 'function update('));
    }

    public function test_la_operacion_nace_en_el_trait_y_avisa_si_no_se_escribe(): void
    {
        $operations = $this->project->read('src/Models/Traits/Operations/CourseOperations.php');

        foreach (['publishModel', 'archiveModel', 'importRosterModel', 'certificateModel'] as $operation) {
            $this->assertStringContainsString("public function {$operation}(\$request)", $operations);
            $this->assertStringContainsString("Course::{$operation}() no está implementada.", $operations);
        }
    }

    public function test_cada_endpoint_trae_su_test(): void
    {
        $publish = $this->project->read('tests/Feature/Endpoints/Course/PublishTest.php');
        $this->assertStringContainsString("route('api.acme.school.course.publish')", $publish);
        $this->assertStringContainsString('->assertUnauthorized()', $publish);

        $certificate = $this->project->read('tests/Feature/Endpoints/Course/CertificateTest.php');
        $this->assertStringContainsString("route('api.acme.school.course.certificate', ['course_id' => \$course->id])", $certificate);
        $this->assertStringContainsString('->assertForbidden()', $certificate);
    }

    public function test_un_modelo_sin_endpoints_no_cambia(): void
    {
        $this->assertStringNotContainsString('ENDPOINT_', $this->project->read('src/Http/Controllers/PostController.php'));
        $this->assertStringNotContainsString('ENDPOINT_', $this->project->read('src/Policies/PostPolicy.php'));
        $this->assertStringNotContainsString('ENDPOINT_', $this->project->read('routes/api/models/post.php'));
        $this->assertStringNotContainsString('ENDPOINT_', $this->project->read('src/Models/Traits/Operations/PostOperations.php'));
        $this->assertFalse($this->project->has('tests/Feature/Endpoints/Post'));
    }

    public function test_un_controlador_sin_modelo_no_genera_tabla_ni_politica(): void
    {
        $desk = $this->project->read('src/Http/Controllers/DeskController.php');

        $this->assertMatchesRegularExpression('/public function overview\(OverviewRequest \$request\)\s*\{\s*return \$request->handle\(\);\s*\}/', $desk);
        $this->assertStringContainsString("Route::get('overview', [DeskController::class, 'overview'])", $this->project->read('routes/api/models/desk.php'));

        $overview = $this->project->read('src/Http/Requests/Desk/OverviewRequest.php');
        $this->assertMatchesRegularExpression('/public function authorize\(\): bool\s*\{.*return false;\s*\}/s', $overview);
        $this->assertStringContainsString('abort(501', $overview);
        $this->assertStringNotContainsString('Models\\', $overview);

        $revoke = $this->project->read('src/Http/Requests/Oauth/RevokeRequest.php');
        $this->assertStringContainsString("return Gate::forUser(\$this->user())->allows('oauth.revoke');", $revoke);

        foreach (['src/Models/Desk.php', 'src/Policies/DeskPolicy.php', 'database/factories/DeskFactory.php', 'src/Http/Resources/Models/DeskResource.php'] as $absent) {
            $this->assertFalse($this->project->has($absent), "{$absent} no debería existir: Desk no es un modelo.");
        }

        $this->assertSame([], glob($this->project->path.'/database/migrations/*_desks_table.php'));
    }

    public function test_el_manifiesto_guarda_los_endpoints_para_verify(): void
    {
        $manifest = json_decode($this->project->read('.larapack/manifest.json'), true);

        $this->assertSame(['publish', 'archive', 'importRoster', 'certificate'], array_column($manifest['models']['Course']['declaration']['endpoints'], 'name'));
        $this->assertTrue($manifest['models']['Desk']['declaration']['controller']);
        $this->assertSame([], $manifest['models']['Desk']['declaration']['actions']);
        $this->assertArrayNotHasKey('declaration', $manifest['models']['Post']);
    }

    /**
     * Regenerar dos veces seguidas no cambia nada, y lo que el proyecto
     * rellenó —la request, la política, la operación— no se pisa.
     */
    public function test_regenerar_es_idempotente_y_respeta_los_huecos_rellenados(): void
    {
        $this->import(force: true);
        $before = $this->snapshot();

        $this->import(force: true);
        $this->assertSame($before, $this->snapshot(), 'Regenerar dos veces seguidas cambió archivos.');

        $request = $this->project->path.'/src/Http/Requests/Course/PublishRequest.php';
        $filled = str_replace("            //\n        ];", "            'notify' => 'boolean',\n        ];", file_get_contents($request));
        $this->assertNotSame(file_get_contents($request), $filled);
        file_put_contents($request, $filled);

        $policy = $this->project->path.'/src/Policies/CoursePolicy.php';
        $opened = preg_replace('/(function publish\(User \$user, Course \$course\): Response\|bool\s*\{\s*)return false;/', '$1return $user->getKey() === 1;', file_get_contents($policy));
        file_put_contents($policy, $opened);

        $operations = $this->project->path.'/src/Models/Traits/Operations/CourseOperations.php';
        $written = str_replace("throw new \\LogicException('Course::publishModel() no está implementada.');", 'return $this;', file_get_contents($operations));
        file_put_contents($operations, $written);

        $this->import(force: true);

        $this->assertSame($filled, file_get_contents($request));
        $this->assertSame($opened, file_get_contents($policy));
        $this->assertSame($written, file_get_contents($operations));
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project->path, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($this->project->path) + 1)] = md5_file($file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
