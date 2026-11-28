<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Innoboxrr\LarapackGenerator\Support\Realign\Apply\PhpMembers;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Command\Command;

/**
 * El realineamiento de punta a punta sobre los tres perfiles de proyecto que
 * documenta docs/realign/profiles.md, cada uno sintético pero con las formas
 * de un proyecto real (tests/Fixtures/realign-profiles, recortadas de las
 * aplicaciones, nunca las aplicaciones):
 *
 *  1. con contrato y manifiesto al día (school-management);
 *  2. con contrato de una versión vieja y deuda de controladores (expediente);
 *  3. sin contrato (brandai).
 *
 * Cada uno recorre el ciclo que sigue un agente sin contexto, sólo con lo que
 * dicen los comandos: diagnose → plan → apply determinista → adopt → next y
 * check de un paso guiado → except con la aprobación del dueño simulada →
 * verify --strict en verde, con exactamente lo declarado fuera de alcance.
 * Lo que un agente no decide (las decisiones, la aprobación) lo hace aquí el
 * «dueño», explícito en la prueba.
 */
final class RealignProfilesTest extends TestCase
{
    use RealignProject;

    private const FIXTURES = __DIR__.'/../Fixtures/realign-profiles';

    public function test_perfil_1_con_contrato_y_manifiesto_al_dia(): void
    {
        $this->generatedPackage();

        // Las desviaciones de school-management: lo formateado después de
        // generar (el manifiesto guarda el hash de antes), una plantilla de
        // una versión anterior que nadie editó, la deuda de `fix`, una request
        // editada fuera de su hueco y el closure de salud de la CI.
        file_put_contents($this->path('src/Http/Controllers/PostController.php'), str_replace('    ', "\t", $this->project->read('src/Http/Controllers/PostController.php')));
        $this->olderTemplate('src/Http/Requests/Post/ShowRequest.php', '        //', '        // la plantilla de antes');
        $this->removeMethod('src/Models/Traits/Relations/PostRelations.php', 'user');
        $editedRequest = 'src/Http/Requests/Post/DeleteRequest.php';
        $generatedRequest = $this->project->read($editedRequest);
        $this->edit($editedRequest, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (\$this->user()?->isAdmin()) {\n            return true;\n        }\n");
        $this->put('routes/web.php', (string) file_get_contents(self::FIXTURES.'/school-management/web.php'));
        $this->commitAll();

        $before = $this->cycleStart();
        $this->assertSame(['adopt@.', 'regenerate@.', 'fix:relation-missing@.'], $this->pending('deterministic'));
        $this->assertSame(['edited-outside:Requests@.'], $this->pending('guided'));
        // routes/web.php no lo conoce nadie (código sin gestionar) y su
        // closure no es el fallback de la SPA: dos decisiones.
        $this->assertSame(['route-closure@.', 'unmanaged-http@.'], $this->pending('decision'));

        // Lo determinista, en dos pasadas, como dice la guía.
        $this->applyDeterministic();
        $this->assertSame([], $this->pending('deterministic'));

        // El paso guiado: next da el playbook rellenado y check lo juzga.
        $next = $this->realign('next');
        $this->assertSame('edited-outside:Requests@.', $next['step']['id']);
        $this->assertSame('edited-outside', $next['how']['playbook']);
        $this->assertStringContainsString($editedRequest, $next['how']['instructions']);
        $this->assertStringContainsString('larapack:realign check edited-outside:Requests@.', $next['how']['instructions']);
        // La ruta exacta de lo regenerado, para compararlo sin buscarlo.
        $this->assertSame(1, preg_match('#regenerado: `(\.larapack/realign/cache/root/[0-9a-f]{16}/'.preg_quote($editedRequest, '#').')`#', $next['how']['instructions'], $regenerated));
        $this->assertFileExists($this->path($regenerated[1]));
        $this->assertFalse($this->realign('check', ['targets' => ['edited-outside:Requests@.']], Command::FAILURE)['done']);
        // El agente devuelve lo de fuera del hueco a su sitio (aquí: la
        // autorización vuelve a ser la de la política, como la generada).
        file_put_contents($this->path($editedRequest), $generatedRequest);
        $this->commitAll();
        $this->assertTrue($this->realign('check', ['targets' => ['edited-outside:Requests@.']])['done']);

        // La decisión: la toma el dueño, el agente pide la excepción, el
        // dueño la aprueba.
        $this->decideAndExcept('route-closure@.', 'except', 'La CI consulta la versión desplegada: sin datos de nadie.');
        $this->decideAndExcept('unmanaged-http@.', 'keep', 'routes/web.php sirve la SPA y la salud de la CI: no es del contrato.');

        $this->assertCompliant();
        $this->assertProgress($before, 'school-management');
    }

    public function test_perfil_2_con_contrato_de_una_version_vieja_y_deuda(): void
    {
        $this->generatedPackage();

        // Las de expediente (7.x): todo formateado después de generar, las
        // llaves en cascada que el contrato de 8.x calla, las pruebas de
        // endpoints de antes de 9.1, un modelo con sus listas atrasadas, un
        // trait de controlador que sólo usan requests, lo de un modelo que se
        // retiró del contrato y un controlador escrito a mano.
        foreach (['src/Http/Controllers/PostController.php', 'src/Http/Resources/Models/PostResource.php'] as $file) {
            file_put_contents($this->path($file), str_replace('    ', "\t", $this->project->read($file)));
        }

        $migration = $this->migration('create_posts_table');
        $this->edit($migration, "->onUpdate('restrict')->onDelete('restrict')", "->onUpdate('cascade')->onDelete('cascade')");
        $test = 'tests/Feature/Models/PostEndpointsTest.php';

        foreach (['test_post_cada_accion_requiere_sesion', 'test_post_cada_accion_pregunta_a_la_politica', 'test_post_index_pasa_por_su_managed_filter'] as $method) {
            $this->removeMethod($test, $method);
        }

        $this->edit($test, "use Innoboxrr\\SearchSurge\\Search\\Support\\FilterRegistry;\n", '');
        $generatedModel = $this->project->read('src/Models/Post.php');
        $this->edit('src/Models/Post.php', "protected \$fillable = [\n        'title', 'published', 'user_id'", "protected \$fillable = [\n        'title', 'user_id'");
        $this->retiredModel('StaffMember');
        $this->concernUsedOnlyByRequests();
        $controller = 'src/Http/Controllers/Clinic/CatalogCodesController.php';
        $this->put($controller, (string) file_get_contents(self::FIXTURES.'/expediente/CatalogCodesController.php'));
        $this->commitAll();

        $before = $this->cycleStart();
        $deterministic = $this->pending('deterministic');

        foreach (['prune@.', 'adopt@.', 'controller-concern@.', 'endpoint-tests@.', 'model-lists@.', 'pin-foreign-keys@.'] as $id) {
            $this->assertContains($id, $deterministic, "Falta el paso determinista {$id}.");
        }

        $this->assertContains('controller-shape@'.$controller, $this->pending('guided'));

        $this->applyDeterministic();
        $this->assertSame([], $this->pending('deterministic'));

        // Lo que hicieron: la cascada fijada en el contrato (la migración,
        // intacta), las pruebas añadidas, la lista completa, el trait movido.
        $this->assertStringContainsString('"on_delete": "cascade"', $this->project->read('laraimport.json'));
        $this->assertStringContainsString("->onDelete('cascade')", $this->project->read($migration));
        $this->assertStringContainsString('test_post_cada_accion_requiere_sesion', $this->project->read($test));
        $this->assertMatchesRegularExpression("/protected \\\$fillable = \\[[^\\]]*'published'/", $this->project->read('src/Models/Post.php'));
        $this->assertFileExists($this->path('src/Http/Requests/Concerns/ChecksClinic.php'));
        $this->assertFileDoesNotExist($this->path('src/Http/Controllers/Concerns/ChecksClinic.php'));

        // El controlador a mano: el paso guiado con su playbook y sus anexos.
        $id = 'controller-shape@'.$controller;
        $next = $this->nextGuided($id);
        $this->assertSame('controller-to-request', $next['how']['playbook']);
        $this->assertStringContainsString('validate()', $next['how']['instructions']);
        $this->assertStringContainsString('--path='.$controller, $next['how']['instructions']);
        $this->assertStringContainsString('403', $next['how']['instructions']);
        $this->assertFalse($this->realign('check', ['targets' => [$id]], Command::FAILURE)['done']);

        // El agente lleva la lógica a su FormRequest, como dice el playbook.
        $this->delegate('Acme\\Blog', $controller, 'Clinic', 'CatalogCodesController', 'IndexCatalogCodesRequest');
        $this->commitAll();
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);

        // model-lists completó la lista en el orden del contrato: el modelo
        // vuelve a ser el generado y no queda un paso guiado
        // `edited-outside:Model` que sólo difiera en el orden (antes lo
        // añadía al final y lo dejaba así).
        $this->assertSame($generatedModel, $this->project->read('src/Models/Post.php'));
        $this->assertNotContains('edited-outside:Model@.', $this->pending('guided'));

        // El controlador sigue fuera del contrato: el dueño decidió dejarlo así
        // antes de lo guiado (o declararlo, con su route_name, otro día). Hecho
        // lo guiado, la excepción se pide sobre el código ya delegado.
        $this->decideAndExcept('unmanaged-http@.', 'keep', 'El buscador del CIE-10 se queda a mano hasta declarar sus rutas.');

        $this->assertCompliant();
        $this->assertProgress($before, 'expediente');
    }

