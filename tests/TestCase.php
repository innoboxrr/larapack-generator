<?php

namespace Innoboxrr\LarapackGenerator\Tests;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected FakeProject $project;

    protected string $lastOutput = '';

    protected function tearDown(): void
    {
        ProjectRoot::set(null);
        Generation::reset();
        Declaration::reset();
        MigrationTimestamp::reset();

        if (isset($this->project)) {
            $this->project->cleanup();
        }

        parent::tearDown();
    }

    /**
     * Monta el proyecto destino y apunta el generador a él.
     */
    protected function useProject(FakeProject $project): FakeProject
    {
        $this->project = $project;

        ProjectRoot::set($project->path);

        return $project;
    }

    /**
     * Ejecuta un comando del generador igual que lo haría el binario
     * `builder`. Ver Support\Larapack.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function runCommand(string $name, array $arguments = []): int
    {
        [$exitCode, $this->lastOutput] = Larapack::run($name, $arguments);

        return $exitCode;
    }

    /**
     * Todo el PHP generado tiene que compilar. Es la red que faltaba: los
     * stubs son texto plano, así que un error de sintaxis solo aparece al
     * abrir el archivo en el proyecto destino.
     */
    protected function assertGeneratedPhpCompiles(): void
    {
        $files = $this->project->phpFiles();

        $this->assertNotEmpty($files, 'El generador no produjo ningún archivo PHP.');

        $errors = [];

        foreach ($files as $relative) {
            $result = $this->lint($this->project->path . '/' . $relative);

            if ($result !== null) {
                $errors[] = "{$relative}: {$result}";
            }
        }

        $this->assertSame([], $errors, "PHP generado con errores de sintaxis:\n" . implode("\n", $errors));
    }

    /**
     * @return string|null  El mensaje de error, o null si compila.
     */
    private function lint(string $file): ?string
    {
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);

        exec($command . ' 2>&1', $output, $exitCode);

        return $exitCode === 0 ? null : trim(implode(' ', $output));
    }

    protected function assertGenerated(string $relative): void
    {
        $this->assertTrue(
            $this->project->has($relative),
            "Se esperaba que el generador creara {$relative}.\nGenerados:\n  "
            . implode("\n  ", $this->project->phpFiles())
        );
    }
}
