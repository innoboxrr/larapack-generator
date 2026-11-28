<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cómo se lee un plan: los pasos por id y por ámbito, qué detiene a cada
 * uno y cuál toca. Lo usan todas las acciones; antes cada una lo resolvía
 * por su cuenta.
 */
final class RealignPlanReadingTest extends TestCase
{
    public function test_los_pasos_por_id_y_por_ambito(): void
    {
        $plan = ['steps' => [
            $this->step('adopt@.', 'adopt'),
            $this->step('fix@packages/blog', 'fix', ['scope' => ['path' => 'packages/blog', 'package' => 'acme/blog']]),
            'basura',
            ['sin' => 'id'],
        ]];

        $this->assertSame(['adopt@.', 'fix@packages/blog'], array_keys(Planner::byId($plan)));
        $this->assertSame([], Planner::byId(null));

        $this->assertCount(3, Planner::stepsIn($plan), 'todos los que son un paso, en su orden');
        $this->assertSame(['fix@packages/blog'], array_column(Planner::stepsIn($plan, ['packages/blog']), 'id'));
        $this->assertSame(['adopt@.'], array_column(Planner::stepsIn($plan, ['.']), 'id'));
        $this->assertSame([], Planner::stepsIn(null, ['.']));

        $this->assertSame(1, Planner::indexOf($plan, 'fix@packages/blog'));
    }

    public function test_un_id_que_no_esta_en_el_plan_es_un_error_que_dice_donde_mirar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay un paso nada@. en el plan.');

