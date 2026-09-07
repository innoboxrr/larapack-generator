<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * El destino se descubría subiendo directorios desde el propio paquete hasta
 * dar con un vendor/autoload.php. Sobre un clon del generador eso da el
 * generador: el binario `builder` escribía donde estuviera instalado, no donde
 * se quisiera generar.
 *
 * Un agente necesita poder apuntar al proyecto de forma explícita.
 */
final class TargetsProjectTest extends TestCase
{
    private FakeProject $otro;

    protected function setUp(): void
    {
        parent::setUp();

        // La raíz "por defecto" de esta prueba, para que un --root ignorado
        // escriba aquí y no en el repositorio del generador.
        $this->useProject(FakeProject::library('Defecto\\Pkg\\'));

        $this->otro = FakeProject::library('Otro\\Pkg\\');
    }

    protected function tearDown(): void
    {
        $this->otro->cleanup();

        parent::tearDown();
    }

    public function test_root_decide_donde_se_genera(): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:model', ['name' => 'Post', '--root' => $this->otro->path]),
            $this->lastOutput
        );

        $this->assertTrue($this->otro->has('src/Models/Post.php'), 'No se generó en la raíz indicada.');
        $this->assertFalse($this->project->has('src/Models/Post.php'), 'Se generó en la raíz por defecto.');
    }

    public function test_root_usa_el_namespace_del_proyecto_indicado(): void
    {
        $this->runCommand('larapack:model', ['name' => 'Post', '--root' => $this->otro->path]);

        $this->assertStringContainsString('namespace Otro\Pkg\Models;', $this->otro->read('src/Models/Post.php'));
    }

    /**
     * Una raíz mal escrita tiene que parar el comando. Lo peligroso seria
     * seguir con la raíz descubierta y generar en un proyecto ajeno.
     */
    public function test_una_raiz_inexistente_falla_en_vez_de_generar_en_otro_sitio(): void
    {
        try {
            $this->runCommand('larapack:model', ['name' => 'Post', '--root' => $this->otro->path . '/no-existe']);

            $this->fail('Se esperaba que una raíz inexistente abortara el comando.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('La raíz indicada no existe', $exception->getMessage());
        }

        $this->assertFalse($this->project->has('src/Models/Post.php'));
        $this->assertFalse($this->otro->has('src/Models/Post.php'));
    }
}
