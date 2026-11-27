<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Commands\GuardCommand;
use Innoboxrr\LarapackGenerator\Commands\SkillCommand;
use Innoboxrr\LarapackGenerator\Support\GuardDoctor;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\HookProject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `larapack:guard --doctor`: qué capas tiene el proyecto, cuáles faltan y
 * con qué orden se ponen. Y `larapack:skill --hooks --agents=…`, que es esa
 * orden.
 */
final class GuardDoctorTest extends TestCase
{
    private HookProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = new HookProject;
    }

    protected function tearDown(): void
    {
        ProjectRoot::set(null);
        $this->project->cleanup();

        parent::tearDown();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function layers(): array
    {
        $layers = [];

        foreach (GuardDoctor::diagnose($this->project->path)['layers'] as $layer) {
            $layers[$layer['id']] = $layer;
        }

        return $layers;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function skill(array $options): CommandTester
    {
        $tester = new CommandTester(new SkillCommand);
        $tester->execute(['--root' => $this->project->path] + $options);

        return $tester;
    }

    public function test_en_un_proyecto_sin_nada_dice_lo_que_falta_y_como_ponerlo(): void
    {
        $diagnosis = GuardDoctor::diagnose($this->project->path);
        $layers = $this->layers();

        $this->assertFalse($diagnosis['ok']);
        $this->assertSame('missing', $layers['git']['status']);
        $this->assertSame('php vendor/bin/builder larapack:skill --hooks', $layers['git']['fix']);
        $this->assertSame('missing', $layers['ci']['status']);
        $this->assertStringContainsString('larapack:ci', (string) $layers['ci']['fix']);
        $this->assertSame('unknown', $layers['branch-protection']['status']);
        $this->assertSame('missing', $layers['claude']['status']);

        foreach (['cursor', 'codex', 'gemini', 'copilot', 'windsurf'] as $agent) {
            $this->assertSame('missing', $layers[$agent]['status'], $agent);
            $this->assertSame('php vendor/bin/builder larapack:skill --agents='.$agent, $layers[$agent]['fix']);
        }

        $this->assertArrayNotHasKey('aider', $layers, 'Sin .aider.conf.yml no se habla de Aider.');
    }

    public function test_con_todo_puesto_esta_en_verde(): void
    {
        $tester = $this->skill(['--hooks' => true, '--agents' => 'all']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->project->write('.github/workflows/larapack.yml', "jobs:\n  verify:\n    steps:\n      - run: php vendor/bin/builder larapack:verify --strict --format=json\n");

        $diagnosis = GuardDoctor::diagnose($this->project->path);

        $this->assertTrue($diagnosis['ok']);

        foreach ($diagnosis['layers'] as $layer) {
            $this->assertContains($layer['status'], ['ok', 'unknown'], $layer['id'].': '.$layer['detail']);
        }

        $display = $tester->getDisplay();
        $this->assertStringContainsString('git usa los hooks de .githooks', $display);
        $this->assertStringContainsString('Cursor, creado:', $display);
        $this->assertStringContainsString('larapack:guard --doctor', $display);
    }

    /**
     * core.hooksPath es de cada clon: un clon nuevo tiene los scripts
     * versionados pero git no los usa todavía.
     */
    public function test_un_clon_sin_core_hooks_path_dice_la_orden_exacta(): void
    {
        $this->skill(['--hooks' => true]);
        $this->project->git('config', '--unset', 'core.hooksPath');

        $git = $this->layers()['git'];

        $this->assertSame('missing', $git['status']);
        $this->assertStringStartsWith('git config core.hooksPath .githooks', (string) $git['fix']);
    }

    public function test_avisa_de_que_aider_se_salta_el_hook_de_git_por_defecto(): void
    {
        $this->project->write('.aider.conf.yml', "model: x\n");
        $this->assertSame('warning', $this->layers()['aider']['status']);

        $this->project->write('.aider.conf.yml', "model: x\ngit-commit-verify: true\n");
        $this->assertSame('ok', $this->layers()['aider']['status']);
    }

    public function test_el_comando_sale_con_1_si_falta_una_capa_que_no_depende_del_agente(): void
    {
        $tester = new CommandTester(new GuardCommand);
        $tester->execute(['--doctor' => true, '--root' => $this->project->path]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('[falta]', $tester->getDisplay());
        $this->assertStringContainsString('→ php vendor/bin/builder larapack:skill --hooks', $tester->getDisplay());

        $tester->execute(['--doctor' => true, '--root' => $this->project->path, '--format' => 'json']);
        $json = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($json);
        $this->assertFalse($json['ok']);
        $this->assertSame('git', $json['layers'][0]['id']);
    }

    public function test_skill_rechaza_un_agente_desconocido_sin_escribir_nada(): void
    {
        $tester = $this->skill(['--agents' => 'cursor,vscode']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Agente desconocido: vscode', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->project->path.'/.cursor/hooks.json');
    }
}
