<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Un paquete que sólo integra —una pasarela de pago, un PAC, una terminal—
 * declara `"models": []`. En school-management son doce (`school-pay-*`,
 * `school-pac-*`, `school-tpv-*`), y con el esquema de 9.x los doce fallaban
 * `validate` con «Array should have at least 1 items, 0 found»: lo único que
 * podían hacer era quitar el laraimport, y con él la puerta de la CI.
 *
 * El laraimport de la prueba es el de `school-pay-stripe`, tal cual.
 */
final class PackageWithoutModelsTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/real-projects/school-management/packages/school-pay-stripe/laraimport.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Innoboxrr\\SchoolPayStripe\\'));

        copy(self::FIXTURE, $this->project->path.'/laraimport.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $command, array $arguments = []): array
    {
        $code = $this->runCommand($command, ['--format' => 'json'] + $arguments);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return ['code' => $code] + $report;
    }

    public function test_validate_acepta_un_paquete_sin_modelos(): void
    {
        $report = $this->json('larapack:validate', ['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $report['code'], json_encode($report['findings']));
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['models']);
    }

    public function test_import_no_genera_nada_de_modelos_y_no_falla(): void
    {
        $report = $this->json('larapack:import');

        $this->assertSame(Command::SUCCESS, $report['code'], $this->lastOutput);
        $this->assertTrue($report['ok']);
        $this->assertSame([], array_filter(
            $report['files'],
            fn (array $file): bool => in_array($file['action'], ['create', 'overwrite'], true)
        ));
        $this->assertFalse($this->project->has('src/Models'));
    }

    /**
     * Un laraimport que no declara nada que generar no deja manifiesto, y eso
     * es lo esperado: avisar de que «no hay nada registrado» con --strict lo
     * dejaría en rojo para siempre.
     */
    public function test_verify_no_lo_trata_como_un_error_ni_con_strict(): void
    {
        $this->json('larapack:import');

        $report = $this->json('larapack:verify', ['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $report['code'], json_encode($report['findings']));
        $this->assertNotContains('empty-manifest', array_column($report['findings'], 'check'));
    }

    /**
     * Con sus proveedores, que es como nace con `larapack:new`, lo registrado
     * se sigue comprobando.
     */
    public function test_con_sus_proveedores_se_siguen_comprobando(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:providers'), $this->lastOutput);
        $this->json('larapack:import');

        $report = $this->json('larapack:verify', ['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $report['code'], json_encode($report['findings']));

        unlink($this->project->path.'/src/Providers/AppServiceProvider.php');

        $report = $this->json('larapack:verify');

        $this->assertSame(Command::FAILURE, $report['code']);
        $this->assertSame(['missing-file'], array_column($report['findings'], 'check'));
    }

    /**
     * Sin `models` sigue siendo un error: es la clave que dice qué hay. Lo que
     * se admite es la lista vacía, no la ausencia.
     */
    public function test_sin_la_clave_models_sigue_siendo_un_error(): void
    {
        file_put_contents($this->project->path.'/laraimport.json', '{"version": 1, "pivots": []}');

        $report = $this->json('larapack:validate');

        $this->assertSame(Command::FAILURE, $report['code']);
        $this->assertFalse($report['ok']);
    }

    /**
     * Un laraimport vacío con un pivote que nombra modelos que no declara no
     * pasa por ser «un paquete sin modelos».
     */
    public function test_un_pivote_sin_sus_modelos_sigue_fallando(): void
    {
        file_put_contents($this->project->path.'/laraimport.json', json_encode([
            'models' => [],
            'pivots' => [['models' => ['Post', 'Tag']]],
        ]));

        $report = $this->json('larapack:validate');

        $this->assertSame(Command::FAILURE, $report['code'], json_encode($report));
    }
}
