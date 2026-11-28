<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Las excepciones de un hallazgo sin archivo —el de una entidad,
 * `realign:inconsistent-entity:model:Tag#…`— se aprueban con el hallazgo de
 * hoy, no con lo que dice la solicitud.
 *
 * Hasta 9.4.0 `approve` tomaba la huella del archivo de solicitudes (que
 * escribe quien pide, también un agente) sin comprobar que verify dijera hoy
 * eso, y `list` las daba siempre por aprobadas: se podía aprobar a ciegas una
 * frase que verify nunca dijo y que, el día que la dijera, ya estaría
 * cubierta.
 */
final class RealignStatementApprovalTest extends TestCase
{
    use RealignProject;

    private const REASON = 'Tag es un catálogo interno: no tiene API ni factoría.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));
        $this->runCommand('larapack:full-model', ['name' => 'Product']);
        $this->runCommand('larapack:full-model', ['name' => 'Category']);
        // Tag, generado a medias: verify dice de él lo que le falta.
        $this->runCommand('larapack:model', ['name' => 'Tag']);
        $this->git('init', '--quiet');
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function owner(string $action, array $arguments = [], int $expected = Command::SUCCESS): array
    {
        $exit = $this->runCommand('larapack:hand-rolled', ['action' => $action, '--root' => $this->project->path, '--format' => 'json'] + $arguments);
        $this->assertSame($expected, $exit, $this->lastOutput);

        return json_decode($this->lastOutput, true);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return array_column(array_filter($this->owner('list')['exceptions'], fn (array $row): bool => $row['realign'] ?? false), 'status', 'target');
    }

    private function factoryTarget(): string
    {
        return ExceptionTargets::forStatement('inconsistent-entity', null, 'Tag', 'No tiene componente Factory y todas las demás entidades sí.');
    }

    public function test_la_solicitud_guarda_el_hallazgo_y_el_dueno_lo_ve_al_aprobar(): void
    {
        $this->realign('except', ['targets' => ['inconsistent-entity@.'], '--option' => ['reason='.self::REASON]]);

        $requests = Exceptions::forRoot($this->project->path)->requests();
        $this->assertSame('No tiene componente Factory y todas las demás entidades sí.', $requests[$this->factoryTarget()]['message']);

        $terminal = $this->ownerAtTerminal();
        $approved = $this->owner('approve', ['targets' => [$this->factoryTarget()]]);

        $this->assertSame([$this->factoryTarget()], array_column($approved['targets'], 'target'));
        $this->assertStringContainsString('cubre: el hallazgo de verify sobre Tag', $terminal->shown);
        $this->assertStringContainsString('hallazgo: «No tiene componente Factory y todas las demás entidades sí.»', $terminal->shown);
        $this->assertStringContainsString('razón: «'.self::REASON.'»', $terminal->shown);
        $this->assertStringContainsString('escribe «apruebo 1»', $terminal->shown);

        $lock = HandRolledLock::read($this->project->path);
        $this->assertSame(ExceptionTargets::statementFingerprint('No tiene componente Factory y todas las demás entidades sí.'), $lock[$this->factoryTarget()]['fingerprint']);

        $statuses = $this->statuses();
        $this->assertSame(Exceptions::APPROVED, $statuses[$this->factoryTarget()]);
        $this->assertContains(Exceptions::UNAPPROVED, $statuses);
    }

    /**
     * Lo que escribe un agente en el archivo de solicitudes —una frase que
     * verify no dice hoy— no se aprueba.
     */
    public function test_no_se_aprueba_a_ciegas_lo_que_verify_no_dice_hoy(): void
    {
        $invented = ExceptionTargets::forStatement('inconsistent-entity', null, 'Product', 'No tiene componente Policy y todas las demás entidades sí.');
        @mkdir($this->project->path.'/.larapack/realign', 0777, true);
        file_put_contents($this->project->path.'/.larapack/realign/exceptions.json', (string) json_encode(['format_version' => 1, 'requests' => [[
            'target' => $invented, 'type' => 'inconsistent-entity', 'check' => null, 'scope' => '.', 'file' => 'model:Product',
            'subject' => null, 'line' => null, 'step' => 'inconsistent-entity@.', 'message' => 'No tiene componente Policy y todas las demás entidades sí.',
            'reason' => self::REASON, 'fingerprint' => ExceptionTargets::statementFingerprint('No tiene componente Policy y todas las demás entidades sí.'),
            'requested_at' => '2026-11-28T00:00:00+00:00',
        ]]]));

        $this->ownerAtTerminal();
        $refused = $this->owner('approve', ['targets' => [$invented]], Command::FAILURE);
        $this->assertStringContainsString('no es una excepción', $refused['error']);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);

        // Y --all no la cuela: no hay nada que aprobar.
        $all = $this->owner('approve', ['--all' => true]);
        $this->assertSame([], $all['targets']);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);
    }

    /**
     * Antes `list` daba por aprobada cualquier excepción de una entidad. Ahora
     * su estado es el de su hallazgo de hoy: aprobada mientras verify lo diga,
     * sobrante cuando ya no.
     */
    public function test_el_estado_de_una_entidad_es_el_de_su_hallazgo_de_hoy(): void
    {
        $this->realign('except', ['targets' => ['inconsistent-entity@.'], '--option' => ['reason='.self::REASON]]);
        $this->ownerAtTerminal();
        $this->owner('approve', ['targets' => [$this->factoryTarget()]]);
        $this->assertSame(Exceptions::APPROVED, $this->statuses()[$this->factoryTarget()]);

        // Una aprobación escrita a mano con otra huella ya no es la de hoy.
        $lock = HandRolledLock::read($this->project->path);
        $lock[$this->factoryTarget()]['fingerprint'] = 'sha256:'.str_repeat('0', 64);
        HandRolledLock::write($this->project->path, $lock);
        $this->assertSame(Exceptions::CODE_CHANGED, $this->statuses()[$this->factoryTarget()]);

        // Tag, completo: verify ya no dice nada de él y la aprobación sobra.
        $this->runCommand('larapack:full-model', ['name' => 'Tag']);
        $this->assertSame(Exceptions::STALE, $this->statuses()[$this->factoryTarget()]);
    }

    public function test_sin_confirmar_en_la_terminal_no_se_aprueba(): void
    {
        $this->realign('except', ['targets' => ['inconsistent-entity@.'], '--option' => ['reason='.self::REASON]]);

        $this->ownerAtTerminal('apruebo');
        $refused = $this->owner('approve', ['--all' => true], Command::FAILURE);

        $this->assertStringContainsString('No se confirmó', $refused['error']);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);
    }
}
