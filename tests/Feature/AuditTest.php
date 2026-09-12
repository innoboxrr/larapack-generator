<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * La auditoría solo sirve si falla cuando debe. Estos tests montan paquetes
 * de mentira con defectos concretos —los mismos que hay hoy en el árbol— y
 * comprueban que cada uno se detecta, además de que un paquete conforme pasa
 * limpio.
 */
final class AuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/larapack-audit-' . bin2hex(random_bytes(6));

        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_un_paquete_conforme_no_produce_hallazgos(): void
    {
        $package = $this->package('conforme', [
            'name' => 'innoboxrr/conforme',
            'description' => 'Un paquete de ejemplo',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['Innoboxrr\\Conforme\\' => 'src/']],
            'require' => [
                'php' => '^8.3',
                'illuminate/support' => '^13.0',
            ],
            'require-dev' => [
                'orchestra/testbench' => '^11.0',
            ],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $result = $this->audit($package);

        $this->assertSame([], $result['findings'], 'Un paquete que cumple la línea base no debería producir hallazgos.');
        $this->assertTrue($result['ok']);
    }

    public function test_detecta_la_restriccion_de_php_ausente(): void
    {
        // Es el defecto más extendido: 23 de 29 paquetes del árbol no
        // declaran `php`, así que Composer los daría por buenos en cualquier
        // intérprete.
        $package = $this->package('sin-php', [
            'name' => 'innoboxrr/sin-php',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['illuminate/support' => '^13.0'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $this->assertHasCheck('php-missing', $this->audit($package));
    }

    public function test_detecta_una_version_de_laravel_fuera_de_la_linea_base(): void
    {
        $package = $this->package('laravel-viejo', [
            'name' => 'innoboxrr/laravel-viejo',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3', 'illuminate/support' => '^11.0'],
            'require-dev' => ['orchestra/testbench' => '^9.0'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $findings = $this->audit($package);

        $this->assertHasCheck('illuminate-version', $findings);
        $this->assertHasCheck('testbench-version', $findings);
    }

    /**
     * Una restriccion mas ancha que la linea base no es un defecto.
     *
     * `illuminate/support: ^12.0 || ^13.0` dice contra que puede funcionar la
     * libreria, no contra que se construye. Estrecharla a `^13.0` no arregla
     * nada y le quita a un consumidor de Laravel 12 la posibilidad de
     * instalarla. Es el mismo criterio que ya deja fuera a las
     * peerDependencies del lado npm.
     */
    public function test_una_restriccion_mas_ancha_que_la_linea_base_es_un_aviso_y_no_un_error(): void
    {
        $package = $this->package('mas-ancho', [
            'name' => 'innoboxrr/mas-ancho',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3', 'illuminate/support' => '^12.0 || ^13.0'],
            'require-dev' => ['orchestra/testbench' => '^10.0 || ^11.0'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $result = $this->audit($package);

        $this->assertSame(0, $result['errors'], 'Una restricción más ancha no debería ser un error.');
        $this->assertGreaterThan(0, $result['warnings'], 'Pero sí un aviso: lo que admite de más no se prueba.');
    }

    public function test_una_restriccion_que_no_admite_la_linea_base_sigue_siendo_un_error(): void
    {
        $package = $this->package('no-admite', [
            'name' => 'innoboxrr/no-admite',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            // ^12.0 se queda corto: con la linea base en ^13.0, este paquete
            // no se puede instalar junto al resto del ecosistema.
            'require' => ['php' => '^8.3', 'illuminate/support' => '^12.0'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $this->assertHasCheck('illuminate-version', $this->audit($package));
    }

    /**
     * La restriccion exacta es el caso silencioso: ni error ni aviso.
     */
    public function test_la_restriccion_exacta_no_produce_ningun_hallazgo(): void
    {
        $package = $this->package('exacto', [
            'name' => 'innoboxrr/exacto',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3', 'illuminate/support' => '^13.0'],
            'require-dev' => ['orchestra/testbench' => '^11.0'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $result = $this->audit($package);

        $this->assertSame(0, $result['errors']);
        $this->assertNotHasCheck('illuminate-version', $result);
        $this->assertNotHasCheck('testbench-version', $result);
    }

    public function test_detecta_una_dependencia_interna_desfasada(): void
    {
        // El caso real: 19 paquetes piden `larapack-generator: ^5.0` cuando
        // lo publicado es 6.0, así que ninguno puede instalar el actual.
        $package = $this->package('interno-viejo', [
            'name' => 'innoboxrr/interno-viejo',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3', 'innoboxrr/larapack-generator' => '^5.0'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $this->assertHasCheck('internal-version', $this->audit($package));
    }

    public function test_detecta_el_bump_automatico_sin_puerta_de_tests(): void
    {
        $package = $this->package('con-bump', [
            'name' => 'innoboxrr/con-bump',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml', 'bump-patch.yml']);

        $this->assertHasCheck('bump-patch', $this->audit($package));
    }

    /**
     * Un bump que espera a los tests no es el mismo caso.
     *
     * `traits`, `search-surge` y `rvoe-manager` ya lo tienen colgado de
     * `workflow_run` con la conclusion comprobada, asi que no publican a
     * ciegas. Sigue siendo peor que declarar la version en un archivo —esto la
     * adivina del ultimo tag—, pero llamarlo error igual que al que publica sin
     * mirar es como se acaba ignorando la lista entera.
     */
    public function test_un_bump_que_espera_a_los_tests_es_un_aviso_y_no_un_error(): void
    {
        $package = $this->package('bump-con-puerta', [
            'name' => 'innoboxrr/bump-con-puerta',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        file_put_contents(
            "{$package}/.github/workflows/bump-patch.yml",
            "name: Bump\non:\n    workflow_run:\n        workflows: ['Tests']\n        types: [completed]\n"
        );

        $result = $this->audit($package);

        $this->assertSame(0, $result['errors']);
        $this->assertHasCheck('bump-patch', $result);
    }

    /**
     * `needs: tests` es la otra forma de esperar a la suite, y la que falla
     * cerrada: si los tests son rojos, el job del tag ni arranca.
     */
    public function test_un_bump_que_llama_a_la_suite_con_needs_es_un_aviso(): void
    {
        $package = $this->package('bump-con-needs', [
            'name' => 'innoboxrr/bump-con-needs',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        file_put_contents(
            "{$package}/.github/workflows/bump-patch.yml",
            "name: Bump\non:\n    push:\n        branches: [master]\njobs:\n    tests:\n        uses: ./.github/workflows/tests.yml\n    bump-version:\n        needs: tests\n"
        );

        $result = $this->audit($package);

        $this->assertSame(0, $result['errors']);
        $this->assertHasCheck('bump-patch', $result);
    }

    /**
     * Un comentario que explica por qué no se usa workflow_run no es una
     * puerta.
     */
    public function test_un_comentario_que_nombra_workflow_run_no_cuenta_como_puerta(): void
    {
        $package = $this->package('bump-comentado', [
            'name' => 'innoboxrr/bump-comentado',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3'],
        ]);

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        file_put_contents(
            "{$package}/.github/workflows/bump-patch.yml",
            "name: Bump\n# workflow_run se dispara despues; aqui no se usa.\non:\n    push:\n        branches: [master]\njobs:\n    bump-version:\n        runs-on: ubuntu-latest\n"
        );

        $this->assertGreaterThan(0, $this->audit($package)['errors']);
    }

    public function test_detecta_la_ausencia_de_tests(): void
    {
        $package = $this->package('sin-tests', [
            'name' => 'innoboxrr/sin-tests',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3'],
        ]);

        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $findings = $this->audit($package);

        $this->assertHasCheck('no-tests', $findings);
        $this->assertHasCheck('phpunit-config', $findings);
    }

    public function test_no_exige_illuminate_a_un_paquete_agnostico(): void
    {
        // Hay paquetes legítimamente independientes del framework. Obligarles
        // a depender de Laravel sería empeorarlos, así que la regla solo
        // aplica si el código lo usa.
        $package = $this->package('agnostico', [
            'name' => 'innoboxrr/agnostico',
            'description' => 'x',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['X\\' => 'src/']],
            'require' => ['php' => '^8.3'],
        ]);

        mkdir("{$package}/src", 0777, true);
        file_put_contents("{$package}/src/Helper.php", "<?php\n\nfinal class Helper {}\n");

        $this->withTests($package);
        $this->withWorkflows($package, ['tests.yml', 'release.yml']);

        $findings = $this->audit($package);

        $this->assertNotHasCheck('illuminate-missing', $findings);
    }

    public function test_audita_varios_paquetes_de_una_vez(): void
    {
        $this->package('uno', ['name' => 'innoboxrr/uno']);
        $this->package('dos', ['name' => 'innoboxrr/dos']);

        $result = $this->audit($this->root, ['--all' => true]);

        $packages = array_unique(array_column($result['findings'], 'package'));

        sort($packages);

        $this->assertSame(['innoboxrr/dos', 'innoboxrr/uno'], $packages);
    }

    public function test_devuelve_codigo_de_salida_distinto_de_cero_cuando_falla(): void
    {
        $package = $this->package('roto', ['name' => 'innoboxrr/roto']);

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:audit', [
            'path' => $package,
            '--format' => 'json',
        ]));
    }

    public function test_no_estrecha_las_peer_dependencies(): void
    {
        // Una libreria que declara `react: ^18 || ^19` como peer esta diciendo
        // contra que puede funcionar, no contra que se construye. Exigirle la
        // version exacta de la linea base la volveria peor.
        $package = $this->package("con-peers", [
            "name" => "innoboxrr/con-peers",
            "description" => "x",
            "license" => "MIT",
            "autoload" => ["psr-4" => ["X\\" => "src/"]],
            "require" => ["php" => "^8.3"],
        ]);

        file_put_contents("{$package}/package.json", (string) json_encode([
            "name" => "innoboxrr-con-peers",
            "version" => "1.0.0",
            "license" => "MIT",
            "type" => "module",
            "exports" => ["." => "./index.js"],
            "files" => ["index.js"],
            "sideEffects" => false,
            "engines" => ["node" => ">=20"],
            "peerDependencies" => ["react" => "^18.0.0 || ^19.0.0"],
        ]));

        $this->withTests($package);
        $this->withWorkflows($package, ["tests.yml", "release.yml"]);

        $this->assertNotHasCheck("js-version", $this->audit($package));
    }
    /**
     * @param  array<string, mixed>  $composer
     */
    private function package(string $name, array $composer): string
    {
        $path = "{$this->root}/{$name}";

        mkdir($path, 0777, true);
        file_put_contents("{$path}/composer.json", (string) json_encode($composer, JSON_PRETTY_PRINT));

        return $path;
    }

    private function withTests(string $package): void
    {
        mkdir("{$package}/tests", 0777, true);
        file_put_contents("{$package}/tests/ExampleTest.php", "<?php\n");
        file_put_contents("{$package}/phpunit.xml.dist", "<phpunit/>\n");
    }

    /**
     * @param  array<int, string>  $workflows
     */
    private function withWorkflows(string $package, array $workflows): void
    {
        mkdir("{$package}/.github/workflows", 0777, true);

        foreach ($workflows as $workflow) {
            file_put_contents("{$package}/.github/workflows/{$workflow}", "name: {$workflow}\n");
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, errors: int, warnings: int, findings: array<int, array<string, string>>}
     */
    private function audit(string $path, array $options = []): array
    {
        $this->runCommand('larapack:audit', ['path' => $path, '--format' => 'json'] + $options);

        $decoded = json_decode($this->lastOutput, true);

        $this->assertIsArray($decoded, "La salida no era JSON:\n{$this->lastOutput}");

        return $decoded;
    }

    /**
     * @param  array{findings: array<int, array<string, string>>}  $result
     */
    private function assertHasCheck(string $check, array $result): void
    {
        $this->assertContains(
            $check,
            array_column($result['findings'], 'check'),
            "Se esperaba el hallazgo `{$check}` y no apareció."
        );
    }

    /**
     * @param  array{findings: array<int, array<string, string>>}  $result
     */
    private function assertNotHasCheck(string $check, array $result): void
    {
        $this->assertNotContains(
            $check,
            array_column($result['findings'], 'check'),
            "No se esperaba el hallazgo `{$check}`."
        );
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = "{$path}/{$entry}";

            is_dir($full) ? $this->deleteTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
