<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use LogicException;
use Symfony\Component\Console\Command\Command;

/**
 * `immutable` y `secret`, comprobados en el código generado y también
 * ejecutándolo.
 *
 * Leer el texto dice que la guarda está escrita; cargar el modelo y llamar a
 * save() dice que funciona. Lo segundo es lo que el spec pide de verdad: la
 * invariante no es «no hay endpoint», es «esta fila no se modifica nunca».
 */
final class DeclaredImmutableAndSecretTest extends TestCase
{
    /** @var (callable(string): void)|null */
    private $autoloader = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Ledger\\'));

        $path = $this->project->path . '/laraimport.json';

        file_put_contents($path, json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
            ['name' => 'Consent', 'props' => [['name' => 'accepted', 'type' => 'boolean']], 'immutable' => true],
            ['name' => 'ApiKey', 'props' => [
                ['name' => 'label', 'type' => 'string', 'datatable' => true],
                ['name' => 'token_hash', 'type' => 'string', 'secret' => true],
            ]],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }

        Model::unsetEventDispatcher();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();

        parent::tearDown();
    }

    public function test_todo_el_php_generado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    // IMMUTABLE

    public function test_el_modelo_inmutable_trae_la_guarda(): void
    {
        $model = $this->project->read('src/Models/Consent.php');

        $this->assertStringContainsString('protected static function booted(): void', $model);
        $this->assertStringContainsString('static::updating($refuse);', $model);
        $this->assertStringContainsString('static::deleting($refuse);', $model);

        $this->assertStringNotContainsString('booted', $this->project->read('src/Models/Post.php'));
    }

    public function test_el_modelo_inmutable_rechaza_modificarse_de_verdad(): void
    {
        $consent = $this->existing('Consent', ['id' => 1, 'accepted' => true]);

        $consent->accepted = false;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Consent es inmutable');

        $consent->save();
    }

    public function test_el_modelo_inmutable_rechaza_borrarse_de_verdad(): void
    {
        $consent = $this->existing('Consent', ['id' => 1, 'accepted' => true]);

        $this->expectException(LogicException::class);

        $consent->delete();
    }

    /**
     * La guarda es del modelo inmutable, no de todos: un modelo normal se
     * modifica hasta donde lo deja la base de datos.
     */
    public function test_un_modelo_normal_no_rechaza_modificarse(): void
    {
        $post = $this->existing('Post', ['id' => 1, 'title' => 'a']);

        $post->title = 'b';

        try {
            $post->save();
        } catch (LogicException $e) {
            $this->fail('Un modelo normal no debería rechazar un save(): ' . $e->getMessage());
        } catch (\Throwable) {
            // Sin base de datos de verdad el UPDATE falla al ejecutarse, que es
            // justo después del punto que se comprueba.
        }

        $this->addToAssertionCount(1);
    }

    public function test_el_test_del_proyecto_comprueba_la_inmutabilidad(): void
    {
        $test = $this->project->read('tests/Feature/Models/ConsentEndpointsTest.php');

        foreach (['no_expone_rutas_de_escritura', 'no_admite_modificaciones', 'no_admite_borrarse'] as $name) {
            $this->assertStringContainsString("test_consent_{$name}", $test);
        }

        $this->assertStringNotContainsString('no_admite_modificaciones', $this->project->read('tests/Feature/Models/PostEndpointsTest.php'));
    }

    // SECRET

    public function test_el_secreto_va_a_hidden_y_no_a_la_exportacion(): void
    {
        $model = $this->project->read('src/Models/ApiKey.php');

        $this->assertMatchesRegularExpression("/protected \\\$hidden = \\[\\s*'token_hash'\\s*\\];/", $model);
        $this->assertMatchesRegularExpression("/\\\$export_cols = \\[\\s*'label'\\s*\\];/", $model);

        $this->assertStringNotContainsString('$hidden', $this->project->read('src/Models/Post.php'));
    }

    public function test_el_secreto_no_es_columna_de_la_tabla(): void
    {
        $contract = $this->project->read('resources/vue/src/models/api-key/index.js');

        $this->assertStringContainsString("id: 'label'", $contract);
        $this->assertStringNotContainsString('token_hash', $contract);
    }

    /**
     * Lo que de verdad importa: lo que devuelve la API.
     */
    public function test_el_secreto_no_sale_por_el_resource(): void
    {
        $key = $this->model('ApiKey');
        $key->forceFill(['id' => 7, 'label' => 'ci', 'token_hash' => 'sha256:abc']);

        $resourceClass = 'Acme\\Ledger\\Http\\Resources\\Models\\ApiKeyResource';
        $payload = (new $resourceClass($key))->toArray(Request::create('/'));

        $this->assertSame('ci', $payload['label']);
        $this->assertArrayNotHasKey('token_hash', $payload);

        // Y se sigue pudiendo escribir.
        $this->assertSame('sha256:abc', $key->token_hash);
    }

    /**
     * Carga el código generado y levanta Eloquent sin base de datos: los
     * eventos que se comprueban se disparan antes de ejecutar ninguna consulta.
     */
    private function model(string $name): Model
    {
        if ($this->autoloader === null) {
            $source = $this->project->path . '/src/';

            $this->autoloader = static function (string $class) use ($source): void {
                if (str_starts_with($class, 'Acme\\Ledger\\')) {
                    $file = $source . str_replace('\\', '/', substr($class, strlen('Acme\\Ledger\\'))) . '.php';

                    if (is_file($file)) {
                        require $file;
                    }
                }
            };

            spl_autoload_register($this->autoloader);

            $capsule = new Capsule();
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->bootEloquent();
        }

        $class = 'Acme\\Ledger\\Models\\' . $name;

        return new $class();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function existing(string $name, array $attributes): Model
    {
        $model = $this->model($name);

        $model->forceFill($attributes);
        $model->syncOriginal();
        $model->exists = true;

        return $model;
    }
}
