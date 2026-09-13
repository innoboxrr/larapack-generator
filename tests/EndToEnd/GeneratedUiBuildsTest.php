<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Que los módulos Vue y React generados compilan con su propio vite.config.
 *
 * Los tests de interfaz leen los archivos: comprueban que no aparezcan clases
 * de UIkit o componentes fantasma. Ninguno los compilaba, así que un import que
 * sólo resuelve dentro de una aplicación concreta —un alias suyo, un archivo
 * que el generador no crea— pasaba igual. Aquí rollup tiene que resolver cada
 * import que no sea del anfitrión.
 *
 * Lo del anfitrión (Vue, React, sus routers, sus stores y los paquetes
 * innoboxrr-*) queda externo, como en el vite.config generado: no se instala.
 * Sólo se instala la cadena de compilación, una vez, en un directorio temporal
 * que se reutiliza entre ejecuciones.
 *
 * Necesita npm. Sin él, o con LARAPACK_SKIP_UI_BUILD=1, se salta.
 */
final class GeneratedUiBuildsTest extends TestCase
{
    private const NS = 'Acme\\Shop\\';

    /**
     * Lo que necesita vite para compilar cada módulo, y nada más.
     */
    private const TOOLCHAIN = [
        'vite' => '^8.0.0',
        '@vitejs/plugin-vue' => '^6.0.0',
        '@vitejs/plugin-react' => '^6.0.0',
        'vue' => '^3.5.0',
        'react' => '^19.0.0',
        'react-dom' => '^19.0.0',
    ];

    private static ?FakeProject $project = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (getenv('LARAPACK_SKIP_UI_BUILD') || ! self::npmAvailable()) {
            return;
        }

        self::$project = FakeProject::empty();
        $laraimport = self::$project->path.'/laraimport.json';

        try {
            [$code, $output] = Larapack::run('larapack:new', ['name' => 'acme/shop', 'directory' => self::$project->path]);

            if ($code !== 0) {
                throw new RuntimeException("larapack:new terminó con {$code}:\n{$output}");
            }

            copy(__DIR__.'/Fixtures/laraimport.json', $laraimport);
            ProjectRoot::set(self::$project->path);

            [$code, $output] = Larapack::run('larapack:import', ['jsonPath' => $laraimport, '--vue' => true, '--react' => true]);

            if ($code !== 0) {
                throw new RuntimeException("larapack:import terminó con {$code}:\n{$output}");
            }
        } finally {
            ProjectRoot::set(null);
            Generation::reset();
            Declaration::reset();
            MigrationTimestamp::reset();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$project?->cleanup();
        self::$project = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$project === null) {
            $this->markTestSkipped('Sin npm, o con LARAPACK_SKIP_UI_BUILD, no se compilan los módulos generados.');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modules(): array
    {
        return ['vue' => ['vue'], 'react' => ['react']];
    }

    #[DataProvider('modules')]
    public function test_el_modulo_generado_compila(string $ui): void
    {
        $toolchain = self::toolchain();

        // Dentro del directorio de la cadena: Node resuelve vite y los plugins
        // subiendo por los padres, sin enlaces simbólicos, que en Windows piden
        // permisos.
        $module = $toolchain.'/modules/'.$ui.'-'.bin2hex(random_bytes(4));
        self::copyTree(self::$project->path."/resources/{$ui}", $module);

        $process = new Process(
            ['node', $toolchain.'/node_modules/vite/bin/vite.js', 'build', '--logLevel', 'warn'],
            $module,
            ['NO_COLOR' => '1'],
            null,
            300
        );

        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        try {
            $this->assertTrue($process->isSuccessful(), "El módulo {$ui} generado no compila:\n{$output}");
            $this->assertFileExists($module.'/dist/index.js', "vite terminó sin producir dist/index.js:\n{$output}");
        } finally {
            self::removeTree($module);
        }
    }

    private static function npmAvailable(): bool
    {
        $process = Process::fromShellCommandline('npm --version');
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Instala la cadena una sola vez por combinación de versiones.
     */
    private static function toolchain(): string
    {
        $directory = sys_get_temp_dir().'/larapack-ui-toolchain-'.substr(sha1((string) json_encode(self::TOOLCHAIN)), 0, 12);

        if (is_file($directory.'/node_modules/vite/bin/vite.js')) {
            return $directory;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear {$directory}");
        }

        file_put_contents($directory.'/package.json', json_encode([
            'name' => 'larapack-ui-toolchain',
            'private' => true,
            'type' => 'module',
            'devDependencies' => self::TOOLCHAIN,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $install = Process::fromShellCommandline('npm install --no-audit --no-fund --loglevel=error', $directory, null, null, 600);
        $install->run();

        if (! $install->isSuccessful()) {
            throw new RuntimeException("npm install falló en {$directory}:\n".$install->getOutput().$install->getErrorOutput());
        }

        return $directory;
    }

    private static function copyTree(string $from, string $to): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        mkdir($to, 0777, true);

        foreach ($iterator as $item) {
            $target = $to.'/'.substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $from)) + 1);

            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
