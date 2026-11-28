<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Las excepciones del realineamiento: lo que no puede llevar #[HandRolled]
 * —los closures de rutas legítimos (el CSP, el OPTIONS del CORS), lo escrito a
 * mano que se deja fuera, un hallazgo que se acepta— se pide con
 * `larapack:realign except` y lo aprueba el dueño con `larapack:hand-rolled
 * approve`, en el mismo candado y con la huella de lo que cubre.
 *
 * Las formas son las de school-management (`/csp-report`, el `OPTIONS` del
 * CORS de xAPI) y expediente (`/ci/test-health`).
 */
final class RealignExceptionsTest extends TestCase
{
    use RealignProject;

    private const ROUTES = 'routes/web.php';

    private const CSP = "realign:route-closure:routes/web.php#post('/csp-report')";

    private const CORS = "realign:route-closure:routes/web.php#options('/xapi/{any}')";

    private const HEALTH = "realign:route-closure:routes/web.php#get('/ci/test-health')";

    private const CSP_REASON = 'Recibe los informes de CSP del navegador: sin datos de nadie y sin sesión.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        @mkdir($this->project->path.'/routes', 0777, true);
        $this->routes();
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
    }

    private function routes(string $csp = 'logger()->warning(\'csp\', $request->all());'): void
    {
        file_put_contents($this->project->path.'/'.self::ROUTES, <<<PHP
            <?php

            use Illuminate\\Http\\Request;
            use Illuminate\\Support\\Facades\\Route;

            Route::post('/csp-report', function (Request \$request) {
                {$csp}

                return response()->noContent();
            })->name('csp.report');

            Route::options('/xapi/{any}', fn () => response('', 204))->where('any', '.*');

            Route::get('/ci/test-health', fn () => response()->json(['ok' => true]));
            PHP);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function owner(string $action, array $arguments = [], int $expected = Command::SUCCESS): array
    {
        $exit = $this->runCommand('larapack:hand-rolled', ['action' => $action, '--root' => $this->project->path, '--format' => 'json'] + $arguments);
        $this->assertSame($expected, $exit, $this->lastOutput);

        return json_decode($this->lastOutput, true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(array $arguments = []): array
    {
        $exit = $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--format' => 'json', '--check' => ['route-closure', 'hand-rolled-stale']] + $arguments);

        return [$exit, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function closures(array $report): array
    {
        return array_values(array_filter($report['findings'], fn (array $finding): bool => $finding['check'] === 'route-closure'));
    }

    public function test_los_closures_salen_como_una_decision_con_su_playbook(): void
    {
        $step = $this->step('route-closure@.');

        $this->assertSame('decision', $step['category']);
        $this->assertSame('decide-route-closure', $step['playbook']);
        $this->assertSame('closure-to-controller', $step['options'][1]['playbook']);

        $next = $this->realign('next');
        $this->assertSame('route-closure@.', $next['step']['id']);
        $this->assertSame('decide-route-closure', $next['how']['playbook']);
        $this->assertStringContainsString('/csp-report', $next['how']['instructions']);
        $this->assertStringContainsString('larapack:realign except route-closure@. --option reason=', $next['how']['instructions']);
        $this->assertStringNotContainsString('{{', $next['how']['instructions']);
    }

    public function test_pedir_no_es_aprobar_y_el_dueno_aprueba_cada_closure_por_su_huella(): void
    {
        // Sin razón no se pide nada.
        $missing = $this->realign('except', ['targets' => ['route-closure@.']], Command::FAILURE);
        $this->assertStringContainsString('reason', $missing['error']);

        $dry = $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason='.self::CSP_REASON], '--dry-run' => true]);
        $this->assertSame([self::HEALTH, self::CORS, self::CSP], $this->sorted(array_column($dry['fixed'], 'target')));
        $this->assertFileDoesNotExist($this->project->path.'/.larapack/realign/exceptions.json');

        $requested = $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason='.self::CSP_REASON]]);
        $this->assertCount(3, $requested['fixed']);
        $this->assertStringContainsString('hand-rolled approve', $requested['notes'][0]);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH, 'Pedir no escribe el candado.');

        // Pedir otra vez lo mismo no cambia nada.
        $again = $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason='.self::CSP_REASON]]);
        $this->assertSame([], $again['fixed']);
        $this->assertCount(3, $again['unchanged']);

        // Solicitada no cubre: verify --strict sigue fallando y el paso, sin hacer.
        [$exit, $report] = $this->verify(['--strict' => true]);
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(['warning', 'warning', 'warning'], array_column($this->closures($report), 'level'));

        $this->realign('decide', ['targets' => ['route-closure@.', 'except'], '--note' => 'CSP, CORS y la salud son legítimas.']);
        $check = $this->realign('check', ['targets' => ['route-closure@.']], Command::FAILURE);
        $this->assertStringContainsString('excepción solicitada y sin aprobar', implode("\n", $check['remaining']));

        // El dueño las lista y las aprueba (no un agente: ver la guardia).
        $list = $this->owner('list');
        $rows = array_column(array_filter($list['exceptions'], fn (array $row): bool => $row['realign'] ?? false), 'status', 'target');
        $this->assertSame([self::HEALTH => 'unapproved', self::CORS => 'unapproved', self::CSP => 'unapproved'], $rows);

        $approved = $this->owner('approve', ['targets' => ["routes/web.php#post('/csp-report')"]]);
        $this->assertSame([self::CSP], array_column($approved['targets'], 'target'));
        $this->owner('approve', ['--all' => true]);

        $lock = json_decode((string) file_get_contents($this->project->path.'/'.HandRolledLock::PATH), true);
        $this->assertSame([self::HEALTH, self::CORS, self::CSP], array_column($lock, 'target'));
        $this->assertSame(self::CSP_REASON, $lock[2]['reason']);

        // Aprobadas: verify --strict pasa y las lista como info.
        [$exit, $report] = $this->verify(['--strict' => true]);
        $this->assertSame(Command::SUCCESS, $exit, json_encode($report));
        $this->assertSame(['info', 'info', 'info'], array_column($this->closures($report), 'level'));
        $this->assertStringContainsString('Excepción aprobada por el dueño del proyecto', $this->closures($report)[0]['message']);
        $this->assertSame(['approved', 'approved', 'approved'], array_column($report['exceptions'], 'status'));

        $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--strict' => true, '--check' => ['route-closure']]);
        $this->assertStringContainsString('Excepciones del realineamiento en .larapack/hand-rolled.lock: 3 aprobadas sobre el código de hoy', $this->lastOutput);
        $this->assertStringContainsString('escrito así a propósito: «'.self::CSP_REASON.'»', $this->lastOutput);

        $this->assertTrue($this->realign('check', ['targets' => ['route-closure@.']])['done']);

        // Y lo que cubre no vuelve al plan.
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::DONE, $this->step('route-closure@.')['status']);
    }

    public function test_si_el_closure_cambia_la_aprobacion_caduca_y_si_desaparece_sobra(): void
    {
        $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason='.self::CSP_REASON]]);
        $this->owner('approve', ['--all' => true]);
        $this->assertSame(Command::SUCCESS, $this->verify(['--strict' => true])[0]);

        // Reformatear o comentar no la cambia.
        $this->routes("// Sólo se registra.\n    logger()->warning('csp',\n        \$request->all());");
        $this->assertSame(Command::SUCCESS, $this->verify(['--strict' => true])[0], $this->lastOutput);

        // Cambiar el código, sí: vuelve a avisar diciendo por qué.
        $this->routes('logger()->warning(\'csp\', $request->all()); cache()->put(\'csp\', $request->all());');
        [$exit, $report] = $this->verify(['--strict' => true]);
        $this->assertSame(Command::FAILURE, $exit);
        $csp = array_values(array_filter($this->closures($report), fn (array $finding): bool => $finding['level'] === 'warning'));
        $this->assertCount(1, $csp);
        $this->assertStringContainsString('era de otro código', $csp[0]['message']);
        $this->assertSame('code-changed', array_column($this->owner('list')['exceptions'], 'status', 'target')[self::CSP]);

        // El dueño la vuelve a aprobar sobre el código nuevo.
        $this->owner('approve', ['targets' => ["post('/csp-report')"]]);
        $this->assertSame(Command::SUCCESS, $this->verify(['--strict' => true])[0]);

        // Si la ruta deja de ser un closure, lo aprobado sobra.
        file_put_contents($this->project->path.'/'.self::ROUTES, str_replace(
            "Route::options('/xapi/{any}', fn () => response('', 204))->where('any', '.*');",
            "Route::view('/xapi', 'xapi');",
            (string) file_get_contents($this->project->path.'/'.self::ROUTES)
        ));
        [$exit, $report] = $this->verify(['--strict' => true]);
        $this->assertSame(Command::FAILURE, $exit);
        $stale = array_values(array_filter($report['findings'], fn (array $finding): bool => $finding['check'] === 'hand-rolled-stale'));
        $this->assertCount(1, $stale);
        $this->assertStringContainsString(self::CORS, $stale[0]['message']);

        $this->owner('revoke', ['targets' => ["options('/xapi/{any}')"]]);
        $this->assertSame(Command::SUCCESS, $this->verify(['--strict' => true])[0]);
    }

    public function test_un_hallazgo_aceptado_de_otra_comprobacion_tambien_se_exceptua(): void
    {
        // Un método a mano en el controlador generado: route-not-declared.
        $this->edit('src/Http/Controllers/PostController.php', '    public function index(', "    public function stats(\\Acme\\Blog\\Http\\Requests\\Post\\IndexRequest \$request)\n    {\n        return \$request->handle();\n    }\n\n    public function index(");
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');

        $step = $this->step('declared-routes@.');
        $this->assertSame('guided', $step['category']);

        $this->realign('except', ['targets' => ['declared-routes@.'], '--option' => ['reason=La usa un tablero interno; se declara en la próxima versión.']]);
        $target = 'realign:declared-routes/route-not-declared:src/Http/Controllers/PostController.php';
        $this->assertArrayHasKey($target, Exceptions::forRoot($this->project->path)->requests());

        $strict = fn (): int => $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--format' => 'json', '--strict' => true, '--check' => ['route-not-declared']]);
        $this->assertSame(Command::FAILURE, $strict());

        $this->owner('approve', ['targets' => [$target]]);
        $this->assertSame(Command::SUCCESS, $strict(), $this->lastOutput);
        $finding = json_decode($this->lastOutput, true)['findings'][0];
        $this->assertSame('info', $finding['level']);
        $this->assertStringContainsString($target, $finding['message']);

        // La guardia de los agentes tampoco lo cuenta como error.
        $this->assertSame([], Guard::errorsFor(Guard::findings($this->project->path, ['route-not-declared'])));

        $this->assertTrue($this->realign('check', ['targets' => ['declared-routes@.']])['done']);
    }

    public function test_un_agente_puede_pedir_pero_no_aprobar(): void
    {
        $command = fn (string $command): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $command], 'cwd' => $this->project->path];

        $this->assertFalse(Guard::approvesHandRolled($command('vendor/bin/builder larapack:realign except route-closure@. --option reason="CSP"')));
        $this->assertTrue(Guard::approvesHandRolled($command("vendor/bin/builder larapack:hand-rolled approve \"routes/web.php#post('/csp-report')\"")));
        $this->assertTrue(Guard::approvesHandRolled($command('php artisan larapack:hand-rolled approve --all')));
        $this->assertNotSame([], Guard::touchedLocks(['tool_name' => 'Write', 'tool_input' => ['file_path' => $this->project->path.'/'.HandRolledLock::PATH]]));
        $this->assertSame([], Guard::touchedLocks(['tool_name' => 'Write', 'tool_input' => ['file_path' => $this->project->path.'/.larapack/realign/exceptions.json']]));
    }

    public function test_dejar_fuera_un_ambito_sin_contrato_vale_mientras_no_tenga_contrato(): void
    {
        rename($this->project->path.'/laraimport.json', $this->project->path.'/laraimport.off');
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame('decision', $this->step('no-contract@.')['category']);

        $this->realign('decide', ['targets' => ['no-contract@.', 'keep'], '--note' => 'Se queda fuera.']);
        $this->assertStringContainsString('falta pedir la excepción', implode("\n", $this->realign('check', ['targets' => ['no-contract@.']], Command::FAILURE)['remaining']));

        $this->realign('except', ['targets' => ['no-contract@.'], '--option' => ['reason=Se queda fuera de LaraPack.']]);
        $this->assertFalse($this->realign('check', ['targets' => ['no-contract@.']], Command::FAILURE)['done']);

        $this->owner('approve', ['targets' => ['realign:no-contract:laraimport.json']]);
        $this->assertTrue($this->realign('check', ['targets' => ['no-contract@.']])['done']);

        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::DONE, $this->step('no-contract@.')['status'], 'Aprobada, el plan no la vuelve a proponer.');

        // El día que el ámbito tiene contrato, la excepción ya no lo cubre.
        $this->assertSame('approved', Exceptions::forRoot($this->project->path)->status('realign:no-contract:laraimport.json'));
        rename($this->project->path.'/laraimport.off', $this->project->path.'/laraimport.json');
        $this->assertSame('code-changed', Exceptions::forRoot($this->project->path)->status('realign:no-contract:laraimport.json'));
    }

    public function test_except_reclasifica_antes_de_pedir_y_se_acota_con_target(): void
    {
        // Dos requests que listan sin forzar `managed`: un paso fix con dos
        // hallazgos. Uno se arregla después de planificar.
        $index = 'src/Http/Requests/Post/IndexRequest.php';
        $report = 'src/Http/Requests/Post/ReportRequest.php';
        $merge = "        \$this->merge([\n            'managed' => true,\n        ]);\n";
        $original = $this->project->read($index);
        $this->edit($index, $merge, '');
        file_put_contents($this->project->path.'/'.$report, str_replace('class IndexRequest', 'class ReportRequest', $this->project->read($index)));
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');

        $id = 'fix:index-managed@.';
        $this->assertEqualsCanonicalizing([$index, $report], array_column($this->step($id)['details']['findings'], 'file'));

        file_put_contents($this->project->path.'/'.$index, $original);
        $this->commitAll();
        $reason = ['reason=La usa un informe interno que ve todas las filas.'];

        // Sin volver a diagnosticar: lo ya arreglado no se pide.
        $document = $this->realign('except', ['targets' => [$id], '--option' => $reason, '--dry-run' => true]);
        $this->assertSame([$report], array_column($document['fixed'], 'file'));
        $this->assertSame([$index], array_column($document['unchanged'], 'file'));
        $this->assertStringContainsString('ya no lo señala', $document['unchanged'][0]['reason']);

        // --target acota a esos elementos (por archivo o por objetivo).
        $only = $this->realign('except', ['targets' => [$id], '--option' => $reason, '--target' => [$report]]);
        $this->assertSame([$report], array_column($only['fixed'], 'file'));
        $this->assertSame([], $only['unchanged']);
        $this->assertSame(['realign:fix/index-managed:'.$report], array_keys(Exceptions::forRoot($this->project->path)->requests()));

        // Uno que no es de esos pasos es un error, no una corrida vacía.
        $refused = $this->realign('except', ['targets' => [$id], '--option' => $reason, '--target' => ['src/Nada.php']], Command::FAILURE);
        $this->assertStringContainsString('src/Nada.php', $refused['error']);

        // Y --target es sólo de except.
        $this->realign('apply', ['targets' => [$id], '--target' => [$report]], Command::FAILURE);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
