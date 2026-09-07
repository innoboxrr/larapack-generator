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

        $target = $this->project->path . '/' . Skill::DEFAULT_TARGET;
        file_put_contents($target, Skill::contents() . "\n\n## Reglas de este proyecto\n");

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
     * Un skill que menciona comandos que no existen es peor que no tenerlo:
     * el agente los ejecuta, fallan, y deja de fiarse del resto del texto.
     */
    public function test_todos_los_comandos_que_menciona_existen(): void
    {
        preg_match_all('/larapack:[a-z-]+/', Skill::contents(), $matches);

        $mentioned = array_unique($matches[0]);

        $this->assertNotEmpty($mentioned);

        $available = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Commands/*Command.php') as $file) {
            $class = 'Innoboxrr\\LarapackGenerator\\Commands\\' . basename($file, '.php');
            $available[] = (new $class())->getName();
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
                'jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport.json',
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
}
