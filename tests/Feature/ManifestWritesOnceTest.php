<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El manifiesto se escribe una vez por corrida, no una vez por archivo.
 *
 * En expediente el manifiesto pesa 1 MB y registra 3 969 archivos: reescribirlo
 * entero tras cada archivo generado era cuadrático y, en Windows, chocaba con
 * el bloqueo de archivos («Failed to open stream: Permission denied») en un
 * modelo distinto en cada corrida. El laraimport de la prueba son tres modelos
 * reales de ese proyecto.
 */
final class ManifestWritesOnceTest extends TestCase
{
    private const FIXTURE = 'real/expediente-three-models.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());
    }

    public function test_una_importacion_de_varios_modelos_escribe_el_manifiesto_una_sola_vez(): void
    {
        $before = Manifest::diskWrites();

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', [
            'jsonPath' => dirname(__DIR__).'/Fixtures/'.self::FIXTURE,
        ]), $this->lastOutput);

        $manifest = json_decode($this->project->read('.larapack/manifest.json'), true);

        // Lo escrito sigue siendo todo: los tres modelos, con sus archivos.
        $this->assertSame(['Clinic', 'Icd11Entity', 'Specialty'], array_keys($manifest['models']));
        $this->assertGreaterThan(20, count($manifest['models']['Clinic']['files']));

        $this->assertSame(1, Manifest::diskWrites() - $before, 'El manifiesto se reescribió más de una vez en la misma corrida.');
    }

    public function test_una_corrida_con_force_tambien_lo_escribe_una_vez(): void
    {
        $json = dirname(__DIR__).'/Fixtures/'.self::FIXTURE;

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => $json]), $this->lastOutput);

        $before = Manifest::diskWrites();

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => $json, '--force' => true]), $this->lastOutput);

        $this->assertSame(1, Manifest::diskWrites() - $before);
    }
}
