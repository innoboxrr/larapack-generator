<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\NamedApproval;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Tests\Support\HookProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `larapack:hand-rolled approve` y `revoke` los confirma una persona, también
 * en un proyecto sin la guardia de los agentes ni el hook de git.
 *
 * Hasta 9.4.0 `approve --all` con la entrada en /dev/null y sin terminal
 * aprobaba todo: un agente sin la guardia instalada aprobaba sus propias
 * excepciones. Aquí el comando se lanza de verdad, como un proceso: sin
 * terminal de control (`setsid`), con una de mentira (`script`) o con la vía
 * sin terminal de una persona: el workflow de aprobación de GitHub Actions,
 * lanzado a mano (NamedApproval).
 *
 * En una prueba de autonomía real, un agente sin la guardia se aprobó sus
 * propias excepciones con `LARAPACK_APPROVER="…" … approve --all
 * --approver="…"`, la orden que le había enseñado el mensaje de rechazo.
 * Aquí se prueba que esa orden ya no aprueba nada fuera del workflow, que
 * ningún rechazo la enseña y que todos salen con un código distinto de cero.
 */
final class HandRolledOwnerTest extends TestCase
{
    private const REASON = 'La firma se calcula sobre el cuerpo en bruto.';

    /**
     * Lo que una corrida de Actions pone en el entorno. Vacío fuera: la suite
     * también corre en Actions, y no debe heredarlo.
     */
    private const OUTSIDE_ACTIONS = ['GITHUB_ACTIONS' => '', 'GITHUB_EVENT_NAME' => '', 'GITHUB_REPOSITORY' => '', 'GITHUB_RUN_ID' => '',
        'GITHUB_WORKFLOW_REF' => '', 'GITHUB_ACTOR' => '', 'GITHUB_TRIGGERING_ACTOR' => '', 'GITHUB_SERVER_URL' => ''];

    private HookProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        if (! HookProject::canDetach()) {
            $this->markTestSkipped('Hace falta setsid(1) para lanzar el comando sin terminal de control.');
        }