        Planner::indexOf(['steps' => [$this->step('adopt@.', 'adopt')]], 'nada@.');
    }

    public function test_la_opcion_de_una_decision_por_su_clave(): void
    {
        $step = ['options' => [['key' => 'keep', 'label' => 'Dejar'], 'basura', ['key' => 'declare', 'run' => 'x']]];

        $this->assertSame(['key' => 'declare', 'run' => 'x'], Planner::option($step, 'declare'));
        $this->assertNull(Planner::option($step, 'otra'));
        $this->assertNull(Planner::option([], 'keep'));
    }

    public function test_lo_que_detiene_a_un_paso_son_sus_dependencias_sin_hacer(): void
    {
        $adopt = $this->step('adopt@.', 'adopt');
        $fix = $this->step('fix@.', 'fix', ['status' => Planner::DONE]);
        $untested = $this->step('security@.', 'security', ['status' => Planner::UNTESTED]);
        $guided = $this->step('controller-shape@a.php', 'controller-shape', ['category' => Category::GUIDED,
            'depends_on' => ['adopt@.', 'fix@.', 'security@.', 'no-existe@.']]);
        $byId = Planner::byId(['steps' => [$adopt, $fix, $untested, $guided]]);

        // Lo hecho y lo hecho sin probar ya no detienen; lo que no está en el plan, tampoco.
        $this->assertSame(['adopt@.'], Planner::blockedBy($guided, $byId));
        $this->assertSame(Planner::BLOCKED, Planner::effectiveStatus($guided, $byId));

        $byId['adopt@.']['status'] = Planner::DONE;
        $this->assertSame([], Planner::blockedBy($guided, $byId));
        $this->assertSame(Planner::PENDING, Planner::effectiveStatus($guided, $byId));

        // A uno hecho no lo detiene nada, dependa de lo que dependa.
        $this->assertSame([], Planner::blockedBy(['status' => Planner::DONE] + $guided, Planner::byId(['steps' => [$adopt]])));
    }

    public function test_una_decision_que_condiciona_lo_guiado_deja_de_detenerlo_al_decidir_seguir(): void
    {
        $decision = $this->step('unmanaged-http@.', 'unmanaged-http', ['category' => Category::DECISION, 'files' => ['a.php', 'b.php'],
            'options' => [['key' => 'keep'], ['key' => 'declare']]]);
        $guided = $this->step('controller-shape@a.php', 'controller-shape', ['category' => Category::GUIDED, 'files' => ['a.php'], 'depends_on' => ['unmanaged-http@.']]);

        $this->assertSame(['unmanaged-http@.'], Planner::blockedBy($guided, ['unmanaged-http@.' => $decision]), 'sin decidir');

        $keep = ['decisions' => ['a.php' => ['option' => 'keep']]] + $decision;
        $this->assertSame([], Planner::blockedBy($guided, ['unmanaged-http@.' => $keep]), 'se deja como código propio: lo guiado sigue valiendo');

        $declare = ['decisions' => ['a.php' => ['option' => 'declare']]] + $decision;
        $this->assertSame(['unmanaged-http@.'], Planner::blockedBy($guided, ['unmanaged-http@.' => $declare]), 'se declara: lo regenera el contrato');
    }

    public function test_el_siguiente_es_el_primero_que_se_puede_hacer(): void
    {
        $steps = [
            $this->step('adopt@.', 'adopt', ['status' => Planner::DONE]),
            $this->step('security@.', 'security', ['status' => Planner::UNTESTED]),
            $this->step('fix@.', 'fix', ['deferred' => ['note' => 'luego', 'at' => 'x']]),
            $this->step('model-lists@.', 'model-lists', ['depends_on' => ['fix@.']]),
            $this->step('no-contract@.', 'no-contract', ['category' => Category::DECISION, 'options' => [['key' => 'infer']]]),
            $this->step('regenerate@.', 'regenerate'),
        ];
        $byId = Planner::byId(['steps' => $steps]);

        // Hecho, sin probar, aplazado y bloqueado se saltan; una decisión sin tomar se da.
        $this->assertSame('no-contract@.', Navigation::next($steps, $byId)['id'] ?? null);
        // Mientras el dueño decide, lo primero que no es suyo.
        $this->assertSame('regenerate@.', Navigation::meanwhile($steps, $byId)['id'] ?? null);

        $this->assertNull(Navigation::next(array_slice($steps, 0, 4), $byId));
        $this->assertNull(Navigation::meanwhile(array_slice($steps, 0, 5), $byId));
        $this->assertNull(Navigation::next([], []));
    }

    public function test_una_decision_ya_ejecutada_no_se_vuelve_a_dar(): void
    {
        $decision = $this->step('route-closure@.', 'route-closure', ['category' => Category::DECISION, 'files' => ['routes/web.php'],
            'options' => [['key' => 'except', 'run' => 'larapack:realign except route-closure@. --option reason="…"']],
            'decision' => ['option' => 'except'],
            'history' => [['event' => 'decided', 'at' => '1'], ['event' => 'applied', 'at' => '2']]]);
        $pending = $this->step('regenerate@.', 'regenerate');

        $this->assertSame('regenerate@.', Navigation::next([$decision, $pending], Planner::byId(['steps' => [$decision, $pending]]))['id'] ?? null);
    }

    public function test_lo_que_espera_al_dueno_por_ambito(): void
    {
        $decision = $this->step('unmanaged-http@packages/blog', 'unmanaged-http', ['category' => Category::DECISION, 'files' => ['a.php', 'b.php']]);
        $scope = ['scope' => ['path' => 'packages/blog', 'package' => 'acme/blog']];
        $guided = fn (string $file, array $more = []): array => $this->step('controller-shape@'.$file, 'controller-shape',
            $more + ['category' => Category::GUIDED, 'files' => [$file], 'depends_on' => ['unmanaged-http@packages/blog']] + $scope);
        $steps = [$decision, $guided('b.php'), $guided('a.php'), $guided('c.php'), $guided('d.php', ['files' => ['a.php'], 'deferred' => ['note' => 'x']])];

        $this->assertSame(
            [['scope' => 'acme/blog', 'package' => 'acme/blog', 'decisions' => ['unmanaged-http@packages/blog'], 'guided' => ['controller-shape@a.php', 'controller-shape@b.php']]],
            Navigation::awaitingOwner($steps, Planner::byId(['steps' => $steps])),
            'ni el que no comparte archivos con la decisión ni el aplazado'
        );

        // Decidido por elemento: ése ya no espera.
        $steps[0]['decisions'] = ['a.php' => ['option' => 'keep']];
        $this->assertSame(['controller-shape@b.php'], Navigation::awaitingOwner($steps, Planner::byId(['steps' => $steps]))[0]['guided']);

        // Decidido con una opción que no deja seguir («declararlos»): lo guiado
        // espera a que la decisión se cumpla, no al dueño.
        $steps[0]['decisions'] = ['a.php' => ['option' => 'declare'], 'b.php' => ['option' => 'declare']];
        $byId = Planner::byId(['steps' => $steps]);
        $this->assertSame(['unmanaged-http@packages/blog'], Planner::blockedBy($steps[2], $byId));
        $this->assertSame([], Navigation::awaitingOwner($steps, $byId));

        // Lo que no es guiado no cuenta, ni lo que espera a un paso que no es una decisión del dueño.
        $adopt = $this->step('adopt@.', 'adopt', ['files' => ['a.php']]);
        $waiting = [
            $adopt,
            $this->step('unmanaged-http@.', 'unmanaged-http', ['category' => Category::DECISION, 'files' => ['a.php']]),
            $this->step('regenerate@.', 'regenerate', ['files' => ['a.php'], 'depends_on' => ['unmanaged-http@.']]),
            $this->step('security@.', 'security', ['category' => Category::GUIDED, 'files' => ['a.php'], 'depends_on' => ['adopt@.']]),
        ];
        $this->assertSame([], Navigation::awaitingOwner($waiting, Planner::byId(['steps' => $waiting])));

        // Y si además espera una decisión, ésa sí.
        $waiting[3]['depends_on'] = ['adopt@.', 'unmanaged-http@.'];
        $this->assertSame(['unmanaged-http@.'], Navigation::awaitingOwner($waiting, Planner::byId(['steps' => $waiting]))[0]['decisions'] ?? null);

        // Varias decisiones y varios guiados salen ordenados y sin repetir.
        $many = [
            $this->step('unmanaged-models@.', 'unmanaged-models', ['category' => Category::DECISION, 'files' => ['a.php', 'b.php']]),
            $this->step('hand-rolled@.', 'hand-rolled', ['category' => Category::DECISION, 'files' => ['a.php', 'b.php']]),
            $this->step('z@b.php', 'controller-shape', ['category' => Category::GUIDED, 'files' => ['b.php'], 'depends_on' => ['unmanaged-models@.', 'hand-rolled@.']]),
            $this->step('a@a.php', 'controller-shape', ['category' => Category::GUIDED, 'files' => ['a.php'], 'depends_on' => ['unmanaged-models@.', 'hand-rolled@.']]),
        ];
        $this->assertSame(
            [['scope' => 'app', 'package' => null, 'decisions' => ['hand-rolled@.', 'unmanaged-models@.'], 'guided' => ['a@a.php', 'z@b.php']]],
            Navigation::awaitingOwner($many, Planner::byId(['steps' => $many]))
        );

        // El núcleo se llama `app`.
        $core = [$this->step('hand-rolled@.', 'hand-rolled', ['category' => Category::DECISION, 'files' => ['a.php']]),
            $this->step('controller-shape@a.php', 'controller-shape', ['category' => Category::GUIDED, 'files' => ['a.php'], 'depends_on' => ['hand-rolled@.']])];
        $this->assertSame(['scope' => 'app', 'package' => null], array_slice(Navigation::awaitingOwner($core, Planner::byId(['steps' => $core]))[0], 0, 2));
    }

    public function test_lo_que_esta_sin_probar_con_sus_dos_ordenes(): void
    {
        $steps = [
            $this->step('adopt@.', 'adopt', ['status' => Planner::DONE]),
            $this->step('security@.', 'security', ['status' => Planner::UNTESTED, 'title' => 'Seguridad', 'scope' => ['path' => 'packages/blog', 'package' => 'acme/blog'],
                // La hora es la de cuando quedó sin probar, no la del último evento.
                'history' => ['basura', ['event' => 'untested', 'at' => '1'], ['event' => 'resolved', 'at' => '3'], ['event' => 'applied', 'at' => '4']]]),
            $this->step('declared-routes@.', 'declared-routes', ['status' => Planner::UNTESTED, 'history' => [['event' => 'applied', 'at' => '9']]]),
            // Un plan editado a mano: la historia no es una lista, la hora es un número.
            $this->step('broken@.', 'broken', ['status' => Planner::UNTESTED, 'history' => 'roto']),
            $this->step('numeric@.', 'numeric', ['status' => Planner::UNTESTED, 'history' => [['event' => 'untested', 'at' => 5]]]),
        ];

        $this->assertSame([
            ['id' => 'security@.', 'title' => 'Seguridad', 'scope' => 'packages/blog', 'at' => '3',
                'check' => 'larapack:realign check security@. --tested="<orden>"', 'accept' => 'larapack:realign accept-untested security@. --note="por qué"'],
            ['id' => 'declared-routes@.', 'title' => 'declared-routes', 'scope' => '.', 'at' => null,
                'check' => 'larapack:realign check declared-routes@. --tested="<orden>"', 'accept' => 'larapack:realign accept-untested declared-routes@. --note="por qué"'],
            ['id' => 'broken@.', 'title' => 'broken', 'scope' => '.', 'at' => null,
                'check' => 'larapack:realign check broken@. --tested="<orden>"', 'accept' => 'larapack:realign accept-untested broken@. --note="por qué"'],
            ['id' => 'numeric@.', 'title' => 'numeric', 'scope' => '.', 'at' => '5',
                'check' => 'larapack:realign check numeric@. --tested="<orden>"', 'accept' => 'larapack:realign accept-untested numeric@. --note="por qué"'],
        ], Navigation::untested($steps));
    }

    /**
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function step(string $id, string $type, array $more = []): array
    {
        return $more + ['id' => $id, 'type' => $type, 'title' => $type, 'category' => Category::DETERMINISTIC, 'status' => Planner::PENDING,
            'scope' => ['path' => '.', 'package' => null], 'files' => [], 'depends_on' => []];
    }
}
