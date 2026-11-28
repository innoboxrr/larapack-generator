<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Un modelo editado a mano se conservaba entero con --force, y con él sus
 * listas: al añadir una columna al laraimport, `$fillable`, `$export_cols` y
 * `casts()` se quedaban sin ella. En school-management, 30 modelos; en
 * `app/Models/ExternalIdentity.php`, `shared` no se convertía a booleano.
 *
 * Las listas son del generador (el contrato del proyecto prohíbe editarlas a
 * mano): en un modelo conservado se completan sólo esas regiones, y lo demás
 * queda byte a byte. La prueba usa el modelo y el contrato reales.
 */
final class PreservedModelListsTest extends TestCase
{
    private const MODEL = 'app/Models/ExternalIdentity.php';

    private string $real;

    /**
     * @var array<string, mixed>
     */
    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());

        $fixtures = dirname(__DIR__).'/Fixtures/real';
        $this->real = (string) file_get_contents($fixtures.'/ExternalIdentity.php.txt');
        $this->contract = json_decode((string) file_get_contents($fixtures.'/school-management-external-identity.json'), true);

        // Como estaba el proyecto antes de la migración que añadió `shared`:
        // se genera sin ella y el modelo pasa a ser el comiteado, que está
        // formateado por Pint y por eso cuenta como editado a mano.
        $before = $this->contract;
        $before['models'][0]['props'] = array_values(array_filter(
            $before['models'][0]['props'],
            fn (array $prop): bool => $prop['name'] !== 'shared'
        ));

        $this->import($before);
        file_put_contents($this->project->path.'/'.self::MODEL, $this->real);
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

    public function test_force_completa_las_listas_del_modelo_conservado_y_no_toca_nada_mas(): void
    {
        $this->import($this->contract, ['--force' => true]);

        $expected = str_replace(
            // `shared` va donde la declara el contrato, no al final.
            [
                "'status', 'matched_by',",
                "'linked_at' => 'datetime', 'last_synced_at' => 'datetime',\n",
            ],
            [
                "'status', 'shared', 'matched_by',",
                "'shared' => 'boolean', 'linked_at' => 'datetime', 'last_synced_at' => 'datetime',\n",
            ],
            $this->real
        );

        $this->assertNotSame($this->real, $expected, 'La fixture ya no tiene la forma esperada.');
        $this->assertSame($expected, $this->project->read(self::MODEL));
        $this->assertStringContainsString('conservado', $this->lastOutput);
        $this->assertStringContainsString('$export_cols', $this->lastOutput);
    }

    public function test_el_informe_json_dice_que_se_anadio(): void
    {
        $this->import($this->contract, ['--force' => true, '--format' => 'json']);

        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([[
            'file' => self::MODEL,
            'added' => ['export_cols' => ["'shared'"], 'casts' => ["'shared' => 'boolean'"]],
            'skipped' => [],
            'dryRun' => false,
        ]], $report['regions']);
    }

    public function test_dry_run_lo_dice_sin_escribir(): void
    {
        $this->import($this->contract, ['--force' => true, '--dry-run' => true, '--format' => 'json']);

        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->real, $this->project->read(self::MODEL));
        $this->assertTrue($report['regions'][0]['dryRun']);
        $this->assertSame(["'shared'"], $report['regions'][0]['added']['export_cols']);
    }

    public function test_una_region_que_no_se_reconoce_no_se_toca_y_se_dice_la_orden_manual(): void
    {
        // $export_cols armado con una constante: no se sabe con certeza si
        // `shared` ya está, así que no se toca. casts() sí se completa.
        $custom = str_replace(
            "public static \$export_cols = [\n",
            "public static \$export_cols = [\n        ...self::BASE_COLUMNS,\n",
            $this->real
        );
        file_put_contents($this->project->path.'/'.self::MODEL, $custom);

        $this->import($this->contract, ['--force' => true, '--format' => 'json']);

        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);
        $model = $this->project->read(self::MODEL);

        $this->assertStringContainsString("'last_synced_at', 'last_synced_hash',\n", $model);
        $this->assertStringContainsString("'shared' => 'boolean'", $model);
        $this->assertSame('export_cols', $report['regions'][0]['skipped'][0]['region']);
        $this->assertStringContainsString("'shared'", $report['regions'][0]['skipped'][0]['manual']);
        $this->assertStringContainsString(self::MODEL, $report['regions'][0]['skipped'][0]['manual']);
    }

    public function test_verify_detecta_el_desfase_sin_regenerar(): void
    {
        file_put_contents($this->project->path.'/laraimport.json', json_encode($this->contract, JSON_PRETTY_PRINT));

        $this->runCommand('larapack:verify', ['--format' => 'json', '--check' => ['model-lists-outdated']]);

        $findings = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR)['findings'];

        $this->assertCount(1, $findings, $this->lastOutput);
        $this->assertSame('model-lists-outdated', $findings[0]['check']);
        $this->assertSame('ExternalIdentity', $findings[0]['model']);
        $this->assertSame(self::MODEL, $findings[0]['file']);
        $this->assertStringContainsString("\$export_cols: 'shared'", $findings[0]['message']);
        $this->assertStringContainsString("casts(): 'shared' => 'boolean'", $findings[0]['message']);
        $this->assertStringContainsString('larapack:import --force', $findings[0]['message']);

        // Y después de completarlo, ya no.
        $this->import($this->contract, ['--force' => true]);
        $this->runCommand('larapack:verify', ['--format' => 'json', '--check' => ['model-lists-outdated']]);

        $this->assertSame([], json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR)['findings']);
    }
}
