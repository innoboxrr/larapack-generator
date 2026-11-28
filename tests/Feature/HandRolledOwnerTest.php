<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Tests\Support\HookProject;
use PHPUnit\Framework\TestCase;

/**
 * `larapack:hand-rolled approve` y `revoke` los confirma una persona, también
 * en un proyecto sin la guardia de los agentes ni el hook de git.
 *
 * Hasta 9.4.0 `approve --all` con la entrada en /dev/null y sin terminal
 * aprobaba todo: un agente sin la guardia instalada aprobaba sus propias
 * excepciones. Aquí el comando se lanza de verdad, como un proceso: sin
 * terminal de control (`setsid`), con una de mentira (`script`) o con la vía
 * sin terminal de una persona (`LARAPACK_APPROVER` + `--approver`).
 */
final class HandRolledOwnerTest extends TestCase
{
    private const REASON = 'La firma se calcula sobre el cuerpo en bruto.';

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
        return $this->project->run(['php', 'vendor/bin/builder', 'larapack:hand-rolled', ...$arguments, '--root='.$this->project->path], '', $env + [OwnerConfirmation::APPROVER_ENV => ''], true);
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

        return $this->project->run(['script', '-qec', 'php vendor/bin/builder larapack:hand-rolled '.$arguments.' --root='.escapeshellarg($this->project->path), '/dev/null'], $typed, [OwnerConfirmation::APPROVER_ENV => '']);
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
        $this->assertStringContainsString(OwnerConfirmation::APPROVER_ENV, $result['stdout'].$result['stderr']);

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

    public function test_sin_terminal_una_persona_aprueba_nombrandose(): void
    {
        // La variable sola —heredada del perfil de la shell— no basta.
        $inherited = $this->detached(['approve', '--all'], [OwnerConfirmation::APPROVER_ENV => 'Ana Pérez']);
        $this->assertNotSame(0, $inherited['code']);
        $this->assertFileDoesNotExist($this->lock());

        // La opción sola, tampoco.
        $option = $this->detached(['approve', '--all', '--approver=Ana Pérez']);
        $this->assertNotSame(0, $option['code']);
        $this->assertStringContainsString(OwnerConfirmation::APPROVER_ENV, $option['stdout'].$option['stderr']);
        $this->assertFileDoesNotExist($this->lock());

        // Ni con otro nombre.
        $other = $this->detached(['approve', '--all', '--approver=Otro'], [OwnerConfirmation::APPROVER_ENV => 'Ana Pérez']);
        $this->assertNotSame(0, $other['code']);
        $this->assertFileDoesNotExist($this->lock());

        $named = $this->detached(['approve', '--all', '--approver=Ana Pérez'], [OwnerConfirmation::APPROVER_ENV => 'Ana Pérez']);
        $this->assertSame(0, $named['code'], $named['stdout'].$named['stderr']);
        $this->assertStringContainsString(self::REASON, $named['stdout']);

        $lock = json_decode((string) file_get_contents($this->lock()), true);
        $this->assertSame('Ana Pérez', $lock[0]['approved_by']);
        $this->assertSame(self::REASON, HandRolledLock::read($this->project->path)['Acme\Notes\Http\Controllers\InboundWebhookController']['reason']);
    }
}
