<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Command\Command;

/**
 * Lo común a las pruebas de `larapack:realign`: un proyecto generado de
 * verdad (el laraimport de las pruebas, con React), comandos en JSON y lo que
 * hay que tocar para desviarlo como se desvían los proyectos reales.
 */
trait RealignProject
{
    /**
     * Un paquete generado con el laraimport de las pruebas.
     */
    protected function generatedPackage(): FakeProject
    {
        $project = $this->useProject(FakeProject::library('Acme\\Blog\\'));

        copy(dirname(__DIR__).'/Fixtures/laraimport.json', $project->path.'/laraimport.json');

        Assert::assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--react' => true, '--format' => 'json']), $this->lastOutput);

        return $project;
    }

    /**
     * Quita del contrato las banderas que el laraimport de las pruebas pone
     * en `payload` y que el generador ignora (cuatro avisos de validate):
     * así el contrato pasa `validate --strict`, que es parte del criterio de
     * terminado (`larapack:realign done`), y genera lo mismo.
     */
    protected function withoutIgnoredFlags(): void
    {
        $path = $this->project->path.'/laraimport.json';
        $contract = json_decode((string) file_get_contents($path), true);

        foreach ($contract['models'] as $model => $definition) {
            foreach ($definition['props'] as $index => $prop) {
                if ($prop['name'] === 'payload') {
                    foreach (['fillable', 'creatable', 'updatable', 'exports_cols'] as $flag) {
                        unset($contract['models'][$model]['props'][$index][$flag]);
                    }
                }
            }
        }

        file_put_contents($path, json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function realign(string $action, array $arguments = [], int $expected = Command::SUCCESS): array
    {
        $exit = $this->runCommand('larapack:realign', ['action' => $action, '--root' => $this->project->path, '--format' => 'json'] + $arguments);
        $document = json_decode($this->lastOutput, true);

        Assert::assertIsArray($document, "La salida de realign {$action} no es un documento JSON:\n".$this->lastOutput);
        Assert::assertSame($expected, $exit, "realign {$action} terminó con {$exit}:\n".$this->lastOutput);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    protected function report(): array
    {
        return json_decode($this->project->read('.larapack/realign/report.json'), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(): array
    {
        return json_decode($this->project->read('.larapack/realign/plan.json'), true);
    }

    /**
     * La entrada del informe de un archivo, o null si cumple (no se guarda).
     *
     * @return array<string, mixed>|null
     */
    protected function entry(string $file): ?array
    {
        foreach ($this->report()['files'] as $entry) {
            if ($entry['file'] === $file) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function step(string $id): array
    {
        foreach ($this->plan()['steps'] as $step) {
            if ($step['id'] === $id) {
                return $step;
            }
        }

        Assert::fail("No hay un paso {$id}. Hay: ".implode(', ', array_column($this->plan()['steps'], 'id')));
    }

    protected function edit(string $relative, string $search, string $replace): void
    {
        $content = $this->project->read($relative);

        Assert::assertStringContainsString($search, $content, "{$relative} no contiene lo que se iba a cambiar.");

        file_put_contents($this->project->path.'/'.$relative, str_replace($search, $replace, $content));
    }

    /**
     * Cambia el manifiesto como lo dejaría otra versión o una edición vieja.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    protected function changeManifest(callable $change): void
    {
        $path = $this->project->path.'/.larapack/manifest.json';
        file_put_contents($path, json_encode($change(json_decode((string) file_get_contents($path), true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function git(string ...$arguments): string
    {
        $command = ['git', '-C', $this->project->path, '-c', 'user.email=realign@test', '-c', 'user.name=realign', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        Assert::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    protected function commitAll(): void
    {
        if (! is_dir($this->project->path.'/.git')) {
            $this->git('init', '-q');
        }

        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'estado');
    }
}
