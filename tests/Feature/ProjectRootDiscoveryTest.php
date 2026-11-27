<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * El falso verde de `verify` sin --root.
 *
 * La raíz se descubría subiendo desde donde estuviera instalado el generador
 * hasta dar con un `vendor/autoload.php`. Con el `builder` de un clon —o con
 * un `vendor/bin/builder` que apunta a uno por un repositorio `path` con
 * enlace simbólico— eso es el propio generador, que tiene su vendor. En
 * school-management, `builder larapack:verify` corrido desde el proyecto
 * medía el generador y devolvía `ok: true` con un aviso `empty-manifest`.
 *
 * Ahora la raíz sale del directorio de trabajo: el proyecto que tiene
 * `laraimport.json`, `.larapack/manifest.json` o un composer.json que requiere el
 * generador. Y si lo que queda es el propio generador sin manifiesto, el
 * comando falla.
 */
final class ProjectRootDiscoveryTest extends TestCase
{
    private const BUILDER = __DIR__.'/../../builder';

    private string $cwd = '';

    /**
     * @var list<string>
     */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);

        foreach ($this->temporary as $directory) {
            exec('rm -rf '.escapeshellarg($directory));
        }

        parent::tearDown();
    }

    private function generatorRoot(): string
    {
        return (string) realpath(dirname(__DIR__, 2));
    }

    private function emptyDirectory(): string
    {
        $path = sys_get_temp_dir().'/larapack-nada-'.bin2hex(random_bytes(6));
        mkdir($path);

        return $this->temporary[] = (string) realpath($path);
    }

    /**
     * El `builder` como lo corre una persona o un agente: otro proceso, con
     * el directorio de trabajo que toque.
     *
     * @param  list<string>  $arguments
     * @return array{0: int, 1: string}
     */
    private function builder(string $cwd, array $arguments, ?string $builder = null): array
    {
        $process = proc_open(
            [PHP_BINARY, $builder ?? self::BUILDER, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );

        $this->assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * Un proyecto con algo generado y un archivo que falta: si `verify` lo
     * mide, no puede salir en verde.
     */
    private function projectWithDrift(): FakeProject
    {
        $project = $this->useProject(FakeProject::library());

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product']), $this->lastOutput);
        unlink($project->path.'/src/Policies/ProductPolicy.php');

        ProjectRoot::set(null);

        return $project;
    }

    public function test_verify_sin_root_mide_el_proyecto_del_directorio_de_trabajo(): void
    {
        $project = $this->projectWithDrift();

        [$code, $output] = $this->builder($project->path, ['larapack:verify', '--format=json']);
        $report = json_decode($output, true);

        $this->assertIsArray($report, $output);
        $this->assertSame(Command::FAILURE, $code, $output);
        $this->assertFalse($report['ok']);
        $this->assertContains('missing-file', array_column($report['findings'], 'check'));
        $this->assertNotContains('empty-manifest', array_column($report['findings'], 'check'));
    }

    public function test_tambien_desde_un_subdirectorio_del_proyecto(): void
    {
        $project = $this->projectWithDrift();

        [$code, $output] = $this->builder($project->path.'/src/Models', ['larapack:verify', '--format=json']);

        $this->assertSame(Command::FAILURE, $code, $output);
        $this->assertContains('missing-file', array_column((array) (json_decode($output, true)['findings'] ?? []), 'check'));
    }

    /**
     * El caso de school-management: `php vendor/bin/builder` en un proyecto
     * cuyo vendor/bin/builder lleva al builder de un clon del generador (un
     * repositorio `path` con enlace simbólico). El generador encuentra su
     * propio vendor/autoload.php.
     */
    public function test_un_vendor_bin_builder_que_apunta_a_un_clon_mide_el_proyecto(): void
    {
        $project = $this->projectWithDrift();

        mkdir($project->path.'/vendor/bin', 0777, true);
        file_put_contents(
            $project->path.'/vendor/bin/builder',
            '<?php require '.var_export((string) realpath(self::BUILDER), true).";\n"
        );

        [$code, $output] = $this->builder($project->path, ['larapack:verify', '--format=json'], 'vendor/bin/builder');

        $this->assertSame(Command::FAILURE, $code, $output);
        $this->assertContains('missing-file', array_column((array) (json_decode($output, true)['findings'] ?? []), 'check'));
    }

    public function test_fuera_de_un_proyecto_verify_falla_en_vez_de_medir_el_generador(): void
    {
        [$code, $output] = $this->builder($this->emptyDirectory(), ['larapack:verify', '--format=json']);
        $report = json_decode($output, true);

        $this->assertIsArray($report, $output);
        $this->assertNotSame(Command::SUCCESS, $code, $output);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('--root', $report['error']);
        $this->assertStringContainsString('LaraPack', $report['error']);
    }

    public function test_en_texto_tambien_falla(): void
    {
        [$code, $output] = $this->builder($this->emptyDirectory(), ['larapack:verify']);

        $this->assertNotSame(Command::SUCCESS, $code, $output);
        $this->assertStringNotContainsString('Sin desviaciones', $output);
        $this->assertStringContainsString('--root', $output);
    }

    public function test_root_apuntando_al_propio_generador_falla(): void
    {
        [$code, $output] = $this->builder($this->emptyDirectory(), ['larapack:verify', '--format=json', '--root='.$this->generatorRoot()]);
        $report = json_decode($output, true);

        $this->assertIsArray($report, $output);
        $this->assertNotSame(Command::SUCCESS, $code);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('LaraPack', $report['error']);
    }

    /**
     * La misma resolución vale para todo comando con --root: un generador
     * fuera de un proyecto no escribe en el generador.
     */
    public function test_un_generador_fuera_de_un_proyecto_tampoco_sigue(): void
    {
        [$code, $output] = $this->builder($this->emptyDirectory(), ['larapack:model', 'Post', '--dry-run', '--format=json']);
        $report = json_decode($output, true);

        $this->assertIsArray($report, $output);
        $this->assertNotSame(Command::SUCCESS, $code);
        $this->assertFalse($report['ok']);
        $this->assertFileDoesNotExist($this->generatorRoot().'/src/Models/Post.php');
    }

    public function test_validate_lee_el_laraimport_del_directorio_de_trabajo(): void
    {
        $project = $this->useProject(FakeProject::library());
        ProjectRoot::set(null);
        file_put_contents($project->path.'/laraimport.json', '{"models": [{"name": "Tag", "props": [{"name": "name", "type": "string"}]}]}');

        [$code, $output] = $this->builder($project->path, ['larapack:validate', '--format=json']);
        $report = json_decode($output, true);

        $this->assertSame(Command::SUCCESS, $code, $output);
        $this->assertSame($project->path.'/laraimport.json', $report['file']);
    }

    /**
     * Las tres marcas de un proyecto, cada una por separado.
     */
    public function test_cada_marca_de_proyecto_basta_para_encontrar_la_raiz(): void
    {
        $markers = [
            'laraimport.json' => fn (string $path) => file_put_contents($path.'/laraimport.json', '{"models": []}'),
            '.larapack/manifest.json' => fn (string $path) => mkdir($path.'/.larapack') && file_put_contents($path.'/.larapack/manifest.json', '{"version": 1, "models": {}}'),
            'require' => fn (string $path) => file_put_contents($path.'/composer.json', '{"require": {"innoboxrr/larapack-generator": "^9.3"}}'),
            'require-dev' => fn (string $path) => file_put_contents($path.'/composer.json', '{"require-dev": {"innoboxrr/larapack-generator": "^9.3"}}'),
        ];

        foreach ($markers as $name => $mark) {
            $root = $this->emptyDirectory();
            $mark($root);
            mkdir($root.'/a/b', 0777, true);

            chdir($root.'/a/b');

            $this->assertSame($root, ProjectRoot::path(), "La marca {$name} no se reconoció.");
        }
    }

    /**
     * Un composer.json sin el generador no es una marca: es cualquier
     * proyecto PHP, y elegirlo sería medir algo que no usa LaraPack.
     */
    public function test_un_composer_json_ajeno_no_es_una_marca(): void
    {
        $root = $this->emptyDirectory();
        file_put_contents($root.'/composer.json', '{"require": {"laravel/framework": "^13.0"}}');

        $this->assertNull(ProjectRoot::fromWorkingDirectory($root));
    }

    /**
     * Un `.larapack/` sin manifiesto —sólo el candado de #[HandRolled]— no
     * hace de un directorio el proyecto: uno así en `/` volvía proyecto a
     * todo lo que cuelga de él.
     */
    public function test_un_larapack_sin_manifiesto_no_es_una_marca(): void
    {
        $root = $this->emptyDirectory();
        mkdir($root.'/.larapack');
        file_put_contents($root.'/.larapack/hand-rolled.lock', '[]');

        $this->assertFalse(ProjectRoot::isProject($root));
    }

    /**
     * El más cercano gana: desde un paquete con su laraimport, la raíz es el
     * paquete, no la aplicación que lo contiene.
     */
    public function test_gana_el_proyecto_mas_cercano(): void
    {
        $root = $this->emptyDirectory();
        file_put_contents($root.'/laraimport.json', '{"models": []}');
        mkdir($root.'/packages/billing/src', 0777, true);
        file_put_contents($root.'/packages/billing/laraimport.json', '{"models": []}');

        $this->assertSame($root.'/packages/billing', ProjectRoot::fromWorkingDirectory($root.'/packages/billing/src'));
        $this->assertSame($root, ProjectRoot::fromWorkingDirectory($root.'/packages'));
    }

    public function test_ensure_project_rechaza_el_generador_sin_manifiesto(): void
    {
        ProjectRoot::set($this->generatorRoot());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('es el propio LaraPack');

        ProjectRoot::ensureProject();
    }
}
