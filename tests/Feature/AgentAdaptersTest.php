<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Commands\GuardCommand;
use Innoboxrr\LarapackGenerator\Support\AgentAdapters;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La guardia con la entrada de cada agente, y su respuesta en el idioma de
 * cada uno.
 *
 * Las entradas son las que documenta cada agente: Cursor (`preToolUse` con
 * `tool_input`, `beforeShellExecution` con `command` suelto, `postToolUse`,
 * `stop` con `loop_count`), Codex (la de Claude Code, con `turn_id`, y los
 * parches de `apply_patch`), Gemini CLI (`BeforeTool`, `AfterTool`,
 * `AfterAgent`), Copilot (`toolName` y `toolArgs` en texto JSON, sin el
 * evento) y Windsurf (`agent_action_name` y `tool_info`). Lo que se comprueba
 * es lo que importa: que lo que hay que frenar se frene con la señal que el
 * agente respeta, y que el motivo vaya por el canal que llega al modelo.
 */
final class AgentAdaptersTest extends TestCase
{
    private string $stdout = '';

    private string $stderr = '';

    private string $temporary = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporary = sys_get_temp_dir().'/larapack-adapters-'.bin2hex(random_bytes(6));
        mkdir($this->temporary, 0700);
        Guard::useTemporaryDirectory($this->temporary);

        $this->useProject(FakeProject::library('Acme\\Notes\\'));

        mkdir($this->project->path.'/src/Http/Controllers', 0777, true);
        mkdir($this->project->path.'/src/Http/Requests/Note', 0777, true);

        file_put_contents($this->project->path.'/src/Http/Requests/Note/IndexRequest.php', <<<'PHP'
        <?php

        namespace Acme\Notes\Http\Requests\Note;

        use Illuminate\Foundation\Http\FormRequest;

        class IndexRequest extends FormRequest
        {
            public function handle(): array
            {
                return [];
            }
        }
        PHP);

