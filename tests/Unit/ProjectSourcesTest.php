<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use PHPUnit\Framework\TestCase;

/**
 * Dónde buscan ControllerShape y LegacySecurity: la raíz, los repositorios
 * `path`, `packages/*`, sus PSR-4 (y classmap), y nada del vendor ni del
 * node_modules de cada raíz, ni de los directorios ocultos.
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

    public function test_salta_el_vendor_y_el_node_modules_de_cada_raiz_y_los_ocultos(): void
    {
        // La raíz es además una fuente: su vendor/ y su node_modules/ son lo
        // que instalan composer y npm.
        $this->write('composer.json', ['autoload' => ['psr-4' => ['Root\\' => '', 'App\\' => 'app/']]]);
        $this->write('app/Http/Requests/IndexRequest.php');
        $this->write('vendor/x/src/Http/Requests/IndexRequest.php');
        $this->write('node_modules/x/Http/Requests/IndexRequest.php');
        $this->write('packages/library/src/Http/Requests/IndexRequest.php');
        $this->write('packages/library/vendor/y/Http/Requests/IndexRequest.php');
        $this->write('app/.cache/Http/Requests/IndexRequest.php');
        $this->write('app/Http/Requests/notes.txt', 'no es PHP');

        $sources = new ProjectSources($this->project->path);

        $this->assertSame(
            ['app/Http/Requests/IndexRequest.php', 'packages/library/src/Http/Requests/IndexRequest.php'],
            array_map([$sources, 'relative'], $sources->files('/Http/Requests/'))
        );
    }

    /**
     * Auditoría de seguridad de 9.4.0: se saltaba cualquier directorio
     * llamado `vendor` o `node_modules`, y PSR-4 carga
     * `src/Http/Controllers/vendor/Evil.php` como cualquier otro: guardar ahí
     * un controlador lo sacaba de verify.
     */
    public function test_un_vendor_que_no_es_el_de_una_raiz_es_codigo_del_proyecto(): void
    {
        $this->write('composer.json', ['autoload' => ['psr-4' => ['App\\' => 'app/']]]);
        $this->write('app/Http/Controllers/vendor/EvilController.php');
        $this->write('app/vendor/Http/Controllers/EvilController.php');
        $this->write('app/Http/Controllers/node_modules/EvilController.php');

        $sources = new ProjectSources($this->project->path);

        $this->assertSame([
            'app/Http/Controllers/node_modules/EvilController.php',
            'app/Http/Controllers/vendor/EvilController.php',
            'app/vendor/Http/Controllers/EvilController.php',
        ], array_map([$sources, 'relative'], $sources->files('/Http/Controllers/')));
    }

    /**
     * Un directorio enlazado se recorre como lo recorre el autoloader, y un
     * enlace circular no cuelga nada.
     */
    public function test_sigue_los_directorios_enlazados_sin_repetir_ni_colgarse(): void
    {
        $this->write('composer.json', ['autoload' => ['psr-4' => ['App\\' => 'app/']]]);
        $this->write('app/Http/Controllers/HomeController.php');
        $this->write('lib/Admin/EvilController.php');
        symlink($this->project->path.'/lib/Admin', $this->project->path.'/app/Http/Controllers/Admin');
        symlink($this->project->path.'/app', $this->project->path.'/app/Http/Controllers/loop');

        $sources = new ProjectSources($this->project->path);

        $this->assertSame([
            'app/Http/Controllers/Admin/EvilController.php',
            'app/Http/Controllers/HomeController.php',
        ], array_map([$sources, 'relative'], $sources->files('/Http/Controllers/')));
    }

    /**
     * Lo que composer carga por classmap o PSR-0 se enruta igual que lo de
     * PSR-4.
     */
    public function test_los_directorios_de_classmap_y_psr0_tambien_son_fuentes(): void
    {
        $this->write('composer.json', ['autoload' => [
            'psr-4' => ['App\\' => 'app/'],
            'classmap' => ['lib/', 'no-existe/', 'suelto.php'],
            'psr-0' => ['Legacy_' => 'legacy/'],
        ]]);
        $this->write('suelto.php');
        $this->write('app/Http/Controllers/HomeController.php');
        $this->write('lib/Http/Controllers/EvilController.php');
        $this->write('legacy/Http/Controllers/OldController.php');

        $sources = new ProjectSources($this->project->path);

        $this->assertSame([
            'app/Http/Controllers/HomeController.php',
            'legacy/Http/Controllers/OldController.php',
            'lib/Http/Controllers/EvilController.php',
        ], array_map([$sources, 'relative'], $sources->files('/Http/Controllers/')));
        $this->assertSame(['App\\' => [$this->project->path.'/app']], $sources->autoload());
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
