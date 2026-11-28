<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use PHPUnit\Framework\TestCase;

/**
 * Qué pasos necesitan la prueba del proyecto para darse por hechos
 * (StepTypes::changesBehaviour()): los guiados que mueven código con
 * comportamiento y las decisiones decididas con una opción que lo mueve.
 */
final class RealignBehaviourTest extends TestCase
{
    public function test_los_guiados_que_mueven_comportamiento(): void
    {
        foreach (['controller-shape', 'declared-routes', 'security'] as $type) {
            $this->assertTrue(StepTypes::changesBehaviour(['type' => $type, 'category' => Category::GUIDED]), $type);
        }

        $this->assertFalse(StepTypes::changesBehaviour(['type' => 'verify-other', 'category' => Category::GUIDED]));
        // Un determinista no, aunque reescriba código: lo juzga su comprobación.
        $this->assertFalse(StepTypes::changesBehaviour(['type' => 'regenerate', 'category' => Category::DETERMINISTIC]));

        foreach (['Requests' => true, 'Controller' => true, 'Policy' => true, 'Model' => false, 'Resource' => false] as $family => $expected) {
            $this->assertSame($expected, StepTypes::changesBehaviour(['type' => 'edited-outside', 'category' => Category::GUIDED, 'subject' => $family]), $family);
        }
    }

    public function test_las_decisiones_segun_la_opcion_elegida(): void
    {
        $closure = ['type' => 'route-closure', 'category' => Category::DECISION, 'files' => ['routes/web.php', 'routes/api.php']];

        $this->assertFalse(StepTypes::changesBehaviour($closure), 'sin decidir');
        $this->assertFalse(StepTypes::changesBehaviour($closure + ['decision' => ['option' => 'except']]));
        $this->assertTrue(StepTypes::changesBehaviour($closure + ['decision' => ['option' => 'refactor']]));
        // Por elemento: basta con uno que se mueva.
        $this->assertTrue(StepTypes::changesBehaviour($closure + ['decisions' => ['routes/api.php' => ['option' => 'refactor'], 'routes/web.php' => ['option' => 'except']]]));

        $this->assertTrue(StepTypes::changesBehaviour(['type' => 'unmanaged-http', 'category' => Category::DECISION, 'decision' => ['option' => 'declare']]));
        $this->assertFalse(StepTypes::changesBehaviour(['type' => 'unmanaged-http', 'category' => Category::DECISION, 'decision' => ['option' => 'keep']]));
        $this->assertFalse(StepTypes::changesBehaviour(['type' => 'missing', 'category' => Category::DECISION, 'decision' => ['option' => 'restore']]));
    }

    public function test_un_guiado_pasado_al_dueno(): void
    {
        $escalated = Navigation::escalate(['id' => 'controller-shape@a.php', 'type' => 'controller-shape', 'category' => Category::GUIDED, 'playbook' => 'controller-to-request'], ['note' => 'x', 'at' => 'now']);

        $this->assertTrue(StepTypes::changesBehaviour($escalated), 'sin decidir: si desaparece, alguien lo hizo');
        $this->assertTrue(StepTypes::changesBehaviour($escalated + ['decision' => ['option' => 'refactor']]));
        $this->assertFalse(StepTypes::changesBehaviour($escalated + ['decision' => ['option' => 'hand-rolled']]));

        $other = Navigation::escalate(['id' => 'verify-other@.', 'type' => 'verify-other', 'category' => Category::GUIDED], ['note' => 'x', 'at' => 'now']);
        $this->assertFalse(StepTypes::changesBehaviour($other + ['decision' => ['option' => 'refactor']]));
    }
}
