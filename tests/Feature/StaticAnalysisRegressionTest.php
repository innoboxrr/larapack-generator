<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Innoboxrr\LarapackGenerator\Tools\Model\ModelTool;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que encontró PHPStan al subir de nivel 5 a 8 y que era un fallo de
 * verdad, no sólo un tipo sin declarar. Cada prueba fallaba antes del arreglo.
 */
final class StaticAnalysisRegressionTest extends TestCase
{
    /**
     * json_decode() de un composer.json roto devuelve null; se le añadían los
     * proveedores y se escribía encima un archivo con esa clave y nada más.
     */
    public function test_no_reescribe_un_composer_json_que_no_se_entiende(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $broken = "{\n    \"name\": \"acme/blog\",\n    \"type\": \"library\",\n";
        file_put_contents($this->project->path.'/composer.json', $broken);

        try {
            $this->runCommand('larapack:providers');
            $this->fail('Se esperaba que no tocara un composer.json inválido.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('composer.json no es un objeto JSON válido', $e->getMessage());
        }

        $this->assertSame($broken, $this->project->read('composer.json'));
    }

    /**
     * Sin `autoload-dev.psr-4`, la sección entera se sustituía por una con sólo
     * el namespace de los tests: un classmap declarado ahí desaparecía.
     */
    public function test_conserva_lo_que_ya_declara_autoload_dev(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $composer = json_decode($this->project->read('composer.json'), true);
        $composer['autoload-dev'] = ['classmap' => ['tests/fixtures']];
        file_put_contents($this->project->path.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:test', ['name' => 'Post']), $this->lastOutput);

        $composer = json_decode($this->project->read('composer.json'), true);

        $this->assertSame(['tests/fixtures'], $composer['autoload-dev']['classmap']);
        $this->assertSame('tests/', $composer['autoload-dev']['psr-4']['Acme\\Blog\\Tests\\']);
    }

    /**
     * Leía la primera clave de `autoload.psr-4` antes de comprobar que existía:
     * sin ella, array_keys(null) era un TypeError y la rama que la creaba no
     * corría nunca.
     */
    public function test_la_factory_crea_el_psr4_si_composer_json_no_lo_trae(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        file_put_contents($this->project->path.'/composer.json', json_encode(['name' => 'acme/blog', 'type' => 'library']));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:factory', ['name' => 'Post']), $this->lastOutput);

        $composer = json_decode($this->project->read('composer.json'), true);

        $this->assertSame(['Database\\Factories\\' => 'database/factories/'], $composer['autoload']['psr-4']);
    }

    /**
     * Con el importador activo, un modelo que el laraimport no declara seguía
     * como null hasta un TypeError sin contexto en mitad de la herramienta.
     */
    public function test_el_post_proceso_dice_que_modelo_falta_en_el_laraimport(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        Tool::setFromJsonImporter(true);
        Tool::setJsonContent(['models' => [['name' => 'Post', 'props' => []]]]);

        try {
            $this->expectException(MakerException::class);
            $this->expectExceptionMessage('El laraimport no declara el modelo «Ghost».');

            (new ModelTool)->create('Ghost');
        } finally {
            Tool::setFromJsonImporter(false);
            Tool::setJsonContent([]);
        }
    }
}
