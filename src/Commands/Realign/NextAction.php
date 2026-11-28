<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign next [<id>]`: el siguiente paso (Navigation::next()), o
 * ese; si no queda nada que hacer ahora, qué espera y a quién.
 */
final class NextAction extends StepAction
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        $plan = $this->state()->requirePlan();
        $byId = Planner::byId($plan);

        if ($targets !== []) {
            return $this->describe($plan, $byId, $plan['steps'][Planner::indexOf($plan, $targets[0])]);
        }

        $steps = Planner::stepsIn($plan, $this->scopePaths());
        $step = Navigation::next($steps, $byId);

        if ($step === null) {
            $left = array_values(array_filter($steps, fn (array $step): bool => ! Planner::finished($step)));

            if ($left !== []) {
                return $this->waiting($left, $byId);
            }

            if ($this->wantsJson()) {
                $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'next', 'done' => true, 'step' => null, 'untested' => Navigation::untested($steps)]);
            } else {
                $this->output->writeln('  <info>No queda nada pendiente.</info> Confírmalo con un diagnóstico nuevo: larapack:realign diagnose && larapack:realign plan');

                if (Navigation::untested($steps) !== []) {
                    $this->output->writeln('  <comment>Pero hay '.count(Navigation::untested($steps)).' sin probar</comment>: larapack:realign status los lista.');
                }
            }

            return Command::SUCCESS;
        }

        // Una decisión sin decidir es del dueño: se ofrece primero, y se
        // dice con qué seguir mientras tanto para no parar todo.
        $meanwhile = Planner::effectiveStatus($step, $byId) === Planner::AWAITING_DECISION ? Navigation::meanwhile($steps, $byId) : null;

        return $this->describe($plan, $byId, $step, $meanwhile);
    }

    /**
     * Nada que hacer ahora, pero queda: lo aplazado y lo que espera.
     *
     * @param  list<array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $byId
     */
    private function waiting(array $left, array $byId): int
    {
        $ids = fn (callable $filter): array => array_values(array_map(fn (array $step): string => (string) $step['id'], array_filter($left, $filter)));
        $deferred = $ids(fn (array $step): bool => isset($step['deferred']));
        $owner = $ids(fn (array $step): bool => ! isset($step['deferred']) && Navigation::handedOver($step));
        $blocked = $ids(fn (array $step): bool => ! isset($step['deferred']) && ! Navigation::handedOver($step)
            && (Planner::effectiveStatus($step, $byId) === Planner::BLOCKED || Navigation::waitsForGuided($step, $byId)));

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'next', 'done' => false, 'step' => null,
                'waiting' => ['deferred' => $deferred, 'owner' => $owner, 'blocked' => $blocked]]);

            return Command::SUCCESS;
        }

        $this->output->writeln(sprintf('  Nada que hacer ahora: quedan %d aplazados, %d en manos del dueño (lo pedido espera su aprobación: check dice qué falta) y %d bloqueados.', count($deferred), count($owner), count($blocked)));
        $this->output->writeln('  larapack:realign status los lista; larapack:realign undefer <id> devuelve uno aplazado.');

        return Command::SUCCESS;
    }
}
