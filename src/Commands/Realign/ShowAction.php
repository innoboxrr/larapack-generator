<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Planner;

/**
 * `larapack:realign show <id>`: cualquier paso, como lo da `next`, esté como
 * esté.
 */
final class ShowAction extends StepAction
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if (count($targets) !== 1) {
            return $this->fail('show necesita el id de un paso: larapack:realign show <id>.');
        }

        $plan = $this->state()->requirePlan();

        return $this->describe($plan, Planner::byId($plan), $plan['steps'][Planner::indexOf($plan, $targets[0])]);
    }
}
