<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Las factories y los tests que se generan dentro de una aplicación.
 *
 * Un paquete declara en su composer.json `<Namespace>\Database\Factories\` y
 * `<Namespace>\Tests\`. Una aplicación Laravel ya trae los suyos, sin `App\`
 * delante: `Database\Factories\` y `Tests\`. El piloto de la aplicación base
 * generó `App\Database\Factories` y `App\Tests`, que no carga nadie, y cada test
 * generado falló con "Class not found". Además llamaban a `/api/product/...`
 * cuando el RouteServiceProvider registra `api/app/product/...`.
 */
final class ApplicationFactoriesAndTestsTest extends TestCase
{
    /**
     * El archivo de rutas de cada modelo lleva su nombre en snake_case.
     */
    private const ROUTE_FILES = ['User' => 'user', 'Product' => 'product', 'OrderLine' => 'order_line'];

    /**
     * @param  array<int, string>  $models
     */
    private function import(FakeProject $project, array $models): void
    {
        $this->useProject($project);

        $declared = [
            'User' => [
                'name' => 'User',
                'authenticatable' => true,
                'props' => [
                    ['name' => 'name', 'type' => 'string', 'datatable' => true],
                    ['name' => 'email', 'type' => 'string', 'datatable' => true],
                    ['name' => 'email_verified_at', 'type' => 'timestamp', 'nullable' => true],
                    ['name' => 'password', 'type' => 'string'],
                ],
            ],
            'Product' => [
                'name' => 'Product',
                'props' => [['name' => 'title', 'type' => 'string', 'datatable' => true]],
            ],
            'OrderLine' => [
                'name' => 'OrderLine',
                'props' => [
                    ['name' => 'quantity', 'type' => 'integer'],
                    ['name' => 'product_id', 'type' => 'foreignId', 'constraint' => 'products'],
                ],
            ],
        ];

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode([
            'models' => array_map(fn (string $model): array => $declared[$model], $models),
        ]));

        foreach ([
            ['larapack:import', ['jsonPath' => $path]],
            ['larapack:route-service-provider', []],
        ] as [$command, $arguments]) {
            $this->assertSame(
                Command::SUCCESS,
                $this->runCommand($command, $arguments),
                "{$command} terminó con error:\n".$this->lastOutput
            );
        }
    }

    // FACTORIES

    public function test_en_una_aplicacion_las_factories_estan_en_database_factories(): void
    {
        $this->import(FakeProject::application(), ['User', 'Product', 'OrderLine']);

        foreach (['User', 'Product', 'OrderLine'] as $model) {
            $this->assertStringContainsString(
                'namespace Database\\Factories;',
                $this->project->read("database/factories/{$model}Factory.php"),
                "{$model}Factory no está en el namespace que carga la aplicación."
            );

            $source = $this->project->read("app/Models/{$model}.php");

            $this->assertStringContainsString("use Database\\Factories\\{$model}Factory;", $source);
            $this->assertStringContainsString("#[UseFactory({$model}Factory::class)]", $source);
        }

        $this->assertNoGeneratedFileMentions('App\\Database');
    }

    public function test_en_un_paquete_las_factories_siguen_en_el_namespace_del_paquete(): void
    {
        $this->import(FakeProject::library('Acme\\Shop\\'), ['Product', 'OrderLine']);

        foreach (['Product', 'OrderLine'] as $model) {
            $this->assertStringContainsString(
                'namespace Acme\\Shop\\Database\\Factories;',
                $this->project->read("database/factories/{$model}Factory.php")
            );

            $this->assertStringContainsString(
                "use Acme\\Shop\\Database\\Factories\\{$model}Factory;",
                $this->project->read("src/Models/{$model}.php")
            );
        }
    }

    // TESTS

    public function test_en_una_aplicacion_los_tests_estan_en_tests(): void
    {
        $this->import(FakeProject::application(), ['User', 'Product', 'OrderLine']);

        foreach (['User', 'Product', 'OrderLine'] as $model) {
            $test = $this->project->read("tests/Feature/Models/{$model}EndpointsTest.php");

            $this->assertStringContainsString('namespace Tests\\Feature\\Models;', $test, "{$model}EndpointsTest no está en el namespace que carga la aplicación.");
            $this->assertStringContainsString('use Tests\\TestCase;', $test);
        }

        // Sin TestCase en la aplicación se genera el de Laravel, no el de un
        // paquete: ese arranca Testbench y usa un usuario que aquí no existe.
        $testCase = $this->project->read('tests/TestCase.php');

        $this->assertStringContainsString('namespace Tests;', $testCase);
        $this->assertStringContainsString('use Illuminate\\Foundation\\Testing\\TestCase as BaseTestCase;', $testCase);
        $this->assertStringNotContainsString('Orchestra', $testCase);
        $this->assertFalse($this->project->has('tests/User.php'), 'Una aplicación ya tiene su usuario.');

        $this->assertNoGeneratedFileMentions('App\\Tests');
    }

    public function test_en_una_aplicacion_no_se_pisa_el_testcase_que_ya_tiene(): void
    {
        $project = FakeProject::application();
        mkdir($project->path.'/tests');
        file_put_contents($project->path.'/tests/TestCase.php', "<?php\n\nnamespace Tests;\n\n// el de la aplicación\n");

        $this->import($project, ['Product']);

        $this->assertStringContainsString('// el de la aplicación', $this->project->read('tests/TestCase.php'));
    }

    public function test_en_un_paquete_los_tests_siguen_en_el_namespace_del_paquete(): void
    {
        $this->import(FakeProject::library('Acme\\Shop\\'), ['Product', 'OrderLine']);

        foreach (['Product', 'OrderLine'] as $model) {
            $test = $this->project->read("tests/Feature/Models/{$model}EndpointsTest.php");

            $this->assertStringContainsString('namespace Acme\\Shop\\Tests\\Feature\\Models;', $test);
            $this->assertStringContainsString('use Acme\\Shop\\Tests\\TestCase;', $test);
        }

        $testCase = $this->project->read('tests/TestCase.php');

        $this->assertStringContainsString('namespace Acme\\Shop\\Tests;', $testCase);
        $this->assertStringContainsString('Orchestra\\Testbench\\TestCase', $testCase);
        $this->assertStringContainsString('namespace Acme\\Shop\\Tests;', $this->project->read('tests/User.php'));
    }

    // RUTAS

    public function test_en_una_aplicacion_los_tests_llaman_a_las_rutas_que_registra_el_proveedor(): void
    {
        $this->import(FakeProject::application(), ['User', 'Product', 'OrderLine']);

        $this->assertTestsCallRegisteredRoutes('app/Providers/RouteServiceProvider.php', ['User', 'Product', 'OrderLine']);
    }

    public function test_en_un_paquete_los_tests_llaman_a_las_rutas_que_registra_el_proveedor(): void
    {
        $this->import(FakeProject::library('Acme\\Shop\\'), ['Product', 'OrderLine']);

        $this->assertTestsCallRegisteredRoutes('src/Providers/RouteServiceProvider.php', ['Product', 'OrderLine']);
    }

    // SESIÓN

    /**
     * Las rutas piden sesión en Sanctum y las políticas nacen cerradas: un test
     * que no inicia sesión recibe 401, y uno que no abre la autorización, 403.
     */
    public function test_en_una_aplicacion_los_tests_inician_sesion_y_abren_la_autorizacion(): void
    {
        $this->import(FakeProject::application(), ['User', 'Product', 'OrderLine']);

        foreach (['User', 'Product', 'OrderLine'] as $model) {
            $test = $this->project->read("tests/Feature/Models/{$model}EndpointsTest.php");

            // El TestCase es el de la aplicación: el test no puede contar con él.
            $this->assertStringContainsString('Gate::before(fn () => true);', $test, "{$model}EndpointsTest no abre la autorización.");
            $this->assertEndpointTestsSignIn($model, $test);
        }
    }

    public function test_en_un_paquete_los_tests_inician_sesion_y_abren_la_autorizacion(): void
    {
        $this->import(FakeProject::library('Acme\\Shop\\'), ['Product', 'OrderLine']);

        $this->assertStringContainsString('Gate::before(fn () => true);', $this->project->read('tests/TestCase.php'));

        foreach (['Product', 'OrderLine'] as $model) {
            $this->assertEndpointTestsSignIn($model, $this->project->read("tests/Feature/Models/{$model}EndpointsTest.php"));
        }
    }

    // EDICIÓN EN LOTE

    /**
     * Una aplicación trae `users.email` único, y el test de edición en lote
     * ponía el mismo valor en dos usuarios: la base lo rechazaba y respondía 500.
     */
    public function test_en_una_aplicacion_la_edicion_en_lote_no_repite_valores_de_columnas_unicas(): void
    {
        $this->import(FakeProject::application(), ['User', 'Product']);

        foreach (['User', 'Product'] as $model) {
            $test = $this->project->read("tests/Feature/Models/{$model}EndpointsTest.php");

            $this->assertSame(
                1,
                preg_match('/public function test_\w+_bulk_update_endpoint\(\): void\s*\{(.*?)\n    \}/s', $test, $method),
                "{$model}EndpointsTest no prueba la edición en lote."
            );

            $this->assertStringContainsString("->where('unique', true)", $method[1], "{$model}EndpointsTest pone el mismo valor en varias filas sin apartar las columnas únicas.");
        }
    }

    // AYUDAS

    /**
     * Cada test que llama a un endpoint inicia sesión, salvo uno: el que
     * comprueba que sin sesión no se entra.
     */
    private function assertEndpointTestsSignIn(string $model, string $test): void
    {
        preg_match_all('/public function (test_\w+)\(\): void\s*\{(.*?)\n    \}/s', $test, $methods, PREG_SET_ORDER);

        $this->assertNotEmpty($methods, "{$model}EndpointsTest no tiene tests.");

        $guests = 0;

        foreach ($methods as [, $name, $body]) {
            if (! preg_match('/\broute\(/', $body)) {
                continue;
            }

            $signsIn = (bool) preg_match('/\$this->signIn\w*\(\)/', $body);

            if (str_ends_with($name, '_requiere_sesion')) {
                $guests++;

                $this->assertFalse($signsIn, "{$model}EndpointsTest::{$name} inicia sesión y dice comprobar que sin ella no se entra.");
                $this->assertStringContainsString('->assertUnauthorized()', $body);

                continue;
            }

            $this->assertTrue($signsIn, "{$model}EndpointsTest::{$name} llama a un endpoint sin iniciar sesión: recibe 401.");
        }

        $this->assertSame(1, $guests, "{$model}EndpointsTest no comprueba que sin sesión no se entra.");
    }

    /**
     * Cada endpoint al que llama el test generado de un modelo, resuelto a su
     * URI con lo que registra el RouteServiceProvider generado: el prefijo y el
     * nombre que antepone a cada archivo de routes/api/models, y la ruta y el
     * nombre que declara ese archivo.
     *
     * @param  array<int, string>  $models
     */
    private function assertTestsCallRegisteredRoutes(string $provider, array $models): void
    {
        $source = $this->project->read($provider);

        $this->assertSame(1, preg_match('/->prefix\(\'([^\']*)\'\s*\.\s*\$name\)/', $source, $prefix), "{$provider} no antepone un prefijo a cada archivo de rutas.");
        $this->assertSame(1, preg_match('/->as\(\'([^\']*)\'\s*\.\s*\$name\s*\.\s*\'\.\'\)/', $source, $as), "{$provider} no antepone un nombre a cada archivo de rutas.");

        foreach ($models as $model) {
            $file = self::ROUTE_FILES[$model];
            $base = $prefix[1].$file;
            $namePrefix = $as[1].$file.'.';

            preg_match_all('/Route::\w+\(\'([^\']+)\'[^;]*?->name\(\'([^\']+)\'\)/s', $this->project->read("routes/api/models/{$file}.php"), $declared);

            $paths = array_combine($declared[2], $declared[1]);
            $test = $this->project->read("tests/Feature/Models/{$model}EndpointsTest.php");
            $uris = [];

            // Por nombre, como el front.
            preg_match_all('/\broute\(\'([^\']+)\'/', $test, $names);

            foreach ($names[1] as $name) {
                $this->assertStringStartsWith($namePrefix, $name, "{$model}EndpointsTest llama a la ruta {$name}, y el proveedor nombra las de {$file}.php {$namePrefix}*.");

                $action = substr($name, strlen($namePrefix));

                $this->assertArrayHasKey($action, $paths, "{$model}EndpointsTest llama a la ruta {$name}, que routes/api/models/{$file}.php no declara.");

                $uris[] = $base.'/'.$paths[$action];
            }

            // Por URI escrita en el test.
            preg_match_all('#\'/?(api/[^\']*)\'#', $test, $literals);
            array_push($uris, ...$literals[1]);

            $this->assertNotEmpty($uris, "{$model}EndpointsTest no llama a ningún endpoint.");

            foreach ($uris as $uri) {
                $this->assertStringStartsWith($base.'/', $uri, "{$model}EndpointsTest llama a {$uri}, y el proveedor registra las rutas de {$file}.php en {$base}/.");
                $this->assertContains(substr($uri, strlen($base) + 1), $paths, "{$model}EndpointsTest llama a {$uri}, que routes/api/models/{$file}.php no declara.");
            }
        }
    }

    private function assertNoGeneratedFileMentions(string $namespace): void
    {
        $offending = array_values(array_filter(
            $this->project->phpFiles(),
            fn (string $file): bool => str_contains($this->project->read($file), $namespace)
        ));

        $this->assertSame([], $offending, "Mencionan {$namespace}, que una aplicación no carga.");
    }
}
