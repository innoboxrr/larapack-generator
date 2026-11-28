<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Moverse por el plan como lo hace un agente sin contexto, con lo que
 * encontraron las dos pruebas de autonomía sobre copias de proyectos:
 *
 *  - `next <id>` ignoraba el id y daba el siguiente en orden: no había cómo
 *    ver el playbook rellenado de un paso concreto;
 *  - las decisiones que condicionan lo guiado (declarar o no los
 *    controladores escritos a mano) salían al final, y si el dueño elegía
 *    «declarar» se rehacía lo guiado ya hecho;
 *  - una decisión valía para el paso entero (84 controladores a la vez);
 *  - no había cómo aplazar un paso guiado ni pasárselo al dueño;
 *  - `status` arrastraba la última comprobación de antes de un diagnóstico
 *    nuevo.
 */
final class RealignNavigationTest extends TestCase
{
    use RealignProject;

    private const REPORT = 'src/Http/Controllers/Admin/ReportController.php';

    private const EXPORT = 'src/Http/Controllers/Admin/ExportController.php';

    private const SHOW_REQUEST = 'src/Http/Requests/Post/ShowRequest.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        // Un archivo sólo con otro formato: el paso determinista `adopt@.`.
        file_put_contents($this->project->path.'/src/Http/Controllers/PostController.php', str_replace('    ', "\t", $this->project->read('src/Http/Controllers/PostController.php')));
        $this->edit(self::SHOW_REQUEST, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (true) {\n            return true;\n        }");
        @mkdir($this->project->path.'/src/Http/Controllers/Admin', 0777, true);

        foreach ([self::REPORT => 'ReportController', self::EXPORT => 'ExportController'] as $file => $class) {
            file_put_contents($this->project->path.'/'.$file, <<<PHP
                <?php

                namespace Acme\\Blog\\Http\\Controllers\\Admin;

                use Acme\\Blog\\Http\\Controllers\\Controller;
                use Illuminate\\Http\\Request;

                class {$class} extends Controller
                {
                    public function index(Request \$request)
                    {
                        \$request->validate(['from' => 'required|date']);

                        return response()->json(['ok' => true]);
                    }
                }
                PHP);
        }

        $this->realign('diagnose');
        $this->realign('plan');
    }

    public function test_next_con_un_id_da_ese_paso_y_no_el_siguiente(): void
    {
        $id = 'controller-shape@'.self::EXPORT;
        $next = $this->realign('next', ['targets' => [$id]]);

        $this->assertSame($id, $next['step']['id']);
        $this->assertSame('controller-to-request', $next['how']['playbook']);
        $this->assertStringContainsString(self::EXPORT, $next['how']['instructions']);
        // Bloqueado, pero se ve igual: lo que lo bloquea va con él.
        $this->assertSame(Planner::BLOCKED, $next['step']['effective_status']);
        $this->assertContains('unmanaged-http@.', $next['blocked_by']);

        // `show` es lo mismo con el id obligatorio, para cualquier paso.
        $decision = $this->realign('show', ['targets' => ['unmanaged-http@.']]);
        $this->assertSame('unmanaged-http@.', $decision['step']['id']);
        $this->assertSame(['declare', 'keep'], array_column($decision['how']['options'], 'key'));

        $deterministic = $this->realign('show', ['targets' => ['adopt@.']]);
        $this->assertSame('larapack:realign adopt adopt@.', $deterministic['how']['command']);

        $this->assertStringContainsString('No hay un paso no-existe@.', $this->realign('show', ['targets' => ['no-existe@.']], Command::FAILURE)['error']);
        $this->assertStringContainsString('show necesita el id', $this->realign('show', [], Command::FAILURE)['error']);
    }

    public function test_un_argumento_que_la_accion_no_usa_es_un_error(): void
    {
        foreach (['status' => ['adopt@.'], 'plan' => ['adopt@.'], 'diagnose' => ['app'], 'next' => ['adopt@.', 'unmanaged-http@.'], 'check' => ['adopt@.', 'otro'], 'decide' => ['unmanaged-http@.', 'keep', 'otro']] as $action => $targets) {
            $error = $this->realign($action, ['targets' => $targets], Command::FAILURE)['error'];

            $this->assertStringContainsString("`{$action}`", $error, $action);
            $this->assertStringContainsString(end($targets), $error, $action);
        }

        $this->assertStringContainsString('--target', $this->realign('status', ['--target' => [self::REPORT]], Command::FAILURE)['error']);
    }

