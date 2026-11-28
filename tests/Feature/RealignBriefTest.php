<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign brief`: el camino del dueño ausente. Un agente hace todo
 * lo que no depende del dueño, llega a decisiones y a excepciones que sólo el
 * dueño puede tomar y aprobar, y le deja **un** documento con todo lo que
 * necesita para hacerlo de una vez: qué es cada cosa, por qué importa, la
 * evidencia agrupada, las opciones con sus consecuencias, la recomendación
 * del paquete cuando la regla la permite y la orden exacta que corre. Cuando
 * el dueño la corre, el agente retoma con `status`.
 *
 * El proyecto de la prueba tiene lo que encontró la prueba de autonomía real
 * en pequeño: controladores escritos a mano (varios en el mismo directorio,
 * para comprobar que la evidencia se agrupa), closures de rutas, unos
 * legítimos y uno con lógica, y excepciones pedidas sin aprobar.
 */
final class RealignBriefTest extends TestCase
{
    use RealignProject;

    private const CSP = "realign:route-closure:routes/web.php#post('/csp-report')";

    private const CORS = "realign:route-closure:routes/web.php#options('/xapi/{any}')";

    private const HEALTH = "realign:route-closure:routes/web.php#get('/ci/test-health')";

    private const ADMIN = ['ExportController', 'ImportController'];

    private const REPORT = 'src/Http/Controllers/Admin/ReportController.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        @mkdir($this->project->path.'/routes', 0777, true);
        @mkdir($this->project->path.'/src/Http/Controllers/Admin', 0777, true);

        foreach (self::ADMIN as $class) {
            file_put_contents($this->project->path."/src/Http/Controllers/Admin/{$class}.php", <<<PHP
                <?php

                namespace Acme\\Blog\\Http\\Controllers\\Admin;

                use Acme\\Blog\\Http\\Controllers\\Controller;

                class {$class} extends Controller
                {
                }
                PHP);
        }

        // Y uno con lógica en el controlador: trabajo guiado que el agente
        // puede hacer mientras el dueño decide.
        file_put_contents($this->project->path.'/'.self::REPORT, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Controllers\Controller;
            use Illuminate\Http\Request;

            class ReportController extends Controller
            {
                public function index(Request $request)
                {
                    $request->validate(['from' => 'required|date']);

                    return response()->json(['ok' => true]);
                }
            }
            PHP);
    }

    private function routes(bool $withLogic): void
    {
        $stats = $withLogic ? "\nRoute::get('/stats', fn () => response()->json(['posts' => \\DB::table('posts')->count()]));\n" : '';

        file_put_contents($this->project->path.'/routes/web.php', <<<PHP
            <?php

            use Illuminate\\Http\\Request;
            use Illuminate\\Support\\Facades\\Route;

            Route::post('/csp-report', function (Request \$request) {
                logger()->warning('csp', \$request->all());

                return response()->noContent();
            })->name('csp.report');

            Route::options('/xapi/{any}', fn () => response('', 204))->where('any', '.*');

            Route::get('/ci/test-health', fn () => response()->json(['ok' => true]));
            {$stats}
            PHP);
    }

    private function prepare(bool $withLogic = false): void
    {
        $this->routes($withLogic);
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function owner(string $action, array $arguments = []): void
    {
        $exit = $this->runCommand('larapack:hand-rolled', ['action' => $action, '--root' => $this->project->path, '--format' => 'json'] + $arguments);
        $this->assertSame(Command::SUCCESS, $exit, $this->lastOutput);
    }

    /**
     * La orden de un brief (`larapack:… args --opción="…"`) como argumentos
     * del comando, para correrla tal cual la escribió.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parse(string $command): array
    {
        preg_match_all('/--([a-z-]+)="([^"]*)"|--([a-z-]+)|"([^"]*)"|(\S+)/', $command, $matches, PREG_SET_ORDER);
        $name = array_shift($matches)[0];
        $arguments = ['targets' => []];
        $first = true;

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                $arguments['--'.$match[1]] = $match[2];
            } elseif (($match[3] ?? '') !== '') {
                $arguments['--'.$match[3]] = true;
            } elseif ($first) {
                $arguments['action'] = ($match[4] ?? '') !== '' ? $match[4] : $match[5];
                $first = false;
            } else {
                $arguments['targets'][] = ($match[4] ?? '') !== '' ? $match[4] : ($match[5] ?? '');
            }
        }

        return [$name, $arguments];
    }

    /**
     * Corre una orden del brief como la correría el dueño.
     */
    private function runAsOwner(string $command): void
    {
        [$name, $arguments] = $this->parse($command);

        // Aprobar lo confirma una persona en su terminal: aquí, el dueño.
        if ($name === 'larapack:hand-rolled') {
            $this->ownerAtTerminal();
        }

        $exit = $this->runCommand($name, ['--root' => $this->project->path, '--format' => 'json'] + $arguments);
        $this->assertSame(Command::SUCCESS, $exit, $command."\n".$this->lastOutput);
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    private function decision(array $brief, string $id): array
    {
        foreach ($brief['decisions'] as $decision) {
            if ($decision['id'] === $id) {
                return $decision;
            }
        }

        $this->fail("El brief no trae la decisión {$id}. Trae: ".implode(', ', array_column($brief['decisions'], 'id')));
    }

    public function test_sin_plan_no_hay_brief(): void
    {
        $failure = $this->realign('brief', [], Command::FAILURE);

        $this->assertStringContainsString('No hay plan', $failure['error']);
    }

    public function test_reune_decisiones_y_excepciones_con_la_orden_exacta_y_se_retoma_con_status(): void
    {
        $this->prepare();

        // Lo que puede hacer el agente sin el dueño: pedir la excepción de los
        // closures, que es lo que la decisión acabará pidiendo.
        $this->realign('decide', ['targets' => ['route-closure@.', 'except'], '--note' => 'CSP, CORS y salud son legítimas.']);
        $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason=Sin datos de nadie y sin sesión.']]);
        $this->commitAll();

        $brief = $this->realign('brief');

        $this->assertSame(RealignState::FORMAT_VERSION, $brief['format_version']);
        $this->assertSame('brief', $brief['action']);

        // El resumen de arriba.
        $summary = $brief['summary'];
        $this->assertSame(1, $summary['decisions']);
        $this->assertSame(3, $summary['exceptions']);
        $this->assertGreaterThan(0, $summary['review_minutes']);
        $this->assertStringContainsString('verify --strict', $summary['if_nothing_is_decided']);

        // La decisión: qué es, por qué importa, la evidencia agrupada, las
        // opciones con su orden exacta.
        $http = $this->decision($brief, 'unmanaged-http@.');
        $this->assertSame('Controladores y rutas escritos a mano', $http['title']);
        $this->assertNotSame('', $http['why']);
        // Tres controladores del mismo directorio son un grupo, no tres; el
        // archivo de rutas, otro.
        $this->assertSame([['controller', 'src/Http/Controllers/Admin', 3], ['routes', 'routes', 1]],
            array_map(fn (array $group): array => [$group['kind'], $group['directory'], $group['count']], $http['evidence']['groups']));
        $group = $http['evidence']['groups'][0];
        $this->assertSame('src/Http/Controllers/Admin', $group['directory']);
        $this->assertSame(3, $group['count']);
        $this->assertSame(['src/Http/Controllers/Admin/ExportController.php', 'src/Http/Controllers/Admin/ImportController.php', 'src/Http/Controllers/Admin/ReportController.php'],
            array_column($group['items'], 'file'));
        $this->assertSame(['declare', 'keep'], array_column($http['options'], 'key'));
        $this->assertSame('larapack:realign decide unmanaged-http@. declare --note="…"', $http['options'][0]['decide']);
        $this->assertTrue($http['options'][1]['needs_owner_again'], 'Dejarlos como están pide excepciones que vuelve a aprobar el dueño.');
        $this->assertArrayHasKey('recommendation', $http);
        $this->assertContains('larapack:realign check unmanaged-http@.', $http['blocks']);

        // Cada excepción: objetivo, motivo, el mensaje del hallazgo y la orden.
        $targets = array_column($brief['exceptions'], null, 'target');
        $this->assertSame([self::HEALTH, self::CORS, self::CSP], array_keys($targets));
        $csp = $targets[self::CSP];
        $this->assertSame('Sin datos de nadie y sin sesión.', $csp['reason']);
        $this->assertSame('unapproved', $csp['status']);
        $this->assertSame('route-closure@.', $csp['step']);
        $this->assertStringContainsString('closure (post())', (string) $csp['finding']);
        $this->assertSame('larapack:hand-rolled approve "'.self::CSP.'"', $csp['approve']);
        $this->assertContains('larapack:realign check route-closure@.', $csp['blocks']);

        // Lo que el agente puede seguir haciendo mientras. Aquí nada: el único
        // paso guiado (el controlador que no delega) es de un controlador
        // escrito a mano, y espera a la decisión unmanaged-http —declararlo
        // en el contrato o dejarlo— antes de trabajarse (depends_on).
        $this->assertSame([], array_column($brief['meanwhile'], 'id'));
        $this->assertNotContains('unmanaged-http@.', array_column($brief['meanwhile'], 'id'));
        $this->assertNotContains('route-closure@.', array_column($brief['meanwhile'], 'id'), 'Decidida y con sus excepciones por aprobar, espera al dueño.');
        $this->assertSame('larapack:realign status', $brief['resume']['command']);

        // Escrito en disco, igual a lo que sale por stdout.
        $json = json_decode($this->project->read('.larapack/realign/brief.json'), true);
        $this->assertSame($brief['decisions'], $json['decisions']);
        $markdown = $this->project->read('.larapack/realign/brief.md');
        $this->assertStringContainsString('# Lo que necesita el dueño', $markdown);
        $this->assertStringContainsString('larapack:realign decide unmanaged-http@. keep --note="…"', $markdown);
        $this->assertStringContainsString('larapack:hand-rolled approve "'.self::CSP.'"', $markdown);
        $this->assertStringContainsString('src/Http/Controllers/Admin (3)', $markdown);

        // No toca nada más que esos dos archivos.
        $this->assertSame(['.larapack/realign/brief.json', '.larapack/realign/brief.md'], $this->untracked());

        // El dueño corre lo que dice el brief, tal cual…
        foreach ($brief['exceptions'] as $exception) {
            $this->runAsOwner($exception['approve']);
        }

        $this->runAsOwner($http['options'][1]['decide']);

        // …y el agente retoma: no queda nada para el dueño salvo pedir y
        // aprobar lo que acaba de decidir dejar como está.
        $after = $this->realign('brief');
        $this->assertSame(0, $after['summary']['exceptions']);
        $this->assertSame(0, $after['summary']['decisions']);
        $this->assertTrue($this->realign('check', ['targets' => ['route-closure@.']])['done']);
        $this->assertSame('keep', $this->step('unmanaged-http@.')['decision']['option']);
    }

    public function test_lo_decidido_por_elemento_no_se_vuelve_a_ofrecer_y_su_excepcion_vieja_no_cuenta(): void
    {
        $this->prepare();
        $export = 'src/Http/Controllers/Admin/ExportController.php';

        // El dueño deja uno como está y pide su excepción…
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep'], '--target' => [$export], '--note' => 'se queda']);
        $this->realign('except', ['targets' => ['unmanaged-http@.'], '--target' => [$export], '--option' => ['reason=Exporta a mano.']]);
        $this->commitAll();

        $http = $this->decision($this->realign('brief'), 'unmanaged-http@.');
        // Sólo lo que falta, y cada orden acotada a ello: una sin --target
        // borraría lo decidido para ExportController.
        $this->assertNotContains($export, (array) $http['undecided']);
        $this->assertNotEmpty($http['undecided']);
        $this->assertStringContainsString('--target=', $http['options'][0]['decide']);

        // …y luego cambia de opinión: ese controlador se declara.
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'declare'], '--target' => [$export], '--note' => 'mejor declararlo']);
        $brief = $this->realign('brief');
        $this->assertSame([], array_values(array_filter(array_column($brief['exceptions'], 'file'), fn (?string $file): bool => $file === $export)),
            'Una excepción que el dueño decidió no dar no se le vuelve a presentar.');
        // check no exige aprobar la excepción vieja (sí que se declare).
        $this->runCommand('larapack:realign', ['action' => 'check', 'targets' => ['unmanaged-http@.'], '--root' => $this->project->path, '--format' => 'json']);
        $remaining = (array) (json_decode($this->lastOutput, true)['remaining'] ?? []);
        $this->assertNotEmpty($remaining);
        $this->assertSame([], array_values(array_filter($remaining, fn (string $line): bool => str_contains($line, 'ExportController') && str_contains($line, 'excepción'))));
    }

    public function test_done_y_brief_no_aceptan_argumentos(): void
    {
        $this->prepare();

        foreach (['done', 'brief'] as $action) {
            $this->assertSame(Command::FAILURE, $this->runCommand('larapack:realign', ['action' => $action, 'targets' => ['basura'], '--root' => $this->project->path, '--format' => 'json']), $action);
        }
    }

    public function test_recomienda_solo_cuando_la_regla_lo_permite(): void
    {
        $this->prepare(withLogic: true);

        $brief = $this->realign('brief');

        // Un closure con lógica entre los legítimos: llevarlo a un controlador.
        $closures = $this->decision($brief, 'route-closure@.');
        $this->assertSame('refactor', $closures['recommendation']['option']);
        $this->assertStringContainsString("get('/stats')", $closures['recommendation']['why']);
        $labels = array_column($closures['evidence']['groups'][0]['items'], 'subject');
        $this->assertContains("post('/csp-report')", $labels);
        $this->assertSame([6, 12, 14, 16], array_column($closures['evidence']['groups'][0]['items'], 'line'));
        // El mismo mensaje para cuatro closures se dice una vez.
        $this->assertStringContainsString('La ruta de la línea N lleva su acción en un closure', (string) $closures['evidence']['groups'][0]['message']);

        // Los controladores a mano: sin saber si sus rutas se conservan no
        // hay regla; lo dice y dice cómo averiguarlo.
        $http = $this->decision($brief, 'unmanaged-http@.');
        $this->assertNull($http['recommendation']['option']);
        $this->assertStringContainsString('larapack:realign infer unmanaged-http@. --dry-run', $http['recommendation']['why']);

        // Sin el closure con lógica, todos son de los legítimos conocidos.
        $this->prepare();
        $this->assertSame('except', $this->decision($this->realign('brief'), 'route-closure@.')['recommendation']['option']);
    }

    public function test_en_texto_imprime_el_markdown(): void
    {
        $this->prepare();

        $exit = $this->runCommand('larapack:realign', ['action' => 'brief', '--root' => $this->project->path]);

        $this->assertSame(Command::SUCCESS, $exit, $this->lastOutput);
        $this->assertStringContainsString('# Lo que necesita el dueño', $this->lastOutput);
        $this->assertStringContainsString('## Resumen', $this->lastOutput);
        $this->assertStringContainsString('.larapack/realign/brief.md', $this->lastOutput);
    }

    public function test_no_acepta_opciones_que_no_usa(): void
    {
        $this->prepare();

        $failure = $this->realign('brief', ['--dry-run' => true], Command::FAILURE);

        $this->assertStringContainsString('--dry-run', $failure['error']);
    }

    /**
     * @return list<string>
     */
    private function untracked(): array
    {
        $lines = array_filter(explode("\n", trim($this->git('status', '--porcelain', '--untracked-files=all'))));
        $files = array_map(fn (string $line): string => substr($line, 3), $lines);
        sort($files);

        return array_values($files);
    }
}