        $this->project = new HookProject(false);
        $this->project->write('src/Http/Controllers/InboundWebhookController.php', <<<'PHP'
            <?php

            namespace Acme\Notes\Http\Controllers;

            use Illuminate\Http\Request;
            use Innoboxrr\LarapackGenerator\Attributes\HandRolled;

            #[HandRolled('La firma se calcula sobre el cuerpo en bruto.')]
            class InboundWebhookController
            {
                public function __invoke(Request $request)
                {
                    return response()->noContent();
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function detached(array $arguments, array $env = []): array
    {
        return $this->project->run(['php', 'vendor/bin/builder', 'larapack:hand-rolled', ...$arguments, '--root='.$this->project->path], '', $env + [OwnerConfirmation::APPROVER_ENV => ''] + self::OUTSIDE_ACTIONS, true);
    }

    /**
     * El entorno de una corrida del workflow de aprobación lanzada a mano por
     * `ana`, con lo que se quiera cambiar.
     *
     * @param  array<string, string>  $override
     * @return array<string, string>
     */
    private static function approvalRun(array $override = []): array
    {
        return $override + [
            'GITHUB_ACTIONS' => 'true',
            'GITHUB_EVENT_NAME' => 'workflow_dispatch',
            'GITHUB_REPOSITORY' => 'acme/notes',
            'GITHUB_RUN_ID' => '4242',
            'GITHUB_WORKFLOW_REF' => 'acme/notes/'.NamedApproval::WORKFLOW.'@refs/heads/feature',
            'GITHUB_ACTOR' => 'ana',
            'GITHUB_TRIGGERING_ACTOR' => 'ana',
            'GITHUB_SERVER_URL' => 'https://github.com',
            OwnerConfirmation::APPROVER_ENV => 'ana',
        ];
    }

    /**
     * Lo que un rechazo no puede enseñar: la orden de la vía sin terminal
     * lista para copiar.
     */
    private function assertDoesNotTeachTheBypass(string $message): void
    {
        $this->assertStringNotContainsString(OwnerConfirmation::APPROVER_ENV.'=', $message);
        $this->assertStringNotContainsString('--approver=', $message);
        $this->assertStringContainsString('agente', $message);
        $this->assertStringContainsString(NamedApproval::DOCS, $message);
    }

    /**
     * Con una terminal de mentira: `script` le da al comando una terminal y le
     * pasa lo que se escribe.
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function withTerminal(string $arguments, string $typed): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_executable('/usr/bin/script')) {
            $this->markTestSkipped('Hace falta script(1) de util-linux para darle al comando una terminal.');
        }

        return $this->project->run(['script', '-qec', 'php vendor/bin/builder larapack:hand-rolled '.$arguments.' --root='.escapeshellarg($this->project->path), '/dev/null'], $typed, [OwnerConfirmation::APPROVER_ENV => ''] + self::OUTSIDE_ACTIONS);
    }

    private function lock(): string
    {
        return $this->project->path.'/'.HandRolledLock::PATH;
    }

    public function test_sin_terminal_approve_no_aprueba_nada(): void
    {
        $result = $this->detached(['approve', '--all']);

        $this->assertNotSame(0, $result['code'], $result['stdout'].$result['stderr']);
        $this->assertFileDoesNotExist($this->lock());
        $this->assertStringContainsString('terminal', $result['stdout'].$result['stderr']);
        // Remite a la documentación para personas, no a la orden.
        $this->assertDoesNotTeachTheBypass($result['stdout'].$result['stderr']);

        // Tampoco en JSON: el formato no cambia quién confirma.
        $json = $this->detached(['approve', '--all', '--format=json']);
        $this->assertNotSame(0, $json['code']);
        $this->assertFalse(json_decode($json['stdout'], true)['ok']);
        $this->assertFileDoesNotExist($this->lock());
    }

    public function test_sin_terminal_revoke_tampoco(): void
    {
        $this->project->write(HandRolledLock::PATH, (string) json_encode([['target' => 'Acme\Notes\Http\Controllers\InboundWebhookController', 'reason' => self::REASON, 'fingerprint' => null]]));
        $before = (string) file_get_contents($this->lock());

        $result = $this->detached(['revoke', 'InboundWebhookController']);

        $this->assertNotSame(0, $result['code'], $result['stdout'].$result['stderr']);
        $this->assertSame($before, file_get_contents($this->lock()));
    }

    public function test_con_terminal_la_persona_ve_lo_que_aprueba_y_lo_confirma(): void
    {
        $refused = $this->withTerminal('approve --all', "si\n");
        $this->assertNotSame(0, $refused['code'], $refused['stdout']);
        $this->assertFileDoesNotExist($this->lock());

        $approved = $this->withTerminal('approve --all', OwnerConfirmation::phrase('approve', 1)."\n");
        $this->assertSame(0, $approved['code'], $approved['stdout'].$approved['stderr']);
        $this->assertStringContainsString('Acme\Notes\Http\Controllers\InboundWebhookController', $approved['stdout']);
        $this->assertStringContainsString(self::REASON, $approved['stdout']);
        $this->assertStringContainsString('src/Http/Controllers/InboundWebhookController.php', $approved['stdout']);
        $this->assertStringContainsString('«apruebo 1»', $approved['stdout']);

        $lock = json_decode((string) file_get_contents($this->lock()), true);
        $this->assertSame(['Acme\Notes\Http\Controllers\InboundWebhookController'], array_column($lock, 'target'));
        $this->assertArrayNotHasKey('approved_by', $lock[0]);
    }

    /**
     * Una tubería no es una persona: la pregunta va a la terminal, no a la
     * entrada estándar.
     */
    public function test_una_tuberia_no_contesta_la_pregunta(): void
    {
        $result = $this->project->run(['php', 'vendor/bin/builder', 'larapack:hand-rolled', 'approve', '--all', '--root='.$this->project->path], "apruebo 1\n", [OwnerConfirmation::APPROVER_ENV => ''], true);

        $this->assertNotSame(0, $result['code']);
        $this->assertFileDoesNotExist($this->lock());
    }

    /**
     * La orden exacta con la que el agente de la prueba de autonomía se
     * aprobó sus excepciones: fuera del workflow de aprobación, no aprueba
     * nada, sale con error y no enseña cómo hacerlo.
     */
    public function test_nombrarse_fuera_del_workflow_de_aprobacion_no_aprueba(): void
    {
        $named = $this->detached(['approve', '--all', '--approver=Ana Pérez'], [OwnerConfirmation::APPROVER_ENV => 'Ana Pérez']);

        $this->assertNotSame(0, $named['code'], $named['stdout'].$named['stderr']);
        $this->assertFileDoesNotExist($this->lock());
        $this->assertDoesNotTeachTheBypass($named['stdout'].$named['stderr']);

        $json = $this->detached(['approve', '--all', '--approver=Ana Pérez', '--format=json'], [OwnerConfirmation::APPROVER_ENV => 'Ana Pérez']);
        $this->assertNotSame(0, $json['code']);
        $this->assertFalse(json_decode($json['stdout'], true)['ok']);
        $this->assertFileDoesNotExist($this->lock());

        // Ni con un CI=true cualquiera: los agentes también corren en CI.
        $ci = $this->detached(['approve', '--all', '--approver=Ana Pérez'], [OwnerConfirmation::APPROVER_ENV => 'Ana Pérez', 'CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'push']);
        $this->assertNotSame(0, $ci['code']);
        $this->assertFileDoesNotExist($this->lock());
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: list<string>}>
     */
    public static function notTheApprovalRun(): array
    {
        return [
            'la variable sola' => [[], []],
            'la opción sola' => [[OwnerConfirmation::APPROVER_ENV => ''], ['--approver=ana']],
            'otro nombre que el de quien la lanzó' => [[OwnerConfirmation::APPROVER_ENV => 'Ana Pérez'], ['--approver=Ana Pérez']],
            'un push, no a mano' => [['GITHUB_EVENT_NAME' => 'push'], ['--approver=ana']],
            'un pull_request' => [['GITHUB_EVENT_NAME' => 'pull_request'], ['--approver=ana']],
            'otro workflow' => [['GITHUB_WORKFLOW_REF' => 'acme/notes/.github/workflows/agente.yml@refs/heads/feature'], ['--approver=ana']],
            'el workflow de otro repositorio' => [['GITHUB_WORKFLOW_REF' => 'otro/repo/'.NamedApproval::WORKFLOW.'@refs/heads/main'], ['--approver=ana']],
            'la relanzó otra persona' => [['GITHUB_TRIGGERING_ACTOR' => 'mallory'], ['--approver=ana']],
            'la lanzó un bot' => [['GITHUB_ACTOR' => 'claude[bot]', 'GITHUB_TRIGGERING_ACTOR' => 'claude[bot]', OwnerConfirmation::APPROVER_ENV => 'claude[bot]'], ['--approver=claude[bot]']],
        ];
    }

    /**
     * @param  array<string, string>  $override
     * @param  list<string>  $options
     */
    #[DataProvider('notTheApprovalRun')]
    public function test_sin_terminal_solo_aprueba_el_workflow_de_aprobacion_lanzado_a_mano(array $override, array $options): void
    {
        $result = $this->detached(['approve', '--all', ...$options], self::approvalRun($override));

        $this->assertNotSame(0, $result['code'], $result['stdout'].$result['stderr']);
        $this->assertFileDoesNotExist($this->lock());
    }

    public function test_el_workflow_de_aprobacion_aprueba_a_nombre_de_quien_lo_lanzo(): void
    {
        $named = $this->detached(['approve', '--all', '--approver=ana'], self::approvalRun());
        $this->assertSame(0, $named['code'], $named['stdout'].$named['stderr']);
        $this->assertStringContainsString(self::REASON, $named['stdout']);

        $lock = json_decode((string) file_get_contents($this->lock()), true);
        $this->assertSame('ana', $lock[0]['approved_by']);
        $this->assertSame('https://github.com/acme/notes/actions/runs/4242', $lock[0]['approved_run']);
        $approval = HandRolledLock::read($this->project->path)['Acme\Notes\Http\Controllers\InboundWebhookController'];
        $this->assertSame(self::REASON, $approval['reason']);
        $this->assertSame('https://github.com/acme/notes/actions/runs/4242', $approval['approved_run']);
    }

    /**
     * Ningún rechazo sale con 0: quien lo corre en un script, una CI o un
     * agente lo trata como un fallo, no como hecho. También por Artisan (la
     * aplicación de Testbench) y desde una terminal de verdad.
     */
    public function test_todo_rechazo_sale_con_un_codigo_distinto_de_cero(): void
    {
        $this->project->write(HandRolledLock::PATH, (string) json_encode([['target' => 'Acme\Notes\Http\Controllers\InboundWebhookController', 'reason' => 'Otra razón.', 'fingerprint' => null]]));
        $before = (string) file_get_contents($this->lock());

        $refusals = [
            'approve sin terminal' => $this->detached(['approve', '--all']),
            'revoke sin terminal' => $this->detached(['revoke', 'InboundWebhookController']),
            'approve nombrándose fuera del workflow' => $this->detached(['approve', '--all', '--approver=ana'], [OwnerConfirmation::APPROVER_ENV => 'ana']),
            'revoke nombrándose fuera del workflow' => $this->detached(['revoke', 'InboundWebhookController', '--approver=ana'], [OwnerConfirmation::APPROVER_ENV => 'ana']),
        ];

        $testbench = dirname(__DIR__, 2).'/vendor/bin/testbench';

        if (is_file($testbench)) {
            foreach (['approve' => ['--all'], 'revoke' => ['InboundWebhookController']] as $action => $arguments) {
                $refusals["{$action} por Artisan"] = $this->project->run(['php', $testbench, 'larapack:hand-rolled', $action, ...$arguments, '--root='.$this->project->path], '', [OwnerConfirmation::APPROVER_ENV => ''] + self::OUTSIDE_ACTIONS, true);
            }
        }

        if (PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/script')) {
            $refusals['approve en la terminal, sin la frase'] = $this->withTerminal('approve --all', "si\n");
            $refusals['revoke en la terminal, sin la frase'] = $this->withTerminal('revoke InboundWebhookController', "no\n");
        }

        foreach ($refusals as $what => $result) {
            $this->assertNotSame(0, $result['code'], "{$what}: ".$result['stdout'].$result['stderr']);
            $this->assertSame($before, file_get_contents($this->lock()), $what);
        }
    }
}