        $this->writeController(true);
    }

    protected function tearDown(): void
    {
        Guard::useTemporaryDirectory(null);
        @array_map('unlink', glob($this->temporary.'/*') ?: []);
        @rmdir($this->temporary);

        parent::tearDown();
    }

    private function writeController(bool $clean): void
    {
        $body = $clean
            ? "use Acme\\Notes\\Http\\Requests\\Note\\IndexRequest;\n\nclass NoteController\n{\n    public function index(IndexRequest \$request)\n    {\n        return \$request->handle();\n    }\n}\n"
            : "use Illuminate\\Http\\Request;\n\nclass NoteController\n{\n    public function index(Request \$request)\n    {\n        \$data = \$request->validate(['q' => ['nullable']]);\n\n        return response()->json(\$data);\n    }\n}\n";

        file_put_contents($this->controller(), "<?php\n\nnamespace Acme\\Notes\\Http\\Controllers;\n\n".$body);
    }

    private function controller(): string
    {
        return $this->project->path.'/src/Http/Controllers/NoteController.php';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    private function guard(array $payload, array $options = []): int
    {
        $tester = new CommandTester(new GuardCommand);
        $tester->setInputs([(string) json_encode($payload)]);

        $code = $tester->execute($options, ['capture_stderr_separately' => true]);

        $this->stdout = trim($tester->getDisplay());
        $this->stderr = trim($tester->getErrorOutput());

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function stdoutJson(): array
    {
        $decoded = json_decode($this->stdout, true);
        $this->assertIsArray($decoded, 'La salida estándar debería ser un JSON: '.$this->stdout);

        return $decoded;
    }

    public function test_un_agente_desconocido_no_comprueba_nada_y_lo_dice(): void
    {
        $this->assertSame(Command::FAILURE, $this->guard(['tool_input' => ['command' => 'ls']], ['--agent' => 'vscode']));
        $this->assertStringContainsString('--agent=vscode no es un agente conocido', $this->stderr);
    }

    public function test_reconoce_al_agente_por_la_forma_de_su_entrada(): void
    {
        $this->assertSame(AgentAdapters::CURSOR, AgentAdapters::detect(['hook_event_name' => 'preToolUse', 'cursor_version' => '2.1']));
        $this->assertSame(AgentAdapters::CURSOR, AgentAdapters::detect(['workspace_roots' => ['/x']]));
        $this->assertSame(AgentAdapters::WINDSURF, AgentAdapters::detect(['agent_action_name' => 'pre_run_command']));
        $this->assertSame(AgentAdapters::COPILOT, AgentAdapters::detect(['toolName' => 'bash', 'toolArgs' => '{}']));
        $this->assertSame(AgentAdapters::GEMINI, AgentAdapters::detect(['hook_event_name' => 'BeforeTool']));
        $this->assertSame(AgentAdapters::CODEX, AgentAdapters::detect(['hook_event_name' => 'PreToolUse', 'turn_id' => 't']));
        $this->assertSame(AgentAdapters::CLAUDE, AgentAdapters::detect(['hook_event_name' => 'PreToolUse']));
    }

    // Cursor

    public function test_cursor_niega_antes_de_la_herramienta_con_permission_deny_y_codigo_2(): void
    {
        $code = $this->guard([
            'hook_event_name' => 'preToolUse', 'cursor_version' => '2.1', 'workspace_roots' => [$this->project->path],
            'tool_name' => 'Shell', 'tool_input' => ['command' => 'php vendor/bin/builder larapack:hand-rolled approve --all'],
        ], ['--agent' => 'cursor']);

        $this->assertSame(2, $code);
        $reply = $this->stdoutJson();
        $this->assertSame('deny', $reply['permission']);
        $this->assertStringContainsString('dueño del proyecto', $reply['agent_message']);
        $this->assertStringStartsWith('LaraPack lo impidió:', $reply['user_message']);
    }

    public function test_cursor_before_shell_execution_trae_la_orden_suelta(): void
    {
        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'beforeShellExecution', 'cursor_version' => '2.1', 'workspace_roots' => [$this->project->path],
            'command' => 'git commit --no-verify -m wip', 'cwd' => $this->project->path,
        ], ['--agent' => 'cursor']));

        $this->assertStringContainsString('--no-verify', $this->stdoutJson()['agent_message']);
    }

    public function test_cursor_escribir_el_candado_tambien_se_niega(): void
    {
        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'preToolUse', 'cursor_version' => '2.1', 'workspace_roots' => [$this->project->path],
            'tool_name' => 'Write', 'tool_input' => ['file_path' => $this->project->path.'/.larapack/hand-rolled.lock', 'content' => '[]'],
        ], ['--agent' => 'cursor']));

        $this->assertSame('deny', $this->stdoutJson()['permission']);
    }

    public function test_cursor_despues_de_editar_devuelve_el_motivo_como_additional_context(): void
    {
        $this->writeController(false);

        $code = $this->guard([
            'hook_event_name' => 'postToolUse', 'cursor_version' => '2.1', 'workspace_roots' => [$this->project->path],
            'tool_name' => 'Write', 'tool_input' => ['file_path' => $this->controller(), 'content' => '...'],
        ], ['--agent' => 'cursor']);

        // postToolUse no puede bloquear: el motivo llega al modelo por
        // additional_context, que es lo único suyo que Cursor le pasa.
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('controller-shape', $this->stdoutJson()['additional_context']);
    }

    public function test_cursor_al_terminar_pide_otra_vuelta_con_followup_message_y_sólo_una_vez(): void
    {
        $this->writeController(false);
        $stop = ['hook_event_name' => 'stop', 'cursor_version' => '2.1', 'workspace_roots' => [$this->project->path], 'status' => 'completed', 'loop_count' => 0];

        $this->assertSame(Command::SUCCESS, $this->guard($stop, ['--agent' => 'cursor', '--stop' => true]));
        $this->assertStringContainsString('la tarea no ha terminado', $this->stdoutJson()['followup_message']);

        // La segunda vuelta la provocó este hook: se deja terminar.
        $this->assertSame(Command::SUCCESS, $this->guard(['loop_count' => 1] + $stop, ['--agent' => 'cursor', '--stop' => true]));
        $this->assertSame('', $this->stdout);
    }

    /**
     * Cursor importa los hooks de .claude/settings.json y los ejecuta con su
     * entrada: sin reconocerla, el de Claude Code no veía la orden.
     */
    public function test_el_hook_de_claude_code_ejecutado_por_cursor_reconoce_su_entrada(): void
    {
        $payload = [
            'hook_event_name' => 'preToolUse', 'cursor_version' => '2.1', 'workspace_roots' => [$this->project->path],
            'tool_name' => 'Shell', 'tool_input' => ['command' => 'git -c core.hooksPath=/dev/null commit -m x'],
        ];

        $this->assertSame(2, $this->guard($payload, ['--pre' => true]));
        $this->assertSame('deny', $this->stdoutJson()['permission']);

        // Con los hooks propios de Cursor en el proyecto, el importado calla:
        // ya contesta el de Cursor.
        mkdir($this->project->path.'/.cursor');
        file_put_contents($this->project->path.'/.cursor/hooks.json', '{"version":1,"hooks":{"preToolUse":[{"command":"php vendor/bin/builder larapack:guard --agent=cursor --pre"}]}}');

        $this->assertSame(Command::SUCCESS, $this->guard($payload, ['--pre' => true]));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    // Codex

    public function test_codex_bloquea_antes_de_la_herramienta_con_codigo_2(): void
    {
        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'PreToolUse', 'turn_id' => 't1', 'cwd' => $this->project->path,
            'tool_name' => 'Bash', 'tool_input' => ['command' => 'git push --no-verify origin main'],
        ], ['--agent' => 'codex']));

        $this->assertStringContainsString('git push --no-verify', $this->stderr);
    }

    public function test_codex_un_parche_que_toca_el_candado_se_bloquea_y_no_se_lee_como_orden(): void
    {
        $patch = "*** Begin Patch\n*** Update File: .larapack/hand-rolled.lock\n@@\n-[]\n+[{\"target\":\"X\"}]\n*** End Patch\n";

        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'PreToolUse', 'turn_id' => 't1', 'cwd' => $this->project->path,
            'tool_name' => 'apply_patch', 'tool_input' => ['command' => $patch],
        ], ['--agent' => 'codex']));

        $this->assertStringContainsString('hand-rolled.lock es la aprobación del dueño', $this->stderr);
    }

    public function test_codex_despues_de_un_parche_devuelve_decision_block_con_el_motivo(): void
    {
        $this->writeController(false);
        $patch = "*** Begin Patch\n*** Update File: src/Http/Controllers/NoteController.php\n@@\n-a\n+b\n*** End Patch\n";

        $code = $this->guard([
            'hook_event_name' => 'PostToolUse', 'turn_id' => 't1', 'cwd' => $this->project->path,
            'tool_name' => 'apply_patch', 'tool_input' => ['command' => $patch], 'tool_response' => 'ok',
        ], ['--agent' => 'codex']);

        $this->assertSame(Command::SUCCESS, $code);
        $reply = $this->stdoutJson();
        $this->assertSame('block', $reply['decision']);
        $this->assertStringContainsString('controller-shape', $reply['reason']);
    }

    public function test_codex_un_parche_dentro_de_una_orden_de_shell_tambien_cuenta(): void
    {
        $command = "apply_patch <<'EOF'\n*** Begin Patch\n*** Add File: .larapack/hand-rolled.lock\n+[]\n*** End Patch\nEOF";

        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'PreToolUse', 'turn_id' => 't1', 'cwd' => $this->project->path,
            'tool_name' => 'Bash', 'tool_input' => ['command' => $command],
        ], ['--agent' => 'codex']));
    }

    public function test_codex_al_terminar_retiene_con_codigo_2(): void
    {
        $this->writeController(false);

        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'Stop', 'turn_id' => 't1', 'cwd' => $this->project->path, 'stop_hook_active' => false,
        ], ['--agent' => 'codex']));
        $this->assertStringContainsString('la tarea no ha terminado', $this->stderr);
    }

    public function test_lee_las_rutas_de_un_parche(): void
    {
        $this->assertSame(
            ['a/Nuevo.php', 'b/Viejo.php', 'd/Movido.php', 'c/Borrado.php'],
            AgentAdapters::patchedFiles("*** Begin Patch\n*** Add File: a/Nuevo.php\n+x\n*** Update File: b/Viejo.php\n*** Move to: d/Movido.php\n*** Delete File: c/Borrado.php\n*** End Patch")
        );
    }

    // Gemini CLI

    public function test_gemini_before_tool_bloquea_con_codigo_2(): void
    {
        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'BeforeTool', 'cwd' => $this->project->path, 'session_id' => 's',
            'tool_name' => 'run_shell_command', 'tool_input' => ['command' => 'php artisan larapack:hand-rolled revoke X'],
        ], ['--agent' => 'gemini']));

        $this->assertStringContainsString('dueño del proyecto', $this->stderr);
    }

    public function test_gemini_after_tool_devuelve_el_motivo_con_codigo_2(): void
    {
        $this->writeController(false);

        $this->assertSame(2, $this->guard([
            'hook_event_name' => 'AfterTool', 'cwd' => $this->project->path,
            'tool_name' => 'replace', 'tool_input' => ['file_path' => $this->controller(), 'old_string' => 'a', 'new_string' => 'b'],
        ], ['--agent' => 'gemini']));

        $this->assertStringContainsString('controller-shape', $this->stderr);
    }

    public function test_gemini_un_recordatorio_va_en_additional_context(): void
    {
        $this->assertSame(Command::SUCCESS, $this->guard([
            'hook_event_name' => 'AfterTool', 'cwd' => $this->project->path,
            'tool_name' => 'write_file', 'tool_input' => ['file_path' => $this->controller(), 'content' => '...'],
        ], ['--agent' => 'gemini']));

        $reply = $this->stdoutJson();
        $this->assertSame('AfterTool', $reply['hookSpecificOutput']['hookEventName']);
        $this->assertStringContainsString('escrito a mano', $reply['hookSpecificOutput']['additionalContext']);
    }

    public function test_gemini_after_agent_retiene_una_vez(): void
    {
        $this->writeController(false);
        $payload = ['hook_event_name' => 'AfterAgent', 'cwd' => $this->project->path, 'stop_hook_active' => false];

        $this->assertSame(2, $this->guard($payload, ['--agent' => 'gemini']));
        $this->assertSame(Command::SUCCESS, $this->guard(['stop_hook_active' => true] + $payload, ['--agent' => 'gemini']));
    }

    // GitHub Copilot

    public function test_copilot_niega_con_permission_decision_y_los_argumentos_en_texto_json(): void
    {
        $code = $this->guard([
            'sessionId' => 's', 'timestamp' => 1, 'cwd' => $this->project->path,
            'toolName' => 'bash', 'toolArgs' => (string) json_encode(['command' => 'git config core.hooksPath /dev/null']),
        ], ['--agent' => 'copilot', '--pre' => true]);

        // Copilot lee la decisión en el JSON y trata cualquier otro código
        // como fallo cerrado: se contesta con 0.
        $this->assertSame(Command::SUCCESS, $code);
        $reply = $this->stdoutJson();
        $this->assertSame('deny', $reply['permissionDecision']);
        $this->assertStringContainsString('core.hooksPath', $reply['permissionDecisionReason']);
    }

    public function test_copilot_editar_el_candado_se_niega(): void
    {
        $this->guard([
            'sessionId' => 's', 'cwd' => $this->project->path,
            'toolName' => 'edit', 'toolArgs' => ['path' => '.larapack/hand-rolled.lock', 'old_str' => '[]', 'new_str' => '[1]'],
        ], ['--agent' => 'copilot', '--pre' => true]);

        $this->assertSame('deny', $this->stdoutJson()['permissionDecision']);
    }

    public function test_copilot_una_orden_cualquiera_pasa_en_silencio(): void
    {
        $this->assertSame(Command::SUCCESS, $this->guard([
            'sessionId' => 's', 'cwd' => $this->project->path, 'toolName' => 'bash', 'toolArgs' => '{"command":"git status"}',
        ], ['--agent' => 'copilot', '--pre' => true]));

        $this->assertSame('', $this->stdout.$this->stderr);
    }

    public function test_copilot_despues_de_editar_y_al_terminar(): void
    {
        $this->writeController(false);

        $this->assertSame(Command::SUCCESS, $this->guard([
            'sessionId' => 's', 'cwd' => $this->project->path,
            'toolName' => 'create', 'toolArgs' => ['path' => $this->controller(), 'file_text' => '...'],
        ], ['--agent' => 'copilot']));
        $this->assertStringContainsString('controller-shape', $this->stdoutJson()['additionalContext']);

        $this->assertSame(Command::SUCCESS, $this->guard([
            'sessionId' => 's', 'cwd' => $this->project->path, 'stop_hook_active' => false, 'stopReason' => 'end_turn',
        ], ['--agent' => 'copilot', '--stop' => true]));
        $reply = $this->stdoutJson();
        $this->assertSame('block', $reply['decision']);
        $this->assertStringContainsString('la tarea no ha terminado', $reply['reason']);
    }

    public function test_copilot_un_fallo_de_la_guardia_antes_de_la_herramienta_no_la_niega(): void
    {
        $reply = AgentAdapters::reply(AgentAdapters::COPILOT, 'pre', ['kind' => 'failure', 'message' => 'larapack:guard falló: x']);

        $this->assertSame(0, $reply['code']);
        $this->assertSame('', $reply['stdout']);

        // Los demás lo enseñan con 1, que no bloquea.
        $this->assertSame(1, AgentAdapters::reply(AgentAdapters::CURSOR, 'pre', ['kind' => 'failure', 'message' => 'x'])['code']);
        $this->assertSame(1, AgentAdapters::reply(AgentAdapters::CLAUDE, 'pre', ['kind' => 'failure', 'message' => 'x'])['code']);
    }

    // Windsurf

    public function test_windsurf_bloquea_una_orden_con_codigo_2(): void
    {
        $this->assertSame(2, $this->guard([
            'agent_action_name' => 'pre_run_command', 'trajectory_id' => 't', 'execution_id' => 'e',
            'tool_info' => ['command_line' => 'git commit -anm wip', 'cwd' => $this->project->path],
        ], ['--agent' => 'windsurf']));

        $this->assertStringContainsString('git commit --no-verify', $this->stderr);
    }

    public function test_windsurf_bloquea_escribir_el_candado_antes_de_escribirlo(): void
    {
        $this->assertSame(2, $this->guard([
            'agent_action_name' => 'pre_write_code', 'trajectory_id' => 't',
            'tool_info' => ['file_path' => $this->project->path.'/.larapack/hand-rolled.lock', 'edits' => [['old_string' => '', 'new_string' => '[]']]],
        ], ['--agent' => 'windsurf']));
    }

    public function test_windsurf_despues_de_escribir_sólo_puede_avisar(): void
    {
        $this->writeController(false);

        $this->assertSame(Command::SUCCESS, $this->guard([
            'agent_action_name' => 'post_write_code', 'trajectory_id' => 't',
            'tool_info' => ['file_path' => $this->controller(), 'edits' => []],
        ], ['--agent' => 'windsurf']));

        $this->assertStringContainsString('controller-shape', $this->stdout);
    }

    /**
     * Lo que no hay que frenar no se frena en ningún agente: un hook que
     * estorba acaba quitado.
     */
    public function test_un_controlador_limpio_y_una_orden_cualquiera_pasan_en_todos(): void
    {
        $cases = [
            'cursor' => ['hook_event_name' => 'preToolUse', 'cursor_version' => '2', 'workspace_roots' => [$this->project->path], 'tool_name' => 'Shell', 'tool_input' => ['command' => 'git commit -m "fix -n"']],
            'codex' => ['hook_event_name' => 'PreToolUse', 'turn_id' => 't', 'cwd' => $this->project->path, 'tool_name' => 'Bash', 'tool_input' => ['command' => 'git config --get core.hooksPath']],
            'gemini' => ['hook_event_name' => 'BeforeTool', 'cwd' => $this->project->path, 'tool_name' => 'run_shell_command', 'tool_input' => ['command' => 'git push origin main']],
            'windsurf' => ['agent_action_name' => 'pre_run_command', 'tool_info' => ['command_line' => 'git commit -m wip', 'cwd' => $this->project->path]],
        ];

        foreach ($cases as $agent => $payload) {
            $this->assertSame(Command::SUCCESS, $this->guard($payload, ['--agent' => $agent]), $agent.': '.$this->stdout.$this->stderr);
            $this->assertSame('', $this->stdout.$this->stderr, $agent);
        }
    }
}
