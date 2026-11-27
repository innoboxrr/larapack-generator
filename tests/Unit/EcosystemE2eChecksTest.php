<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las lecturas de la prueba del ecosistema (.github/scripts/ecosystem-e2e-checks.php).
 *
 * Nacieron como `php -r` dentro del script, con `exit("mensaje")` en cada
 * fallo. En PHP eso imprime el mensaje y sale con 0: la comprobación de que se
 * ejecutaron todos los tests generados no podía fallar, y nadie la había visto
 * fallar. Aquí se la alimenta con informes rotos y se exige un 1, con el
 * motivo en stderr.
 */
final class EcosystemE2eChecksTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/larapack-ecosystem-checks-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/app/tests/Feature/Models', 0777, true);
        mkdir($this->dir.'/app/tests/Feature/Endpoints/Desk', 0777, true);

        file_put_contents($this->dir.'/app/tests/Feature/Models/ProductEndpointsTest.php', <<<'PHP'
            <?php
            class ProductEndpointsTest {
                public function test_product_cada_accion_requiere_sesion() {}
                public function test_product_index_pasa_por_su_managed_filter() {}
            }
            PHP);
        file_put_contents($this->dir.'/app/tests/Feature/Endpoints/Desk/SearchTest.php', <<<'PHP'
            <?php
            class SearchTest {
                public function test_con_sesion_basta_la_sesion() {}
            }
            PHP);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    // --- junit -----------------------------------------------------------

    public function test_junit_pasa_si_cada_test_generado_se_ejecuta_entero(): void
    {
        [$code, $out, $err] = $this->check('junit', $this->junit(), $this->app(), 'product_cada_accion_requiere_sesion', 'con_sesion_basta_la_sesion');

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('2 archivos generados ejecutados enteros; 5 tests en total', $out);
    }

    public function test_junit_falla_si_hay_tests_saltados(): void
    {
        [$code, , $err] = $this->check('junit', $this->junit(skipped: 3), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('3 tests saltados', $err);
    }

    public function test_junit_falla_si_un_archivo_generado_no_se_ejecuta_entero(): void
    {
        [$code, , $err] = $this->check('junit', $this->junit(omit: 'test_product_index_pasa_por_su_managed_filter'), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tests/Feature/Models/ProductEndpointsTest.php (1 de 2)', $err);
    }

    public function test_junit_falla_si_un_archivo_generado_no_se_ejecuta_nada(): void
    {
        [$code, , $err] = $this->check('junit', $this->junit(omitFile: 'Endpoints/Desk/SearchTest.php'), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tests/Feature/Endpoints/Desk/SearchTest.php (0 de 1)', $err);
    }

    public function test_junit_falla_si_falta_un_test_exigido(): void
    {
        [$code, , $err] = $this->check('junit', $this->junit(), $this->app(), 'user_cada_accion_pregunta_a_la_politica');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('faltan tests generados: user_cada_accion_pregunta_a_la_politica', $err);
    }

    public function test_junit_falla_sin_tests_generados(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir.'/app/tests'));
        mkdir($this->dir.'/app/tests');

        [$code, , $err] = $this->check('junit', $this->junit(), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('no hay tests generados', $err);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function unreadableReports(): array
    {
        return [
            'sin informe' => [null],
            'XML roto' => ['<testsuites><testsuite name="x" tests="1"'],
            'sin suite' => ['<?xml version="1.0"?><testsuites/>'],
        ];
    }

    #[DataProvider('unreadableReports')]
    public function test_junit_falla_si_el_informe_no_se_puede_leer(?string $content): void
    {
        $path = $this->dir.'/junit.xml';

        if ($content !== null) {
            file_put_contents($path, $content);
        }

        [$code, , $err] = $this->check('junit', $path, $this->app());

        $this->assertSame(1, $code);
        $this->assertNotSame('', trim($err));
    }

    // --- verify ----------------------------------------------------------

    public function test_verify_pasa_limpio(): void
    {
        [$code, $out, $err] = $this->check('verify', $this->json(['ok' => true, 'findings' => [['level' => 'info', 'check' => 'x', 'file' => 'a.php', 'message' => 'nada']]]), $this->app());

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('sin hallazgos que bloqueen', $out);
    }

    public function test_verify_falla_si_no_devuelve_json(): void
    {
        $path = $this->dir.'/verify.json';
        file_put_contents($path, "Sin desviaciones.\n");

        [$code, , $err] = $this->check('verify', $path, $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('no es JSON', $err);
    }

    public function test_verify_falla_si_no_dice_si_paso(): void
    {
        [$code, , $err] = $this->check('verify', $this->json(['findings' => []]), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString("falta 'ok'", $err);
    }

    public function test_verify_falla_con_un_aviso_aunque_diga_ok(): void
    {
        [$code, , $err] = $this->check('verify', $this->json(['ok' => true, 'findings' => [$this->finding('routes/web.php')]]), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('1 hallazgos que bloquean', $err);
    }

    public function test_verify_atribuye_a_laravel_setup_solo_un_archivo_identico_a_su_stub(): void
    {
        $this->stub('vue', 'routes/web.php', "<?php // stub\n");
        $this->appFile('routes/web.php', "<?php // stub\n");

        [$code, $out] = $this->check('verify', $this->json(['ok' => false, 'findings' => [$this->finding('routes/web.php')]]), $this->app());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('lo puso innoboxrr/laravel-setup (idéntico a su stubs/app/vue/routes/web.php)', $out);
    }

    public function test_verify_no_culpa_a_laravel_setup_de_un_archivo_reescrito_sobre_su_stub(): void
    {
        $this->stub('vue', 'app/Models/User.php', "<?php // stub\n");
        $this->appFile('app/Models/User.php', "<?php // regenerado por LaraPack\n");

        [, $out] = $this->check('verify', $this->json(['ok' => false, 'findings' => [$this->finding('app/Models/User.php')]]), $this->app());

        $this->assertStringContainsString('existe en los stubs de innoboxrr/laravel-setup (stubs/app/vue/app/Models/User.php), pero con otro contenido', $out);
        $this->assertStringNotContainsString('lo puso innoboxrr/laravel-setup', $out);
    }

    public function test_verify_atribuye_a_larapack_lo_que_no_esta_en_los_stubs(): void
    {
        $this->appFile('app/Http/Controllers/DeskController.php', "<?php\n");

        [, $out] = $this->check('verify', $this->json(['ok' => false, 'findings' => [$this->finding('app/Http/Controllers/DeskController.php')]]), $this->app());

        $this->assertStringContainsString('lo generó innoboxrr/larapack-generator', $out);
    }

    // --- installed-from --------------------------------------------------

    public function test_installed_from_acepta_el_repositorio_path_con_la_version(): void
    {
        [$code, $out, $err] = $this->check('installed-from', $this->installed('path', '9.2.0'), '9.2.0');

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('path 9.2.0', $out);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foreignInstalls(): array
    {
        return [
            'la dist de Packagist' => ['zip', '9.2.0'],
            'otra versión' => ['path', '9.1.0'],
        ];
    }

    #[DataProvider('foreignInstalls')]
    public function test_installed_from_rechaza_lo_que_no_es_este_checkout(string $type, string $version): void
    {
        [$code, , $err] = $this->check('installed-from', $this->installed($type, $version), '9.2.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("({$type} {$version}, se esperaba path 9.2.0)", $err);
    }

    public function test_installed_from_falla_si_larapack_no_esta_instalado(): void
    {
        [$code, , $err] = $this->check('installed-from', $this->json(['packages' => [['name' => 'innoboxrr/laravel-setup', 'version' => '7.2.1']]]), '9.2.0');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('no está instalado', $err);
    }

    // --- pick-version ----------------------------------------------------

    public function test_pick_version_elige_la_ultima_estable(): void
    {
        [$code, $out] = $this->check('pick-version', '^7.2.1', stdin: (string) json_encode(['versions' => ['7.2.1', '7.10.0', '7.11.0-beta1', 'v7.3.0', 'dev-main']]));

        $this->assertSame(0, $code);
        $this->assertSame("7.10.0\n", $out);
    }

    public function test_pick_version_falla_si_ninguna_cumple_la_restriccion(): void
    {
        // Lo que deja `composer show` cuando ninguna cumple: nada en stdout.
        [$code, $out, $err] = $this->check('pick-version', '^7.2.1', stdin: '');

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('ninguna versión estable publicada cumple ^7.2.1', $err);
    }

    public function test_una_comprobacion_desconocida_falla(): void
    {
        [$code, , $err] = $this->check('junit-xml');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('comprobación desconocida', $err);
    }

    // --- apoyo -----------------------------------------------------------

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function check(string ...$args): array
    {
        $stdin = $args['stdin'] ?? '';
        unset($args['stdin']);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/ecosystem-e2e-checks.php', ...array_values($args)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function app(): string
    {
        return $this->dir.'/app';
    }

    /**
     * Un informe como el de `php artisan test --log-junit`: los tests
     * generados con su archivo, y uno de la aplicación.
     */
    private function junit(int $skipped = 0, ?string $omit = null, ?string $omitFile = null): string
    {
        $cases = [
            ['Models/ProductEndpointsTest.php', 'test_product_cada_accion_requiere_sesion'],
            ['Models/ProductEndpointsTest.php', 'test_product_index_pasa_por_su_managed_filter'],
            ['Endpoints/Desk/SearchTest.php', 'test_con_sesion_basta_la_sesion'],
        ];
        $cases = array_values(array_filter($cases, fn (array $c): bool => $c[1] !== $omit && $c[0] !== $omitFile));

        $xml = '';

        foreach ($cases as [$file, $name]) {
            $xml .= sprintf('<testcase name="%s" file="%s/tests/Feature/%s" assertions="1"/>', $name, $this->app(), $file);
        }

        $xml .= '<testcase name="test_the_application_returns_a_successful_response" file="'.$this->app().'/tests/Feature/ExampleTest.php" assertions="1"/>';
        $xml .= '<testcase name="test_that_true_is_true" file="'.$this->app().'/tests/Unit/ExampleTest.php" assertions="1"/>';
        $total = count($cases) + 2;

        $path = $this->dir.'/junit.xml';
        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="%s/phpunit.xml" tests="%d" assertions="%d" errors="0" failures="0" skipped="%d">%s</testsuite></testsuites>',
            $this->app(), $total, $total, $skipped, $xml
        ));

        return $path;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function json(array $data): string
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, (string) json_encode($data));

        return $path;
    }

    private function installed(string $type, string $version): string
    {
        return $this->json(['packages' => [
            ['name' => 'innoboxrr/laravel-setup', 'version' => '7.2.1', 'dist' => ['type' => 'zip']],
            ['name' => 'innoboxrr/larapack-generator', 'version' => $version, 'dist' => ['type' => $type]],
        ]]);
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $file): array
    {
        return ['level' => 'warning', 'check' => 'route-closure', 'file' => $file, 'message' => 'lógica en un closure'];
    }

    private function stub(string $ui, string $file, string $content): void
    {
        $path = $this->app()."/vendor/innoboxrr/laravel-setup/stubs/app/{$ui}/{$file}";
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function appFile(string $file, string $content): void
    {
        $path = $this->app()."/{$file}";
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
