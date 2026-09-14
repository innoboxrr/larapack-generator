<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * LaraPack dentro de una aplicación Laravel, como lo usa la aplicación base: los
 * tests que genera pasan sin tocarlos.
 *
 * GeneratedPackageTest lo prueba en un paquete. Dentro de una aplicación nadie
 * los había ejecutado, y el piloto de la aplicación base encontró 23 de 25 en
 * rojo: factories y tests en App\Database\Factories y App\Tests, que la
 * aplicación no carga, y URIs que el RouteServiceProvider no registra.
 *
 * La aplicación es la de Laravel en lo que aquí importa: su composer.json, su
 * migración de usuarios, su UserFactory y la migración con la que la aplicación
 * base prepara `users` para el User generado. Su TestCase usa Testbench en lugar
 * de bootstrap/app.php, con los proveedores que registraría
 * bootstrap/providers.php.
 */
final class GeneratedApplicationTest extends TestCase
{
    /**
     * Lo que declara la aplicación base: su usuario, con metas y sin alta desde
     * el administrador. Y dos modelos de la aplicación, uno con clave foránea
     * al otro y con nombre de dos palabras.
     */
    private const LARAIMPORT = ['models' => [
        [
            'name' => 'User',
            'authenticatable' => true,
            'metas' => true,
            'routes' => ['except' => ['create']],
            'props' => [
                ['name' => 'name', 'type' => 'string', 'datatable' => true],
                ['name' => 'email', 'type' => 'string', 'datatable' => true],
                ['name' => 'email_verified_at', 'type' => 'timestamp', 'nullable' => true],
                ['name' => 'password', 'type' => 'string'],
            ],
        ],
        [
            'name' => 'Product',
            'props' => [
                ['name' => 'title', 'type' => 'string', 'datatable' => true],
                ['name' => 'price', 'type' => 'decimal'],
                ['name' => 'active', 'type' => 'boolean'],
            ],
        ],
        [
            'name' => 'OrderLine',
            'props' => [
                ['name' => 'quantity', 'type' => 'integer'],
                ['name' => 'product_id', 'type' => 'foreignId', 'constraint' => 'products'],
            ],
        ],
    ]];

    private static ?FakeProject $project = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$project = FakeProject::application();
        $root = self::$project->path;

        self::writeLaravelApplication($root);

        file_put_contents($root.'/laraimport.json', (string) json_encode(self::LARAIMPORT, JSON_PRETTY_PRINT));

