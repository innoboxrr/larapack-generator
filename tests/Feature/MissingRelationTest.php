<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Los traits de relaciones se escriben una sola vez: son un hueco del
 * proyecto. Una relación añadida después a `load_relations` nunca aparecía, y
 * nada lo decía. Expediente lo documenta como una trampa, y escribió a mano
 * `Location::practitioner_locations()`: es el caso de esta prueba, con el
 * contrato y el trait reales.
 */
final class MissingRelationTest extends TestCase
{
    private const TRAIT = 'app/Models/Traits/Relations/LocationRelations.php';

    private const METHOD = "    public function practitioner_locations()\n    {\n        return \$this->hasMany(PractitionerLocation::class);\n    }";

    /**
     * @var array<string, mixed>
     */
    private array $contract;

    private string $before;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());

        $fixtures = dirname(__DIR__).'/Fixtures/real';
        $this->contract = json_decode((string) file_get_contents($fixtures.'/expediente-location.json'), true);

        // El trait real, como estaba antes de que alguien escribiera la
        // relación a mano.
        $real = (string) file_get_contents($fixtures.'/LocationRelations.php.txt');
        $start = strpos($real, "    /**\n     * Los médicos que atienden aquí.");
        $end = strpos($real, '    public function schedules()');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->before = str_replace("use App\\Models\\PractitionerLocation;\n", '', substr($real, 0, $start).substr($real, $end));

        // Primero sin la relación; después se declara y se reimporta, que es lo
        // que hace un proyecto.
        $without = $this->contract;
        $without['models'][0]['load_relations'] = array_values(array_filter(
            $without['models'][0]['load_relations'],
            fn (array $relation): bool => $relation['name'] !== 'practitioner_locations'
        ));

        $this->import($without);
        file_put_contents($this->project->path.'/'.self::TRAIT, $this->before);
        $this->import($this->contract, ['--force' => true]);
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $options
     */
    private function import(array $contract, array $options = []): void
    {
        file_put_contents($this->project->path.'/laraimport.json', json_encode($contract, JSON_PRETTY_PRINT));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', [
            'jsonPath' => $this->project->path.'/laraimport.json',
        ] + $options), $this->lastOutput);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function verify(): array
    {
        $this->runCommand('larapack:verify', ['--format' => 'json', '--check' => ['relation-missing']]);

        return json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR)['findings'];
    }

    public function test_reimportar_no_toca_el_trait_porque_es_del_proyecto(): void
    {
        $this->assertSame($this->before, $this->project->read(self::TRAIT));
    }

    public function test_verify_dice_que_falta_y_con_que_codigo(): void
    {
        $findings = $this->verify();

        $this->assertCount(1, $findings, $this->lastOutput);
        $this->assertSame('relation-missing', $findings[0]['check']);
        $this->assertSame('warning', $findings[0]['level']);
        $this->assertSame('Location', $findings[0]['model']);
        $this->assertSame(self::TRAIT, $findings[0]['file']);
        $this->assertStringContainsString(self::METHOD, $findings[0]['message']);
        $this->assertStringContainsString('use App\\Models\\PractitionerLocation;', $findings[0]['message']);
        $this->assertStringContainsString('larapack:fix relation-missing', $findings[0]['message']);
    }

    public function test_fix_la_anade_al_trait_sin_tocar_lo_demas(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:fix', ['checks' => ['relation-missing']]), $this->lastOutput);

        $expected = str_replace(
            "use App\\Models\\Clinic;\n",
            "use App\\Models\\Clinic;\nuse App\\Models\\PractitionerLocation;\n",
            str_replace(
                "        return \$this->hasMany(Schedule::class);\n    }\n}",
                "        return \$this->hasMany(Schedule::class);\n    }\n\n".self::METHOD."\n}",
                $this->before
            )
        );

        // `use` va detrás del último import, así que delante de Schedule.
        $expected = str_replace(
            "use App\\Models\\Clinic;\nuse App\\Models\\PractitionerLocation;\nuse App\\Models\\Schedule;\n",
            "use App\\Models\\Clinic;\nuse App\\Models\\Schedule;\nuse App\\Models\\PractitionerLocation;\n",
            $expected
        );

        $this->assertSame($expected, $this->project->read(self::TRAIT));
        $this->assertSame([], $this->verify());

        // Una segunda vez no cambia nada.
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:fix', ['checks' => ['relation-missing']]), $this->lastOutput);
        $this->assertSame($expected, $this->project->read(self::TRAIT));
    }

    public function test_dry_run_ensena_el_diff_sin_escribir(): void
    {
        $this->runCommand('larapack:fix', ['checks' => ['relation-missing'], '--dry-run' => true, '--format' => 'json']);
        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->before, $this->project->read(self::TRAIT));
        $this->assertSame(self::TRAIT, $report['checks']['relation-missing']['fixed'][0]['file']);
        $this->assertStringContainsString('+    public function practitioner_locations()', $report['checks']['relation-missing']['fixed'][0]['diff']);
    }

    public function test_si_el_nombre_corto_ya_se_importa_para_otra_clase_se_salta(): void
    {
        file_put_contents($this->project->path.'/'.self::TRAIT, str_replace(
            "use App\\Models\\Clinic;\n",
            "use App\\Models\\Clinic;\nuse Other\\Domain\\PractitionerLocation;\n",
            $this->before
        ));

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:fix', ['checks' => ['relation-missing'], '--format' => 'json']));
        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $report['checks']['relation-missing']['fixed']);
        $this->assertStringContainsString('Other\\Domain\\PractitionerLocation', $report['checks']['relation-missing']['skipped'][0]['reason']);
    }

    public function test_si_el_modelo_ya_la_define_no_se_reporta(): void
    {
        $model = $this->project->path.'/app/Models/Location.php';
        file_put_contents($model, str_replace("\n}\n", "\n\n    public function practitioner_locations()\n    {\n        return \$this->hasMany(PractitionerLocation::class);\n    }\n}\n", (string) file_get_contents($model)));

        $this->assertSame([], $this->verify());
    }
}
