<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Skill;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class SkillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));
    }

    public function test_instala_el_skill_donde_un_agente_lo_lee(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill'), $this->lastOutput);

        $this->assertGenerated(Skill::DEFAULT_TARGET);
        $this->assertSame(Skill::contents(), $this->project->read(Skill::DEFAULT_TARGET));
    }

    /**
     * Reejecutarlo tras actualizar el paquete es el caso normal, no un error.
     */
    public function test_reinstalarlo_no_falla_ni_avisa_de_nada(): void
    {
        $this->runCommand('larapack:skill');

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill'));
        $this->assertStringContainsString('al día', $this->lastOutput);
    }

    /**
     * El destino puede haberse ampliado con reglas del proyecto: sobrescribir
     * sin avisar se llevaria por delante ese trabajo.
     */
    public function test_no_pisa_un_skill_modificado_sin_force(): void
    {
        $this->runCommand('larapack:skill');

        $target = $this->project->path.'/'.Skill::DEFAULT_TARGET;
        file_put_contents($target, Skill::contents()."\n\n## Reglas de este proyecto\n");

        $this->runCommand('larapack:skill');

        $this->assertStringContainsString('Reglas de este proyecto', file_get_contents($target));
        $this->assertStringContainsString('--force', $this->lastOutput);

        $this->runCommand('larapack:skill', ['--force' => true]);

        $this->assertSame(Skill::contents(), file_get_contents($target));
    }

    public function test_declara_el_frontmatter_que_lo_hace_descubrible(): void
    {
        $skill = Skill::contents();

        $this->assertStringStartsWith("---\n", str_replace("\r\n", "\n", $skill));
        $this->assertMatchesRegularExpression('/^name:\s*larapack$/m', $skill);
        $this->assertMatchesRegularExpression('/^description:\s*\S.{40,}/m', $skill);
    }

    /**
     * La regla que se perdió en school-management tiene que estar escrita, con
     * los endpoints a mano dentro, y con la guardia que la hace cumplir.
     */
    public function test_explica_el_patron_handle_tambien_para_los_endpoints_a_mano(): void
    {
        $skill = Skill::contents();

        $this->assertStringContainsString('return $request->handle();', $skill);
        $this->assertStringContainsString('Endpoints que LaraPack no genera', $skill);
        $this->assertStringContainsString('larapack:guard', $skill);
        $this->assertStringContainsString('Antes de terminar una tarea', $skill);
    }

    /**
     * Un skill que menciona comandos que no existen es peor que no tenerlo:
     * el agente los ejecuta, fallan, y deja de fiarse del resto del texto.
     */
    public function test_todos_los_comandos_que_menciona_existen(): void
    {
        preg_match_all('/larapack:[a-z-]+/', Skill::contents(), $matches);

        $mentioned = array_unique($matches[0]);

        $this->assertNotEmpty($mentioned);

        $available = [];

        foreach (glob(dirname(__DIR__, 2).'/src/Commands/*Command.php') as $file) {
            $class = 'Innoboxrr\\LarapackGenerator\\Commands\\'.basename($file, '.php');
            $available[] = (new $class)->getName();
        }

        // `larapack:<comando>` es el marcador de posición de la sintaxis.
        $missing = array_diff($mentioned, $available, ['larapack:comando']);

        $this->assertSame([], array_values($missing), 'El skill menciona comandos que no existen.');
    }

    /**
     * Los huecos que el skill promete tienen que existir en lo generado; si
     * el generador deja de emitir uno, el texto manda al agente a un archivo
     * que no está.
     */
    public function test_los_huecos_que_promete_existen_en_lo_generado(): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__).'/Fixtures/laraimport.json',
            ]),
            $this->lastOutput
        );

        foreach ([
            'src/Models/Traits/Relations/PostRelations.php',
            'src/Models/Traits/Operations/PostOperations.php',
            'src/Models/Traits/Storage/PostStorage.php',
            'src/Models/Traits/Mutators/PostMutators.php',
            'src/Models/Filters/Post/ManagedFilter.php',
            'src/Policies/PostPolicy.php',
            'src/Observers/PostObserver.php',
            'src/Http/Resources/Models/PostResource.php',
            'database/factories/PostFactory.php',
            'tests/Feature/Models/PostEndpointsTest.php',
        ] as $hole) {
            $this->assertGenerated($hole);
        }
    }

    /**
     * Un agente que llega a un proyecto que se desvió tiene que encontrar la
     * guía del realineamiento donde busca los skills, sin que nadie le
     * escriba un prompt, y empezar por `larapack:realign status`.
     */
    public function test_instala_la_guia_del_realineamiento_junto_al_skill(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill'), $this->lastOutput);

        $this->assertSame('.claude/skills/larapack-realign/SKILL.md', Skill::REALIGN_TARGET);
        $this->assertSame(Skill::realignContents(), $this->project->read(Skill::REALIGN_TARGET));
        $this->assertStringContainsString('Guía del realineamiento instalada', $this->lastOutput);
        $this->assertMatchesRegularExpression('/^name:\s*larapack-realign$/m', Skill::realignContents());
        $this->assertStringContainsString('larapack:realign status', Skill::realignContents());
        $this->assertStringContainsString('docs/realign/README.md', Skill::realignContents());

        // Reinstalar es el caso normal; una guía editada no se pisa sin --force.
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill'));
        $this->assertStringContainsString('Guía del realineamiento al día', $this->lastOutput);

        file_put_contents($this->project->path.'/'.Skill::REALIGN_TARGET, "propia\n");
        $this->runCommand('larapack:skill');
        $this->assertSame("propia\n", $this->project->read(Skill::REALIGN_TARGET));
        $this->assertStringContainsString('difiere del paquete', $this->lastOutput);

        $this->runCommand('larapack:skill', ['--force' => true]);
        $this->assertSame(Skill::realignContents(), $this->project->read(Skill::REALIGN_TARGET));
    }

    public function test_sin_realineamiento_o_fuera_de_un_directorio_de_skills_no_se_adivina_el_destino(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill', ['--no-realign' => true]));
        $this->assertFileDoesNotExist($this->project->path.'/'.Skill::REALIGN_TARGET);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill', ['--path' => 'AGENTS.md']));
        $this->assertFileDoesNotExist($this->project->path.'/'.Skill::REALIGN_TARGET);
        $this->assertStringContainsString('larapack:realign status', $this->lastOutput);

        $this->assertSame('.cursor/skills/larapack-realign/SKILL.md', Skill::realignTargetFor('.cursor/skills/larapack/SKILL.md'));
        $this->assertSame('/abs/skills/larapack-realign/SKILL.md', Skill::realignTargetFor('/abs/skills/larapack/SKILL.md'));
        $this->assertNull(Skill::realignTargetFor('docs/agentes.md'));
    }

    /**
     * El bloque de AGENTS.md es también el de las reglas de Cursor, Codex,
     * Gemini y Copilot: los cinco tienen que mandar al punto de entrada.
     */
    public function test_el_bloque_de_los_agentes_manda_al_punto_de_entrada_del_realineamiento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:skill', ['--agents' => 'all']), $this->lastOutput);

        foreach (['AGENTS.md', '.cursor/rules/larapack.mdc', 'GEMINI.md', '.github/copilot-instructions.md', '.windsurf/rules/larapack.md'] as $file) {
            $contents = $this->project->read($file);

            $this->assertStringContainsString('larapack:realign status', $contents, "{$file} no manda a larapack:realign status.");
            $this->assertStringContainsString('docs/realign/README.md', $contents, "{$file} no dice dónde está la guía del realineamiento.");
        }

        $this->assertStringContainsString('larapack:realign status', Skill::contents());
    }

    public function test_la_guia_del_realineamiento_solo_menciona_comandos_que_existen(): void
    {
        preg_match_all('/larapack:[a-z-]+/', Skill::realignContents(), $matches);

        $available = [];

        foreach (glob(dirname(__DIR__, 2).'/src/Commands/*Command.php') as $file) {
            $class = 'Innoboxrr\\LarapackGenerator\\Commands\\'.basename($file, '.php');
            $available[] = (new $class)->getName();
        }

        $this->assertSame([], array_values(array_diff(array_unique($matches[0]), $available)));
    }
}
