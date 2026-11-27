<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:verify` contra los endpoints declarados (SPEC 02).
 *
 * Cada test parte de un proyecto recién generado y sin desviaciones, y hace a
 * mano lo que hicieron los agentes cuando el laraimport no sabía declarar un
 * endpoint: añadir un método al controlador, meterle lógica, escribir una
 * ruta. Y lo contrario: declarar un endpoint y dejarlo a medias.
 */
final class VerifyDeclaredEndpointsTest extends TestCase
{
    private string $laraimport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\School\\'));

        $this->laraimport = $this->project->path.'/laraimport.json';

        $this->write([
            ['name' => 'publish', 'method' => 'post'],
            ['name' => 'importRoster', 'method' => 'post', 'target' => 'collection'],
        ]);

        $this->import();
    }

    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     */
    private function write(array $endpoints): void
    {
        file_put_contents($this->laraimport, json_encode([
            'models' => [
                ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
                ['name' => 'Course', 'props' => [['name' => 'title', 'type' => 'string']], 'endpoints' => $endpoints],
            ],
            'controllers' => [
                ['name' => 'Desk', 'endpoints' => [['name' => 'overview', 'method' => 'get']]],
            ],
        ]));
    }

    private function import(bool $force = false): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $this->laraimport] + ($force ? ['--force' => true] : [])),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(bool $strict = false): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json'] + ($strict ? ['--strict' => true] : []));

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function findingsOf(array $report, string $check): array
    {
        return array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === $check));
    }

    private function edit(string $relative, callable $change): void
    {
        $file = $this->project->path.'/'.$relative;

        $changed = $change((string) file_get_contents($file));

        $this->assertNotSame(file_get_contents($file), $changed, "La edición de {$relative} no cambió nada: el test no probaría lo que dice.");

        file_put_contents($file, $changed);
    }

    public function test_un_proyecto_recien_generado_con_endpoints_no_tiene_desviaciones(): void
    {
        [$code, $report] = $this->verify(strict: true);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->findingsOf($report, 'inconsistent-entity'));
    }

    // ENDPOINT-MISSING

    public function test_un_endpoint_sin_su_ruta_falla(): void
    {
        $this->edit('routes/api/models/course.php', fn (string $php): string => preg_replace(
            "/\nRoute::post\\('publish'.*?;\n/s",
            "\n",
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'endpoint-missing');
        $this->assertCount(1, $findings);
        $this->assertSame('Course', $findings[0]['model']);
        $this->assertStringContainsString("ruta 'publish'", $findings[0]['message']);
    }

    public function test_un_endpoint_sin_su_operacion_dice_que_metodo_escribir(): void
    {
        $this->edit('src/Models/Traits/Operations/CourseOperations.php', fn (string $php): string => str_replace('function publishModel(', 'function renamedModel(', $php));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'endpoint-missing');
        $this->assertCount(1, $findings);
        $this->assertSame('src/Models/Traits/Operations/CourseOperations.php', $findings[0]['file']);
        $this->assertStringContainsString('Course::publishModel($request)', $findings[0]['message']);
    }

    /**
     * El caso de verdad: el trait y la política ya existían, son código del
     * proyecto y el generador no escribe en ellos. Un endpoint declarado
     * después llega sin su habilidad ni su operación, y verify lo dice.
     */
    public function test_un_endpoint_declarado_despues_avisa_de_lo_que_el_proyecto_tiene_que_escribir(): void
    {
        $this->edit('src/Policies/CoursePolicy.php', fn (string $php): string => str_replace(
            'public function index(User $user): Response|bool',
            "// Escrita por el proyecto.\n    public function index(User \$user): Response|bool",
            $php
        ));

        $this->write([
            ['name' => 'publish', 'method' => 'post'],
            ['name' => 'importRoster', 'method' => 'post', 'target' => 'collection'],
            ['name' => 'archive', 'method' => 'post'],
        ]);

        $this->import(force: true);

        $this->assertStringContainsString('function archive(ArchiveRequest', $this->project->read('src/Http/Controllers/CourseController.php'));
        $this->assertTrue($this->project->has('src/Http/Requests/Course/ArchiveRequest.php'));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $messages = array_column($this->findingsOf($report, 'endpoint-missing'), 'message', 'file');

        $this->assertStringContainsString('public function archive(User $user, Course $course): Response|bool', $messages['src/Policies/CoursePolicy.php']);
        $this->assertStringContainsString('Course::archiveModel($request)', $messages['src/Models/Traits/Operations/CourseOperations.php']);
    }

    public function test_un_endpoint_sin_su_metodo_en_el_controlador_falla(): void
    {
        $this->edit('src/Http/Controllers/DeskController.php', fn (string $php): string => preg_replace(
            '/\n    public function overview\(.*?\n    \}\n/s',
            "\n",
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'endpoint-missing');
        $this->assertCount(1, $findings);
        $this->assertSame('Desk', $findings[0]['model']);
    }

    // ROUTE-NOT-DECLARED

    /**
     * Lo que pasó en el sistema real: un constructor que inyecta un servicio y
     * un __invoke con la lógica dentro.
     */
    public function test_un_metodo_escrito_a_mano_en_el_controlador_falla(): void
    {
        $this->edit('src/Http/Controllers/DeskController.php', fn (string $php): string => preg_replace(
            '/\n}\s*$/',
            "\n    public function __invoke(\\Illuminate\\Http\\Request \$request)\n    {\n        \$data = \$request->validate(['scope_id' => 'required']);\n\n        return response()->json(\$data);\n    }\n}\n",
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $undeclared = $this->findingsOf($report, 'route-not-declared');
        $this->assertCount(1, $undeclared);
        $this->assertStringContainsString('__invoke', $undeclared[0]['message']);
        $this->assertStringContainsString('a mano', $undeclared[0]['message']);

        // Que no delegue lo dice controller-shape, una vez.
        $shape = $this->findingsOf($report, 'controller-shape');
        $this->assertCount(1, $shape);
        $this->assertStringContainsString('__invoke()', $shape[0]['message']);
    }

    public function test_una_ruta_escrita_a_mano_falla_tenga_o_no_nombre(): void
    {
        $this->edit('routes/api/models/desk.php', fn (string $php): string => $php
            ."\nRoute::get('stats', [DeskController::class, 'overview'])\n\t->name('stats');\n"
            ."\nRoute::get('raw', [DeskController::class, 'overview']);\n");

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $messages = array_column($this->findingsOf($report, 'route-not-declared'), 'message');

        $this->assertCount(2, $messages);
        $this->assertStringContainsString("'stats'", $messages[0]);
        $this->assertStringContainsString('sin nombre', $messages[1]);
    }

    public function test_una_request_que_nadie_declaro_falla(): void
    {
        file_put_contents(
            $this->project->path.'/src/Http/Requests/Course/StatsRequest.php',
            "<?php\n\nnamespace Acme\\School\\Http\\Requests\\Course;\n\nclass StatsRequest {}\n"
        );

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'route-not-declared');
        $this->assertCount(1, $findings);
        $this->assertSame('src/Http/Requests/Course/StatsRequest.php', $findings[0]['file']);
    }

    // FULL-MODEL SUELTO

    /**
     * larapack:full-model no sabe declarar endpoints: los toma del manifiesto.
     * Antes anotaba el modelo sin ellos y, con --force, regeneraba el
     * controlador y las rutas sin sus métodos: los endpoints daban 404.
     */
    public function test_full_model_suelto_conserva_los_endpoints_ya_declarados(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Course', '--force' => true]), $this->lastOutput);

        $this->assertStringContainsString("->name('publish')", $this->project->read('routes/api/models/course.php'));
        $this->assertStringContainsString("->name('import.roster')", $this->project->read('routes/api/models/course.php'));
        $this->assertStringContainsString('function publish(PublishRequest', $this->project->read('src/Http/Controllers/CourseController.php'));

        $this->assertSame(['publish', 'importRoster'], array_column((new Manifest($this->project->path))->declarationOf('Course')['endpoints'], 'name'));

        [$code, $report] = $this->verify(strict: true);

        // Lo único que queda es lo que full-model suelto no sabe hacer: no lee
        // el laraimport, así que el modelo regenerado sale con las listas
        // vacías. Desde 9.3.1 verify lo dice (model-lists-outdated) porque el
        // proyecto tiene laraimport.json; larapack:import --force lo completa.
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['model-lists-outdated'], array_values(array_unique(array_column($report['findings'], 'check'))), json_encode($report['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString("\$fillable: 'title'", $report['findings'][0]['message']);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--force' => true]), $this->lastOutput);
        [$code, $report] = $this->verify(strict: true);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_full_model_suelto_tambien_con_opciones_de_forma(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Course', '--except' => 'export', '--force' => true]), $this->lastOutput);

        $declaration = (new Manifest($this->project->path))->declarationOf('Course');

        $this->assertNotContains('export', $declaration['actions']);
        $this->assertSame(['publish', 'importRoster'], array_column($declaration['endpoints'], 'name'));
    }

    public function test_full_model_no_le_crea_un_modelo_a_un_controlador_sin_modelo(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:full-model', ['name' => 'Desk', '--force' => true]));
        $this->assertStringContainsString('controllers[]', $this->lastOutput);
        $this->assertFalse($this->project->has('src/Models/Desk.php'));
        $this->assertTrue((new Manifest($this->project->path))->declarationOf('Desk')['controller']);
    }

    // ENDPOINTS RETIRADOS DEL LARAIMPORT

    /**
     * Quitar un endpoint del laraimport y volver a importar sin --force deja
     * lo que se generó para él. verify lo encuentra, y no culpa de escribirlo
     * a mano a quien no tocó nada.
     */
    public function test_un_endpoint_retirado_no_se_atribuye_a_mano(): void
    {
        $this->write([['name' => 'importRoster', 'method' => 'post', 'target' => 'collection']]);
        $this->import();

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $messages = array_column($this->findingsOf($report, 'route-not-declared'), 'message', 'file');

        $this->assertArrayHasKey('src/Http/Controllers/CourseController.php', $messages);
        $this->assertArrayHasKey('routes/api/models/course.php', $messages);
        $this->assertArrayHasKey('src/Http/Requests/Course/PublishRequest.php', $messages);

        foreach ($messages as $file => $message) {
            $this->assertStringContainsString('ya no está declarado', $message, $file);
            $this->assertStringNotContainsString('a mano', $message, $file);
        }

        // Con --force el controlador y las rutas se rehacen sin él; la
        // request es del proyecto y se queda hasta que alguien la borre.
        $this->import(force: true);

        [, $report] = $this->verify();

        $findings = $this->findingsOf($report, 'route-not-declared');
        $this->assertCount(1, $findings);
        $this->assertSame('src/Http/Requests/Course/PublishRequest.php', $findings[0]['file']);
        $this->assertStringContainsString('ya no está declarado', $findings[0]['message']);
    }

    /**
     * Si el controlador está editado a mano, --force lo conserva: mandar a
     * regenerar sería mandar a un comando que no hace nada.
     */
    public function test_regenerar_no_se_ofrece_sobre_un_controlador_editado(): void
    {
        $this->edit('src/Http/Controllers/CourseController.php', fn (string $php): string => preg_replace(
            '/\n    public function publish\(.*?\n    \}\n/s',
            "\n",
            $php
        ));

        [, $report] = $this->verify();

        $findings = array_values(array_filter(
            $this->findingsOf($report, 'endpoint-missing'),
            fn (array $finding): bool => $finding['file'] === 'src/Http/Controllers/CourseController.php'
        ));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Regenerar no basta', $findings[0]['message']);
    }

    // LÓGICA EN EL CONTROLADOR: ES DE CONTROLLER-SHAPE

    /**
     * Un solo dueño para "el controlador hace algo más que
     * `return $request->handle();`": controller-shape, que mira todos los
     * controladores. Antes el generado daba además un controller-logic con
     * otra regla, y el mismo problema salía dos veces.
     */
    public function test_logica_en_un_metodo_generado_falla_una_vez(): void
    {
        $this->edit('src/Http/Controllers/CourseController.php', fn (string $php): string => str_replace(
            "public function publish(PublishRequest \$request)\n    {\n        return \$request->handle();",
            "public function publish(PublishRequest \$request)\n    {\n        abort_unless(\$request->user()->isAdmin(), 403);\n\n        return \$request->handle();",
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $this->findingsOf($report, 'controller-logic'));

        $findings = $this->findingsOf($report, 'controller-shape');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('publish()', $findings[0]['message']);
        $this->assertSame('src/Http/Controllers/CourseController.php', $findings[0]['file']);
    }

    public function test_tambien_en_una_accion_crud(): void
    {
        $this->edit('src/Http/Controllers/PostController.php', fn (string $php): string => str_replace(
            "public function index(IndexRequest \$request)\n    {\n        return \$request->handle();",
            "public function index(IndexRequest \$request)\n    {\n        return \\Acme\\School\\Models\\Post::query()->where('title', \$request->input('q'))->get();",
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $this->findingsOf($report, 'controller-logic'));
        $this->assertCount(1, $this->findingsOf($report, 'controller-shape'));
        $this->assertSame('src/Http/Controllers/PostController.php', $this->findingsOf($report, 'controller-shape')[0]['file']);
    }

    public function test_check_acepta_endpoint_missing_y_ya_no_controller_logic(): void
    {
        $this->assertContains('endpoint-missing', Verifier::CHECKS);
        $this->assertContains('route-not-declared', Verifier::CHECKS);
        $this->assertNotContains('controller-logic', Verifier::CHECKS);

        $this->edit('routes/api/models/course.php', fn (string $php): string => preg_replace(
            "/\nRoute::post\\('publish'.*?;\n/s",
            "\n",
            $php
        ));

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:verify', ['--format' => 'json', '--check' => ['endpoint-missing']]));

        $report = json_decode($this->lastOutput, true);

        $this->assertSame(['endpoint-missing'], array_values(array_unique(array_column($report['findings'], 'check'))));
    }
}
