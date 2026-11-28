<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ProjectTests;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * El estado del realineamiento se versiona con el proyecto y lo lee quien
 * tenga el paquete instalado, que puede ser una versión anterior a la que lo
 * escribió. Cada archivo que se lee dice su formato, y uno de un formato
 * posterior no se interpreta: se dice cuál es, hasta cuál se entiende y qué
 * hacer. Y un error, en JSON, lleva siempre `format_version` y `action`.
 */
final class RealignFormatVersionTest extends TestCase
{
    use RealignProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stateFiles(): iterable
    {
        yield 'el informe' => ['report.json'];
        yield 'el plan' => ['plan.json'];
    }

    #[DataProvider('stateFiles')]
    public function test_un_estado_de_un_formato_posterior_no_se_lee(string $file): void
    {
        $this->writeState($file, ['format_version' => RealignState::FORMAT_VERSION + 1, 'steps' => [], 'scopes' => []]);

        $document = $this->realign('status', [], Command::FAILURE);

        $this->assertFalse($document['ok']);
        $this->assertStringContainsString($file.' está en la versión '.(RealignState::FORMAT_VERSION + 1).' del formato', $document['error']);
        $this->assertStringContainsString('entiende hasta la '.RealignState::FORMAT_VERSION, $document['error']);
        $this->assertStringContainsString('Actualiza innoboxrr/larapack-generator', $document['error']);
        $this->assertSame(RealignState::FORMAT_VERSION, $document['format_version']);
        $this->assertSame('status', $document['action']);
    }

    #[DataProvider('stateFiles')]
    public function test_un_estado_sin_version_no_se_confunde_con_uno_posterior(string $file): void
    {
        $this->writeState($file, ['steps' => [], 'scopes' => []]);

        $document = $this->realign('status', [], Command::FAILURE);

        $this->assertStringContainsString($file.' no dice su `format_version`', $document['error']);
        $this->assertStringNotContainsString('Actualiza', $document['error']);
    }

    public function test_la_version_de_hoy_y_las_anteriores_se_leen(): void
    {
        $state = new RealignState($this->project->path);
        $state->savePlan(['steps' => []]);

        $this->assertSame(RealignState::FORMAT_VERSION, $state->plan()['format_version'] ?? null);
        $this->assertSame(['format_version', 'steps'], array_keys((array) json_decode((string) file_get_contents($state->planPath()), true)), 'la versión, lo primero del archivo');

        $this->writeState('plan.json', ['format_version' => RealignState::FORMAT_VERSION - 1, 'steps' => []]);
        $this->assertSame([], $state->requirePlan()['steps']);
    }

    public function test_las_solicitudes_de_excepcion_de_un_formato_posterior_no_se_leen(): void
    {
        $this->writeState('exceptions.json', ['format_version' => RealignState::FORMAT_VERSION + 1, 'requests' => []]);

        try {
            Exceptions::forRoot($this->project->path)->requests();
            $this->fail('Se leyeron solicitudes de un formato posterior.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceptions.json está en la versión '.(RealignState::FORMAT_VERSION + 1), $e->getMessage());
            $this->assertStringContainsString('Actualiza innoboxrr/larapack-generator', $e->getMessage());
        }
    }

    public function test_la_configuracion_de_un_formato_posterior_no_se_lee(): void
    {
        $this->writeState('config.json', ['format_version' => RealignState::FORMAT_VERSION + 1, 'tests' => 'php artisan test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config.json está en la versión '.(RealignState::FORMAT_VERSION + 1));

        (new ProjectTests($this->project->path))->command();
    }

    /**
     * Manifest lee un manifiesto posterior (sólo se niega a reescribirlo);
     * el diagnóstico no clasifica con él.
     */
    public function test_diagnose_no_clasifica_con_un_manifiesto_de_un_formato_posterior(): void
    {
        copy(dirname(__DIR__).'/Fixtures/laraimport.json', $this->project->path.'/laraimport.json');
        @mkdir($this->project->path.'/.larapack', 0777, true);
        file_put_contents($this->project->path.'/.larapack/manifest.json', json_encode(['version' => Manifest::VERSION + 1, 'models' => [], 'package' => []]));

        $document = $this->realign('diagnose', [], Command::FAILURE);

        $this->assertStringContainsString('.larapack/manifest.json está en la versión '.(Manifest::VERSION + 1).' del formato', $document['error']);
        $this->assertStringContainsString('entiende hasta la '.Manifest::VERSION, $document['error']);
        $this->assertSame('diagnose', $document['action']);
        $this->assertFileDoesNotExist((new RealignState($this->project->path))->reportPath(), 'no dejó un informe a medias');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function failures(): iterable
    {
        yield 'una acción que no existe' => ['adivina', []];
        yield 'una opción que la acción no usa' => ['plan', ['--dry-run' => true]];
        yield 'un argumento de más' => ['status', ['targets' => ['sobra']]];
        yield 'check sin id' => ['check', []];
        yield 'decide sin opción' => ['decide', ['targets' => ['un-paso']]];
        yield 'brief sin plan' => ['brief', []];
        yield 'playbook que no existe' => ['playbook', ['targets' => ['no-existe']]];
        yield 'jobs que no es un número' => ['diagnose', ['--jobs' => '0']];
        yield 'option sin valor' => ['adopt', ['--option' => ['suelta']]];
        yield 'help de una acción que no existe' => ['help', ['targets' => ['adivina']]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('failures')]
    public function test_un_error_en_json_lleva_siempre_la_version_y_la_accion(string $action, array $arguments): void
    {
        $document = $this->realign($action, $arguments, Command::FAILURE);

        $this->assertFalse($document['ok']);
        $this->assertNotSame('', $document['error']);
        $this->assertSame(RealignState::FORMAT_VERSION, $document['format_version']);
        $this->assertSame($action, $document['action']);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeState(string $file, array $document): void
    {
        (new RealignState($this->project->path))->prepare();
        file_put_contents($this->project->path.'/'.RealignState::DIRECTORY.'/'.$file, json_encode($document));
    }
}