    public function test_las_decisiones_que_condicionan_lo_guiado_van_antes_y_no_bloquean_lo_demas(): void
    {
        $ids = array_column($this->plan()['steps'], 'id');

        $this->assertLessThan(array_search('controller-shape@'.self::REPORT, $ids, true), array_search('unmanaged-http@.', $ids, true));
        $this->assertContains('unmanaged-http@.', $this->step('controller-shape@'.self::REPORT)['depends_on']);
        // Lo guiado que no toca esos archivos no depende de la decisión.
        $this->assertNotContains('unmanaged-http@.', $this->step('edited-outside:Requests@.')['depends_on']);

        $this->markDone('adopt@.');

        // `next` ofrece la decisión primero, y dice con qué seguir mientras.
        $next = $this->realign('next');
        $this->assertSame('unmanaged-http@.', $next['step']['id']);
        $this->assertSame('edited-outside:Requests@.', $next['meanwhile']['id']);
        $this->assertSame('larapack:realign next edited-outside:Requests@.', $next['meanwhile']['show']);

        $status = $this->realign('status');
        $this->assertSame([['scope' => 'app', 'package' => null, 'decisions' => ['unmanaged-http@.'], 'guided' => ['controller-shape@'.self::EXPORT, 'controller-shape@'.self::REPORT]]], $status['awaiting_owner']);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'status', '--root' => $this->project->path]));
        $this->assertStringContainsString('Falta 1 decisión del dueño antes de seguir con lo guiado de app', $this->lastOutput);

        // Declararlos los saca de lo guiado: siguen esperando, no se hacen
        // para rehacerlos después.
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'declare']]);
        $this->assertSame(Planner::BLOCKED, $this->effective('controller-shape@'.self::REPORT));
        // Y lo que toca es declararlos: next da la decisión con su orden.
        $declared = $this->realign('next');
        $this->assertSame('unmanaged-http@.', $declared['step']['id']);
        $this->assertSame('larapack:realign infer unmanaged-http@.', $declared['how']['then']);

        // Dejarlos como código propio, sí: lo guiado sigue.
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep']]);
        $this->assertSame(Planner::PENDING, $this->effective('controller-shape@'.self::REPORT));
        $this->assertSame([], $this->realign('status')['awaiting_owner']);
    }

    public function test_una_decision_tomada_se_cumple_despues_de_lo_guiado_y_luego_es_del_dueno(): void
    {
        $this->markDone('adopt@.');
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep'], '--note' => 'Son código propio.']);

        // La excepción se pide sobre el código ya llevado a su request:
        // pedirla antes la caducaría. Primero lo guiado.
        $this->assertSame('controller-shape@'.self::EXPORT, $this->realign('next')['step']['id']);

        $this->markDone('controller-shape@'.self::EXPORT);
        $this->markDone('controller-shape@'.self::REPORT);
        $next = $this->realign('next');
        $this->assertSame('unmanaged-http@.', $next['step']['id']);
        $this->assertSame('larapack:realign except unmanaged-http@. --option reason="por qué"', $next['how']['then']);

        // Pedida la excepción, lo que falta es del dueño: next sigue.
        $this->commitAll();
        $this->realign('except', ['targets' => ['unmanaged-http@.'], '--option' => ['reason=Son código propio.']]);
        $this->assertSame('edited-outside:Requests@.', $this->realign('next')['step']['id']);

        $this->markDone('edited-outside:Requests@.');
        $waiting = $this->realign('next');
        $this->assertFalse($waiting['done']);
        $this->assertNull($waiting['step']);
        $this->assertSame(['unmanaged-http@.'], $waiting['waiting']['owner']);
    }

    public function test_se_decide_por_elemento_y_el_plan_refleja_lo_mixto(): void
    {
        $this->markDone('adopt@.');

        $unknown = $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep'], '--target' => ['src/Otro.php']], Command::FAILURE);
        $this->assertStringContainsString(self::REPORT, $unknown['error']);

        $kept = $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep'], '--target' => [self::REPORT], '--note' => 'Es un informe interno.']);
        $this->assertSame([self::REPORT], $kept['targets']);
        $this->assertSame('keep', $this->step('unmanaged-http@.')['decisions'][self::REPORT]['option']);
        // A medias: falta el otro.
        $this->assertSame(Planner::AWAITING_DECISION, $this->effective('unmanaged-http@.'));
        $this->assertSame(Planner::PENDING, $this->effective('controller-shape@'.self::REPORT));
        $this->assertSame(Planner::BLOCKED, $this->effective('controller-shape@'.self::EXPORT));

        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'declare'], '--target' => [self::EXPORT]]);
        $this->assertSame(Planner::DECIDED, $this->effective('unmanaged-http@.'));

        $show = $this->realign('show', ['targets' => ['unmanaged-http@.']]);
        $this->assertSame(['declare' => [self::EXPORT], 'keep' => [self::REPORT]], $show['how']['decided_by_target']);
        $this->assertSame([], $show['how']['undecided']);

        // La operación de cada opción sólo toca los suyos.
        $this->commitAll();
        $except = $this->realign('except', ['targets' => ['unmanaged-http@.'], '--option' => ['reason=Es un informe interno.'], '--dry-run' => true]);
        $this->assertSame([self::REPORT], array_values(array_unique(array_column($except['fixed'], 'file'))));

        $check = $this->realign('check', ['targets' => ['unmanaged-http@.']], Command::FAILURE);
        $this->assertStringNotContainsString('Falta decidir', implode("\n", $check['remaining']));
    }

    public function test_un_paso_se_aplaza_y_se_retoma(): void
    {
        $this->markDone('adopt@.');
        $this->realign('decide', ['targets' => ['unmanaged-http@.', 'keep']]);
        $id = 'controller-shape@'.self::EXPORT;

        $this->assertStringContainsString('--note', $this->realign('defer', ['targets' => [$id]], Command::FAILURE)['error']);

        $this->realign('defer', ['targets' => [$id], '--note' => 'Es el callback de OAuth2: el playbook dice dejarlo.']);
        $this->assertSame('Es el callback de OAuth2: el playbook dice dejarlo.', $this->step($id)['deferred']['note']);
        // Sigue pendiente: aplazar no es hacer.
        $this->assertSame(Planner::PENDING, $this->effective($id));
        $this->assertSame('deferred', end($this->step($id)['history'])['event']);

        $next = $this->realign('next');
        $this->assertNotSame($id, $next['step']['id']);

        $status = $this->realign('status');
        $this->assertSame([$id], array_column($status['deferred'], 'id'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'status', '--root' => $this->project->path]));
        $this->assertStringContainsString('Aplazados (1)', $this->lastOutput);

        // Planificar otra vez no lo pierde.
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertArrayHasKey('deferred', $this->step($id));

        $this->realign('undefer', ['targets' => [$id]]);
        $this->assertArrayNotHasKey('deferred', $this->step($id));
        $this->assertSame([], $this->realign('status')['deferred']);
        $this->assertStringContainsString('no está aplazado', $this->realign('undefer', ['targets' => [$id]], Command::FAILURE)['error']);
    }

    public function test_un_paso_guiado_se_le_pasa_al_dueno_como_decision(): void
    {
        $this->markDone('adopt@.');
        $id = 'controller-shape@'.self::EXPORT;

        $this->assertStringContainsString('--note', $this->realign('escalate', ['targets' => [$id]], Command::FAILURE)['error']);
        $this->assertStringContainsString('no es un paso guiado', $this->realign('escalate', ['targets' => ['unmanaged-http@.'], '--note' => 'x'], Command::FAILURE)['error']);

        $this->realign('escalate', ['targets' => [$id], '--note' => 'Es el callback de OAuth2: ¿se queda a mano?']);

        $step = $this->step($id);
        $this->assertSame(Category::DECISION, $step['category']);
        $this->assertSame(Category::GUIDED, $step['escalated']['from']);
        $this->assertSame(['refactor', 'hand-rolled'], array_column($step['options'], 'key'));

        $shown = $this->realign('show', ['targets' => [$id]]);
        $this->assertSame(['refactor', 'hand-rolled'], array_column($shown['how']['options'], 'key'));
        $this->assertStringContainsString('OAuth2', $shown['step']['escalated']['note']);

        $this->realign('decide', ['targets' => [$id, 'refactor']]);

        // Planificar otra vez la conserva como decisión, con lo decidido.
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Category::DECISION, $this->step($id)['category']);
        $this->assertSame('refactor', $this->step($id)['decision']['option']);
    }

    public function test_status_no_arrastra_la_comprobacion_de_antes_de_un_diagnostico_nuevo(): void
    {
        $this->realign('check', ['targets' => ['controller-shape@'.self::REPORT]], Command::FAILURE);
        $this->assertSame('controller-shape@'.self::REPORT, $this->realign('status')['last_check']['id']);

        $this->realign('diagnose');
        $this->realign('plan');

        $this->assertNull($this->realign('status')['last_check']);
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'status', '--root' => $this->project->path]));
        $this->assertStringNotContainsString('Última comprobación', $this->lastOutput);
    }

    private function effective(string $id): string
    {
        $steps = $this->plan()['steps'];

        return Planner::effectiveStatus($this->step($id), array_column($steps, null, 'id'));
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
