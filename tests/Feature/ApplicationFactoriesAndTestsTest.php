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

    // AYUDAS

    private function assertNoGeneratedFileMentions(string $namespace): void
    {
        $offending = array_values(array_filter(
            $this->project->phpFiles(),
            fn (string $file): bool => str_contains($this->project->read($file), $namespace)
        ));

        $this->assertSame([], $offending, "Mencionan {$namespace}, que una aplicación no carga.");
    }
}
