<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\StepCheck;
use PHPUnit\Framework\TestCase;

/**
 * StepCheck es la única pieza que da un paso por hecho. Lo que no puede
 * pasar: que un paso que cambia comportamiento quede `done` sin que nadie
 * registrara su prueba, o que uno ya probado pierda su prueba por
 * comprobarlo otra vez.
 */
final class RealignStepCheckTest extends TestCase
{
    private const NOW = '2026-01-02T03:04:05+00:00';

    private const TESTED = ['via' => 'declared', 'command' => 'php artisan test', 'at' => self::NOW];

    public function test_un_paso_que_no_cambia_comportamiento_queda_hecho_al_pasar(): void
    {
        $step = StepCheck::check($this->plain(), true, 0, null, self::NOW);

        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame(['id' => 'adopt@.', 'at' => self::NOW, 'done' => true, 'remaining' => 0], $step['last_check']);
        $this->assertSame('done', $step['history'][0]['event']);
        $this->assertStringStartsWith('Comprobado: ', $step['history'][0]['detail']);
        $this->assertArrayNotHasKey('tested', $step);
    }

    public function test_si_no_pasa_sigue_pendiente_y_no_deja_historia(): void
    {
        $step = StepCheck::check($this->plain(), false, 3, null, self::NOW);

        $this->assertSame(Planner::PENDING, $step['status']);
        $this->assertSame(['id' => 'adopt@.', 'at' => self::NOW, 'done' => false, 'remaining' => 3], $step['last_check']);
        $this->assertArrayNotHasKey('history', $step);
    }

    public function test_el_que_cambia_comportamiento_sin_prueba_queda_untested_y_no_done(): void
    {
        $step = StepCheck::check($this->behaviour(), true, 0, null, self::NOW);

        $this->assertSame(Planner::UNTESTED, $step['status']);
        $this->assertFalse($step['last_check']['done'], 'sin probar no es hecho');
        $this->assertTrue($step['last_check']['untested']);
        $this->assertSame('untested', $step['history'][0]['event']);
        $this->assertArrayNotHasKey('tested', $step);
    }

    public function test_con_su_prueba_queda_hecho_y_la_anota(): void
    {
        $step = StepCheck::check($this->behaviour(), true, 0, self::TESTED, self::NOW);

        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame(self::TESTED, $step['tested']);
        $this->assertTrue($step['last_check']['done']);
        $this->assertArrayNotHasKey('untested', $step['last_check']);
        $this->assertStringContainsString('Probado (lo declara quien lo hizo): php artisan test.', $step['history'][0]['detail']);

        $configured = StepCheck::check($this->behaviour(), true, 0, ['via' => 'config'] + self::TESTED, self::NOW);
        $this->assertStringContainsString('Probado (la orden del proyecto): php artisan test.', $configured['history'][0]['detail']);
    }

    public function test_un_untested_pasa_a_hecho_cuando_llega_la_prueba(): void
    {
        $untested = StepCheck::check($this->behaviour(), true, 0, null, self::NOW);
        $step = StepCheck::check($untested, true, 0, self::TESTED, self::NOW);

        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame(['untested', 'done'], array_column($step['history'], 'event'));
    }

    public function test_un_untested_comprobado_otra_vez_sin_prueba_sigue_igual(): void
    {
        $untested = StepCheck::check($this->behaviour(), true, 0, null, self::NOW);
        $step = StepCheck::check($untested, true, 0, null, '2026-02-02T00:00:00+00:00');

        $this->assertSame(Planner::UNTESTED, $step['status']);
        $this->assertCount(1, $step['history'], 'no repite la historia');
        $this->assertSame('2026-02-02T00:00:00+00:00', $step['last_check']['at']);
    }

    public function test_uno_hecho_y_probado_no_pierde_su_prueba_por_comprobarlo_sin_ella(): void
    {
        $done = StepCheck::check($this->behaviour(), true, 0, self::TESTED, self::NOW);
        $step = StepCheck::check($done, true, 0, null, '2026-02-02T00:00:00+00:00');

        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame(self::TESTED, $step['tested']);
        $this->assertCount(1, $step['history']);
    }

