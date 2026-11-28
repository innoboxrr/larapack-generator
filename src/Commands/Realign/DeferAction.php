<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign defer <id>` y `undefer <id>`.
 */
final class DeferAction extends Action
{
    /**
     * `defer <id> --note=…` aplaza un paso: sigue pendiente, pero `next` lo
     * salta y `status` lo lista. `undefer <id>` lo devuelve al orden.
     *
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        $action = $this->name;
        $defer = $action === 'defer';

        if (count($targets) !== 1) {
            return $this->fail("{$action} necesita el id de un paso: ".RealignActions::ALL[$action]['usage'][0].'.');
        }

        $note = trim((string) $this->input->getOption('note'));

        if ($defer && $note === '') {
            return $this->fail('defer necesita la razón: --note="por qué se aplaza".', 'Es lo que lee quien retome el plan.');
        }

        $state = $this->state();
        $plan = $state->requirePlan();
        $index = Planner::indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];
        $now = gmdate('c');

        if ($defer && Planner::finished($step)) {
            return $this->fail("El paso {$step['id']} ya está hecho: no hay nada que aplazar.");
        }

        if (! $defer && ! isset($step['deferred'])) {
            return $this->fail("El paso {$step['id']} no está aplazado.");
        }

        if ($defer) {
            $plan['steps'][$index]['deferred'] = ['note' => $note, 'at' => $now];
        } else {
            unset($plan['steps'][$index]['deferred']);
        }

        $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => $defer ? 'deferred' : 'undeferred', 'detail' => $defer ? $note : 'Vuelve al orden del plan.'];
        $state->savePlan($plan);

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => $action, 'id' => $step['id'],
                'deferred' => $plan['steps'][$index]['deferred'] ?? null, 'next' => 'larapack:realign next']);

            return Command::SUCCESS;
        }

        $this->output->writeln($defer
            ? "  Aplazado {$step['id']}: sigue pendiente, next pasa al siguiente y status lo lista. Para retomarlo: larapack:realign undefer {$step['id']}"
            : "  {$step['id']} vuelve al orden del plan. Siguiente: larapack:realign next");

        return Command::SUCCESS;
    }
}
