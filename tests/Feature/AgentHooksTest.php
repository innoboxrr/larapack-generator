<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\AgentHooks;
use Innoboxrr\LarapackGenerator\Support\Skill;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:skill` escribe en dos archivos que no son suyos: el
 * `.claude/settings.json` y el `AGENTS.md` del proyecto. Los dos suelen tener
 * ya cosas —permisos, otros hooks, las reglas de la casa—, así que lo que se
 * prueba aquí es sobre todo lo que NO se toca.
 */
final class AgentHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));
    }

    private function settingsFile(): string
    {
        return $this->project->path.'/'.AgentHooks::SETTINGS;
    }

    private function agentsFile(): string
    {
        return $this->project->path.'/'.AgentHooks::AGENTS;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return json_decode((string) file_get_contents($this->settingsFile()), true);
    }

    /**
     * @return array<int, string>
     */
    private function commands(string $event): array
    {
        $commands = [];

        foreach ($this->settings()['hooks'][$event] ?? [] as $group) {
            foreach ($group['hooks'] ?? [] as $handler) {
                $commands[] = $handler['command'] ?? '';
            }
        }

        return $commands;
    }

    public function test_con_hooks_crea_el_settings_con_la_guardia_y_el_hook_de_fin_de_turno(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill', ['--hooks' => true]), $this->lastOutput);

        $settings = $this->settings();

        $this->assertSame('Write|Edit|MultiEdit', $settings['hooks']['PostToolUse'][0]['matcher']);
        $this->assertSame('command', $settings['hooks']['PostToolUse'][0]['hooks'][0]['type']);
        $this->assertSame(['php "$CLAUDE_PROJECT_DIR/vendor/bin/builder" larapack:guard'], $this->commands('PostToolUse'));
        $this->assertSame(['php "$CLAUDE_PROJECT_DIR/vendor/bin/builder" larapack:guard --stop'], $this->commands('Stop'));
        $this->assertArrayNotHasKey('matcher', $settings['hooks']['Stop'][0], 'Stop no admite matcher.');
        $this->assertStringContainsString('Hooks añadidos', $this->lastOutput);
    }

    public function test_sin_hooks_no_toca_el_settings(): void
    {
        $this->runCommand('larapack:skill');

        $this->assertFileDoesNotExist($this->settingsFile());
    }

    public function test_en_una_aplicacion_llama_a_artisan(): void
    {
        $app = FakeProject::application();
        touch($app->path.'/artisan');

        try {
            $this->runCommand('larapack:skill', ['--hooks' => true, '--root' => $app->path]);

            $settings = json_decode((string) file_get_contents($app->path.'/'.AgentHooks::SETTINGS), true);

            $this->assertSame('php "$CLAUDE_PROJECT_DIR/artisan" larapack:guard', $settings['hooks']['PostToolUse'][0]['hooks'][0]['command']);
            $this->assertSame('php "$CLAUDE_PROJECT_DIR/artisan" larapack:guard --stop', $settings['hooks']['Stop'][0]['hooks'][0]['command']);
        } finally {
            $app->cleanup();
        }
    }

    /**
     * Lo que ya tenía el proyecto sigue ahí, con su tipo: un `{}` vacío no
     * puede volver como `[]`.
     */
    public function test_fusiona_sin_perder_lo_que_ya_habia(): void
    {
        mkdir(dirname($this->settingsFile()), 0777, true);
        file_put_contents($this->settingsFile(), json_encode([
            '$schema' => 'https://json.schemastore.org/claude-code-settings.json',
            'env' => new \stdClass,
            'permissions' => ['allow' => ['Bash(php artisan:*)']],
            'hooks' => [
                'PreToolUse' => [['matcher' => 'Write|Edit', 'hooks' => [['type' => 'command', 'command' => 'php scripts/generated_guard.php']]]],
                'Stop' => [['hooks' => [['type' => 'command', 'command' => 'php scripts/docs_guard.php', 'timeout' => 20]]]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill', ['--hooks' => true]), $this->lastOutput);

        $raw = (string) file_get_contents($this->settingsFile());
        $settings = $this->settings();

        $this->assertStringContainsString('"env": {}', $raw);
        $this->assertSame('https://json.schemastore.org/claude-code-settings.json', $settings['$schema']);
        $this->assertSame(['Bash(php artisan:*)'], $settings['permissions']['allow']);
        $this->assertSame('php scripts/generated_guard.php', $settings['hooks']['PreToolUse'][0]['hooks'][0]['command']);
        $this->assertSame(['php scripts/docs_guard.php', 'php "$CLAUDE_PROJECT_DIR/vendor/bin/builder" larapack:guard --stop'], $this->commands('Stop'));
        $this->assertCount(1, $this->commands('PostToolUse'));
    }

    public function test_reejecutarlo_no_duplica_ni_reescribe(): void
    {
        $this->runCommand('larapack:skill', ['--hooks' => true]);

        $before = (string) file_get_contents($this->settingsFile());

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill', ['--hooks' => true]));

        $this->assertSame($before, file_get_contents($this->settingsFile()));
        $this->assertCount(1, $this->commands('PostToolUse'));
        $this->assertCount(1, $this->commands('Stop'));
        $this->assertStringContainsString('ya presentes', $this->lastOutput);
    }

    /**
     * Un hook de LaraPack cambiado a mano —otro binario, otro timeout— se
     * reconoce por el comando y se respeta.
     */
    public function test_respeta_una_guardia_ya_configurada_a_mano(): void
    {
        mkdir(dirname($this->settingsFile()), 0777, true);
        file_put_contents($this->settingsFile(), json_encode(['hooks' => [
            'PostToolUse' => [['matcher' => 'Write|Edit', 'hooks' => [['type' => 'command', 'command' => 'php artisan larapack:guard', 'timeout' => 5]]]],
        ]]));

        $result = AgentHooks::installSettings($this->project->path);

        $this->assertSame(['PostToolUse'], $result['present']);
        $this->assertSame(['Stop'], $result['added']);
        $this->assertSame(['php artisan larapack:guard'], $this->commands('PostToolUse'));
        $this->assertSame(5, $this->settings()['hooks']['PostToolUse'][0]['hooks'][0]['timeout']);
    }

    public function test_un_settings_invalido_no_se_sobrescribe(): void
    {
        mkdir(dirname($this->settingsFile()), 0777, true);
        file_put_contents($this->settingsFile(), '{"hooks": [roto');

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:skill', ['--hooks' => true]));
        $this->assertSame('{"hooks": [roto', file_get_contents($this->settingsFile()));
        $this->assertStringContainsString('no se sobrescribe', $this->lastOutput);

        file_put_contents($this->settingsFile(), '{"hooks": {"PostToolUse": {"no": "es una lista"}}}');

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:skill', ['--hooks' => true]));
        $this->assertSame('{"hooks": {"PostToolUse": {"no": "es una lista"}}}', file_get_contents($this->settingsFile()));
    }

    public function test_crea_agents_md_con_el_bloque_entre_marcadores(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill'), $this->lastOutput);

        $agents = (string) file_get_contents($this->agentsFile());

        $this->assertStringStartsWith(AgentHooks::BEGIN."\n", $agents);
        $this->assertStringEndsWith(AgentHooks::END."\n", $agents);
        $this->assertStringContainsString('return $request->handle();', $agents);
        $this->assertStringContainsString(Skill::DEFAULT_TARGET, $agents);
        $this->assertStringNotContainsString('{{skill}}', $agents);
    }

    public function test_anade_el_bloque_al_final_de_un_agents_md_existente(): void
    {
        file_put_contents($this->agentsFile(), "# Reglas de la casa\n\nNunca se hace push a main.\n");

        $this->runCommand('larapack:skill');

        $agents = (string) file_get_contents($this->agentsFile());

        $this->assertStringStartsWith("# Reglas de la casa\n\nNunca se hace push a main.\n\n".AgentHooks::BEGIN, $agents);
        $this->assertSame(1, substr_count($agents, AgentHooks::BEGIN));
    }

    /**
     * Lo de dentro de los marcadores es del paquete y sigue al paquete; lo de
     * fuera, antes y después, es del proyecto.
     */
    public function test_actualiza_solo_lo_que_hay_entre_los_marcadores(): void
    {
        file_put_contents($this->agentsFile(), "# Antes\n\n".AgentHooks::BEGIN."\nTexto viejo de LaraPack\n".AgentHooks::END."\n\n## Después\n");

        $this->runCommand('larapack:skill');

        $agents = (string) file_get_contents($this->agentsFile());

        $this->assertStringStartsWith("# Antes\n\n".AgentHooks::BEGIN."\n", $agents);
        $this->assertStringEndsWith(AgentHooks::END."\n\n## Después\n", $agents);
        $this->assertStringNotContainsString('Texto viejo', $agents);
        $this->assertStringContainsString('actualizado', $this->lastOutput);

        $this->runCommand('larapack:skill');

        $this->assertSame($agents, file_get_contents($this->agentsFile()));
        $this->assertStringContainsString('al día', $this->lastOutput);
    }

    public function test_con_no_agents_no_toca_agents_md(): void
    {
        $this->runCommand('larapack:skill', ['--no-agents' => true]);

        $this->assertFileDoesNotExist($this->agentsFile());
    }

    public function test_el_bloque_apunta_a_donde_se_instalo_el_skill(): void
    {
        $this->runCommand('larapack:skill', ['--path' => 'docs/agents/LARAPACK.md']);

        $this->assertStringContainsString('`docs/agents/LARAPACK.md`', (string) file_get_contents($this->agentsFile()));
    }

    /**
     * Igual que el skill: un comando que no existe en el bloque es una orden
     * que el agente ejecuta y falla.
     */
    public function test_el_bloque_solo_menciona_comandos_que_existen(): void
    {
        preg_match_all('/larapack:[a-z-]+/', AgentHooks::agentsBlock(), $matches);

        $available = [];

        foreach (glob(dirname(__DIR__, 2).'/src/Commands/*Command.php') as $file) {
            $class = 'Innoboxrr\\LarapackGenerator\\Commands\\'.basename($file, '.php');
            $available[] = (new $class)->getName();
        }

        $this->assertNotEmpty($matches[0]);
        $this->assertSame([], array_values(array_diff(array_unique($matches[0]), $available, ['larapack:comando'])));
    }
}
