<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class IdempotencyTest extends TestCase
{
    private const MODEL = 'src/Models/Product.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());
    }

    private function generate(array $options = []): int
    {
        return $this->runCommand('larapack:model', ['name' => 'Product'] + $options);
    }

    public function test_registra_en_el_manifiesto_lo_que_genera(): void
    {
        $this->generate();

        $this->assertGenerated('.larapack/manifest.json');

        $manifest = json_decode($this->project->read('.larapack/manifest.json'), true);

        $this->assertSame(1, $manifest['version']);
        $this->assertArrayHasKey('Product', $manifest['models']);
        $this->assertSame('TestVendor\\TestPkg\\', $manifest['models']['Product']['namespace']);

        $file = $manifest['models']['Product']['files'][self::MODEL];

        $this->assertSame('Model/ModelTemplate.txt', $file['stub']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $file['hash']);
    }

    /**
     * Antes cada tool abortaba con file_exists y devolvia false en silencio:
     * editar el JSON y reimportar no propagaba nada, y nadie lo decia.
     */
    public function test_sin_force_no_toca_lo_que_ya_existe(): void
    {
        $this->generate();

        file_put_contents($this->project->path . '/' . self::MODEL, '<?php // mio');

        $this->generate();

        $this->assertSame('<?php // mio', $this->project->read(self::MODEL));
        $this->assertStringContainsString('omitido', $this->lastOutput);
    }

    public function test_force_regenera_lo_que_no_se_ha_tocado(): void
    {
        $this->generate();

        $original = $this->project->read(self::MODEL);

        // Se borra el contenido conservando el hash registrado seria trampa;
        // se regenera tal cual, que es el caso real tras cambiar un stub.
        $this->assertSame(Command::SUCCESS, $this->generate(['--force' => true]));

        $this->assertSame($original, $this->project->read(self::MODEL));
        $this->assertStringContainsString('regenerado', $this->lastOutput);
    }

    /**
     * El motivo de que exista el manifiesto: distinguir lo generado de lo
     * escrito a mano para poder regenerar sin destruir trabajo.
     */
    public function test_force_no_sobrescribe_lo_editado_a_mano(): void
    {
        $this->generate();

        $mio = "<?php\n// logica de negocio que escribi yo\n";

        file_put_contents($this->project->path . '/' . self::MODEL, $mio);

        $this->generate(['--force' => true]);

        $this->assertSame($mio, $this->project->read(self::MODEL));
        $this->assertStringContainsString('conservado', $this->lastOutput);
        $this->assertStringContainsString('editado a mano', $this->lastOutput);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->generate(['--dry-run' => true]);

        $this->assertFalse($this->project->has(self::MODEL));
        $this->assertFalse($this->project->has('.larapack/manifest.json'));
        $this->assertStringContainsString('Simulacion', $this->lastOutput);
        $this->assertStringContainsString(self::MODEL, str_replace('\\', '/', $this->lastOutput));
    }

    public function test_informa_en_json_para_que_lo_lea_un_agente(): void
    {
        $this->generate(['--format' => 'json']);

        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, "La salida no era JSON:\n" . $this->lastOutput);
        $this->assertFalse($report['dryRun']);
        $this->assertSame(1, $report['summary']['create']);

        $file = $report['files'][0];

        $this->assertSame('create', $file['action']);
        $this->assertSame(self::MODEL, str_replace('\\', '/', $file['file']));
        $this->assertSame('Model/ModelTemplate.txt', $file['stub']);
    }

    public function test_el_manifiesto_distingue_generado_de_propio(): void
    {
        $this->generate();

        $manifest = new Manifest($this->project->path);

        $generado = $this->project->path . '/' . self::MODEL;

        $this->assertFalse($manifest->wasCustomised($generado));

        file_put_contents($generado, '<?php // tocado');

        $this->assertTrue($manifest->wasCustomised($generado));

        // Un archivo que el generador nunca escribio es del proyecto: no se
        // toca aunque venga --force.
        $ajeno = $this->project->path . '/src/Models/Otro.php';

        file_put_contents($ajeno, '<?php');

        $this->assertTrue($manifest->wasCustomised($ajeno));
    }
}
