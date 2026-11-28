<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Una excepción aprobada cierra el paso de clasificación que exceptúa.
 *
 * En una prueba de autonomía sobre una copia de un proyecto 7.x, el paso
 * `edited-outside:Export@.` agrupaba 60 Exports con un `bindValue` propio
 * fuera de los huecos. Se pidieron las 60 excepciones y el dueño las aprobó,
 * pero `check` seguía fallando en las 60 con «(excepción aprobada); se espera
 * identical o format_only o hole_edited»: el done_when de clasificación miraba
 * la clase del archivo (sigue siendo `edited_outside`, aprobado o no) y no la
 * excepción. El paso no terminaba nunca, ni `realign done`.
 */
final class RealignExceptEditedOutsideTest extends TestCase
{
    use RealignProject;

    private const EXPORT = 'src/Exports/PostsExports.php';

    private const STEP = 'edited-outside:Export@.';

    private const REASON = 'Protección propia contra inyección de fórmulas, revisada por seguridad.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        $this->withoutIgnoredFlags();
        $this->ownerAtTerminal();
        $this->editOutside('/* revisado */');
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
    }

    /**
     * Un método que no está en la plantilla: fuera de los huecos.
     */
    private function editOutside(string $body): void
    {
        $content = $this->project->read(self::EXPORT);
        $content = preg_replace('/\n}\s*$/', "\n    public function sheetTitle(): string\n    {\n        {$body}\n        return 'posts';\n    }\n}\n", $content);
        file_put_contents($this->project->path.'/'.self::EXPORT, $content);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function owner(string $action, array $arguments = []): void
    {
        $exit = $this->runCommand('larapack:hand-rolled', ['action' => $action, '--root' => $this->project->path, '--format' => 'json'] + $arguments);
        $this->assertSame(Command::SUCCESS, $exit, $this->lastOutput);
    }

    public function test_la_excepcion_aprobada_cierra_el_paso_y_caduca_si_el_codigo_cambia(): void
    {
        $this->assertSame([self::EXPORT], array_column($this->step(self::STEP)['details']['files'], 'file'));
        $this->assertFalse($this->realign('check', ['targets' => [self::STEP]], Command::FAILURE)['done']);

        // Se pide y, sin aprobar, el paso sigue sin hacer y dice a quién le toca.
        $this->realign('except', ['targets' => [self::STEP], '--option' => ['reason='.self::REASON]]);
        $check = $this->realign('check', ['targets' => [self::STEP]], Command::FAILURE);
        $this->assertStringContainsString('excepción solicitada y sin aprobar', implode("\n", $check['remaining']));

        // El dueño la aprueba: el paso está hecho.
        $this->owner('approve', ['--all' => true]);
        $check = $this->realign('check', ['targets' => [self::STEP]]);
        $this->assertTrue($check['done'], implode("\n", $check['remaining']));
        $this->assertSame([], $check['remaining']);

        // diagnose y plan no lo vuelven a abrir, y realign done lo cuenta igual.
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::DONE, $this->step(self::STEP)['status']);
        $this->assertTrue($this->realign('check', ['targets' => [self::STEP]])['done']);
        $done = $this->realign('done');
        $this->assertTrue($done['done'], json_encode($done));
        $this->assertSame([], $done['missing']['steps']);

        // Se vuelve a editar: la huella caduca y el paso se reabre.
        $this->editOutside('/* revisado otra vez */ $sheet = \'posts\';');
        $this->commitAll();
        $check = $this->realign('check', ['targets' => [self::STEP]], Command::FAILURE);
        $this->assertFalse($check['done']);
        $this->assertStringContainsString(self::EXPORT, implode("\n", $check['remaining']));

        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::PENDING, $this->step(self::STEP)['status']);
        $done = $this->realign('done', [], Command::FAILURE);
        $this->assertContains(self::STEP, array_column($done['missing']['steps'], 'id'));
    }

    public function test_dejar_borrado_un_archivo_generado_con_su_excepcion_tambien_cierra_el_paso(): void
    {
        // El otro done_when de clasificación exceptuable (`resolved`): un
        // archivo generado borrado adrede.
        unlink($this->project->path.'/'.self::EXPORT);
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');

        $missing = array_values(array_filter($this->plan()['steps'], fn (array $step): bool => $step['type'] === 'missing'));
        $this->assertCount(1, $missing, json_encode($this->plan()['steps']));
        $id = $missing[0]['id'];

        $this->realign('decide', ['targets' => [$id, 'forget'], '--note' => 'Este paquete no exporta.']);
        $this->realign('except', ['targets' => [$id], '--option' => ['reason=Este paquete no exporta.']]);
        $this->assertFalse($this->realign('check', ['targets' => [$id]], Command::FAILURE)['done']);

        $this->owner('approve', ['--all' => true]);
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::DONE, $this->step($id)['status']);
    }
}
