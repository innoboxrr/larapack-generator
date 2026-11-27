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
     * Sin `autoload.psr-4` hacia src/ no hay namespace del paquete. En 9.1 la
     * factoría reventaba con un TypeError (array_keys(null)); devolver '' lo
     * convertía en basura silenciosa: `Database\Factories\` sin prefijo en el
     * composer.json del paquete, que choca con el de la aplicación. Ni una
     * cosa ni la otra: se falla diciendo qué falta, sin tocar nada.
     */
    public function test_la_factory_falla_sin_namespace_y_no_toca_composer_json(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $composer = (string) json_encode(['name' => 'acme/blog', 'type' => 'library']);
        file_put_contents($this->project->path.'/composer.json', $composer);

        try {
            $this->runCommand('larapack:factory', ['name' => 'Post']);
            $this->fail('Se esperaba que fallara sin namespace PSR-4.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('no declara en `autoload.psr-4` ningún namespace para src/', $e->getMessage());
        }

        $this->assertSame($composer, $this->project->read('composer.json'));
        $this->assertFalse($this->project->has('database'));
    }

    /**
     * Lo mismo en el comando que más escribe: sin namespace, larapack:full-model
     * salía en 0 con el modelo en `namespace Models;`. Ahora no escribe nada.
     *
     * Un PSR-4 que existe pero no apunta a src/ (sólo el de los tests) cuenta
     * como ninguno: no es el namespace de lo que se genera.
     */
    public function test_full_model_no_genera_en_el_namespace_global(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $composer = (string) json_encode([
            'name' => 'acme/blog',
            'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\Blog\\Tests\\' => 'tests/']],
        ]);
        file_put_contents($this->project->path.'/composer.json', $composer);

        try {
            $this->runCommand('larapack:full-model', ['name' => 'Post']);
            $this->fail('Se esperaba que fallara sin namespace PSR-4 para src/.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('ningún namespace para src/', $e->getMessage());
        }

        $this->assertSame($composer, $this->project->read('composer.json'));
        $this->assertFalse($this->project->has('src'));
        $this->assertFalse($this->project->has('database'));
        $this->assertFalse($this->project->has('.larapack'));
    }

    /**
     * larapack:test escribía tests/TestCase.php y tests/User.php —con un
     * namespace vacío— antes de descubrir, al tocar composer.json, que no se
     * entendía. Ahora composer.json se lee y se valida antes de escribir nada.
     */
    public function test_larapack_test_no_escribe_nada_con_un_composer_json_roto(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $broken = "{\n    \"name\": \"acme/blog\",\n";
        file_put_contents($this->project->path.'/composer.json', $broken);

        try {
            $this->runCommand('larapack:test', ['name' => 'Post']);
            $this->fail('Se esperaba que no tocara un composer.json inválido.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('composer.json no es un objeto JSON válido', $e->getMessage());
        }

        $this->assertSame($broken, $this->project->read('composer.json'));
        $this->assertFalse($this->project->has('tests'));
        $this->assertFalse($this->project->has('phpunit.xml'));
    }

    /**
     * Un composer.json roto en una aplicación se leía como vacío, es decir,
     * como un paquete: se generaba en src/ en vez de app/.
     */
    public function test_un_composer_json_roto_no_se_toma_por_un_paquete(): void
    {
        $this->useProject(FakeProject::application());

        file_put_contents($this->project->path.'/composer.json', '{"type": "project",');

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('no es un objeto JSON válido');

        try {
            $this->runCommand('larapack:model', ['name' => 'Post']);
        } finally {
            $this->assertFalse($this->project->has('src'));
            $this->assertFalse($this->project->has('app'));
        }
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
