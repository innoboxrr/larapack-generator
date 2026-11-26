<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use PHPUnit\Framework\TestCase;

/**
 * Dónde buscan ControllerShape y LegacySecurity: la raíz, los repositorios
 * `path`, `packages/*`, sus PSR-4, y nada de vendor, node_modules ni ocultos.
 */
final class ProjectSourcesTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = FakeProject::empty();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    /**
     * @param  array<string, mixed>|string  $content
     */
    private function write(string $relative, array|string $content = "<?php\n"): void
    {
        $file = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, is_array($content) ? json_encode($content) : $content);
    }

    public function test_descubre_la_raiz_los_repositorios_path_y_packages(): void
    {
        $this->write('composer.json', [
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'repositories' => [
                ['type' => 'path', 'url' => 'modules/*'],
                ['type' => 'vcs', 'url' => 'https://example.test/repo.git'],
            ],
        ]);
        $this->write('app/Http/Controllers/HomeController.php');
        $this->write('modules/billing/composer.json', ['autoload' => ['psr-4' => ['Acme\\Billing\\' => 'src/']]]);
        $this->write('modules/billing/src/Http/Controllers/InvoiceController.php');
        // Sin composer.json: app y src, sin prefijo.
        $this->write('packages/library/src/Http/Controllers/BookController.php');

        $sources = new ProjectSources($this->project->path);
        $root = $this->project->path;

        $this->assertSame([$root, "{$root}/modules/billing", "{$root}/packages/library"], $sources->roots());
        $this->assertSame(["{$root}/app", "{$root}/modules/billing/src", "{$root}/packages/library/src"], $sources->sources());
        $this->assertSame(['App\\' => ["{$root}/app"], 'Acme\\Billing\\' => ["{$root}/modules/billing/src"]], $sources->autoload());

        $this->assertSame([
            "{$root}/app/Http/Controllers/HomeController.php",
            "{$root}/modules/billing/src/Http/Controllers/InvoiceController.php",
            "{$root}/packages/library/src/Http/Controllers/BookController.php",
        ], $sources->files('/Http/Controllers/'));
    }

    public function test_un_paquete_declarado_y_en_packages_se_cuenta_una_vez(): void
    {
        $this->write('composer.json', ['repositories' => [['type' => 'path', 'url' => 'packages/*']]]);
        $this->write('packages/library/src/Http/Requests/IndexRequest.php');

        $sources = new ProjectSources($this->project->path);

        $this->assertCount(2, $sources->roots());
        $this->assertSame(['packages/library/src/Http/Requests/IndexRequest.php'], array_map([$sources, 'relative'], $sources->files('/Http/Requests/')));
    }

    public function test_salta_vendor_node_modules_y_ocultos(): void
    {
        $this->write('app/Http/Requests/IndexRequest.php');
        $this->write('app/vendor/x/Http/Requests/IndexRequest.php');
        $this->write('app/node_modules/x/Http/Requests/IndexRequest.php');
        $this->write('app/.cache/Http/Requests/IndexRequest.php');
        $this->write('app/Http/Requests/notes.txt', 'no es PHP');

        $sources = new ProjectSources($this->project->path);

        $this->assertSame(['app/Http/Requests/IndexRequest.php'], array_map([$sources, 'relative'], $sources->files('/Http/Requests/')));
    }

    public function test_el_segmento_se_busca_dentro_de_la_fuente(): void
    {
        $this->write('composer.json', ['autoload' => ['psr-4' => ['App\\' => 'Http/Requests/app/']]]);
        $this->write('Http/Requests/app/Models/Post.php');

        $this->assertSame([], (new ProjectSources($this->project->path))->files('/Http/Requests/'));
    }

    public function test_localiza_una_clase_por_psr4_sin_distinguir_mayusculas(): void
    {
        $this->write('composer.json', ['autoload' => ['psr-4' => ['App\\' => 'app/', 'App\\Models\\' => 'models/']]]);
        $this->write('app/Http/Requests/Post/IndexRequest.php');
        $this->write('models/Post.php');

        $sources = new ProjectSources($this->project->path);
        $root = $this->project->path;

        $this->assertSame("{$root}/app/Http/Requests/Post/IndexRequest.php", $sources->locate('App\\Http\\Requests\\Post\\IndexRequest'));
        $this->assertSame("{$root}/app/Http/Requests/Post/IndexRequest.php", $sources->locate('app\\http\\requests\\post\\indexrequest'));
        // El prefijo más largo gana.
        $this->assertSame("{$root}/models/Post.php", $sources->locate('App\\Models\\Post'));
        $this->assertNull($sources->locate('App\\Http\\Requests\\Missing'));
        $this->assertNull($sources->locate('Vendor\\Thing'));

        $this->assertTrue($sources->owns('App\\Http\\Requests\\Missing'));
        $this->assertFalse($sources->owns('Vendor\\Thing'));
    }

    public function test_la_raiz_de_un_archivo_es_la_del_paquete_que_lo_contiene(): void
    {
        $this->write('app/Http/Requests/IndexRequest.php');
        $this->write('packages/library/src/Http/Requests/IndexRequest.php');

        $sources = new ProjectSources($this->project->path);
        $root = $this->project->path;

        $this->assertSame($root, $sources->rootOf("{$root}/app/Http/Requests/IndexRequest.php"));
        $this->assertSame("{$root}/packages/library", $sources->rootOf("{$root}/packages/library/src/Http/Requests/IndexRequest.php"));
        $this->assertSame('/fuera/de/todo.php', $sources->relative('/fuera/de/todo.php'));
    }
}
