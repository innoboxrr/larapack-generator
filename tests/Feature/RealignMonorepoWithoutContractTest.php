<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Un monorepo SIN contrato: la raíz y cada paquete de `packages/*` escritos a
 * mano, ninguno con laraimport ni manifiesto. Lo encontró una prueba de
 * autonomía sobre uno de 20 paquetes: los pasos `controller-shape` de los
 * paquetes salían con el ámbito de la raíz, `no-contract` sólo en la raíz,
 * `infer no-contract@.` escribía los borradores de todos y `diagnose` sólo
 * resumía el núcleo. Cada paquete local es su propio ámbito, de punta a
 * punta (docs/realign/profiles.md, perfil 3).
 */
final class RealignMonorepoWithoutContractTest extends TestCase
{
    use RealignProject;

    private function monorepo(): void
    {
        $project = $this->useProject(FakeProject::application());
        $composer = json_decode($project->read('composer.json'), true);
        $composer['require-dev'] = ['innoboxrr/larapack-generator' => '^9.4'];
        $composer['repositories'] = [['type' => 'path', 'url' => './packages/*', 'options' => ['symlink' => true]]];
        // Como en el monorepo ensayado, la raíz carga también un paquete: su
        // código es del paquete, no de la raíz.
        $composer['autoload']['psr-4']['Acme\\Billing\\'] = 'packages/billing/src/';
        file_put_contents($project->path.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->put('app/Http/Controllers/TagController.php', $this->controller('App\\Http\\Controllers', 'TagController'));
        $this->put('app/Models/Tag.php', "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Tag extends Model {}\n");

        foreach (['billing' => 'Billing', 'crm' => 'Crm'] as $directory => $namespace) {
            $this->put("packages/{$directory}/composer.json", (string) json_encode([
                'name' => 'acme/'.$directory,
                'type' => 'library',
                'autoload' => ['psr-4' => ["Acme\\{$namespace}\\" => 'src/']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->put("packages/{$directory}/src/Http/Controllers/{$namespace}Controller.php", $this->controller("Acme\\{$namespace}\\Http\\Controllers", $namespace.'Controller'));
            $this->put("packages/{$directory}/src/Models/{$namespace}Item.php", "<?php\n\nnamespace Acme\\{$namespace}\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$namespace}Item extends Model {}\n");
        }

        $this->commitAll();
    }

    public function test_cada_paquete_sin_contrato_es_su_propio_ambito_de_punta_a_punta(): void
    {
        $this->monorepo();

        $diagnosis = $this->realign('diagnose');
        $this->assertSame(['.', 'packages/billing', 'packages/crm'], array_column($diagnosis['scopes'], 'path'));
        $this->assertSame([null, 'acme/billing', 'acme/crm'], array_column($diagnosis['scopes'], 'package'));

        // El código sin gestionar de cada paquete es de su ámbito, no de la raíz.
        $this->assertCount(1, array_filter($this->report()['unmanaged'], fn (array $item): bool => $item['file'] === 'packages/billing/src/Http/Controllers/BillingController.php'));
        $unmanaged = array_column($this->report()['unmanaged'], 'scope', 'file');
        $this->assertSame('packages/billing', $unmanaged['packages/billing/src/Http/Controllers/BillingController.php']);
        $this->assertSame('packages/crm', $unmanaged['packages/crm/src/Models/CrmItem.php']);
        $this->assertSame('.', $unmanaged['app/Http/Controllers/TagController.php']);

        $this->realign('plan');
        $steps = array_column($this->plan()['steps'], null, 'id');

        foreach (['.', 'packages/billing', 'packages/crm'] as $path) {
            $this->assertArrayHasKey('no-contract@'.$path, $steps, "Falta no-contract en {$path}.");
            $this->assertArrayHasKey('unmanaged-http@'.$path, $steps, "Falta unmanaged-http en {$path}.");
        }

        $shape = $steps['controller-shape@packages/billing/src/Http/Controllers/BillingController.php'];
        $this->assertSame(['package' => 'acme/billing', 'path' => 'packages/billing'], $shape['scope']);
        // Lo guiado de un paquete lo condiciona la decisión de su paquete, no la de la raíz.
        $this->assertContains('unmanaged-http@packages/billing', $shape['depends_on']);
        $this->assertNotContains('unmanaged-http@.', $shape['depends_on']);

        // status: lo que espera al dueño, por ámbito, con sus decisiones.
        $waiting = array_column($this->realign('status')['awaiting_owner'], 'decisions', 'scope');
        $this->assertSame(['unmanaged-http@packages/billing'], $waiting['acme/billing'] ?? null);
        $this->assertSame(['unmanaged-http@packages/crm'], $waiting['acme/crm'] ?? null);

        // El texto de diagnose resume cada ámbito, no sólo el núcleo.
        $this->runCommand('larapack:realign', ['action' => 'diagnose', '--root' => $this->project->path]);
        $this->assertStringContainsString('acme/billing:', $this->lastOutput);
        $this->assertStringContainsString('acme/crm:', $this->lastOutput);

        // --package acota al ámbito del paquete.
        $billing = $this->realign('status', ['--package' => ['billing']]);
        $this->assertSame(['acme/billing'], array_column($billing['awaiting_owner'], 'scope'));

        // infer no-contract@. infiere la raíz; cada paquete, con el suyo.
        $inferred = $this->realign('infer', ['targets' => ['no-contract@.'], '--dry-run' => true]);
        $this->assertSame(['laraimport.inferred.json'], array_column($inferred['fixed'], 'file'));
        $package = $this->realign('infer', ['targets' => ['no-contract@packages/crm'], '--dry-run' => true]);
        $this->assertSame(['packages/crm/laraimport.inferred.json'], array_column($package['fixed'], 'file'));

        // brief y done, por ámbito.
        $brief = $this->realign('brief');
        $this->assertStringContainsString('no-contract@packages/billing', (string) json_encode($brief, JSON_UNESCAPED_SLASHES));
        $done = $this->realign('done', [], Command::FAILURE);
        $this->assertStringContainsString('packages/crm', (string) json_encode($done['missing'], JSON_UNESCAPED_SLASHES));
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function controller(string $namespace, string $class): string
    {
        return "<?php\n\nnamespace {$namespace};\n\nuse Illuminate\\Http\\JsonResponse;\nuse Illuminate\\Http\\Request;\n\nclass {$class}\n{\n    public function update(Request \$request): JsonResponse\n    {\n        \$validated = \$request->validate(['name' => 'required|string']);\n\n        return response()->json(['data' => \$validated]);\n    }\n}\n";
    }
}