        // Lo generado sale con el formato de Pint, como en la aplicación.
        putenv('LARAPACK_PINT='.dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint');
        ProjectRoot::set($root);

        try {
            foreach ([
                ['larapack:import', ['jsonPath' => $root.'/laraimport.json']],
                ['larapack:route-service-provider', []],
                ['larapack:event-service-provider', []],
            ] as [$command, $arguments]) {
                [$code, $output] = Larapack::run($command, $arguments);

                if ($code !== 0) {
                    throw new RuntimeException("{$command} terminó con {$code}:\n{$output}");
                }
            }
        } finally {
            putenv('LARAPACK_PINT');
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

    /**
     * Todos: se cuentan en el informe de PHPUnit contra los que hay escritos,
     * para que un test que no se carga no pase por uno que pasa.
     */
    public function test_los_tests_que_genera_pasan_sin_tocarlos(): void
    {
        $root = self::$project->path;
        $junit = $root.'/junit.xml';

        $process = new Process([
            PHP_BINARY,
            ...$this->inheritedExtensions(),
            dirname(__DIR__, 2).'/vendor/phpunit/phpunit/phpunit',
            '--bootstrap', $this->autoloadBootstrap(),
            '--configuration', $root.'/phpunit.xml',
            '--log-junit', $junit,
            '--do-not-cache-result',
            '--colors=never',
        ], $root, null, null, 300);

        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertTrue($process->isSuccessful(), "Los tests generados fallan:\n".$output);

        $written = 0;

        foreach (glob($root.'/tests/Feature/Models/*EndpointsTest.php') ?: [] as $file) {
            $written += preg_match_all('/public function test_\w+\(\)/', (string) file_get_contents($file));
        }

        $this->assertGreaterThan(0, $written, 'No se generó ningún test.');

        $report = simplexml_load_file($junit);

        $this->assertNotFalse($report, "PHPUnit no dejó el informe:\n".$output);
        $this->assertSame($written, (int) $report->testsuite['tests'], "No se ejecutaron todos los tests generados:\n".$output);
        $this->assertSame(0, (int) $report->testsuite['skipped'], "Hay tests generados que se saltan:\n".$output);
    }

    // AYUDAS

    /**
     * Lo que trae `laravel/laravel` y usa lo generado, y lo que la aplicación
     * base añade antes de generar su usuario.
     */
    private static function writeLaravelApplication(string $root): void
    {
        file_put_contents($root.'/composer.json', (string) json_encode([
            'name' => 'laravel/laravel',
            'type' => 'project',
            'autoload' => ['psr-4' => [
                'App\\' => 'app/',
                'Database\\Factories\\' => 'database/factories/',
                'Database\\Seeders\\' => 'database/seeders/',
            ]],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach (['app/Http/Controllers', 'database/migrations', 'database/factories', 'tests'] as $directory) {
            mkdir($root.'/'.$directory, 0777, true);
        }

        file_put_contents($root.'/app/Http/Controllers/Controller.php', <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            abstract class Controller
            {
                //
            }

            PHP);

        copy(
            dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000000_testbench_create_users_table.php',
            $root.'/database/migrations/0001_01_01_000000_create_users_table.php'
        );

        file_put_contents($root.'/database/migrations/0001_01_01_000010_add_payload_and_soft_deletes_to_users_table.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->longText('payload')->nullable()->after('password');
                        $table->softDeletes();
                    });
                }
            };

            PHP);

        file_put_contents($root.'/database/factories/UserFactory.php', <<<'PHP'
            <?php

            namespace Database\Factories;

            use Illuminate\Database\Eloquent\Factories\Factory;
            use Illuminate\Support\Facades\Hash;
            use Illuminate\Support\Str;

            /**
             * @extends Factory<\App\Models\User>
             */
            class UserFactory extends Factory
            {
                protected static ?string $password;

                public function definition(): array
                {
                    return [
                        'name' => fake()->name(),
                        'email' => fake()->unique()->safeEmail(),
                        'email_verified_at' => now(),
                        'password' => static::$password ??= Hash::make('password'),
                        'remember_token' => Str::random(10),
                    ];
                }
            }

            PHP);

        file_put_contents($root.'/tests/TestCase.php', <<<'PHP'
            <?php

            namespace Tests;

            use Illuminate\Http\Resources\Json\JsonResource;
            use Orchestra\Testbench\TestCase as BaseTestCase;

            abstract class TestCase extends BaseTestCase
            {
                /**
                 * Sanctum y Excel, que la aplicación base instala, y lo que
                 * registra en bootstrap/providers.php.
                 */
                protected function getPackageProviders($app): array
                {
                    return [
                        \Laravel\Sanctum\SanctumServiceProvider::class,
                        \Maatwebsite\Excel\ExcelServiceProvider::class,
                        \App\Providers\RouteServiceProvider::class,
                        \App\Providers\EventServiceProvider::class,
                    ];
                }

                /**
                 * database/ es el de la aplicación, el usuario es App\Models\User y
                 * las respuestas van sin el envoltorio `data`.
                 */
                protected function defineEnvironment($app): void
                {
                    $app->useDatabasePath(dirname(__DIR__).'/database');

                    $app['config']->set('auth.providers.users.model', \App\Models\User::class);

                    JsonResource::withoutWrapping();
                }
            }

            PHP);
    }

    /**
     * Lo que haría `composer dump-autoload` en la aplicación, para un proceso
     * aparte: el autoloader de LaraPack más los namespaces de su composer.json.
     */
    private function autoloadBootstrap(): string
    {
        $root = self::$project->path;
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
        $bootstrap = $root.'/vendor-autoload.php';
        $lines = ['<?php', '$loader = require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'];

        foreach (($composer['autoload']['psr-4'] ?? []) + ($composer['autoload-dev']['psr-4'] ?? []) as $prefix => $directory) {
            $lines[] = '$loader->addPsr4('.var_export($prefix, true).', '.var_export($root.'/'.$directory, true).');';
        }

        file_put_contents($bootstrap, implode(PHP_EOL, $lines).PHP_EOL);

        return $bootstrap;
    }

    /**
     * El PHP de este proceso puede tener extensiones cargadas por -d que el
     * ini no trae (pdo_sqlite en Windows); el proceso hijo las necesita igual.
     *
     * @return array<int, string>
     */
    private function inheritedExtensions(): array
    {
        $ini = (string) shell_exec(escapeshellarg(PHP_BINARY).' -m');
        $arguments = [];

        foreach (['pdo_sqlite', 'sqlite3'] as $extension) {
            if (extension_loaded($extension) && ! preg_match('/^'.$extension.'$/mi', $ini)) {
                $arguments[] = '-d';
                $arguments[] = "extension={$extension}";
            }
        }

        return $arguments;
    }
}
