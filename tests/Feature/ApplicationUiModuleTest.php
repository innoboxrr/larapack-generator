<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El módulo de interfaz dentro de una aplicación.
 *
 * En un paquete, el módulo se publica en npm y necesita su package.json y su
 * vite.config.js. En una aplicación lo compila el Vite de la aplicación: el
 * segundo par que se generaba en resources/ no lo usaba nadie.
 */
final class ApplicationUiModuleTest extends TestCase
{
    private function import(FakeProject $project): void
    {
        $this->useProject($project);

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string', 'datatable' => true]]],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true, '--react' => true]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    public function test_en_una_aplicacion_el_modulo_no_trae_su_propio_build(): void
    {
        $this->import(FakeProject::application());

        foreach (['vue', 'react'] as $ui) {
            $this->assertFalse($this->project->has("resources/{$ui}/package.json"), "{$ui}: sobra package.json en una aplicación.");
            $this->assertFalse($this->project->has("resources/{$ui}/vite.config.js"), "{$ui}: sobra vite.config.js en una aplicación.");

            $this->assertTrue($this->project->has("resources/{$ui}/index.js"), "{$ui}: falta el punto de entrada del módulo.");
            $this->assertTrue($this->project->has("resources/{$ui}/src/routes/index.js"), "{$ui}: falta el agregador de rutas.");
            $this->assertTrue($this->project->has("resources/{$ui}/src/models/post/index.js"), "{$ui}: falta el contrato del modelo.");
        }
    }

    public function test_en_un_paquete_el_modulo_sigue_trayendo_su_build(): void
    {
        $this->import(FakeProject::library('Acme\\Blog\\'));

        foreach (['vue', 'react'] as $ui) {
            $this->assertTrue($this->project->has("resources/{$ui}/package.json"));
            $this->assertTrue($this->project->has("resources/{$ui}/vite.config.js"));
        }
    }
}
