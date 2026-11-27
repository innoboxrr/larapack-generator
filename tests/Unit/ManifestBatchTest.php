<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use RuntimeException;

/**
 * Lo que Manifest::batch() garantiza: dentro de una corrida se lee y se anota
 * en memoria, se escribe una vez al salir —también si algo lanza a mitad— y
 * la escritura es atómica, con reintento acotado ante un bloqueo.
 */
final class ManifestBatchTest extends TestCase
{
    private string $stub = '/x/src/Stubs/Model/ModelTemplate.txt';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());
    }

    protected function tearDown(): void
    {
        Manifest::moveUsing(null);

        parent::tearDown();
    }

    private function file(string $relative): string
    {
        $path = $this->project->path.'/'.$relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php // {$relative}\n");

        return $path;
    }

    public function test_dentro_de_una_corrida_se_anota_en_memoria_y_se_escribe_al_salir(): void
    {
        $manifest = new Manifest;
        $before = Manifest::diskWrites();

        Manifest::batch(function () use ($manifest): void {
            foreach (['A', 'B', 'C'] as $model) {
                $manifest->record($model, 'App\\', $this->file("src/Models/{$model}.php"), $this->stub);
            }

            // Todavía no hay nada en disco, pero otra instancia ya lo ve.
            $this->assertFileDoesNotExist($manifest->path());
            $this->assertSame(['A', 'B', 'C'], (new Manifest)->models());
            $this->assertTrue((new Manifest)->knows($this->project->path.'/src/Models/B.php'));
        });

        $this->assertSame(1, Manifest::diskWrites() - $before);
        $this->assertSame(['A', 'B', 'C'], array_keys(json_decode((string) file_get_contents($manifest->path()), true)['models']));
    }

    public function test_un_fallo_a_mitad_conserva_lo_registrado_hasta_entonces(): void
    {
        $manifest = new Manifest;

        try {
            Manifest::batch(function () use ($manifest): void {
                $manifest->record('A', 'App\\', $this->file('src/Models/A.php'), $this->stub);
                $manifest->record('B', 'App\\', $this->file('src/Models/B.php'), $this->stub);

                throw new RuntimeException('el modelo C no se pudo generar');
            });
            $this->fail('La excepción tenía que salir de la corrida.');
        } catch (RuntimeException $e) {
            $this->assertSame('el modelo C no se pudo generar', $e->getMessage());
        }

        $written = json_decode((string) file_get_contents($manifest->path()), true);

        $this->assertSame(['A', 'B'], array_keys($written['models']));
    }

    public function test_las_corridas_anidadas_escriben_una_vez_al_salir_de_la_exterior(): void
    {
        $manifest = new Manifest;
        $before = Manifest::diskWrites();

        Manifest::batch(function () use ($manifest, $before): void {
            Manifest::batch(fn () => $manifest->record('A', 'App\\', $this->file('src/Models/A.php'), $this->stub));
            Manifest::batch(fn () => $manifest->record('B', 'App\\', $this->file('src/Models/B.php'), $this->stub));

            $this->assertSame($before, Manifest::diskWrites());
        });

        $this->assertSame(1, Manifest::diskWrites() - $before);
    }

    public function test_sin_cambios_no_se_escribe(): void
    {
        $before = Manifest::diskWrites();

        Manifest::batch(fn () => (new Manifest)->models());

        $this->assertSame(0, Manifest::diskWrites() - $before);
        $this->assertFileDoesNotExist((new Manifest)->path());
    }

    public function test_un_bloqueo_pasajero_se_reintenta(): void
    {
        $attempts = 0;

        Manifest::moveUsing(function (string $from, string $to) use (&$attempts): bool {
            // Lo que hace Windows mientras otro proceso tiene el archivo
            // abierto: el rename falla con «Permission denied».
            return ++$attempts < 3 ? false : rename($from, $to);
        });

        (new Manifest)->record('A', 'App\\', $this->file('src/Models/A.php'), $this->stub);

        $this->assertSame(3, $attempts);
        $this->assertSame(['A'], (new Manifest)->models());
        $this->assertSame([], glob(dirname((new Manifest)->path()).'/*.tmp') ?: []);
    }

    public function test_un_bloqueo_que_no_cede_falla_y_deja_lo_registrado_en_un_archivo_aparte(): void
    {
        $manifest = new Manifest;
        $manifest->record('A', 'App\\', $this->file('src/Models/A.php'), $this->stub);
        $original = (string) file_get_contents($manifest->path());

        Manifest::moveUsing(fn (): bool => false);

        try {
            $manifest->record('B', 'App\\', $this->file('src/Models/B.php'), $this->stub);
            $this->fail('Tenía que fallar.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('.larapack/manifest.json', $e->getMessage());
            $message = $e->getMessage();
        }

        // Lo de antes queda intacto: nunca un manifiesto a medio escribir.
        $this->assertSame($original, (string) file_get_contents($manifest->path()));

        // Y lo registrado no se pierde: queda en un archivo aparte que el
        // mensaje nombra, para moverlo a mano cuando se libere el bloqueo.
        $pending = glob(dirname($manifest->path()).'/*.tmp') ?: [];
        $this->assertCount(1, $pending);
        $this->assertStringContainsString(basename($pending[0]), $message);
        $this->assertSame(['A', 'B'], array_keys(json_decode((string) file_get_contents($pending[0]), true)['models']));
    }
}