    public function test_uno_hecho_comprobado_otra_vez_no_repite_la_historia(): void
    {
        $done = StepCheck::check($this->plain(), true, 0, null, self::NOW);
        $step = StepCheck::check($done, true, 0, null, self::NOW);

        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertCount(1, $step['history']);
    }

    public function test_una_prueba_nueva_sobre_uno_hecho_se_anota_y_quita_la_aceptacion(): void
    {
        $accepted = StepCheck::acceptUntested(
            StepCheck::check($this->behaviour(), true, 0, null, self::NOW),
            ['note' => 'sin entorno', 'at' => self::NOW, 'via' => 'terminal', 'by' => null]
        );
        $step = StepCheck::check($accepted, true, 0, self::TESTED, self::NOW);

        $this->assertSame(Planner::DONE, $step['status']);
        $this->assertSame(self::TESTED, $step['tested']);
        $this->assertArrayNotHasKey('untested_accepted', $step, 'ya está probado: la aceptación sobra');
        $this->assertSame(['untested', 'accepted-untested', 'done'], array_column($step['history'], 'event'));
    }

    public function test_uno_hecho_que_deja_de_pasar_se_reabre_y_pierde_prueba_y_aceptacion(): void
    {
        foreach ([Planner::DONE, Planner::UNTESTED] as $status) {
            $step = StepCheck::check(
                ['status' => $status, 'tested' => self::TESTED, 'untested_accepted' => ['note' => 'x']] + $this->behaviour(),
                false, 2, null, self::NOW
            );

            $this->assertSame(Planner::PENDING, $step['status'], $status);
            $this->assertArrayNotHasKey('tested', $step);
            $this->assertArrayNotHasKey('untested_accepted', $step);
            $this->assertSame('reopened', $step['history'][0]['event']);
            $this->assertSame(2, $step['last_check']['remaining']);
        }
    }

    /**
     * Quien llama sólo pasa la prueba si el `done_when` pasó; si no, aquí
     * tampoco cuenta.
     */
    public function test_una_prueba_no_cuenta_si_la_comprobacion_no_pasa(): void
    {
        $step = StepCheck::check($this->behaviour(), false, 1, self::TESTED, self::NOW);

        $this->assertSame(Planner::PENDING, $step['status']);
        $this->assertArrayNotHasKey('tested', $step);
        $this->assertArrayNotHasKey('history', $step);
    }

    public function test_la_aceptacion_del_dueno_queda_a_la_vista(): void
    {
        $untested = StepCheck::check($this->behaviour(), true, 0, null, self::NOW);

        $named = StepCheck::acceptUntested($untested, ['note' => 'se prueba en staging', 'at' => self::NOW, 'via' => 'approver', 'by' => 'ana']);
        $this->assertSame(Planner::DONE, $named['status']);
        $this->assertSame('ana', $named['untested_accepted']['by']);
        $this->assertSame('Aceptado sin probar por ana: se prueba en staging', $named['history'][1]['detail']);
        $this->assertSame(self::NOW, $named['history'][1]['at']);

        $terminal = StepCheck::acceptUntested($untested, ['note' => 'n', 'at' => self::NOW, 'via' => 'terminal', 'by' => null]);
        $this->assertSame('Aceptado sin probar (en la terminal del dueño): n', $terminal['history'][1]['detail']);
    }

    /**
     * @return array<string, mixed>
     */
    private function plain(): array
    {
        return ['id' => 'adopt@.', 'type' => 'adopt', 'category' => Category::DETERMINISTIC, 'status' => Planner::PENDING,
            'done_when' => ['kind' => 'classification', 'files' => ['a.php']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function behaviour(): array
    {
        return ['id' => 'controller-shape@a.php', 'type' => 'controller-shape', 'category' => Category::GUIDED, 'status' => Planner::PENDING,
            'done_when' => ['kind' => 'verify', 'checks' => ['controller-shape'], 'files' => ['a.php']]];
    }
}
