<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que el realineamiento da por «formato» se adopta sin preguntar, y lo
 * adoptado lo vuelve a escribir el siguiente `import --force`. Si una
 * edición pasa por formato, se pierde. De punta a punta, con el ciclo que
 * hace cualquiera: diagnose, plan, apply --deterministic e import --force.
 *
 * El controlador con tabuladores es formato de verdad y da al menos un paso
 * determinista, para que apply --deterministic haga algo.
 */
final class RealignFormatTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    private const SHOW_VIEW = 'resources/react/src/models/post/views/ShowView.jsx';

    private const INDEX_REQUEST = 'src/Http/Requests/Post/IndexRequest.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        file_put_contents($this->project->path.'/'.self::CONTROLLER, str_replace('    ', "\t", $this->project->read(self::CONTROLLER)));
    }

    public function test_una_edicion_de_espacios_dentro_de_una_cadena_jsx_sobrevive_al_ciclo(): void
    {
        // Sólo cambia un espacio, pero dentro de una cadena: es otro texto.
        $this->edit(self::SHOW_VIEW, "t('Changes saved')", "t('Changessaved')");

        $this->realign('diagnose');

        $this->assertNotSame(FileClass::FORMAT_ONLY, $this->entry(self::SHOW_VIEW)['class'] ?? null);
        $this->assertSame(FileClass::FORMAT_ONLY, $this->entry(self::CONTROLLER)['class'] ?? null);

        $this->cycle();

        $this->assertStringContainsString("t('Changessaved')", $this->project->read(self::SHOW_VIEW));
    }

    public function test_la_indentacion_y_los_finales_de_linea_de_un_jsx_siguen_siendo_formato(): void
    {
        $content = $this->project->read(self::SHOW_VIEW);
        file_put_contents($this->project->path.'/'.self::SHOW_VIEW, str_replace(['    ', "\n"], ["\t", "  \r\n"], $content));

        $this->realign('diagnose');

        $this->assertSame(FileClass::FORMAT_ONLY, $this->entry(self::SHOW_VIEW)['class'] ?? null);
        $this->assertSame('adopt', $this->entry(self::SHOW_VIEW)['type'] ?? null);
    }

    public function test_los_comentarios_del_usuario_en_un_php_sobreviven_al_ciclo(): void
    {
        $this->edit(self::INDEX_REQUEST, 'protected function prepareForValidation', "// Lo dejamos así a propósito.\n    protected function prepareForValidation");

        $this->realign('diagnose');
        $entry = $this->entry(self::INDEX_REQUEST);

        // Cumple y se conserva como editado: ni adopción ni paso.
        $this->assertNull($entry);
        $this->assertSame(1, $this->report()['totals']['classes'][FileClass::HOLE_EDITED] ?? null);

        $this->cycle();

        $this->assertStringContainsString('// Lo dejamos así a propósito.', $this->project->read(self::INDEX_REQUEST));
    }

    private function cycle(): void
    {
        $this->realign('plan');
        $this->commitAll();
        $this->realign('apply', ['--deterministic' => true]);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--react' => true, '--force' => true, '--format' => 'json']), $this->lastOutput);
    }
}
