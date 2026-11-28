<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que cambia comportamiento no se da por hecho sin su prueba.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ: en una prueba de autonomía real, sin poder correr las pruebas del
 * proyecto, (a) un agente no corrió `check` en un controlador —como pedía
 * guided.md— y el siguiente `diagnose`/`plan` marcó el paso `done` solo
 * («resolved: el diagnóstico ya no lo encuentra»); (b) otro corrió `check`
 * sin pruebas y la herramienta no avisó. Lo no probado acababa hecho y
 * `realign done` podía llegar a verde con cambios de comportamiento que nadie
 * había visto pasar.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Ahora un paso que cambia comportamiento (StepTypes::changesBehaviour()):
 * con `check --tested="<orden>"` o con la orden de pruebas configurada que
 * `check` corre él mismo, queda `done`; sin eso, `untested`, con su aviso; la
 * resolución automática de `plan` lo deja `untested`; `status`, `brief` y
 * `done` lo listan; y `done` falla mientras quede uno, salvo que el dueño lo
 * acepte (`accept-untested`, confirmado por él como una aprobación).
 */
final class RealignUntestedTest extends TestCase
{
    use RealignProject;

    private const REQUEST = 'src/Http/Requests/Post/DeleteRequest.php';

    private const STEP = 'edited-outside:Requests@.';

    private string $generated = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        $this->withoutIgnoredFlags();
        $this->commitAll();

