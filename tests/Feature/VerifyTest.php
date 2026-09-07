<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class VerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());
    }

    private function verify(array $options = []): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json'] + $options);

        return [$code, json_decode($this->lastOutput, true)];
    }

    public function test_un_proyecto_recien_generado_no_tiene_desviaciones(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);

        [$code, $report] = $this->verify();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertTrue($report['ok']);
        $this->assertSame(0, $report['errors']);
    }

    public function test_avisa_cuando_no_hay_manifiesto(): void
    {
        [$code, $report] = $this->verify();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame('empty-manifest', $report['findings'][0]['check']);
    }

    public function test_detecta_un_archivo_generado_que_ha_desaparecido(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);

        unlink($this->project->path . '/src/Policies/ProductPolicy.php');

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFalse($report['ok']);

        $missing = $this->findingsOf($report, 'missing-file');

        $this->assertCount(1, $missing);
        $this->assertSame('src/Policies/ProductPolicy.php', $missing[0]['file']);
    }

    /**
     * Editar lo generado es legitimo: debe informarse, no fallar.
     */
    public function test_informa_de_lo_editado_a_mano_sin_fallar(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);

        file_put_contents(
            $this->project->path . '/src/Policies/ProductPolicy.php',
            "<?php\n// mi logica\n"
        );

        [$code, $report] = $this->verify();

        $this->assertSame(Command::SUCCESS, $code);

        $customised = $this->findingsOf($report, 'customised');

        $this->assertNotEmpty($customised);
        $this->assertSame('info', $customised[0]['level']);
    }

    /**
     * Esto es el drift que describe el problema: dos entidades equivalentes
     * que dejan de tener la misma forma.
     */
    public function test_detecta_una_entidad_con_menos_componentes_que_sus_pares(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);
        $this->runCommand('larapack:full-model', ['name' => 'Category']);

        // Un tercer modelo generado a medias: solo el modelo, sin politica ni
        // requests, como haria un agente que se salta la arquitectura.
        $this->runCommand('larapack:model', ['name' => 'Tag']);

        [$code, $report] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::FAILURE, $code);

        $inconsistent = $this->findingsOf($report, 'inconsistent-entity');

        $models = array_unique(array_column($inconsistent, 'model'));

        $this->assertSame(['Tag'], array_values($models));

        $missing = implode(' ', array_column($inconsistent, 'message'));

        $this->assertStringContainsString('Policy', $missing);
        $this->assertStringContainsString('Requests', $missing);
    }

    /**
     * Los proveedores, la config y el andamiaje del modulo npm no pertenecen a
     * ninguna entidad. Registrarlos como si fueran una hacia que la
     * comprobacion de consistencia los tratara como un modelo al que le
     * faltaba absolutamente todo.
     */
    public function test_los_artefactos_del_paquete_no_cuentan_como_entidad(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);
        $this->runCommand('larapack:full-model', ['name' => 'Category']);
        $this->runCommand('larapack:providers');

        $manifest = json_decode($this->project->read('.larapack/manifest.json'), true);

        $this->assertSame(['Category', 'Product'], array_keys($manifest['models']));
        $this->assertArrayHasKey(
            'src/Providers/AppServiceProvider.php',
            $manifest['package']['files']
        );

        [$code, $report] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings']));
    }

    public function test_comprueba_tambien_que_no_falten_los_archivos_del_paquete(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);
        $this->runCommand('larapack:providers');

        unlink($this->project->path . '/src/Providers/RouteServiceProvider.php');

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $missing = $this->findingsOf($report, 'missing-file');

        $this->assertSame('src/Providers/RouteServiceProvider.php', $missing[0]['file']);
        $this->assertNull($missing[0]['model']);
    }

    public function test_no_se_queja_con_una_sola_entidad(): void
    {
        $this->runCommand('larapack:model', ['name' => 'Tag']);

        [, $report] = $this->verify(['--strict' => true]);

        $this->assertSame([], $this->findingsOf($report, 'inconsistent-entity'));
    }

    /**
     * El front resuelve cada URL componiendo API_ROUTE_PREFIX; si deja de
     * cuadrar con el ->as() del RouteServiceProvider, el modulo pierde el
     * backend sin que nada avise.
     */
    public function test_detecta_que_el_prefijo_de_rutas_dejo_de_cuadrar(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product', '--vue' => true]);

        $module = $this->project->path . '/resources/vue/src/models/product/index.js';

        $this->assertFileExists($module);

        file_put_contents($module, str_replace(
            "'api.testvendor.testpkg.product.'",
            "'api.otra.cosa.'",
            file_get_contents($module)
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $prefix = $this->findingsOf($report, 'route-prefix');

        $this->assertCount(1, $prefix);
        $this->assertStringContainsString('api.otra.cosa.', $prefix[0]['message']);
        $this->assertStringContainsString('api.testvendor.testpkg.product.', $prefix[0]['message']);
    }

    public function test_la_salida_de_texto_resume_el_resultado(): void
    {
        $this->runCommand('larapack:model', ['name' => 'Tag']);

        $this->runCommand('larapack:verify');

        $this->assertStringContainsString('0 errores', $this->lastOutput);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findingsOf(array $report, string $check): array
    {
        return array_values(array_filter(
            $report['findings'],
            fn (array $f): bool => $f['check'] === $check
        ));
    }
}
