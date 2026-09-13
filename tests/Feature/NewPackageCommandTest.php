<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Ecosystem;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/**
 * larapack:new tiene que dejar un paquete que pase la auditoría, que la CI
 * pueda probar y publicar, y en el que un agente encuentre la guía. Que se
 * instale y funcione de verdad lo comprueba la suite EndToEnd, que parte de él.
 */
final class NewPackageCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->project = FakeProject::empty();
    }

    public function test_crea_un_paquete_listo_para_generar_probar_y_publicar(): void
    {
        $this->create();

        foreach ([
            'composer.json', '.gitignore', '.gitattributes', 'README.md', 'CHANGELOG.md', 'VERSION', 'LICENSE', 'AGENTS.md',
            '.github/workflows/tests.yml', '.github/workflows/release.yml', 'phpunit.xml.dist', 'pint.json', 'phpstan.neon.dist',
            'tests/TestCase.php', 'tests/User.php', 'tests/Feature/PackageBootsTest.php',
            'src/Providers/AppServiceProvider.php', 'src/Providers/AuthServiceProvider.php',
            'src/Providers/EventServiceProvider.php', 'src/Providers/RouteServiceProvider.php',
            'config/acmeshopcatalog.php', '.claude/skills/larapack/SKILL.md',
        ] as $file) {
            $this->assertGenerated($file);
        }

        // Con phpunit.xml.dist no hace falta otro: lo taparía.
        $this->assertFalse($this->project->has('phpunit.xml'));

        $composer = $this->composer();

        $this->assertSame('acme/shop-catalog', $composer['name']);
        $this->assertSame('Catálogo de la tienda', $composer['description']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Acme\\ShopCatalog\\']);
        $this->assertSame('database/factories/', $composer['autoload']['psr-4']['Acme\\ShopCatalog\\Database\\Factories\\']);
        $this->assertSame('tests/', $composer['autoload-dev']['psr-4']['Acme\\ShopCatalog\\Tests\\']);
        $this->assertSame([
            'Acme\\ShopCatalog\\Providers\\AppServiceProvider',
            'Acme\\ShopCatalog\\Providers\\AuthServiceProvider',
            'Acme\\ShopCatalog\\Providers\\EventServiceProvider',
            'Acme\\ShopCatalog\\Providers\\RouteServiceProvider',
        ], $composer['extra']['laravel']['providers']);
        $this->assertArrayNotHasKey('version', $composer, 'La versión la declara VERSION; en composer.json esconde los tags.');

        // La CI del paquete comprueba el formato y los tipos con ellos.
        $this->assertArrayHasKey('laravel/pint', $composer['require-dev']);
        $this->assertArrayHasKey('larastan/larastan', $composer['require-dev']);
        $this->assertStringContainsString('vendor/bin/pint --test', $this->project->read('.github/workflows/tests.yml'));
        $this->assertStringContainsString('vendor/bin/phpstan analyse', $this->project->read('.github/workflows/tests.yml'));

        $this->assertStringContainsString('composer require acme/shop-catalog', $this->project->read('README.md'));
        $this->assertStringContainsString('Copyright (c) ' . date('Y') . ' Acme', $this->project->read('LICENSE'));
    }

    /**
     * La prueba de que salió de la línea base y no de un paquete copiado.
     */
    public function test_el_paquete_recien_creado_pasa_la_auditoria_sin_hallazgos(): void
    {
        $this->create();

        $this->assertSame([], (new Ecosystem())->audit($this->project->path));
    }

    public function test_todo_el_php_creado_compila(): void
    {
        $this->create();

        $this->assertGeneratedPhpCompiles();
    }

    public function test_no_toca_un_directorio_que_ya_es_un_proyecto(): void
    {
        file_put_contents($this->project->path . '/composer.json', '{"name":"otro/proyecto"}');

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:new', ['name' => 'acme/shop', 'directory' => $this->project->path]));

        $this->assertSame('{"name":"otro/proyecto"}', $this->project->read('composer.json'));
        $this->assertFalse($this->project->has('README.md'));
    }

    public function test_la_simulacion_dice_lo_que_crearia_y_no_escribe_nada(): void
    {
        $target = $this->project->path . '/shop';

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:new', [
            'name' => 'acme/shop',
            'directory' => $target,
            '--dry-run' => true,
            '--format' => 'json',
        ]), $this->lastOutput);

        $report = json_decode($this->lastOutput, true);
        $files = array_column($report['files'], 'file');

        $this->assertTrue($report['dryRun']);

        foreach (['composer.json', 'VERSION', 'src/Providers/AppServiceProvider.php', 'tests/Feature/PackageBootsTest.php', 'tests/TestCase.php'] as $file) {
            $this->assertContains($file, $files);
        }

        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_rechaza_un_nombre_que_composer_no_acepta(): void
    {
        foreach (['Acme/Shop', 'acme', 'acme/shop catalog'] as $name) {
            $this->assertSame(Command::FAILURE, $this->runCommand('larapack:new', ['name' => $name, 'directory' => $this->project->path]), $name);
        }

        $this->assertFalse($this->project->has('composer.json'));
    }

    public function test_acepta_un_namespace_explicito(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:new', [
            'name' => 'innoboxrr/catalog',
            'directory' => $this->project->path,
            '--namespace' => 'Innoboxrr\\Store\\Catalog',
        ]), $this->lastOutput);

        $this->assertSame('src/', $this->composer()['autoload']['psr-4']['Innoboxrr\\Store\\Catalog\\']);
        $this->assertStringContainsString('namespace Innoboxrr\\Store\\Catalog\\Providers;', $this->project->read('src/Providers/AppServiceProvider.php'));
    }

    public function test_volver_a_generar_los_proveedores_no_los_duplica(): void
    {
        $this->create();

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:providers', ['--root' => $this->project->path]));

        $this->assertCount(4, $this->composer()['extra']['laravel']['providers']);
    }

    public function test_composer_acepta_el_composer_json(): void
    {
        $this->create();

        $validate = Process::fromShellCommandline('composer validate --no-check-publish --no-check-lock', $this->project->path, null, null, 120);
        $validate->run();

        if (str_contains($validate->getErrorOutput() . $validate->getOutput(), 'not recognized') || $validate->getExitCode() === 127) {
            $this->markTestSkipped('Composer no está disponible.');
        }

        $this->assertTrue($validate->isSuccessful(), $validate->getOutput() . $validate->getErrorOutput());
    }

    private function create(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:new', [
            'name' => 'acme/shop-catalog',
            'directory' => $this->project->path,
            '--description' => 'Catálogo de la tienda',
        ]), $this->lastOutput);
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        return json_decode($this->project->read('composer.json'), true);
    }
}
