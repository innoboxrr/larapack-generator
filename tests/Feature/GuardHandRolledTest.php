<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Commands\GuardCommand;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La guardia de los agentes frente a la aprobación de las excepciones
 * #[HandRolled]: un agente no edita `.larapack/hand-rolled.lock` ni corre
 * `larapack:hand-rolled approve` o `revoke`.
 *
 * Tan importante como lo que bloquea es lo que deja pasar: el hook previo
 * corre en cada orden de Bash, y uno que se queja de un `git commit -m
 * "approve"` acaba quitado del settings.json.
 */
final class GuardHandRolledTest extends TestCase
{
    private string $stderr = '';

    private string $stdout = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    private function guard(array $payload, array $options = []): int
    {
        $tester = new CommandTester(new GuardCommand);
        $tester->setInputs([(string) json_encode($payload + ['cwd' => $this->project->path])]);

        $code = $tester->execute($options, ['capture_stderr_separately' => true]);

        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $code;
    }

    private function bash(string $command, string $event = 'PreToolUse'): int
    {
        return $this->guard(['hook_event_name' => $event, 'tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);
    }

    /**
     * @return list<string>
     */
    public static function approvals(): array
    {
        return [
            'php artisan larapack:hand-rolled approve --all',
            'php vendor/bin/builder larapack:hand-rolled approve TokenController::store',
            "php artisan larapack:hand-rolled revoke 'App\\Http\\Controllers\\X'",
            'php artisan larapack:hand-rolled --root packages/billing approve --all',
            'php artisan larapack:hand-rolled --root=packages/billing approve --all',
            'cd packages/billing && php ../../vendor/bin/builder larapack:hand-rolled approve --all',
            'php artisan larapack:hand-rolled "approve" --all',
            'php vendor/bin/builder make:hand-rolled approve --all',
            'php artisan larapack:hand approve --all',
            'php artisan l:h revoke X',
            'bash -c "php artisan larapack:hand-rolled approve --all"',
            "sh -c 'cd app && php artisan larapack:hand-rolled approve --all' > /dev/null",
            "php artisan larapack:hand-rolled approve --all\ngit add .larapack",
            '(php artisan larapack:hand-rolled approve --all)',
            'eval "php artisan larapack:hand-rolled approve --all"',
            "bash -lc 'php artisan larapack:hand-rolled revoke X'",
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [
            'php artisan larapack:hand-rolled list',
            'php artisan larapack:hand-rolled list --format=json | grep approve',
            'php artisan larapack:hand-rolled list; echo approve',
            'git commit -m "approve the plan"',
            'grep -rn "larapack:hand-rolled approve" docs',
            'cat .larapack/hand-rolled.lock',
            'git diff -- .larapack/hand-rolled.lock',
            'php artisan larapack:verify --strict',
            'php artisan larapack:handle approve',
            'echo larapack:hand-rolled',
            'echo "php artisan larapack:hand-rolled approve --all"',
        ];
    }

    public function test_el_hook_previo_bloquea_cada_forma_de_aprobar(): void
    {
        foreach (self::approvals() as $command) {
            $this->assertTrue(Guard::approvesHandRolled(['tool_input' => ['command' => $command]]), $command);
            $this->assertSame(GuardCommand::BLOCK, $this->bash($command), $command);
            $this->assertStringContainsString('Sólo el dueño del proyecto aprueba excepciones', $this->stderr, $command);
            $this->assertStringContainsString('los corre el dueño del proyecto, no un agente', $this->stderr);
            $this->assertStringNotContainsString('Ya está hecho', $this->stderr);
        }
    }

    public function test_el_hook_previo_deja_pasar_lo_que_no_aprueba(): void
    {
        foreach (self::allowed() as $command) {
            $this->assertFalse(Guard::approvesHandRolled(['tool_input' => ['command' => $command]]), $command);
            $this->assertSame(Command::SUCCESS, $this->bash($command), $command);
            $this->assertSame('', $this->stderr.$this->stdout, $command);
        }
    }

    public function test_el_hook_previo_bloquea_editar_el_candado(): void
    {
        $lock = $this->project->path.'/'.HandRolledLock::PATH;

        foreach ([
            ['Write', ['file_path' => $lock, 'content' => '[]']],
            ['Edit', ['file_path' => HandRolledLock::PATH, 'old_string' => 'a', 'new_string' => 'b']],
            ['Edit', ['file_path' => $this->project->path.'/.larapack/Hand-Rolled.lock']],
            ['MultiEdit', ['file_path' => $this->project->path.'/src/X.php', 'edits' => [['file_path' => $lock]]]],
            ['Write', ['file_path' => $this->project->path.'/packages/billing/.larapack/hand-rolled.lock']],
        ] as [$tool, $input]) {
            $code = $this->guard(['hook_event_name' => 'PreToolUse', 'tool_name' => $tool, 'tool_input' => $input]);

            $this->assertSame(GuardCommand::BLOCK, $code, json_encode($input));
            $this->assertStringContainsString('es la aprobación del dueño del proyecto', $this->stderr);
        }

        // El manifiesto, al lado, no es asunto de este hook.
        $code = $this->guard(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Write', 'tool_input' => ['file_path' => $this->project->path.'/.larapack/manifest.json']]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame('', $this->stderr.$this->stdout);
    }

    /**
     * El hook previo sólo mira la aprobación: un controlador con lógica se
     * comprueba sobre lo escrito, después, y no antes de que exista.
     */
    public function test_el_hook_previo_no_corre_el_verifier(): void
    {
        $controller = $this->project->path.'/src/Http/Controllers/FatController.php';
        mkdir(dirname($controller), 0777, true);
        file_put_contents($controller, "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers;\n\nclass FatController\n{\n    public function index(\\Illuminate\\Http\\Request \$request)\n    {\n        return \$request->all();\n    }\n}\n");

        $this->assertSame(Command::SUCCESS, $this->guard(['tool_name' => 'Edit', 'tool_input' => ['file_path' => $controller]], ['--pre' => true]));
        $this->assertSame('', $this->stderr.$this->stdout);

        $this->assertSame(GuardCommand::BLOCK, $this->guard(['hook_event_name' => 'PostToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => $controller]]));
        $this->assertStringContainsString('controller-shape', $this->stderr);
    }

    public function test_con_pre_y_sin_evento_tambien_bloquea(): void
    {
        $code = $this->guard(['tool_name' => 'Bash', 'tool_input' => ['command' => 'php artisan larapack:hand-rolled approve --all']], ['--pre' => true]);

        $this->assertSame(GuardCommand::BLOCK, $code);
    }

    /**
     * Después ya está escrito: se bloquea igual, para que el agente lo lea, y
     * se le pide que lo deshaga.
     */
    public function test_despues_de_la_herramienta_pide_deshacerlo(): void
    {
        $lock = $this->project->path.'/'.HandRolledLock::PATH;

        $this->assertSame(GuardCommand::BLOCK, $this->guard(['hook_event_name' => 'PostToolUse', 'tool_name' => 'Write', 'tool_input' => ['file_path' => $lock]]));
        $this->assertStringContainsString('Ya está hecho: deshazlo', $this->stderr);

        $this->assertSame(GuardCommand::BLOCK, $this->bash('php artisan larapack:hand-rolled approve --all', 'PostToolUse'));
        $this->assertStringContainsString('Ya está hecho: deshazlo', $this->stderr);
    }

    /**
     * Con candado, un #[HandRolled] que el agente añade sin aprobación es un
     * error del archivo que editó: la guardia lo devuelve en el acto.
     */
    public function test_con_candado_un_hand_rolled_nuevo_se_bloquea_al_escribirlo(): void
    {
        HandRolledLock::write($this->project->path, []);

        $controller = $this->project->path.'/src/Http/Controllers/WebhookController.php';
        mkdir(dirname($controller), 0777, true);
        file_put_contents($controller, "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers;\n\nuse Innoboxrr\\LarapackGenerator\\Attributes\\HandRolled;\n\n"
            ."#[HandRolled('Para que verify pase.')]\nclass WebhookController\n{\n    public function __invoke(\\Illuminate\\Http\\Request \$request)\n    {\n        return \$request->all();\n    }\n}\n");

        $this->assertSame(GuardCommand::BLOCK, $this->guard(['hook_event_name' => 'PostToolUse', 'tool_name' => 'Write', 'tool_input' => ['file_path' => $controller]]));
        $this->assertStringContainsString('hand-rolled-unapproved', $this->stderr);
        $this->assertStringContainsString('Sólo el dueño del proyecto aprueba excepciones', $this->stderr);

        // Sin candado, como en 9.0: es aviso, no bloquea.
        unlink($this->project->path.'/'.HandRolledLock::PATH);

        $this->assertSame(Command::SUCCESS, $this->guard(['hook_event_name' => 'PostToolUse', 'tool_name' => 'Write', 'tool_input' => ['file_path' => $controller]]));
    }
}