    public function test_perfil_3_sin_contrato(): void
    {
        $this->useProject(FakeProject::application());
        $this->copyDirectory(dirname(__DIR__).'/Fixtures/infer/sin-contrato', $this->project->path);
        $controller = 'app/Http/Controllers/WorkspaceMicroservicePreferenceController.php';
        $this->put($controller, (string) file_get_contents(self::FIXTURES.'/brandai/WorkspaceMicroservicePreferenceController.php'));
        $this->commitAll();

        $this->cycleStart();

        // Sin contrato no hay nada que regenerar ni adoptar: lo determinista
        // está vacío, y la herramienta lo dice en vez de inventar.
        $this->assertSame([], $this->pending('deterministic'));
        $this->assertStringContainsString('No hay pasos pendientes', $this->realign('apply', ['--deterministic' => true], Command::FAILURE)['error']);

        $this->assertContains('no-contract@.', $this->pending('decision'));
        $this->assertContains('controller-shape@'.$controller, $this->pending('guided'));

        // El material de la decisión, sin decidir nada: un borrador que valida
        // y no toca el código.
        $inferred = $this->realign('infer', ['targets' => ['no-contract@.']]);
        $this->assertSame('laraimport.inferred.json', $inferred['fixed'][0]['file']);
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:validate', ['jsonPath' => $this->path('laraimport.inferred.json'), '--format' => 'json']), $this->lastOutput);
        $this->assertFalse($this->project->has('laraimport.json'));
        $this->commitAll();

        // Aunque el proyecto se quede fuera de LaraPack, verify vigila la forma
        // de sus controladores: el guiado se hace igual.
        $id = 'controller-shape@'.$controller;
        $this->nextGuided($id);
        $this->delegate('App', $controller, '', 'WorkspaceMicroservicePreferenceController', 'UpdateMicroservicePreferenceRequest', 'update');
        $this->commitAll();
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);

