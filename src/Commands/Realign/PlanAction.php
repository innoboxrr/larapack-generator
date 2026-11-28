<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use RuntimeException;

/**
 * `larapack:realign plan`: del diagnóstico al plan (Planner::build(), que
 * conserva lo hecho y lo decidido), y después lo mismo que `status`.
 */
final class PlanAction extends StatusAction
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        $state = $this->state();
        $report = $state->report();

        if ($report === null) {
            throw new RuntimeException('No hay diagnóstico: corre `larapack:realign diagnose` primero.');
        }

        $plan = Planner::build($report, $state->plan());
        $state->savePlan($plan);

        return parent::run($targets);
    }
}
