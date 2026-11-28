<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Un paso guiado de punta a punta, como lo haría un agente sin contexto:
 * `next` le da el playbook rellenado con los archivos, los hallazgos y las
 * órdenes exactas de ESE paso; hace el cambio; `check` lo da por hecho sólo
 * si de verdad está hecho, y si no, dice qué falta y por qué.
 *
 * El controlador tiene la forma de los que school-management llevó a mano
 * a sus requests (bloque «CON»): recibe Illuminate\Http\Request, autoriza y
 * valida en la acción y responde con un JSON propio.
 */
final class RealignGuidedStepTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/Admin/ReportController.php';

    private const OTHER = 'src/Http/Controllers/Zeta/AuditController.php';

    private const REQUEST = 'src/Http/Requests/Admin/ReportRequest.php';

    private const STEP = 'controller-shape@'.self::CONTROLLER;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        $this->write(self::CONTROLLER, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Controllers\Controller;
            use Illuminate\Http\Request;

            class ReportController extends Controller
            {
                public function index(Request $request)
                {
                    abort_unless($request->user()?->can('viewReports'), 403);

                    $request->validate(['from' => 'required|date']);

                    return response()->json(['from' => $request->input('from')]);
                }
            }
            PHP);
        $this->write(self::OTHER, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Zeta;

            use Acme\Blog\Http\Controllers\Controller;

            class AuditController extends Controller
            {
                public function index()
                {
                    return response()->json([]);
                }
            }
            PHP);

        $this->realign('diagnose');
        $this->realign('plan');
    }

    private function write(string $relative, string $content): void
    {
        @mkdir(dirname($this->project->path.'/'.$relative), 0777, true);
        file_put_contents($this->project->path.'/'.$relative, $content);
    }

    /**
     * El siguiente paso guiado, saltando lo determinista (que en un proyecto
     * de verdad se habría hecho ya con su orden) y con la decisión que lo
     * condiciona tomada: los controladores escritos a mano se quedan como
     * código propio (si se declararan, lo guiado no se haría).
     *
     * @return array<string, mixed>
     */
    private function nextGuided(): array
    {
        $path = $this->project->path.'/.larapack/realign/plan.json';
        $plan = json_decode((string) file_get_contents($path), true);

        foreach ($plan['steps'] as $index => $step) {
            if ($step['category'] === 'deterministic') {
                $plan['steps'][$index]['status'] = Planner::DONE;
            }
        }

        file_put_contents($path, json_encode($plan));

        $this->assertSame('unmanaged-http@.', $this->realign('next')['step']['id']);
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep'], '--note' => 'Son código propio.']);

        return $this->realign('next');
    }

    public function test_next_da_el_playbook_rellenado_con_sus_anexos_y_ordenes_exactas(): void
    {
        $next = $this->nextGuided();

        $this->assertSame(self::STEP, $next['step']['id']);
        $this->assertSame('controller-to-request', $next['how']['playbook']);
        $this->assertSame(['raw-request-to-formrequest', 'validate-to-rules'], $this->sorted($next['step']['annexes']));
        $this->assertSame(['kind' => 'verify', 'checks' => ['controller-shape', 'request-handle'], 'package' => null, 'files' => [self::CONTROLLER]], $next['step']['done_when']);

        $instructions = $next['how']['instructions'];

        foreach ([
            '# Llevar la lógica del controlador a su FormRequest',
            'Anexo: la validación del controlador pasa a `rules()`',
            'Anexo: la acción recibe `Illuminate\Http\Request`',
            '403 antes que 422',
            self::CONTROLLER,
            'ReportController::index() (línea 10) no delega',
            'larapack:verify --format=json --package=app --check=controller-shape --check=request-handle --path='.self::CONTROLLER,
            'larapack:realign check '.self::STEP,
        ] as $expected) {
            $this->assertStringContainsString($expected, $instructions);
        }

        $this->assertStringNotContainsString('{{', $instructions);
        // El otro controlador es otro paso: no aparece en éste.
        $this->assertStringNotContainsString(self::OTHER, $instructions);
        $this->assertSame(Playbooks::render($next['step']), $instructions);
    }

    public function test_la_orden_de_verify_del_playbook_mira_solo_ese_controlador(): void
    {
        $exit = $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--format' => 'json',
            '--check' => ['controller-shape', 'request-handle'], '--path' => [self::CONTROLLER]]);
        $report = json_decode($this->lastOutput, true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([self::CONTROLLER], array_values(array_unique(array_column($report['findings'], 'file'))));
        $this->assertSame([self::CONTROLLER], $report['paths']);

        // Sin --path salen los dos.
        $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--format' => 'json', '--check' => ['controller-shape']]);
        $this->assertContains(self::OTHER, array_column(json_decode($this->lastOutput, true)['findings'], 'file'));

        // Un directorio acota a lo que hay debajo; lo que no tiene archivo no entra.
        $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--format' => 'json', '--check' => ['controller-shape'], '--path' => ['./src/Http/Controllers/Zeta/']]);
        $this->assertSame([self::OTHER], array_values(array_unique(array_column(json_decode($this->lastOutput, true)['findings'], 'file'))));
    }

    public function test_un_cambio_a_medias_no_se_da_por_hecho_y_el_correcto_si(): void
    {
        $this->nextGuided();

        // 1. Mal: la acción delega, pero sigue validando antes.
        $this->write(self::CONTROLLER, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Controllers\Controller;
            use Acme\Blog\Http\Requests\Admin\ReportRequest;

            class ReportController extends Controller
            {
                public function index(ReportRequest $request)
                {
                    $request->validate(['from' => 'required|date']);

                    return $request->handle();
                }
            }
            PHP);
        $this->write(self::REQUEST, $this->request(withHandle: true));

        $check = $this->realign('check', ['targets' => [self::STEP]], Command::FAILURE);
        $this->assertFalse($check['done']);
        $this->assertCount(1, $check['remaining']);
        $this->assertStringContainsString('controller-shape: '.self::CONTROLLER, $check['remaining'][0]);
        $this->assertStringContainsString('valida en el controlador con validate()', $check['remaining'][0]);
        $this->assertSame(Planner::PENDING, $this->step(self::STEP)['status']);

        // 2. Mal: la acción tiene la forma, pero su request no tiene handle().
        // El hallazgo está en la request, que el paso no nombraba: también
        // cuenta.
        $this->write(self::CONTROLLER, $this->thinController());
        $this->write(self::REQUEST, $this->request(withHandle: false));

        $check = $this->realign('check', ['targets' => [self::STEP]], Command::FAILURE);
        $this->assertCount(1, $check['remaining']);
        $this->assertStringContainsString('request-handle: '.self::REQUEST, $check['remaining'][0]);
        $this->assertStringContainsString('no define handle()', $check['remaining'][0]);

        // 3. Bien.
        $this->write(self::REQUEST, $this->request(withHandle: true));

        $check = $this->realign('check', ['targets' => [self::STEP]]);
        $this->assertTrue($check['done']);
        $this->assertSame(Planner::DONE, $this->step(self::STEP)['status']);

        // Y el siguiente es el otro controlador; la excepción de «dejarlos
        // como código propio» se pide después, sobre el código ya delegado.
        $this->assertSame('controller-shape@'.self::OTHER, $this->realign('next')['step']['id']);
    }

    public function test_playbook_lista_los_del_paquete_y_da_uno_sin_rellenar(): void
    {
        $list = $this->realign('playbook');
        $this->assertSame(Playbooks::names(), array_keys($list['playbooks']));
        $this->assertSame('Llevar la lógica del controlador a su FormRequest', $list['playbooks']['controller-to-request']);

        $one = $this->realign('playbook', ['targets' => ['decide-foreign-keys']]);
        $this->assertStringContainsString('cascade', $one['instructions']);
        $this->assertStringNotContainsString('{{', $one['instructions']);

        $this->assertStringContainsString('No hay un playbook', $this->realign('playbook', ['targets' => ['../../etc/passwd']], Command::FAILURE)['error']);
    }

    private function thinController(): string
    {
        return <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Controllers\Controller;
            use Acme\Blog\Http\Requests\Admin\ReportRequest;

            class ReportController extends Controller
            {
                public function index(ReportRequest $request)
                {
                    return $request->handle();
                }
            }
            PHP;
    }

    private function request(bool $withHandle): string
    {
        $handle = $withHandle ? <<<'PHP'

                public function handle()
                {
                    return response()->json(['from' => $this->input('from')]);
                }
            PHP : '';

        return <<<PHP
            <?php

            namespace Acme\\Blog\\Http\\Requests\\Admin;

            use Illuminate\\Foundation\\Http\\FormRequest;

            class ReportRequest extends FormRequest
            {
                public function authorize(): bool
                {
                    return (bool) \$this->user()?->can('viewReports');
                }

                public function rules(): array
                {
                    return ['from' => 'required|date'];
                }
            {$handle}
            }
            PHP;
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
