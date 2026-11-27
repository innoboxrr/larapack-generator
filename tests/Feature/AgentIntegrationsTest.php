<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\AgentAdapters;
use Innoboxrr\LarapackGenerator\Support\AgentHooks;
use Innoboxrr\LarapackGenerator\Support\AgentIntegrations;
use Innoboxrr\LarapackGenerator\Tests\Support\HookProject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Los hooks y las reglas de los otros agentes: que se instalen sin pisar lo
 * del proyecto, y que el comando que queda escrito funcione de verdad.
 *
 * Las pruebas de punta a punta sacan el comando del archivo que leería cada
 * agente y lo ejecutan con bash, con las variables y la entrada que ese agente
 * le pasa. Así se prueba lo que AgentAdaptersTest no ve: el filtro de la shell,
 * cómo encuentra la raíz y que la respuesta salga entera por la tubería.
 */
final class AgentIntegrationsTest extends TestCase
{
    private HookProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = new HookProject;
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $agent): array
    {
        $decoded = json_decode((string) file_get_contents($this->project->path.'/'.AgentIntegrations::HOOK_FILES[$agent]), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * El comando que el agente ejecutaría para ese evento, tal como quedó
     * escrito.
     */
    private function command(string $agent, string $event): string
    {
        $entries = $this->config($agent)['hooks'][$event] ?? [];

        foreach ($entries as $entry) {
            foreach ([$entry['command'] ?? null, $entry['bash'] ?? null, ...array_column($entry['hooks'] ?? [], 'command')] as $command) {
                if (is_string($command) && str_contains($command, '--agent='.$agent)) {
                    return $command;
                }
            }
        }

        throw new RuntimeException("No hay comando de LaraPack para {$agent} en {$event}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $env
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runHook(string $agent, string $event, array $payload, array $env = []): array
    {
        return $this->project->run(['bash', '-c', $this->command($agent, $event)], (string) json_encode($payload), $env);
    }

    public function test_instala_los_hooks_y_las_reglas_de_cada_agente(): void
    {
        foreach (AgentIntegrations::AGENTS as $agent) {
            $written = AgentIntegrations::install($this->project->path, $agent);

            $this->assertSame(['created', 'created'], array_column($written, 'action'), $agent);
            $this->assertTrue(AgentIntegrations::hasNative($this->project->path, $agent), $agent);
            $this->assertSame(['hooks' => 'installed', 'rules' => 'installed'], AgentIntegrations::status($this->project->path, $agent), $agent);
        }

        $this->assertSame(1, $this->config(AgentAdapters::CURSOR)['version']);
        $this->assertSame(1, $this->config(AgentAdapters::COPILOT)['version']);
        $this->assertStringStartsWith("---\ndescription:", (string) file_get_contents($this->project->path.'/.cursor/rules/larapack.mdc'));
        $this->assertStringContainsString('alwaysApply: true', (string) file_get_contents($this->project->path.'/.cursor/rules/larapack.mdc'));
        $this->assertStringStartsWith("---\ntrigger: always_on\n---", (string) file_get_contents($this->project->path.'/.windsurf/rules/larapack.md'));

        foreach (['GEMINI.md', '.github/copilot-instructions.md', 'AGENTS.md', '.cursor/rules/larapack.mdc'] as $rules) {
            $this->assertStringContainsString('return $request->handle();', (string) file_get_contents($this->project->path.'/'.$rules), $rules);
            $this->assertStringContainsString('--no-verify', (string) file_get_contents($this->project->path.'/'.$rules), $rules);
        }
    }

    public function test_reinstalarlo_no_cambia_nada(): void
    {
        foreach (AgentIntegrations::AGENTS as $agent) {
            AgentIntegrations::install($this->project->path, $agent);
            $again = AgentIntegrations::install($this->project->path, $agent);

            $this->assertSame(['unchanged', 'unchanged'], array_column($again, 'action'), $agent);
        }
    }

    /**
     * `.gemini/settings.json` y `.cursor/hooks.json` suelen tener cosas del
     * proyecto: se añade lo de LaraPack y lo demás se queda.
     */
    public function test_fusiona_sin_pisar_la_configuracion_del_proyecto(): void
    {
        $this->project->write('.gemini/settings.json', (string) json_encode([
            'theme' => 'Dracula',
            'mcpServers' => (object) [],
            'hooks' => ['BeforeTool' => [['matcher' => 'run_shell_command', 'hooks' => [['type' => 'command', 'command' => './audit.sh']]]]],
        ]));

        AgentIntegrations::install($this->project->path, AgentAdapters::GEMINI);

        $settings = (string) file_get_contents($this->project->path.'/.gemini/settings.json');
        $config = $this->config(AgentAdapters::GEMINI);

        $this->assertSame('Dracula', $config['theme']);
        $this->assertStringContainsString('"mcpServers": {}', $settings, 'Un objeto vacío del proyecto no puede volver como lista.');
        $this->assertCount(2, $config['hooks']['BeforeTool']);
        $this->assertSame('./audit.sh', $config['hooks']['BeforeTool'][0]['hooks'][0]['command']);
    }

    /**
     * Lo de LaraPack —lo que llama a la guardia con `--agent=`— sigue al
     * paquete: una versión anterior se pone al día en su sitio, sin duplicar.
     */
    public function test_pone_al_dia_su_propia_entrada_sin_duplicarla(): void
    {
        $this->project->write('.cursor/hooks.json', (string) json_encode([
            'version' => 1,
            'hooks' => ['postToolUse' => [['command' => 'php vendor/bin/builder larapack:guard --agent=cursor', 'matcher' => 'Write']]],
        ]));

        $this->assertSame('outdated', AgentIntegrations::status($this->project->path, AgentAdapters::CURSOR)['hooks']);

        AgentIntegrations::install($this->project->path, AgentAdapters::CURSOR);

        $config = $this->config(AgentAdapters::CURSOR);
        $this->assertCount(1, $config['hooks']['postToolUse']);
        $this->assertSame('^Write$', $config['hooks']['postToolUse'][0]['matcher']);
        $this->assertSame('installed', AgentIntegrations::status($this->project->path, AgentAdapters::CURSOR)['hooks']);
    }

    public function test_un_archivo_de_reglas_ajeno_no_se_toca(): void
    {
        $this->project->write('.cursor/rules/larapack.mdc', "---\nalwaysApply: false\n---\nMis reglas.\n");

        $written = AgentIntegrations::install($this->project->path, AgentAdapters::CURSOR);

        $this->assertSame('kept', $written[1]['action']);
        $this->assertSame("---\nalwaysApply: false\n---\nMis reglas.\n", file_get_contents($this->project->path.'/.cursor/rules/larapack.mdc'));
    }

    public function test_un_archivo_roto_no_se_sobrescribe(): void
    {
        $this->project->write('.codex/hooks.json', '{ esto no es json');

        try {
            AgentIntegrations::install($this->project->path, AgentAdapters::CODEX);
            $this->fail('Debería negarse a reescribir un JSON roto.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no es un objeto JSON válido', $e->getMessage());
        }

        $this->assertSame('{ esto no es json', file_get_contents($this->project->path.'/.codex/hooks.json'));
    }

    public function test_lee_la_lista_de_agentes(): void
    {
        $this->assertSame(AgentIntegrations::AGENTS, AgentIntegrations::parse('all'));
        $this->assertSame(['cursor', 'codex'], AgentIntegrations::parse(' Cursor, codex ,cursor'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agente desconocido: vscode');
        AgentIntegrations::parse('cursor,vscode');
    }

    /**
     * Una aplicación se llama por Artisan y necesita vendor/autoload.php; un
     * paquete, por el builder. Como en Claude Code.
     */
    public function test_en_una_aplicacion_el_comando_usa_artisan(): void
    {
        $this->project->write('artisan', "<?php\n");

        $command = AgentIntegrations::command($this->project->path, AgentAdapters::CURSOR, '');

        $this->assertStringContainsString('[ ! -f "$R/vendor/autoload.php" ] || php "$R/artisan" larapack:guard --agent=cursor', $command);
    }

    // De punta a punta: el comando escrito, ejecutado con bash.

    public function test_cursor_de_punta_a_punta(): void
    {
        AgentIntegrations::install($this->project->path, AgentAdapters::CURSOR);
        $env = ['CURSOR_PROJECT_DIR' => $this->project->path];
        $base = ['cursor_version' => '2.1', 'workspace_roots' => [$this->project->path], 'conversation_id' => 'c'];

        $denied = $this->runHook('cursor', 'preToolUse', $base + ['hook_event_name' => 'preToolUse', 'tool_name' => 'Shell', 'tool_input' => ['command' => 'git commit --no-verify -m x']], $env);
        $this->assertSame(2, $denied['code'], $denied['stderr']);
        $this->assertSame('deny', json_decode($denied['stdout'], true)['permission']);

        // El filtro de la shell: una orden sin nada que ver no arranca PHP.
        $allowed = $this->runHook('cursor', 'preToolUse', $base + ['hook_event_name' => 'preToolUse', 'tool_name' => 'Shell', 'tool_input' => ['command' => 'ls -la']], $env);
        $this->assertSame(0, $allowed['code']);
        $this->assertSame('', $allowed['stdout'].$allowed['stderr']);

        $file = $this->project->controller(false);
        $edited = $this->runHook('cursor', 'postToolUse', $base + ['hook_event_name' => 'postToolUse', 'tool_name' => 'Write', 'tool_input' => ['file_path' => $file]], $env);
        $this->assertSame(0, $edited['code']);
        $this->assertStringContainsString('controller-shape', json_decode($edited['stdout'], true)['additional_context']);

        $stopped = $this->runHook('cursor', 'stop', $base + ['hook_event_name' => 'stop', 'status' => 'completed', 'loop_count' => 0], $env);
        $this->assertSame(0, $stopped['code']);
        $this->assertStringContainsString('la tarea no ha terminado', json_decode($stopped['stdout'], true)['followup_message']);
    }

    public function test_codex_de_punta_a_punta(): void
    {
        AgentIntegrations::install($this->project->path, AgentAdapters::CODEX);
        $base = ['session_id' => 's', 'turn_id' => 't', 'cwd' => $this->project->path, 'model' => 'm', 'permission_mode' => 'default', 'transcript_path' => null];

        $denied = $this->runHook('codex', 'PreToolUse', $base + ['hook_event_name' => 'PreToolUse', 'tool_name' => 'apply_patch', 'tool_use_id' => 'u',
            'tool_input' => ['command' => "*** Begin Patch\n*** Update File: .larapack/hand-rolled.lock\n@@\n-[]\n+[1]\n*** End Patch\n"]]);
        $this->assertSame(2, $denied['code'], $denied['stdout']);
        $this->assertStringContainsString('hand-rolled.lock', $denied['stderr']);

        $file = $this->project->controller(false);
        $edited = $this->runHook('codex', 'PostToolUse', $base + ['hook_event_name' => 'PostToolUse', 'tool_name' => 'apply_patch', 'tool_use_id' => 'u', 'tool_response' => 'ok',
            'tool_input' => ['command' => "*** Begin Patch\n*** Update File: src/Http/Controllers/NoteController.php\n@@\n*** End Patch\n"]]);
        $this->assertSame(0, $edited['code'], $edited['stderr']);
        $this->assertSame('block', json_decode($edited['stdout'], true)['decision']);
        $this->assertFileExists($file);

        $stopped = $this->runHook('codex', 'Stop', $base + ['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'last_assistant_message' => null]);
        $this->assertSame(2, $stopped['code']);
    }

    public function test_gemini_de_punta_a_punta(): void
    {
        AgentIntegrations::install($this->project->path, AgentAdapters::GEMINI);
        $env = ['GEMINI_PROJECT_DIR' => $this->project->path];
        $base = ['session_id' => 's', 'cwd' => $this->project->path, 'timestamp' => '2026-11-27T00:00:00Z'];

        $denied = $this->runHook('gemini', 'BeforeTool', $base + ['hook_event_name' => 'BeforeTool', 'tool_name' => 'run_shell_command', 'tool_input' => ['command' => 'php vendor/bin/builder larapack:hand-rolled approve --all']], $env);
        $this->assertSame(2, $denied['code']);
        $this->assertStringContainsString('dueño del proyecto', $denied['stderr']);

        $file = $this->project->controller(false);
        $edited = $this->runHook('gemini', 'AfterTool', $base + ['hook_event_name' => 'AfterTool', 'tool_name' => 'replace', 'tool_input' => ['file_path' => $file]], $env);
        $this->assertSame(2, $edited['code']);
        $this->assertStringContainsString('controller-shape', $edited['stderr']);

        $this->assertSame(2, $this->runHook('gemini', 'AfterAgent', $base + ['hook_event_name' => 'AfterAgent', 'stop_hook_active' => false], $env)['code']);
    }

    public function test_copilot_de_punta_a_punta(): void
    {
        AgentIntegrations::install($this->project->path, AgentAdapters::COPILOT);
        $base = ['sessionId' => 's', 'timestamp' => 1, 'cwd' => $this->project->path];

        $denied = $this->runHook('copilot', 'preToolUse', $base + ['toolName' => 'bash', 'toolArgs' => '{"command":"git -c core.hooksPath=/dev/null commit -m x"}']);
        $this->assertSame(0, $denied['code']);
        $this->assertSame('deny', json_decode($denied['stdout'], true)['permissionDecision']);

        $file = $this->project->controller(false);
        $edited = $this->runHook('copilot', 'postToolUse', $base + ['toolName' => 'edit', 'toolArgs' => ['path' => $file]]);
        $this->assertStringContainsString('controller-shape', json_decode($edited['stdout'], true)['additionalContext']);

        $stopped = $this->runHook('copilot', 'agentStop', $base + ['stop_hook_active' => false, 'stopReason' => 'end_turn', 'transcriptPath' => '']);
        $this->assertSame('block', json_decode($stopped['stdout'], true)['decision']);
    }

    public function test_windsurf_de_punta_a_punta(): void
    {
        AgentIntegrations::install($this->project->path, AgentAdapters::WINDSURF);

        $denied = $this->runHook('windsurf', 'pre_run_command', ['agent_action_name' => 'pre_run_command', 'trajectory_id' => 't',
            'tool_info' => ['command_line' => 'git push --no-verify', 'cwd' => $this->project->path]]);
        $this->assertSame(2, $denied['code']);
        $this->assertStringContainsString('git push --no-verify', $denied['stderr']);

        $locked = $this->runHook('windsurf', 'pre_write_code', ['agent_action_name' => 'pre_write_code', 'trajectory_id' => 't',
            'tool_info' => ['file_path' => $this->project->path.'/.larapack/hand-rolled.lock', 'edits' => []]]);
        $this->assertSame(2, $locked['code']);

        $file = $this->project->controller(false);
        $edited = $this->runHook('windsurf', 'post_write_code', ['agent_action_name' => 'post_write_code', 'trajectory_id' => 't', 'tool_info' => ['file_path' => $file, 'edits' => []]]);
        $this->assertSame(0, $edited['code']);
        $this->assertStringContainsString('controller-shape', $edited['stdout']);
    }

    /**
     * Sin vendor/ —antes del composer install— ningún hook hace nada, y
     * ninguno falla: un hook que falla en cada herramienta acaba quitado.
     */
    public function test_sin_vendor_ningun_hook_hace_nada(): void
    {
        foreach (AgentIntegrations::AGENTS as $agent) {
            AgentIntegrations::install($this->project->path, $agent);
        }

        unlink($this->project->path.'/vendor/bin/builder');
        $payload = ['tool_input' => ['command' => 'git commit --no-verify'], 'cwd' => $this->project->path, 'toolArgs' => '{"command":"git commit --no-verify"}'];

        foreach (AgentIntegrations::AGENTS as $agent) {
            foreach (array_keys($this->config($agent)['hooks']) as $event) {
                $result = $this->runHook($agent, $event, $payload, ['CURSOR_PROJECT_DIR' => $this->project->path, 'GEMINI_PROJECT_DIR' => $this->project->path]);

                $this->assertSame(0, $result['code'], "{$agent} {$event}: {$result['stderr']}");
                $this->assertSame('', $result['stdout'].$result['stderr'], "{$agent} {$event}");
            }
        }
    }

    /**
     * El filtro de PreToolUse que escribía 9.2 no deja pasar a PHP un
     * `git commit --no-verify`: el suyo se pone al día; uno cambiado a mano,
     * no.
     */
    public function test_pone_al_dia_el_filtro_de_claude_code_de_la_version_anterior(): void
    {
        $old = 'input=$(cat); bare=$(printf \'%s\' "$input" | tr -d \'\\\\"\'"\'"\'); case "$input $bare" in *approve*|*revoke*|*[Hh][Aa][Nn][Dd]-[Rr][Oo][Ll][Ll][Ee][Dd]*|*\'\\\\\\\'[nr]*) [ ! -f "$CLAUDE_PROJECT_DIR/vendor/bin/builder" ] || printf \'%s\' "$input" 2>/dev/null | php "$CLAUDE_PROJECT_DIR/vendor/bin/builder" larapack:guard --pre;; esac';
        $this->project->write('.claude/settings.json', (string) json_encode(['hooks' => [
            'PreToolUse' => [['matcher' => 'Write|Edit|MultiEdit|Bash', 'hooks' => [['type' => 'command', 'command' => $old, 'timeout' => 30]]]],
        ]]));

        $this->assertSame('outdated', AgentHooks::settingsStatus($this->project->path)['PreToolUse']);

        $result = AgentHooks::installSettings($this->project->path);

        $this->assertContains('PreToolUse', $result['added']);
        $settings = json_decode((string) file_get_contents($this->project->path.'/.claude/settings.json'), true);
        $this->assertCount(1, $settings['hooks']['PreToolUse']);
        $this->assertStringContainsString('*no-veri*', $settings['hooks']['PreToolUse'][0]['hooks'][0]['command']);
        $this->assertSame('installed', AgentHooks::settingsStatus($this->project->path)['PreToolUse']);

        // Uno escrito a mano que llama a la guardia se respeta.
        $this->project->write('.claude/settings.json', (string) json_encode(['hooks' => [
            'PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'php vendor/bin/builder larapack:guard --pre']]]],
        ]]));
        AgentHooks::installSettings($this->project->path);
        $settings = json_decode((string) file_get_contents($this->project->path.'/.claude/settings.json'), true);
        $this->assertSame('php vendor/bin/builder larapack:guard --pre', $settings['hooks']['PreToolUse'][0]['hooks'][0]['command']);
    }
}
