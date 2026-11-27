<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `verify` en la raíz de un proyecto con paquetes locales.
 *
 * En school-management la raíz tiene su manifiesto y cada uno de sus 81
 * paquetes el suyo (`packages/<p>/.larapack/manifest.json`). `verify` en la
 * raíz recorría el código de todos —controller-shape mira `packages/*`—
 * pero no leía el manifiesto de ninguno: un archivo generado que faltaba en
 * un paquete no salía. Hubo que correrlo 81 veces, una con cada --root.
 *
 * El proyecto de la prueba tiene la forma del de school-management: la
 * raíz declara `packages/*` como repositorio `path`, un paquete con modelos
 * (y con algo roto) y otro que sólo integra, con el laraimport de
 * `school-pay-stripe` tal cual.
 */
final class VerifyLocalPackagesTest extends TestCase
{
    private const PAY_FIXTURE = __DIR__.'/../Fixtures/real-projects/school-management/packages/school-pay-stripe/laraimport.json';

    private string $billing = '';

    private string $pay = '';

    protected function setUp(): void
    {
        parent::setUp();

        $project = $this->useProject(FakeProject::application());

        $composer = json_decode($project->read('composer.json'), true);
        $composer['require-dev'] = ['innoboxrr/larapack-generator' => '^9.3'];
        $composer['repositories'] = [['type' => 'path', 'url' => './packages/*', 'options' => ['symlink' => true]]];
        file_put_contents($project->path.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->billing = $this->package('billing', 'acme/billing', 'Acme\\Billing\\');
        $this->pay = $this->package('school-pay-stripe', 'innoboxrr/school-pay-stripe', 'Innoboxrr\\SchoolPayStripe\\');

        $this->generate(['name' => 'Product']);
        $this->generate(['name' => 'Invoice', '--root' => $this->billing]);
        $this->generate(['name' => 'Payment', '--root' => $this->billing]);

        copy(self::PAY_FIXTURE, $this->pay.'/laraimport.json');
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:providers', ['--root' => $this->pay]), $this->lastOutput);
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--root' => $this->pay]), $this->lastOutput);

        // Lo roto, sólo en el paquete.
        unlink($this->billing.'/src/Policies/InvoicePolicy.php');
    }

    private function package(string $directory, string $name, string $namespace): string
    {
        $path = $this->project->path.'/packages/'.$directory;
        mkdir($path.'/src', 0777, true);

        file_put_contents($path.'/composer.json', json_encode([
            'name' => $name,
            'type' => 'library',
            'require-dev' => ['innoboxrr/larapack-generator' => '^9.3'],
            'autoload' => ['psr-4' => [$namespace => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function generate(array $arguments): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:full-model', $arguments + ['--root' => $this->project->path]),
            $this->lastOutput
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(array $options = []): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json', '--root' => $this->project->path] + $options);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return [$code, $report];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function missing(array $report): array
    {
        return array_values(array_filter($report['findings'], fn (array $finding): bool => $finding['check'] === 'missing-file'));
    }

    public function test_verify_en_la_raiz_lee_tambien_los_manifiestos_de_los_paquetes(): void
    {
        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code, json_encode($report));
        $this->assertFalse($report['ok']);
        $this->assertSame(1, $report['errors']);

        $missing = $this->missing($report);

        $this->assertCount(1, $missing);
        $this->assertSame('acme/billing', $missing[0]['package']);
        $this->assertSame('packages/billing/src/Policies/InvoicePolicy.php', $missing[0]['file']);
        $this->assertSame('Invoice', $missing[0]['model']);
    }

    public function test_el_resumen_va_por_paquete(): void
    {
        [, $report] = $this->verify();

        $summary = array_column($report['summary'], null, 'path');

        $this->assertSame(['.', 'packages/billing', 'packages/school-pay-stripe'], array_keys($summary));

        $this->assertNull($summary['.']['package']);
        $this->assertTrue($summary['.']['manifest']);
        $this->assertSame(0, $summary['.']['errors']);

        $this->assertSame('acme/billing', $summary['packages/billing']['package']);
        $this->assertTrue($summary['packages/billing']['manifest']);
        $this->assertSame(1, $summary['packages/billing']['errors']);

        // El que sólo integra: sus proveedores registrados, nada roto.
        $this->assertSame('innoboxrr/school-pay-stripe', $summary['packages/school-pay-stripe']['package']);
        $this->assertTrue($summary['packages/school-pay-stripe']['manifest']);
        $this->assertSame(0, $summary['packages/school-pay-stripe']['errors']);
        $this->assertSame(0, $summary['packages/school-pay-stripe']['warnings']);

        // Los totales son la suma de las filas.
        $this->assertSame($report['errors'], array_sum(array_column($report['summary'], 'errors')));
        $this->assertSame($report['warnings'], array_sum(array_column($report['summary'], 'warnings')));
    }

    /**
     * Cada hallazgo dice de qué paquete es, también los del código: la forma
     * de un controlador de un paquete cuenta para ese paquete.
     */
    public function test_cada_hallazgo_lleva_su_paquete(): void
    {
        mkdir($this->billing.'/src/Http/Controllers/Hand', 0777, true);
        file_put_contents($this->billing.'/src/Http/Controllers/Hand/RefundController.php', <<<'PHP'
<?php

namespace Acme\Billing\Http\Controllers\Hand;

use Illuminate\Http\Request;

class RefundController
{
    public function store(Request $request)
    {
        $data = $request->validate(['amount' => ['required']]);

        return response()->json($data);
    }
}
PHP);

        file_put_contents($this->project->path.'/app/Policies/ProductPolicy.php', "<?php\n// a mano\n");

        [, $report] = $this->verify();

        $byFile = array_column($report['findings'], 'package', 'file');

        $this->assertSame('acme/billing', $byFile['packages/billing/src/Http/Controllers/Hand/RefundController.php']);
        $this->assertNull($byFile['app/Policies/ProductPolicy.php']);

        foreach ($report['findings'] as $finding) {
            $this->assertArrayHasKey('package', $finding);
        }
    }

    public function test_package_acota_a_un_paquete(): void
    {
        foreach (['acme/billing', 'billing', 'packages/billing'] as $name) {
            [$code, $report] = $this->verify(['--package' => [$name]]);

            $this->assertSame(Command::FAILURE, $code, $name);
            $this->assertSame(['acme/billing'], array_values(array_unique(array_column($report['findings'], 'package'))), $name);
            $this->assertSame(['packages/billing'], array_column($report['summary'], 'path'), $name);
        }
    }

    public function test_package_app_es_el_nucleo(): void
    {
        [$code, $report] = $this->verify(['--package' => ['app']]);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings']));
        $this->assertSame([], $this->missing($report));
        $this->assertSame(['.'], array_column($report['summary'], 'path'));
    }

    public function test_un_paquete_desconocido_falla_diciendo_los_que_hay(): void
    {
        [$code, $report] = $this->verify(['--package' => ['nope']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('nope', $report['error']);
        $this->assertStringContainsString('acme/billing', $report['error']);
    }

    /**
     * --no-packages es lo de antes: el manifiesto de la raíz y nada más.
     */
    public function test_no_packages_deja_fuera_sus_manifiestos(): void
    {
        [$code, $report] = $this->verify(['--no-packages' => true]);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings']));
        $this->assertSame([], $this->missing($report));
        $this->assertFalse(array_column($report['summary'], 'manifest', 'path')['packages/billing']);
    }

    public function test_check_se_combina_con_package(): void
    {
        [$code, $report] = $this->verify(['--package' => ['acme/billing'], '--check' => ['missing-file']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['missing-file'], array_column($report['findings'], 'check'));
    }

    /**
     * Desde el directorio del paquete, con su --root, se mide el paquete solo,
     * como antes: sus rutas siguen siendo relativas a él.
     */
    public function test_con_root_en_el_paquete_se_mide_el_paquete(): void
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json', '--root' => $this->billing]);
        $report = json_decode($this->lastOutput, true);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame('src/Policies/InvoicePolicy.php', $this->missing($report)[0]['file']);
        $this->assertSame(['.'], array_column($report['summary'], 'path'));
    }

    /**
     * Un repositorio `path` que apunta fuera de la raíz es una dependencia: su
     * manifiesto no se lee al medir el paquete. En school-management cada
     * paquete apunta así a `../school-core-contracts`.
     */
    public function test_un_repositorio_path_de_fuera_no_se_mide(): void
    {
        $composer = json_decode((string) file_get_contents($this->billing.'/composer.json'), true);
        $composer['repositories'] = [['type' => 'path', 'url' => '../school-pay-stripe']];
        file_put_contents($this->billing.'/composer.json', json_encode($composer));

        unlink($this->pay.'/src/Providers/AppServiceProvider.php');

        $code = $this->runCommand('larapack:verify', ['--format' => 'json', '--root' => $this->billing]);
        $report = json_decode($this->lastOutput, true);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['src/Policies/InvoicePolicy.php'], array_column($this->missing($report), 'file'));

        $outside = array_column($report['summary'], null, 'path')['../school-pay-stripe'];

        $this->assertSame('innoboxrr/school-pay-stripe', $outside['package']);
        $this->assertFalse($outside['manifest']);
    }

    public function test_la_salida_de_texto_resume_por_paquete(): void
    {
        $code = $this->runCommand('larapack:verify', ['--root' => $this->project->path]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('[acme/billing]', $this->lastOutput);
        $this->assertStringContainsString('packages/billing/src/Policies/InvoicePolicy.php', $this->lastOutput);
        $this->assertMatchesRegularExpression('/app \(núcleo\): 0 errores/', $this->lastOutput);
        $this->assertMatchesRegularExpression('#acme/billing \(packages/billing\): 1 errores#', $this->lastOutput);
        $this->assertMatchesRegularExpression('#innoboxrr/school-pay-stripe \(packages/school-pay-stripe\): 0 errores#', $this->lastOutput);
    }

    /**
     * Una raíz que no genera nada —sólo agrupa paquetes— no avisa de que «no
     * hay nada registrado» si sus paquetes sí tienen manifiesto.
     */
    public function test_una_raiz_que_solo_agrupa_paquetes_no_avisa_de_manifiesto_vacio(): void
    {
        unlink($this->project->path.'/.larapack/manifest.json');
        $this->assertFileDoesNotExist($this->project->path.'/laraimport.json');

        [, $report] = $this->verify(['--package' => ['app', 'innoboxrr/school-pay-stripe'], '--strict' => true]);

        $this->assertNotContains('empty-manifest', array_column($report['findings'], 'check'), json_encode($report['findings']));
    }
}
