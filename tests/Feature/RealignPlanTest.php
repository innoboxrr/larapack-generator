<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El ciclo del realineamiento: plan → status → next → check → decide, y que
 * se pueda retomar. Un agente que llega sin contexto tiene que saber, con
 * `status` y `next`, dónde se va y qué hacer; y nada se da por hecho sin
 * comprobar su `done_when`.
 */
final class RealignPlanTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    private const SHOW_REQUEST = 'src/Http/Requests/Post/ShowRequest.php';

    private const REPORT_CONTROLLER = 'src/Http/Controllers/Admin/ReportController.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();

        // Una desviación de cada categoría.
        file_put_contents($this->project->path.'/'.self::CONTROLLER, str_replace('    ', "\t", $this->project->read(self::CONTROLLER)));
        $this->edit(self::SHOW_REQUEST, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (true) {\n            return true;\n        }");
        @mkdir($this->project->path.'/src/Http/Controllers/Admin', 0777, true);
        file_put_contents($this->project->path.'/'.self::REPORT_CONTROLLER, <<<'PHP'
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

        $this->realign('diagnose');
    }

    public function test_el_plan_ordena_lo_que_desbloquea_lo_guiado_y_las_decisiones(): void
    {
        $plan = $this->realign('plan');
        $steps = $this->plan()['steps'];

        $this->assertSame('plan', $plan['action']);
        $this->assertSame(RealignState::FORMAT_VERSION, $this->plan()['format_version']);
        $this->assertSame([
            'adopt@.',
            'controller-shape@'.self::REPORT_CONTROLLER,
            'edited-outside:Requests@.',
            'unmanaged-http@.',
        ], array_column($steps, 'id'));
        $this->assertSame([1, 2, 3, 4], array_column($steps, 'order'));
        $this->assertSame([Category::DETERMINISTIC, Category::GUIDED, Category::GUIDED, Category::DECISION], array_column($steps, 'category'));

        // Lo guiado y las decisiones esperan a que el manifiesto diga la verdad.
        $this->assertSame(['adopt@.'], $steps[1]['depends_on']);
        $this->assertSame(['adopt@.'], $steps[3]['depends_on']);
        $this->assertSame(Planner::BLOCKED, $plan['next'] === null ? null : Planner::effectiveStatus($steps[1], array_column($steps, null, 'id')));
        $this->assertSame('adopt@.', $plan['next']['id']);
    }

    public function test_cada_paso_lleva_lo_necesario_para_hacerlo_sin_contexto(): void
    {
        $this->realign('plan');

        $adopt = $this->step('adopt@.');
        $this->assertSame([self::CONTROLLER], $adopt['files']);
        $this->assertSame('larapack:realign adopt adopt@.', $adopt['command']);
        $this->assertSame('low', $adopt['risk']['level']);
        $this->assertNotSame('', $adopt['risk']['why']);
        $this->assertTrue($adopt['reversible']);
        $this->assertSame(['kind' => 'classification', 'files' => [self::CONTROLLER], 'accept' => ['identical', 'format_only'], 'tracked' => true, 'hash_current' => true], $adopt['done_when']);

        $shape = $this->step('controller-shape@'.self::REPORT_CONTROLLER);
        $this->assertSame('controller-to-request', $shape['playbook']);
        // Valida con validate() y recibe Illuminate\Http\Request: sus dos anexos.
        $this->assertSame(['validate-to-rules', 'raw-request-to-formrequest'], $shape['annexes']);
        $this->assertSame(['kind' => 'verify', 'checks' => ['controller-shape', 'request-handle'], 'package' => null, 'files' => [self::REPORT_CONTROLLER]], $shape['done_when']);
        $this->assertStringContainsString('validate()', $shape['details']['findings'][0]['message']);

        $decision = $this->step('unmanaged-http@.');
        $this->assertSame(['declare', 'keep'], array_column($decision['options'], 'key'));
        $this->assertSame('larapack:realign except unmanaged-http@. --option reason="por qué"', $decision['options'][1]['run']);
    }

    public function test_next_da_el_paso_con_su_orden_y_su_comprobacion(): void
    {
        $this->realign('plan');
        $next = $this->realign('next');

        $this->assertFalse($next['done']);
        $this->assertSame('adopt@.', $next['step']['id']);
        $this->assertSame(Planner::PENDING, $next['step']['effective_status']);
        $this->assertSame('larapack:realign adopt adopt@.', $next['how']['command']);
        $this->assertTrue($next['how']['available']);
        $this->assertSame('larapack:realign check adopt@.', $next['check']);
    }

    public function test_un_paso_guiado_trae_su_playbook_rellenado(): void
    {
        $this->realign('plan');
        $this->markDone('adopt@.');

        $next = $this->realign('next');

        $this->assertSame('controller-shape@'.self::REPORT_CONTROLLER, $next['step']['id']);
        $this->assertSame('controller-to-request', $next['how']['playbook']);
        $this->assertStringContainsString(self::REPORT_CONTROLLER, $next['how']['instructions']);
        $this->assertStringContainsString('larapack:realign check controller-shape@'.self::REPORT_CONTROLLER, $next['how']['instructions']);
        $this->assertStringContainsString('--check=controller-shape', $next['how']['instructions']);
        $this->assertStringNotContainsString('{{', $next['how']['instructions']);
    }

    public function test_check_marca_hecho_solo_lo_que_pasa_su_comprobacion(): void
    {
        $this->realign('plan');
        $id = 'controller-shape@'.self::REPORT_CONTROLLER;

        $failing = $this->realign('check', ['targets' => [$id]], Command::FAILURE);
        $this->assertFalse($failing['done']);
        $this->assertCount(1, $failing['remaining']);
        $this->assertStringContainsString('controller-shape', $failing['remaining'][0]);
        $this->assertSame(Planner::PENDING, $this->step($id)['status']);
        $this->assertFalse($this->step($id)['last_check']['done']);

        // El controlador pasa a delegar en su request.
        file_put_contents($this->project->path.'/'.self::REPORT_CONTROLLER, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Controllers\Controller;
            use Acme\Blog\Http\Requests\Post\IndexRequest;

            class ReportController extends Controller
            {
                public function index(IndexRequest $request)
                {
                    return $request->handle();
                }
            }
            PHP);

        $passing = $this->realign('check', ['targets' => [$id]]);
        $this->assertTrue($passing['done']);
        $this->assertSame(Planner::DONE, $this->step($id)['status']);
        $this->assertSame('done', end($this->step($id)['history'])['event']);
    }

    public function test_un_paso_determinista_se_comprueba_con_la_clasificacion(): void
    {
        $this->realign('plan');

        $this->realign('check', ['targets' => ['adopt@.']], Command::FAILURE);

        // Lo que hará `adopt`: anotar el hash de lo que hay.
        $this->changeManifest(function (array $manifest): array {
            $manifest['models']['Post']['files'][self::CONTROLLER]['hash'] = hash('sha256', $this->project->read(self::CONTROLLER));

            return $manifest;
        });

        $this->assertTrue($this->realign('check', ['targets' => ['adopt@.']])['done']);
    }

    public function test_una_decision_se_registra_con_su_opcion_y_su_nota(): void
    {
        $this->realign('plan');

        $unknown = $this->realign('decide', ['targets' => ['unmanaged-http@.', 'tirarlo']], Command::FAILURE);
        $this->assertStringContainsString('declare, keep', $unknown['error']);

        $notDecision = $this->realign('decide', ['targets' => ['adopt@.', 'keep']], Command::FAILURE);
        $this->assertStringContainsString('no es una decisión', $notDecision['error']);

        $decided = $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep'], '--note' => 'Es un informe interno.']);
        $this->assertSame('keep', $decided['decision']['option']);
        $this->assertSame('Es un informe interno.', $decided['decision']['note']);
        $this->assertSame('larapack:realign except unmanaged-http@. --option reason="por qué"', $decided['then']);
        $this->assertSame('keep', $this->step('unmanaged-http@.')['decision']['option']);

        // Decidir no es hacer: hasta que el controlador se apruebe o se
        // declare, sigue pendiente.
        $this->assertFalse($this->realign('check', ['targets' => ['unmanaged-http@.']], Command::FAILURE)['done']);
    }

    public function test_volver_a_planificar_conserva_el_estado_y_cierra_lo_resuelto(): void
    {
        $this->realign('plan');
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep']]);

        // El controlador a mano desaparece y el formato del otro se arregla
        // a mano: lo pendiente que ya no sale queda hecho.
        unlink($this->project->path.'/'.self::REPORT_CONTROLLER);
        file_put_contents($this->project->path.'/'.self::CONTROLLER, str_replace("\t", '    ', $this->project->read(self::CONTROLLER)));
        $this->realign('diagnose');
        $this->realign('plan');

        $this->assertSame(Planner::DONE, $this->step('adopt@.')['status']);
        $this->assertSame('resolved', end($this->step('adopt@.')['history'])['event']);
        $this->assertSame(Planner::DONE, $this->step('unmanaged-http@.')['status']);
        $this->assertSame('keep', $this->step('unmanaged-http@.')['decision']['option']);
        $this->assertSame(Planner::PENDING, $this->step('edited-outside:Requests@.')['status']);
    }

    public function test_un_paso_hecho_que_vuelve_a_salir_se_reabre(): void
    {
        $this->realign('plan');
        $this->markDone('edited-outside:Requests@.');

        $this->realign('diagnose');
        $this->realign('plan');

        $step = $this->step('edited-outside:Requests@.');
        $this->assertSame(Planner::PENDING, $step['status']);
        $this->assertSame('reopened', end($step['history'])['event']);
    }

    public function test_status_dice_donde_se_va_y_si_el_diagnostico_esta_viejo(): void
    {
        $before = $this->realign('status');
        $this->assertNull($before['plan']);
        $this->assertSame([], $before['stale']);

        $this->realign('plan');
        $status = $this->realign('status');

        $this->assertSame(4, $status['steps']['total']);
        $this->assertSame(1, $status['steps']['by_status'][Planner::PENDING]);
        $this->assertSame(3, $status['steps']['by_status'][Planner::BLOCKED]);
        $this->assertSame('adopt@.', $status['next']['id']);
        $this->assertSame(1, $status['totals']['classes']['format_only']);

        $this->edit('laraimport.json', '"name": "Post"', '"name": "Post", "metas": true');

        $this->assertSame(['.'], $this->realign('status')['stale']);
    }

    public function test_sin_plan_next_y_check_lo_dicen(): void
    {
        $next = $this->realign('next', [], Command::FAILURE);
        $this->assertStringContainsString('larapack:realign plan', $next['error']);
        $this->assertSame(RealignState::FORMAT_VERSION, $next['format_version']);

        $this->realign('plan');
        $missing = $this->realign('check', ['targets' => ['no-existe@.']], Command::FAILURE);
        $this->assertStringContainsString('No hay un paso no-existe@.', $missing['error']);
    }

    public function test_sin_pendientes_next_lo_dice(): void
    {
        $this->realign('plan');

        foreach (array_column($this->plan()['steps'], 'id') as $id) {
            $this->markDone($id);
        }

        $next = $this->realign('next');
        $this->assertTrue($next['done']);
        $this->assertNull($next['step']);
    }

    public function test_una_opcion_que_la_accion_no_usa_es_un_error(): void
    {
        $document = $this->realign('status', ['--dry-run' => true], Command::FAILURE);

        $this->assertStringContainsString('--dry-run', $document['error']);
        $this->assertStringContainsString('Acción desconocida', $this->realign('arreglar', [], Command::FAILURE)['error']);
    }

    public function test_help_lista_las_acciones_y_las_categorias(): void
    {
        $help = $this->realign('help');

        $this->assertSame(['diagnose', 'plan', 'status', 'next', 'check', 'decide', 'playbook', 'adopt', 'apply', 'infer', 'except'], array_keys($help['actions']));
        $this->assertSame(Category::ALL, $help['categories']);
        $this->assertSame('docs/realign/README.md', $help['guide']);
    }

    public function test_la_salida_de_texto_se_lee(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'plan', '--root' => $this->project->path]));
        $this->assertStringContainsString('Siguiente: adopt@.', $this->lastOutput);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'next', '--root' => $this->project->path]));
        $this->assertStringContainsString('Ejecuta: larapack:realign adopt adopt@.', $this->lastOutput);
        $this->assertStringContainsString('(primero con --dry-run)', $this->lastOutput);
        $this->assertStringNotContainsString('no disponible en esta versión', $this->lastOutput);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'diagnose', '--root' => $this->project->path]));
        $this->assertStringContainsString('format_only', $this->lastOutput);
    }

    private function markDone(string $id): void
    {
        $path = $this->project->path.'/.larapack/realign/plan.json';
        $plan = json_decode((string) file_get_contents($path), true);

        foreach ($plan['steps'] as $index => $step) {
            if ($step['id'] === $id) {
                $plan['steps'][$index]['status'] = Planner::DONE;
            }
        }

        file_put_contents($path, json_encode($plan));
    }
}
