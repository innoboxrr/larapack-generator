<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign adopt`: el manifiesto vuelve a decir la verdad sin que
 * cambie el contenido de un solo archivo. Con un proyecto generado de verdad
 * y desviado como se desvían los reales: formateado después de generar,
 * migraciones renombradas, registros de lo que ya no existe, un archivo
 * escrito a mano en la ruta de uno generado y un manifiesto v1 sin versiones.
 */
final class RealignAdoptTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    private const POLICY = 'src/Policies/PostPolicy.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
    }

    public function test_adopta_lo_formateado_despues_de_generar_sin_tocarlo(): void
    {
        $this->reformat(self::CONTROLLER);
        $before = $this->project->read(self::CONTROLLER);
        $this->prepare();

        $this->assertSame('adopt', $this->step('adopt@.')['type']);

        $simulated = $this->realign('adopt', ['targets' => ['adopt@.'], '--dry-run' => true]);
        $this->assertSame(self::CONTROLLER, $simulated['fixed'][0]['file']);
        $this->assertTrue($this->manifest()->wasCustomised($this->project->path.'/'.self::CONTROLLER), 'simular no escribe el manifiesto');

        $done = $this->realign('adopt', ['targets' => ['adopt@.']]);

        $this->assertSame([self::CONTROLLER], array_values(array_filter(array_column($done['fixed'], 'file'))));
        $this->assertSame($before, $this->project->read(self::CONTROLLER), 'adoptar no cambia el archivo');
        $entry = $this->manifest()->entryOf(self::CONTROLLER);
        $this->assertSame(GeneratorVersion::current(), $entry['generator']);
        // Lo generó el generador: sigue siendo generado, sólo con el hash al día.
        $this->assertSame(Manifest::GENERATED, $entry['state']);
        $this->assertFalse($this->manifest()->wasCustomised($this->project->path.'/'.self::CONTROLLER));

        $this->assertTrue($this->realign('check', ['targets' => ['adopt@.']])['done']);
    }

    public function test_registra_lo_que_el_manifiesto_no_conoce_y_es_lo_regenerado(): void
    {
        $this->forget(self::POLICY);
        $this->prepare();

        $this->realign('adopt', ['targets' => ['adopt@.']]);

        $entry = $this->manifest()->entryOf(self::POLICY);
        $this->assertNotNull($entry);
        $this->assertSame(Manifest::ADOPTED, $entry['state']);
        $this->assertSame('Post', $entry['model']);
        $this->assertSame('Policy/PolicyTemplate.txt', $entry['stub']);
        $this->assertTrue($this->realign('check', ['targets' => ['adopt@.']])['done']);
    }

    public function test_vuelve_a_enlazar_una_migracion_renombrada(): void
    {
        $old = $this->migration('create_posts_table');
        $new = 'database/migrations/2020_01_01_000000_create_posts_table.php';
        rename($this->project->path.'/'.$old, $this->project->path.'/'.$new);
        $this->prepare();

        $this->assertSame('relink', $this->step('relink@.')['type']);
        $this->realign('adopt', ['targets' => ['relink@.']]);

        $this->assertNull($this->manifest()->entryOf($old));
        $this->assertSame('Migration/MigrationTemplate.txt', $this->manifest()->entryOf($new)['stub'] ?? null);
        $this->assertTrue($this->realign('check', ['targets' => ['relink@.']])['done']);
    }

    public function test_olvida_lo_que_ya_no_existe_y_el_contrato_no_pide(): void
    {
        $gone = 'database/migrations/2020_01_01_000000_alter_posts_table.php';
        $this->changeManifest(function (array $manifest) use ($gone): array {
            $manifest['models']['Post']['files'][$gone] = ['stub' => 'Migration/MigrationTemplate.txt', 'hash' => 'x'];

            return $manifest;
        });
        $this->prepare();

        $this->realign('adopt', ['targets' => ['prune@.']]);

        $this->assertNull($this->manifest()->entryOf($gone));
        $this->assertTrue($this->realign('check', ['targets' => ['prune@.']])['done']);
    }

    public function test_un_modelo_retirado_del_contrato_se_olvida_entero(): void
    {
        $this->changeManifest(function (array $manifest): array {
            $manifest['models']['Retired'] = ['namespace' => 'Acme\\Blog\\', 'files' => [
                'src/Models/Retired.php' => ['stub' => 'Model/ModelTemplate.txt', 'hash' => 'x'],
                'src/Policies/RetiredPolicy.php' => ['stub' => 'Policy/PolicyTemplate.txt', 'hash' => 'y'],
            ]];

            return $manifest;
        });
        $this->prepare();

        $this->assertSame('retired', $this->entry('src/Models/Retired.php')['cause'] ?? null);
        $this->realign('adopt', ['targets' => ['prune@.']]);

        // Sin el modelo vacío, verify no lo ve como una entidad a la que le falta todo.
        $this->assertNotContains('Retired', $this->manifest()->models());
        $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--format' => 'json']);
        $this->assertSame([], array_values(array_filter(
            json_decode($this->lastOutput, true)['findings'],
            fn (array $finding): bool => $finding['check'] === 'inconsistent-entity'
        )), $this->lastOutput);
    }

    public function test_lo_escrito_a_mano_se_adopta_como_editado_solo_si_se_decide(): void
    {
        $this->forget(self::POLICY);
        $this->edit(self::POLICY, 'class PostPolicy', "/** Escrita a mano. */\nclass PostPolicy");
        $this->addMethod(self::POLICY, 'public function custom(): bool { return true; }');
        $this->prepare();

        $id = 'untracked-edited@.';
        $this->assertSame('decision', $this->step($id)['category']);

        $undecided = $this->realign('adopt', ['targets' => [$id]], Command::FAILURE);
        $this->assertStringContainsString('falta decidir', $undecided['skipped'][0]['reason']);
        // Sin ids, una decisión sin tomar no entra.
        $this->assertSame('No hay pasos pendientes que `adopt` sepa hacer.', $this->realign('adopt', [], Command::FAILURE)['error']);

        $this->realign('decide', ['targets' => [$id, 'adopt'], '--note' => 'es nuestra']);
        $before = $this->project->read(self::POLICY);
        $this->realign('adopt');

        $this->assertSame($before, $this->project->read(self::POLICY));
        $entry = $this->manifest()->entryOf(self::POLICY);
        $this->assertSame(Manifest::ADOPTED, $entry['state']);
        // El hash es el de lo regenerado: queda personalizado, --force no lo toca.
        $this->assertTrue($this->manifest()->wasCustomised($this->project->path.'/'.self::POLICY));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--force' => true, '--react' => true, '--root' => $this->project->path]));
        $this->assertSame($before, $this->project->read(self::POLICY));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
    }

    public function test_anota_la_version_en_lo_heredado_sin_editar(): void
    {
        // Un manifiesto v1: ningún archivo dice versión.
        $this->changeManifest(function (array $manifest): array {
            $manifest['version'] = 1;

            foreach ($manifest['models'] as $model => $entry) {
                foreach ($entry['files'] as $file => $meta) {
                    unset($manifest['models'][$model]['files'][$file]['generator']);
                }
            }

            foreach ($manifest['package']['files'] as $file => $meta) {
                unset($manifest['package']['files'][$file]['generator']);
            }

            return $manifest;
        });
        $this->reformat(self::CONTROLLER);
        $this->prepare();

        $simulated = $this->realign('adopt', ['targets' => ['adopt@.'], '--dry-run' => true]);
        $this->assertGreaterThan(10, end($simulated['fixed'])['stamped'] ?? 0);
        $this->assertNull($this->manifest()->entryOf(self::POLICY)['generator']);

        $this->realign('adopt', ['targets' => ['adopt@.']]);

        $this->assertSame(GeneratorVersion::current(), $this->manifest()->entryOf(self::POLICY)['generator']);
        $this->assertSame(2, $this->manifest()->formatVersion());
    }

    public function test_salta_lo_que_cambio_despues_del_diagnostico(): void
    {
        $this->reformat(self::CONTROLLER);
        $this->prepare();
        $this->addMethod(self::CONTROLLER, 'public function extra(): void {}');
        $this->commitAll();

        $document = $this->realign('adopt', ['targets' => ['adopt@.']], Command::FAILURE);

        $this->assertStringContainsString('Ya no es igual a lo regenerado', $document['skipped'][0]['reason']);
        $this->assertTrue($this->manifest()->wasCustomised($this->project->path.'/'.self::CONTROLLER));
    }

    private function prepare(): void
    {
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();
    }

    private function manifest(): Manifest
    {
        return new Manifest($this->project->path);
    }

    private function reformat(string $file): void
    {
        file_put_contents($this->project->path.'/'.$file, str_replace('    ', "\t", $this->project->read($file)));
    }

    private function forget(string $file): void
    {
        $this->changeManifest(function (array $manifest) use ($file): array {
            unset($manifest['models']['Post']['files'][$file], $manifest['package']['files'][$file]);

            return $manifest;
        });
    }

    private function addMethod(string $file, string $method): void
    {
        $content = $this->project->read($file);
        file_put_contents($this->project->path.'/'.$file, substr($content, 0, (int) strrpos($content, '}'))."\n    {$method}\n}\n");
    }

    private function migration(string $suffix): string
    {
        $found = glob($this->project->path.'/database/migrations/*_'.$suffix.'.php') ?: [];
        $this->assertCount(1, $found);

        return 'database/migrations/'.basename($found[0]);
    }
}
