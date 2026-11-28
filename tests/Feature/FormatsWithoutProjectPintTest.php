<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Formatter;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Con `builder` usado desde fuera —el generador clonado aparte y el proyecto
 * sin vendor/—, `import --force` escribía sin formatear: school-management
 * quedó con unos 5 600 archivos que sólo diferían en el formato de Pint, y que
 * a partir de entonces el manifiesto daba por editados a mano.
 *
 * Ahora se usa la Pint del proyecto y, si no tiene, la que trae el generador,
 * siempre con el pint.json del proyecto. Y si no hay ninguna, se dice.
 */
final class FormatsWithoutProjectPintTest extends TestCase
{
    private const FIXTURE = 'real/expediente-three-models.json';

    private string|false $previous;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous = getenv('LARAPACK_PINT');
        // Como un proyecto real: nada de LARAPACK_PINT, que es una llave de la
        // suite de pruebas, y sin vendor/ (FakeProject no lo tiene).
        putenv('LARAPACK_PINT');

        $this->useProject(FakeProject::library());
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'LARAPACK_PINT' : 'LARAPACK_PINT='.$this->previous);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function import(): array
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', [
            'jsonPath' => dirname(__DIR__).'/Fixtures/'.self::FIXTURE,
            '--format' => 'json',
        ]), $this->lastOutput);

        return json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_sin_pint_en_el_proyecto_formatea_con_la_del_generador(): void
    {
        $report = $this->import();

        $this->assertIsInt($report['formatted']);
        $this->assertGreaterThan(0, $report['formatted']);
        $this->assertSame('generator', $report['formatter']['source']);

        // Lo que Pint hace y la plantilla no: los imports en orden alfabético.
        $model = $this->project->read('src/Models/Specialty.php');
        $this->assertLessThan(
            strpos($model, 'use Illuminate\\Database\\Eloquent\\Model;'),
            strpos($model, 'use Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy;')
        );
        $this->assertLessThan(
            strpos($model, 'use TestVendor\\TestPkg\\Database\\Factories\\SpecialtyFactory;'),
            strpos($model, 'use Innoboxrr\\Traits\\MetaOperations;')
        );
    }

    public function test_usa_el_pint_json_del_proyecto(): void
    {
        // Una regla que el preset laravel no trae: si se aplica, se leyó el
        // pint.json del proyecto aunque la Pint sea la del generador.
        file_put_contents($this->project->path.'/pint.json', json_encode([
            'preset' => 'laravel',
            'rules' => ['declare_strict_types' => true],
        ]));

        $report = $this->import();

        $this->assertSame('pint.json', $report['formatter']['config']);
        $this->assertStringContainsString('declare(strict_types=1);', $this->project->read('src/Models/Specialty.php'));
    }

    public function test_lo_formateado_no_cuenta_como_editado_a_mano(): void
    {
        $this->import();

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', [
            'jsonPath' => dirname(__DIR__).'/Fixtures/'.self::FIXTURE,
            '--force' => true,
            '--format' => 'json',
        ]), $this->lastOutput);

        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $report['summary']['preserved'], 'Lo que sólo pasó por Pint se tomó por editado a mano.');
    }

    public function test_si_no_hay_ninguna_pint_lo_dice(): void
    {
        // La única forma de quedarse sin ninguna en la suite: apuntar a una
        // que no existe. El generador no la busca en otra parte.
        putenv('LARAPACK_PINT=/no/existe/pint');

        $report = $this->import();

        $this->assertNull($report['formatted']);
        $this->assertSame('none', $report['formatter']['source']);
        $this->assertStringContainsString('sin formatear', $report['formatter']['message']);
    }

    public function test_desactivarla_a_proposito_tambien_se_dice(): void
    {
        putenv('LARAPACK_PINT=off');

        $report = $this->import();

        $this->assertNull($report['formatted']);
        $this->assertSame('disabled', $report['formatter']['source']);
        $this->assertStringContainsString('LARAPACK_PINT=off', $report['formatter']['message']);
    }

    public function test_en_texto_dice_con_que_pint_formateo(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', [
            'jsonPath' => dirname(__DIR__).'/Fixtures/'.self::FIXTURE,
        ]), $this->lastOutput);

        $this->assertStringContainsString('la Pint del generador', $this->lastOutput);
    }

    public function test_varias_pint_a_la_vez_dejan_lo_mismo_que_una(): void
    {
        $previous = getenv(Formatter::JOBS_ENV);

        try {
            putenv(Formatter::JOBS_ENV);
            $this->assertSame(1, Formatter::jobs());
            $alone = $this->import();
            $expected = $this->formattedFiles();

            // Más archivos que los de un trozo: si no, no hay nada que repartir.
            $this->assertGreaterThan(40, $alone['formatted']);

            $this->project->cleanup();
            Generation::reset();
            Declaration::reset();
            MigrationTimestamp::reset();
            $this->useProject(FakeProject::library());

            putenv(Formatter::JOBS_ENV.'=3');
            $this->assertSame(3, Formatter::jobs());
            $together = $this->import();
        } finally {
            putenv($previous === false ? Formatter::JOBS_ENV : Formatter::JOBS_ENV.'='.$previous);
        }

        $this->assertSame($alone['formatted'], $together['formatted']);
        $this->assertSame($expected, $this->formattedFiles());
    }

    public function test_cuantas_pint_a_la_vez_lo_dice_el_entorno_y_por_defecto_una(): void
    {
        $previous = getenv(Formatter::JOBS_ENV);

        try {
            foreach (['4' => 4, '1' => 1, '0' => 1, '-2' => 1, 'muchas' => 1, '' => 1, '999' => 16] as $value => $jobs) {
                putenv(Formatter::JOBS_ENV.'='.$value);

                $this->assertSame($jobs, Formatter::jobs(), "LARAPACK_PINT_JOBS={$value}");
            }
        } finally {
            putenv($previous === false ? Formatter::JOBS_ENV : Formatter::JOBS_ENV.'='.$previous);
        }
    }

    /**
     * Los PHP del proyecto, ya formateados: ruta (sin la fecha de una
     * migración, que es la hora a la que se generó) => contenido.
     *
     * @return array<string, string>
     */
    private function formattedFiles(): array
    {
        $files = [];

        foreach ($this->project->phpFiles() as $relative) {
            $files[(string) preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}_/', '', $relative)] = $this->project->read($relative);
        }

        ksort($files);

        return $files;
    }
}