        // La autorización editada fuera de su hueco: devolverla cambia lo que
        // responde la acción (un admin que pasaba deja de pasar).
        $this->generated = $this->project->read(self::REQUEST);
        $this->edit(self::REQUEST, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (\$this->user()?->isAdmin()) {\n            return true;\n        }\n");
        $this->commitAll();

        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::PENDING, $this->step(self::STEP)['status']);
    }

    private function restore(): void
    {
        file_put_contents($this->project->path.'/'.self::REQUEST, $this->generated);
        $this->commitAll();
    }

    public function test_check_sin_pruebas_lo_deja_untested_y_lo_avisa(): void
    {
        $this->restore();

        $check = $this->realign('check', ['targets' => [self::STEP]]);

        $this->assertTrue($check['ok']);
        $this->assertFalse($check['done']);
        $this->assertSame(Planner::UNTESTED, $check['status']);
        $this->assertSame([], $check['remaining']);
        $this->assertStringContainsString('SIN PROBAR', $check['warning']);
        $this->assertStringContainsString('--tested=', $check['warning']);
        $this->assertSame(Planner::UNTESTED, $this->step(self::STEP)['status']);
        $this->assertSame('untested', array_column($this->step(self::STEP)['history'], 'event')[0]);

        // En texto, el aviso se ve.
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'check', 'targets' => [self::STEP], '--root' => $this->project->path]));
        $this->assertStringContainsString('SIN PROBAR', $this->lastOutput);

        // El playbook ya pedía la prueba en su orden de check.
        $this->assertStringContainsString('larapack:realign check '.self::STEP.' --tested=', $this->realign('show', ['targets' => [self::STEP]])['how']['instructions']);

        // next no lo vuelve a dar: está hecho a falta de la prueba.
        $this->assertNotSame(self::STEP, $this->realign('next')['step']['id'] ?? null);
    }

    public function test_check_tested_registra_la_orden_y_lo_da_por_hecho(): void
    {
        $this->restore();
        $this->realign('check', ['targets' => [self::STEP]]);

        $check = $this->realign('check', ['targets' => [self::STEP], '--tested' => 'php artisan test --filter=PostEndpointsTest (12 pasan)']);

        $this->assertTrue($check['done']);
        $this->assertSame(Planner::DONE, $check['status']);
        $step = $this->step(self::STEP);
        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame('declared', $step['tested']['via']);
        $this->assertSame('php artisan test --filter=PostEndpointsTest (12 pasan)', $step['tested']['command']);
        $this->assertStringContainsString('php artisan test --filter=PostEndpointsTest', (string) end($step['history'])['detail']);

        // --tested sin la orden no vale: la orden es la evidencia.
        $refused = $this->realign('check', ['targets' => [self::STEP], '--tested' => ' '], Command::FAILURE);
        $this->assertStringContainsString('--tested', $refused['error']);
        // Y sólo en check.
        $this->assertStringContainsString('--tested', $this->realign('next', ['--tested' => 'x'], Command::FAILURE)['error']);
    }

    public function test_lo_que_el_diagnostico_ya_no_encuentra_queda_untested_no_done(): void
    {
        // Lo arregla alguien sin correr check ni las pruebas.
        $this->restore();
        $this->realign('diagnose');
        $this->realign('plan');

        $step = $this->step(self::STEP);
        $this->assertSame(Planner::UNTESTED, $step['status']);
        $this->assertSame('resolved', end($step['history'])['event']);
        $this->assertStringContainsString('sin probar', (string) end($step['history'])['detail']);

        // Volver a planificar no lo cambia.
        $this->realign('plan');
        $this->assertSame(Planner::UNTESTED, $this->step(self::STEP)['status']);
    }

    public function test_status_brief_y_done_lo_listan_y_done_falla(): void
    {
        $this->restore();
        $this->realign('check', ['targets' => [self::STEP]]);

        $status = $this->realign('status');
        $this->assertSame(1, $status['steps']['by_status'][Planner::UNTESTED]);
        $this->assertSame([self::STEP], array_column($status['untested'], 'id'));
        $this->assertStringContainsString('accept-untested', $status['untested'][0]['accept']);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'status', '--root' => $this->project->path]));
        $this->assertStringContainsString('Sin probar (1)', $this->lastOutput);

        $brief = $this->realign('brief');
        $this->assertSame(1, $brief['summary']['untested']);
        $this->assertSame([self::STEP], array_column($brief['untested'], 'id'));
        $this->assertStringContainsString('Sin probar (1)', $this->project->read('.larapack/realign/brief.md'));

        $done = $this->realign('done', [], Command::FAILURE);
        $this->assertSame([self::STEP], array_column($done['missing']['untested'], 'id'));
        $this->assertSame(1, $done['counts']['untested']);
        $this->assertSame(0, $done['counts']['steps']);
        $this->assertStringContainsString('sin probar', $done['error']);

        // Probado: done en verde.
        $this->realign('check', ['targets' => [self::STEP], '--tested' => 'vendor/bin/phpunit']);
        $this->assertTrue($this->realign('done')['done']);
    }

    public function test_aceptarlo_sin_probar_lo_confirma_el_dueno_y_queda_a_la_vista(): void
    {
        $this->restore();
        $this->realign('check', ['targets' => [self::STEP]]);

        // Sin nota, no.
        $this->assertStringContainsString('--note', $this->realign('accept-untested', ['targets' => [self::STEP]], Command::FAILURE)['error']);

        // Un agente sin terminal, no: no cambia nada.
        OwnerConfirmation::useTerminal(fn (): null => null);
        $refused = $this->realign('accept-untested', ['targets' => [self::STEP], '--note' => 'no hay base de datos'], Command::FAILURE);
        $this->assertStringContainsString('dueño', $refused['error']);
        $this->assertSame(Planner::UNTESTED, $this->step(self::STEP)['status']);

        // --approver fuera de la corrida de aprobación lanzada a mano en
        // GitHub (NamedApproval), tampoco: nombrarse no basta.
        $this->assertStringContainsString('GitHub Actions',
            $this->realign('accept-untested', ['targets' => [self::STEP], '--note' => 'x', '--approver' => 'Ana'], Command::FAILURE)['error']);

        // Y la guardia de los agentes bloquea la vía sin terminal: cualquier
        // orden con --approver o que dé valor a la variable.
        foreach (['php artisan larapack:realign accept-untested '.self::STEP.' --note=x --approver=Ana',
            'LARAPACK_APPROVER=Ana vendor/bin/builder larapack:realign accept-untested '.self::STEP.' --note=x --approver Ana'] as $command) {
            $this->assertTrue(Guard::approvesHandRolled(['tool_name' => 'Bash', 'tool_input' => ['command' => $command], 'cwd' => $this->project->path]), $command);
        }

        // La frase equivocada, tampoco.
        $this->ownerAtTerminal('sí');
        $this->realign('accept-untested', ['targets' => [self::STEP], '--note' => 'x'], Command::FAILURE);
        $this->assertSame(Planner::UNTESTED, $this->step(self::STEP)['status']);

        // El dueño, en su terminal, viendo qué acepta.
        $terminal = $this->ownerAtTerminal();
        $accepted = $this->realign('accept-untested', ['targets' => [self::STEP], '--note' => 'Se prueba en staging el lunes.']);
        $this->assertStringContainsString(self::STEP, $terminal->shown);
        $this->assertStringContainsString('Se prueba en staging el lunes.', $terminal->shown);
        $this->assertSame(Planner::DONE, $accepted['status']);

        $step = $this->step(self::STEP);
        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame('Se prueba en staging el lunes.', $step['untested_accepted']['note']);
        $this->assertSame('terminal', $step['untested_accepted']['via']);
        $this->assertSame('accepted-untested', end($step['history'])['event']);

        // done pasa y dice qué se aceptó sin probar.
        $done = $this->realign('done');
        $this->assertTrue($done['done']);
        $this->assertSame([self::STEP], array_column($done['accepted_untested'], 'id'));

        // Sólo lo que está sin probar se acepta.
        $this->assertStringContainsString('no está sin probar', $this->realign('accept-untested', ['targets' => [self::STEP], '--note' => 'x'], Command::FAILURE)['error']);
    }

    public function test_la_orden_de_pruebas_configurada_la_corre_check(): void
    {
        $this->restore();
        $config = $this->project->path.'/.larapack/realign/config.json';

        // Fallan: no está hecho, y dice por qué.
        file_put_contents($config, json_encode(['format_version' => 1, 'tests' => 'php -r "fwrite(STDOUT, \"1 falla\n\"); exit(1);"']));
        $check = $this->realign('check', ['targets' => [self::STEP]], Command::FAILURE);
        $this->assertFalse($check['done']);
        $this->assertSame(1, $check['tests']['exit']);
        $this->assertStringContainsString('1 falla', $check['tests']['output']);
        $this->assertStringContainsString('Las pruebas del proyecto fallan', $check['remaining'][0]);
        $this->assertSame(Planner::PENDING, $this->step(self::STEP)['status']);

        // Pasan: hecho, con la orden que se corrió.
        file_put_contents($config, json_encode(['format_version' => 1, 'tests' => 'php -r "exit(0);"']));
        $check = $this->realign('check', ['targets' => [self::STEP]]);
        $this->assertTrue($check['done']);
        $this->assertSame(0, $check['tests']['exit']);
        $this->assertSame('config', $this->step(self::STEP)['tested']['via']);

        // next dice cómo se prueba el paso.
        $show = $this->realign('show', ['targets' => [self::STEP]]);
        $this->assertTrue($show['tests']['required']);
        $this->assertSame('php -r "exit(0);"', $show['tests']['command']);
    }

    public function test_lo_que_no_cambia_comportamiento_se_da_por_hecho_sin_pruebas(): void
    {
        $this->restore();
        $this->edit('src/Models/Post.php', "'title', 'published', 'user_id'", "'user_id', 'title', 'published'");
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
        $this->edit('src/Models/Post.php', "'user_id', 'title', 'published'", "'title', 'published', 'user_id'");
        $this->commitAll();

        $check = $this->realign('check', ['targets' => ['edited-outside:Model@.']]);
        $this->assertTrue($check['done']);
        $this->assertSame(Planner::DONE, $check['status']);
        $this->assertArrayNotHasKey('warning', $check);
        $this->assertFalse($this->realign('show', ['targets' => ['edited-outside:Model@.']])['tests']['required']);
    }

    public function test_un_paso_sin_probar_que_vuelve_a_salir_se_reabre(): void
    {
        $this->restore();
        $this->realign('check', ['targets' => [self::STEP]]);

        $this->edit(self::REQUEST, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (\$this->user()?->isAdmin()) {\n            return true;\n        }\n");
        $this->commitAll();

        // check lo reabre.
        $this->realign('check', ['targets' => [self::STEP]], Command::FAILURE);
        $this->assertSame(Planner::PENDING, $this->step(self::STEP)['status']);

        // Y plan también.
        $this->restore();
        $this->realign('check', ['targets' => [self::STEP]]);
        $this->edit(self::REQUEST, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (\$this->user()?->isAdmin()) {\n            return true;\n        }\n");
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::PENDING, $this->step(self::STEP)['status']);
        $history = $this->step(self::STEP)['history'];
        $this->assertSame('reopened', end($history)['event']);
    }
}
