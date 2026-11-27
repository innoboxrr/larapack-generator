<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Los tres perfiles de proyecto, con la forma de los de verdad:
 *
 *  - school-management: raíz con contrato y paquetes en `packages/*`, cada
 *    uno con su laraimport y su manifiesto. Cada uno es un ámbito.
 *  - brandai: sin contrato ni manifiesto, con controladores y modelos
 *    escritos a mano. No hay con qué comparar, y se dice.
 *  - un paquete que sólo agrupa código: no es ámbito; su código lo mide la
 *    raíz (verify recorre packages/*).
 */
final class RealignPackagesTest extends TestCase
{
    use RealignProject;

    private string $billing = '';

    private function monorepo(): void
    {
        $project = $this->useProject(FakeProject::application());

        $composer = json_decode($project->read('composer.json'), true);
        $composer['require-dev'] = ['innoboxrr/larapack-generator' => '^9.3'];
        $composer['repositories'] = [['type' => 'path', 'url' => './packages/*', 'options' => ['symlink' => true]]];
        file_put_contents($project->path.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        copy(dirname(__DIR__).'/Fixtures/laraimport.json', $project->path.'/laraimport.json');
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--root' => $project->path]), $this->lastOutput);

        $this->billing = $this->package('billing', 'acme/billing', 'Acme\\Billing\\');
        file_put_contents($this->billing.'/laraimport.json', json_encode(['models' => [[
            'name' => 'Invoice',
            'props' => [['name' => 'number', 'type' => 'string', 'constraint' => null, 'default' => null, 'nullable' => false, 'fillable' => true,
                'creatable' => true, 'updatable' => true, 'exports_cols' => true, 'cast' => null, 'form' => true, 'form_component' => 'TextInputComponent',
                'form_submit' => true, 'datatable' => true]],
        ]]], JSON_PRETTY_PRINT));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--root' => $this->billing]), $this->lastOutput);

        // Un paquete que no es de LaraPack: sólo código.
        $this->package('helpers', 'acme/helpers', 'Acme\\Helpers\\');
    }

    private function package(string $directory, string $name, string $namespace): string
    {
        $path = $this->project->path.'/packages/'.$directory;
        mkdir($path.'/src', 0777, true);

        file_put_contents($path.'/composer.json', json_encode([
            'name' => $name,
            'type' => 'library',
            'autoload' => ['psr-4' => [$namespace => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    public function test_cada_paquete_con_contrato_es_un_ambito_con_su_referencia(): void
    {
        $this->monorepo();
        unlink($this->billing.'/src/Policies/InvoicePolicy.php');

        $diagnosis = $this->realign('diagnose', ['--jobs' => '2']);

        $this->assertSame(['.', 'packages/billing'], array_column($diagnosis['scopes'], 'path'));
        $this->assertSame([null, 'acme/billing'], array_column($diagnosis['scopes'], 'package'));
        $this->assertTrue($diagnosis['scopes'][1]['regeneration']['ok']);

        $policy = $this->entry('packages/billing/src/Policies/InvoicePolicy.php');
        $this->assertNotNull($policy);
        $this->assertSame(FileClass::MISSING, $policy['class']);
        $this->assertSame('packages/billing', $policy['scope']);
        $this->assertSame('src/Policies/InvoicePolicy.php', $policy['path']);

        $this->realign('plan');
        $this->assertSame('packages/billing', $this->step('missing@packages/billing')['scope']['path']);
        $this->assertSame('acme/billing', $this->step('missing@packages/billing')['scope']['package']);
    }

    public function test_acotar_a_un_paquete_conserva_lo_que_se_sabia_de_los_demas(): void
    {
        $this->monorepo();
        unlink($this->billing.'/src/Policies/InvoicePolicy.php');
        $this->realign('diagnose');

        // Se arregla el paquete y se rediagnostica sólo él.
        file_put_contents($this->billing.'/src/Policies/InvoicePolicy.php', '<?php');
        unlink($this->project->path.'/app/Policies/PostPolicy.php');
        $this->realign('diagnose', ['--package' => ['billing']]);

        // La política es un hueco: de vuelta y escrita a mano, cumple.
        $this->assertNull($this->entry('packages/billing/src/Policies/InvoicePolicy.php'));
        $this->assertSame(1, $this->report()['scopes']['packages/billing']['counts'][FileClass::HOLE_EDITED]);
        // El núcleo no se volvió a mirar: lo de su política no se sabe aún.
        $this->assertNull($this->entry('app/Policies/PostPolicy.php'));
        $this->assertSame(['.', 'packages/billing'], array_keys($this->report()['scopes']));

        $unknown = $this->realign('diagnose', ['--package' => ['no-existe']], Command::FAILURE);
        $this->assertStringContainsString('No hay un paquete local no-existe', $unknown['error']);
    }

    public function test_sin_contrato_no_hay_con_que_comparar_y_es_una_decision(): void
    {
        // La forma de brandai: convenciones de LaraPack escritas a mano.
        $project = $this->useProject(FakeProject::application());
        $composer = json_decode($project->read('composer.json'), true);
        $composer['require-dev'] = ['innoboxrr/larapack-generator' => '^9.4'];
        file_put_contents($project->path.'/composer.json', json_encode($composer));
        mkdir($project->path.'/app/Http/Controllers', 0777, true);
        mkdir($project->path.'/app/Models', 0777, true);
        file_put_contents($project->path.'/app/Http/Controllers/TagController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass TagController\n{\n    public function index(\\App\\Http\\Requests\\Tag\\IndexTagRequest \$request)\n    {\n        return \$request->handle();\n    }\n}\n");
        file_put_contents($project->path.'/app/Models/Tag.php', "<?php\n\nnamespace App\\Models;\n\nclass Tag {}\n");

        $diagnosis = $this->realign('diagnose');

        $this->assertFalse($diagnosis['scopes'][0]['contract']);
        $this->assertNull($diagnosis['scopes'][0]['regeneration']);
        $this->assertSame(0, $diagnosis['totals']['files']);
        $this->assertSame(['app/Http/Controllers/TagController.php', 'app/Models/Tag.php'], array_column($this->report()['unmanaged'], 'file'));

        $this->realign('plan');
        $steps = array_column($this->plan()['steps'], null, 'id');

        $this->assertSame(Category::DECISION, $steps['no-contract@.']['category']);
        $this->assertSame(['infer', 'keep'], array_column($steps['no-contract@.']['options'], 'key'));
        $this->assertArrayHasKey('unmanaged-models@.', $steps);
        $this->assertArrayHasKey('unmanaged-http@.', $steps);
    }

    public function test_un_paquete_sin_contrato_ni_manifiesto_no_es_ambito(): void
    {
        $this->monorepo();

        $diagnosis = $this->realign('diagnose');

        $this->assertNotContains('packages/helpers', array_column($diagnosis['scopes'], 'path'));
    }
}
