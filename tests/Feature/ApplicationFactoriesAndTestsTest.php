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