        // El dueño decide dejarlo fuera: cada decisión, con su excepción
        // aprobada. Es exactamente lo que queda declarado fuera de alcance.
        foreach ($this->pending('decision') as $decision) {
            $this->decideAndExcept($decision, $this->keepOption($decision), 'brandai sigue fuera de LaraPack: se adopta paquete por paquete.');
        }

        // Un proyecto que se queda fuera de LaraPack no tiene manifiesto: el
        // único aviso que queda es ése, y es lo declarado fuera de alcance.
        $this->assertCompliant(['empty-manifest']);
        $this->assertNotSame([], $this->approvedExceptions(), 'Lo que queda fuera tiene que estar declarado.');
    }

    /**
     * diagnose y plan, con el estado de partida para comparar al final.
     *
     * @return array{conforming: int, files: int, findings: int}
     */
    private function cycleStart(): array
    {
        $diagnosis = $this->realign('diagnose');
        $this->realign('plan');

        $status = $this->realign('status');
        $this->assertNotNull($status['next'], 'Un proyecto desviado tiene un siguiente paso.');

        return [
            'conforming' => (int) $diagnosis['totals']['conforming'],
            'files' => (int) $diagnosis['totals']['files'],
            'findings' => (int) ($diagnosis['verify']['errors'] ?? 0) + (int) ($diagnosis['verify']['warnings'] ?? 0),
        ];
    }

    /**
     * Como dice la guía: apply --deterministic, diagnose y plan, y otra vez
     * hasta que no quede nada determinista (dos pasadas en los proyectos
     * ensayados). Cada pasada, simulada primero, comiteada después.
     */
    private function applyDeterministic(): void
    {
        for ($pass = 1; $pass <= 3 && $this->pending('deterministic') !== []; $pass++) {
            $simulated = $this->realign('apply', ['--deterministic' => true, '--dry-run' => true]);
            $this->assertTrue($simulated['dry_run']);

            $applied = $this->realign('apply', ['--deterministic' => true]);
            $this->assertSame([], $applied['skipped'], 'Nada determinista se salta en un proyecto con estas formas.');

            foreach ($applied['steps'] as $id) {
                $this->assertTrue($this->realign('check', ['targets' => [$id]])['done'], "{$id} no quedó hecho después de apply.");
            }

            $this->commitAll();
            $this->realign('diagnose');
            $this->realign('plan');
            $this->commitAll();
        }
    }

    /**
     * Lleva `next` hasta el paso guiado. Las decisiones que lo condicionan
     * (dejar o declarar el controlador escrito a mano) van antes: next las
     * ofrece y el dueño decide dejarlo como código propio; la excepción se
     * pide después, sobre el código ya llevado a su request.
     *
     * @return array<string, mixed>
     */
    private function nextGuided(string $id): array
    {
        $next = $this->realign('next');

        while ($next['step'] !== null && $next['step']['effective_status'] === Planner::AWAITING_DECISION && in_array($id, $this->dependentsOf($next['step']['id']), true)) {
            $this->realign('decide', ['targets' => [$next['step']['id'], $this->keepOption($next['step']['id'])], '--note' => 'El dueño: se queda como código propio.']);
            $next = $this->realign('next');
        }

        if (($next['step']['id'] ?? null) !== $id) {
            // Lo guiado va antes que las decisiones: si next no lo da es que
            // hay otro guiado delante, y eso sería un fallo del orden.
            Assert::fail("next da {$next['step']['id']} y no {$id}: ".json_encode(array_column($this->plan()['steps'], 'status', 'id')));
        }

        $this->assertSame('guided', $next['step']['category']);
        $this->assertSame('larapack:realign check '.$id, $next['check']);

        return $next;
    }

    /**
     * La decisión la toma el dueño; la excepción la pide quien trabaja (un
     * agente puede) y la aprueba el dueño.
     */
    private function decideAndExcept(string $id, string $option, string $reason): void
    {
        $next = $this->realign('next');
        $this->assertSame('decision', $next['step']['category'], "Antes de {$id} queda algo que no es una decisión: {$next['step']['id']}.");

        // Decidida ya antes de lo guiado que condicionaba: next la vuelve a
        // dar cuando lo guiado está hecho, para pedir la excepción.
        if (($this->step($id)['decision']['option'] ?? null) === $option) {
            $this->assertSame($id, $next['step']['id']);
            $this->assertStringStartsWith('larapack:realign except '.$id, (string) $next['how']['then']);
        } else {
            $this->assertContains($option, array_column($next['how']['options'] ?? [], 'key'));
            $this->realign('decide', ['targets' => [$id, $option], '--note' => 'El dueño: '.$reason]);
        }

        $this->realign('except', ['targets' => [$id], '--option' => ['reason='.$reason]]);

        // Pedida no cubre nada: hasta que el dueño la aprueba, el paso no está hecho.
        $this->assertFalse($this->realign('check', ['targets' => [$id]], Command::FAILURE)['done']);

        $this->ownerAtTerminal();
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:hand-rolled', ['action' => 'approve', '--all' => true, '--root' => $this->project->path, '--format' => 'json']), $this->lastOutput);
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done'], "{$id} no quedó hecho con la excepción aprobada.");
        $this->commitAll();
    }

    /**
     * Al final: el plan vacío después de volver a diagnosticar, y verify
     * --strict en verde, salvo exactamente lo que se declara fuera de alcance
     * (`$outOfScope`: las comprobaciones que un proyecto que se queda fuera de
     * LaraPack no puede cumplir, como `empty-manifest`).
     *
     * @param  list<string>  $outOfScope
     */
    private function assertCompliant(array $outOfScope = []): void
    {
        $this->realign('diagnose');
        $this->realign('plan');

        $open = array_values(array_filter($this->plan()['steps'], fn (array $step): bool => $step['status'] !== Planner::DONE));
        $this->assertSame([], array_column($open, 'id'), 'Al volver a diagnosticar no puede quedar nada pendiente.');
        $this->assertTrue($this->realign('next')['done']);

        $exit = $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--strict' => true, '--format' => 'json']);
        $report = json_decode($this->lastOutput, true);
        $failing = array_values(array_unique(array_column(array_filter($report['findings'], fn (array $finding): bool => $finding['level'] !== 'info'), 'check')));

        $this->assertSame($outOfScope, $failing, 'verify --strict: '.$this->lastOutput);
        $this->assertSame($outOfScope === [] ? Command::SUCCESS : Command::FAILURE, $exit);

        // El criterio de terminado, uno: `done` lo comprueba todo —también
        // validate --strict, que verify no mira: el agente corrige los avisos
        // del contrato— y sale con 0. Lo que queda fuera por decisión sale en
        // `declared`, no falta.
        if ($this->project->has('laraimport.json')) {
            $this->assertFalse($this->realign('done', [], Command::FAILURE)['done'], 'Con avisos en el contrato no está terminado.');
            $this->withoutIgnoredFlags();
            $this->commitAll();
        }

        $done = $this->realign('done');
        $this->assertTrue($done['done'], json_encode($done['missing'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame($outOfScope, array_values(array_unique(array_map(fn (array $declared): string => $declared['source'] === 'verify' ? 'empty-manifest' : 'no-contract', array_filter($done['declared'], fn (array $declared): bool => $declared['source'] === 'verify')))));

        if ($outOfScope !== []) {
            // Sin lo declarado fuera, la forma de todo el código cumple.
            $checks = array_values(array_diff(['controller-shape', 'request-handle', 'controller-concern', 'route-closure', 'hand-rolled'], $outOfScope));
            $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--strict' => true, '--check' => $checks]), $this->lastOutput);
        }
    }

    /**
     * @param  array{conforming: int, files: int, findings: int}  $before
     */
    private function assertProgress(array $before, string $profile): void
    {
        $after = $this->realign('diagnose');

        $this->assertGreaterThan($before['conforming'], $after['totals']['conforming'], "{$profile}: no subió lo que cumple.");
        $this->assertSame($after['totals']['files'], $after['totals']['conforming'], "{$profile}: queda algo que no cumple.");
    }

    /**
     * @return list<string>
     */
    private function approvedExceptions(): array
    {
        $this->runCommand('larapack:hand-rolled', ['action' => 'list', '--root' => $this->project->path, '--format' => 'json']);

        return array_values(array_column(array_filter(json_decode($this->lastOutput, true)['exceptions'] ?? [], fn (array $row): bool => ($row['status'] ?? null) === 'approved'), 'target'));
    }

    /**
     * La opción de «dejarlo como está» de una decisión: la que pide una excepción.
     */
    /**
     * @return list<string>
     */
    private function dependentsOf(string $id): array
    {
        return array_values(array_map(fn (array $step): string => $step['id'],
            array_filter($this->plan()['steps'], fn (array $step): bool => in_array($id, $step['depends_on'] ?? [], true))));
    }

    private function keepOption(string $id): string
    {
        foreach ($this->step($id)['options'] as $option) {
            if (str_starts_with((string) ($option['run'] ?? ''), 'larapack:realign except')) {
                return $option['key'];
            }
        }

        Assert::fail("{$id} no tiene una opción que deje las cosas como están.");
    }

    /**
     * @return list<string>
     */
    private function pending(string $category): array
    {
        return array_values(array_column(array_filter($this->plan()['steps'], fn (array $step): bool => $step['category'] === $category && $step['status'] !== Planner::DONE), 'id'));
    }

    /**
     * Lo que hace el agente en un paso `controller-shape`, siguiendo
     * controller-to-request: la request tipada con authorize(), rules() con lo
     * que validaba el controlador y handle() con lo demás; la acción, sólo
     * `return $request->handle();`.
     */
    private function delegate(string $namespace, string $controller, string $subnamespace, string $class, string $request, string $action = 'index'): void
    {
        $original = $this->project->read($controller);
        preg_match('/\$request->validate\((\[.*?\])\);/s', $original, $rules);
        $this->assertNotEmpty($rules, 'El controlador de la fixture valida con validate().');

        $base = str_starts_with($controller, 'app/') ? 'app' : 'src';
        $controllerNs = $namespace.'\\Http\\Controllers'.($subnamespace === '' ? '' : '\\'.$subnamespace);
        $requestNs = $namespace.'\\Http\\Requests'.($subnamespace === '' ? '' : '\\'.$subnamespace);
        $requestFile = $base.'/Http/Requests/'.($subnamespace === '' ? '' : $subnamespace.'/').$request.'.php';

        $this->put($requestFile, <<<PHP
            <?php

            namespace {$requestNs};

            use Illuminate\\Foundation\\Http\\FormRequest;
            use Illuminate\\Http\\JsonResponse;

            class {$request} extends FormRequest
            {
                public function authorize(): bool
                {
                    return \$this->user() !== null;
                }

                public function rules(): array
                {
                    return {$rules[1]};
                }

                public function handle(): JsonResponse
                {
                    return response()->json(['data' => \$this->validated()]);
                }
            }
            PHP);

        $parameters = $action === 'update' ? ', \\App\\Models\\Workspace $workspace, int $microserviceId' : '';

        $this->put($controller, <<<PHP
            <?php

            namespace {$controllerNs};

            use {$namespace}\\Http\\Controllers\\Controller;
            use {$requestNs}\\{$request};
            use Illuminate\\Http\\JsonResponse;

            class {$class} extends Controller
            {
                public function {$action}({$request} \$request{$parameters}): JsonResponse
                {
                    return \$request->handle();
                }
            }
            PHP);
    }

    /**
     * Un trait de Http/Controllers/Concerns que sólo usa una request, como los
     * que expediente tenía de antes de 9.0.
     */
    private function concernUsedOnlyByRequests(): void
    {
        $this->put('src/Http/Controllers/Concerns/ChecksClinic.php', <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Concerns;

            trait ChecksClinic
            {
                protected function clinicId(): int
                {
                    return (int) $this->header('X-Consultorio');
                }
            }
            PHP);

        $request = 'src/Http/Requests/Post/IndexRequest.php';
        $this->edit($request, "use Illuminate\\Foundation\\Http\\FormRequest;\n", "use Acme\\Blog\\Http\\Controllers\\Concerns\\ChecksClinic;\nuse Illuminate\\Foundation\\Http\\FormRequest;\n");
    }

    /**
     * Lo que queda en el manifiesto de un modelo que se retiró del contrato y
     * cuyos archivos se borraron (en expediente, StaffMember).
     */
    private function retiredModel(string $model): void
    {
        $this->changeManifest(function (array $manifest) use ($model): array {
            $post = $manifest['models']['Post']['files'];
            $files = [];

            foreach (['src/Policies/PostPolicy.php', 'src/Observers/PostObserver.php'] as $file) {
                $files[str_replace('Post', $model, $file)] = ['stub' => $post[$file]['stub'], 'hash' => str_repeat('0', 64)];
            }

            $manifest['models'][$model] = ['files' => $files] + array_diff_key($manifest['models']['Post'], ['files' => true]);

            return $manifest;
        });
    }

    /**
     * Deja un archivo como lo habría escrito una versión anterior: distinto,
     * y con el manifiesto diciendo que nadie lo editó.
     */
    private function olderTemplate(string $file, string $search, string $replace): void
    {
        $this->edit($file, $search, $replace);
        $hash = hash('sha256', $this->project->read($file));

        $this->changeManifest(function (array $manifest) use ($file, $hash): array {
            $manifest['models']['Post']['files'][$file]['hash'] = $hash;
            $manifest['models']['Post']['files'][$file]['generator'] = '8.0.0';

            return $manifest;
        });
    }

    private function removeMethod(string $file, string $method): void
    {
        $content = $this->project->read($file);
        $members = PhpMembers::of($content);
        $this->assertNotNull($members);
        $found = $members->methods[strtolower($method)];

        file_put_contents($this->path($file), substr($content, 0, $found['start']).ltrim(substr($content, $found['end']), "\n"));
    }

    private function migration(string $suffix): string
    {
        $found = glob($this->project->path.'/database/migrations/*_'.$suffix.'.php') ?: [];
        $this->assertCount(1, $found);

        return 'database/migrations/'.basename($found[0]);
    }

    private function put(string $file, string $content): void
    {
        if (! is_dir(dirname($this->path($file)))) {
            mkdir(dirname($this->path($file)), 0777, true);
        }

        file_put_contents($this->path($file), $content);
    }

    private function copyDirectory(string $from, string $to): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $target = $to.'/'.substr((string) $item, strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0777, true) : copy((string) $item, $target);
        }
    }

    private function path(string $file): string
    {
        return $this->project->path.'/'.$file;
    }
}
