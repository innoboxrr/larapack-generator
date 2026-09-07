<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\ExposedTool;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use PHPUnit\Framework\TestCase;

final class GenerateFromStubTest extends TestCase
{
    private FakeProject $project;

    private ExposedTool $tool;

    private string $stub;

    protected function setUp(): void
    {
        $this->project = FakeProject::library();

        ProjectRoot::set($this->project->path);

        $this->tool = new ExposedTool();
        $this->tool->prepare('Invoice');

        $this->stub = $this->project->path . '/stub.txt';

        file_put_contents($this->stub, "<?php\n// PascalCaseModelName en Namespace\Models\n");
    }

    protected function tearDown(): void
    {
        ProjectRoot::set(null);
        $this->project->cleanup();
    }

    public function test_copia_el_stub_y_sustituye_los_tokens(): void
    {
        $destination = $this->project->path . '/out/Invoice.php';

        $this->assertTrue($this->tool->generateFrom($this->stub, $destination));

        $this->assertStringContainsString('// Invoice en TestVendor\TestPkg\Models', file_get_contents($destination));
    }

    public function test_crea_los_directorios_intermedios(): void
    {
        $destination = $this->project->path . '/a/b/c/Invoice.php';

        $this->tool->generateFrom($this->stub, $destination);

        $this->assertFileExists($destination);
    }

    public function test_no_sobrescribe_un_destino_existente(): void
    {
        $destination = $this->project->path . '/Invoice.php';

        file_put_contents($destination, 'contenido a mano');

        $this->assertFalse($this->tool->generateFrom($this->stub, $destination));
        $this->assertSame('contenido a mano', file_get_contents($destination));
    }

    /**
     * Antes se lanzaba `new MakerException` sin mensaje, asi que un stub
     * ausente o un destino no escribible daban un error mudo.
     */
    public function test_dice_que_plantilla_falta_cuando_no_existe(): void
    {
        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('inexistente.txt');

        $this->tool->generateFrom(
            $this->project->path . '/inexistente.txt',
            $this->project->path . '/out/Invoice.php'
        );
    }
}
