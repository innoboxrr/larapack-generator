<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `validate` en la raíz de un proyecto con paquetes locales, como `verify`
 * desde 9.3.1: el contrato de la raíz y el de cada paquete que lo tenga, con
 * --package (repetible), --no-packages y el resumen por paquete.
 *
 * Antes sólo leía el laraimport.json de la raíz: un contrato roto en un
 * paquete salía en verde, y el criterio de terminado del realineamiento
 * («validate pasa en cada ámbito») obligaba a correrlo con cada --root.
 */
final class ValidateLocalPackagesTest extends TestCase
{
    private const VALID = ['models' => [['name' => 'Product', 'props' => [['name' => 'name', 'type' => 'string']]]]];

    private const BROKEN = ['models' => [['name' => 'Invoice', 'props' => [['name' => 'total', 'type' => 'no-es-un-tipo']]]]];

    protected function setUp(): void
    {
        parent::setUp();

        $project = $this->useProject(FakeProject::application());

        $composer = json_decode($project->read('composer.json'), true);
        $composer['repositories'] = [['type' => 'path', 'url' => './packages/*', 'options' => ['symlink' => true]]];
        file_put_contents($project->path.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($project->path.'/laraimport.json', json_encode(self::VALID));

        $this->package('billing', 'acme/billing', self::BROKEN);
        $this->package('support', 'acme/support', null);
    }

    /**
     * @param  array<string, mixed>|null  $contract
     */
    private function package(string $directory, string $name, ?array $contract): void
    {
        $path = $this->project->path.'/packages/'.$directory;
        mkdir($path.'/src', 0777, true);
        file_put_contents($path.'/composer.json', json_encode(['name' => $name, 'type' => 'library', 'autoload' => ['psr-4' => ['Acme\\'.ucfirst($directory).'\\' => 'src/']]]));

        if ($contract !== null) {
            file_put_contents($path.'/laraimport.json', json_encode($contract));
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function validate(array $options = []): array
    {
        $code = $this->runCommand('larapack:validate', ['--format' => 'json', '--root' => $this->project->path] + $options);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return [$code, $report];
    }

    public function test_en_la_raiz_valida_tambien_el_contrato_de_cada_paquete(): void
    {
        [$code, $report] = $this->validate();

        $this->assertSame(Command::FAILURE, $code, json_encode($report));
        $this->assertFalse($report['ok']);
        $this->assertGreaterThan(0, $report['errors']);
        $this->assertSame(['acme/billing'], array_values(array_unique(array_column($report['findings'], 'package'))));
        $this->assertSame(['packages/billing/laraimport.json'], array_values(array_unique(array_column($report['findings'], 'file'))));

        $summary = array_column($report['summary'], null, 'path');
        $this->assertSame(['.', 'packages/billing', 'packages/support'], array_keys($summary));
        $this->assertTrue($summary['.']['ok']);
        $this->assertSame(['Product'], $summary['.']['models']);
        $this->assertFalse($summary['packages/billing']['ok']);
        $this->assertSame('acme/billing', $summary['packages/billing']['package']);
        // Un paquete sin contrato no es un fallo: se dice.
        $this->assertFalse($summary['packages/support']['contract']);
        $this->assertTrue($summary['packages/support']['ok']);
        // Lo de siempre de la raíz sigue donde estaba.
        $this->assertSame(['Product'], $report['models']);
    }

    public function test_package_acota_y_no_packages_deja_solo_la_raiz(): void
    {
        [$code, $report] = $this->validate(['--package' => ['app']]);
        $this->assertSame(Command::SUCCESS, $code, json_encode($report));
        $this->assertSame(['.'], array_column($report['summary'], 'path'));

        [$code, $report] = $this->validate(['--package' => ['billing']]);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['packages/billing'], array_column($report['summary'], 'path'));

        [$code, $report] = $this->validate(['--package' => ['acme/billing', 'app']]);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['.', 'packages/billing'], array_column($report['summary'], 'path'));

        [$code, $report] = $this->validate(['--no-packages' => true]);
        $this->assertSame(Command::SUCCESS, $code, json_encode($report));
        $this->assertSame(['.'], array_column($report['summary'], 'path'));
    }

    public function test_un_paquete_que_no_existe_o_sin_contrato_nombrado_es_un_error(): void
    {
        [$code, $report] = $this->validate(['--package' => ['no-existe']]);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('No hay un paquete local no-existe', $report['error']);
        $this->assertStringContainsString('acme/billing', $report['error']);

        // Se pidió validar un contrato que no está.
        [$code, $report] = $this->validate(['--package' => ['support']]);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('No se encontró el archivo', $report['findings'][0]['message']);
    }

    public function test_una_raiz_sin_contrato_que_solo_agrupa_paquetes_no_falla_por_eso(): void
    {
        unlink($this->project->path.'/laraimport.json');
        file_put_contents($this->project->path.'/packages/billing/laraimport.json', json_encode(self::VALID));

        [$code, $report] = $this->validate();

        $this->assertSame(Command::SUCCESS, $code, json_encode($report));
        $this->assertFalse(array_column($report['summary'], null, 'path')['.']['contract']);
    }

    public function test_con_una_ruta_valida_ese_archivo_y_no_se_combina_con_package(): void
    {
        [$code, $report] = $this->validate(['jsonPath' => $this->project->path.'/packages/billing/laraimport.json']);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertArrayNotHasKey('summary', $report);

        [$code, $report] = $this->validate(['jsonPath' => $this->project->path.'/laraimport.json']);
        $this->assertSame(Command::SUCCESS, $code, json_encode($report));
        $this->assertSame(['Product'], $report['models']);

        [$code, $report] = $this->validate(['jsonPath' => $this->project->path.'/laraimport.json', '--package' => ['billing']]);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('no se combinan', $report['error']);
    }

    public function test_el_texto_resume_por_paquete(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:validate', ['--root' => $this->project->path]));

        $this->assertStringContainsString('Por paquete:', $this->lastOutput);
        $this->assertStringContainsString('acme/billing (packages/billing): 1 errores', $this->lastOutput);
        $this->assertStringContainsString('acme/support (packages/support): sin laraimport.json', $this->lastOutput);
        $this->assertStringContainsString('packages/billing/laraimport.json', $this->lastOutput);
    }
}
