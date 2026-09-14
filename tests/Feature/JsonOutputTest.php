<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use JsonException;
use Symfony\Component\Console\Command\Command;

/**
 * Con --format=json la salida es un único documento JSON y nada más.
 *
 * La guía del agente dice «parséalo, no raspes el texto», y quien lo parsea
 * decodifica la salida entera: larapack:import escribía su progreso y los avisos
 * del laraimport antes del informe.
 */
final class JsonOutputTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/laraimport.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Shop\\'));
    }

    // IMPORT

    public function test_import_informa_en_un_solo_documento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => self::FIXTURE, '--format' => 'json']), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertFalse($report['dryRun']);
        $this->assertNotSame([], $report['files']);

        // Los avisos del laraimport iban como texto delante del informe; ahora van dentro.
        $this->assertNotSame([], $report['findings']);
        $this->assertSame(['warning'], array_values(array_unique(array_column($report['findings'], 'level'))));
    }

    public function test_import_simula_en_un_solo_documento(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => self::FIXTURE, '--format' => 'json', '--dry-run' => true]), $this->lastOutput);

        $report = $this->decodeOutput();

        $this->assertTrue($report['dryRun']);
        $this->assertNotSame([], $report['files']);
        $this->assertSame([], $this->project->phpFiles());
    }

    // AYUDAS

    /**
     * La salida entera, decodificada: así la lee un agente.
     *
     * @return array<string, mixed>
     */
    private function decodeOutput(): array
    {
        try {
            $decoded = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail("Con --format=json la salida no es un único documento JSON ({$e->getMessage()}):\n{$this->lastOutput}");
        }

        $this->assertIsArray($decoded, "Con --format=json la salida no es un objeto JSON:\n{$this->lastOutput}");

        return $decoded;
    }
}
