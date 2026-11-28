<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Diagnosis;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign status`: dónde se va. Es también lo que enseña `plan`
 * después de planificar (PlanAction).
 */
class StatusAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        $state = $this->state();
        $report = $state->report();
        $plan = $state->plan();
        $steps = Planner::stepsIn($plan, $this->scopePaths());
        $byId = Planner::byId($plan);
        $byStatus = array_fill_keys([Planner::PENDING, Planner::AWAITING_DECISION, Planner::DECIDED, Planner::BLOCKED, Planner::UNTESTED, Planner::DONE], 0);
        $byCategory = [];

        foreach ($steps as $step) {
            $status = Planner::effectiveStatus($step, $byId);
            $byStatus[$status]++;
            $byCategory[(string) $step['category']][$status] = ($byCategory[(string) $step['category']][$status] ?? 0) + 1;
        }

        $next = Navigation::next($steps, $byId);
        $stale = Diagnosis::stale($report, $this->root);
        $checks = array_values(array_filter(array_map(fn (array $step): mixed => $step['last_check'] ?? null, $steps), 'is_array'));
        usort($checks, fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        $document = [
            'format_version' => RealignState::FORMAT_VERSION,
            'ok' => true,
            'action' => $this->name,
            'diagnosed_at' => $report['generated_at'] ?? null,
            'planned_at' => $plan['planned_at'] ?? null,
            'stale' => $stale,
            'totals' => $report['totals'] ?? null,
            'verify' => $report['verify'] ?? null,
            'steps' => ['total' => count($steps), 'by_status' => $byStatus, 'by_category' => $byCategory],
            'next' => $next === null ? null : ['id' => $next['id'], 'title' => $next['title'], 'category' => $next['category'], 'status' => Planner::effectiveStatus($next, $byId)],
            'last_check' => $checks[0] ?? null,
            'awaiting_owner' => Navigation::awaitingOwner($steps, $byId),
            'deferred' => array_values(array_map(fn (array $step): array => ['id' => $step['id'], 'title' => $step['title'], 'note' => $step['deferred']['note'] ?? null, 'at' => $step['deferred']['at'] ?? null],
                array_filter($steps, fn (array $step): bool => is_array($step['deferred'] ?? null) && ! Planner::finished($step)))),
            'untested' => Navigation::untested($steps),
            'plan' => $plan === null ? null : $this->relative($state->planPath()),
        ];

        if ($this->wantsJson()) {
            $this->json($document);

            return Command::SUCCESS;
        }

        if ($report === null) {
            $this->output->writeln('  Sin diagnóstico todavía. Empieza con: larapack:realign diagnose');

            return Command::SUCCESS;
        }

        $this->output->writeln('  Diagnóstico: '.$document['diagnosed_at'].($plan === null ? '  (sin plan: larapack:realign plan)' : '  ·  plan: '.$document['planned_at']));

        foreach ($stale as $path) {
            $this->output->writeln("  <comment>El contrato o el manifiesto de {$path} cambiaron después del diagnóstico: vuelve a correr diagnose y plan.</comment>");
        }

        if ($plan !== null) {
            $this->output->writeln(sprintf("\n  %d pasos: %d hechos, %d sin probar, %d pendientes, %d esperan decisión, %d decididos, %d bloqueados.",
                count($steps), $byStatus[Planner::DONE], $byStatus[Planner::UNTESTED], $byStatus[Planner::PENDING], $byStatus[Planner::AWAITING_DECISION], $byStatus[Planner::DECIDED], $byStatus[Planner::BLOCKED]));

            foreach ($byCategory as $category => $counts) {
                $this->output->writeln(sprintf('    %s %s', $this->padded(Category::LABELS[$category] ?? $category, 17), implode(', ', array_map(fn (string $status, int $count): string => "{$status}: {$count}", array_keys($counts), $counts))));
            }

            if ($document['last_check'] !== null) {
                $outcome = $document['last_check']['done'] ? 'hecho' : (($document['last_check']['untested'] ?? false) ? 'sin probar' : 'falta');
                $this->output->writeln("\n  Última comprobación: ".$document['last_check']['id'].' ('.$outcome.', '.$document['last_check']['at'].')');
            }

            foreach ($document['awaiting_owner'] as $waiting) {
                $count = count($waiting['decisions']);
                $this->output->writeln(sprintf("\n  <comment>Falta%s %d %s del dueño antes de seguir con lo guiado de %s</comment> (%d %s): %s.",
                    $count === 1 ? '' : 'n', $count, $count === 1 ? 'decisión' : 'decisiones', OutputFormatter::escape($waiting['scope']),
                    count($waiting['guided']), count($waiting['guided']) === 1 ? 'paso espera' : 'pasos esperan', implode(', ', $waiting['decisions'])));
                $this->output->writeln('  Preséntaselas (larapack:realign show <id>); mientras, sigue con lo que no depende de ellas (larapack:realign next).');
            }

            if ($document['deferred'] !== []) {
                $this->output->writeln("\n  Aplazados (".count($document['deferred']).'), siguen pendientes; undefer los devuelve:');

                foreach ($document['deferred'] as $deferred) {
                    $this->output->writeln('    '.$deferred['id'].' — '.OutputFormatter::escape((string) $deferred['note']));
                }
            }

            if ($document['untested'] !== []) {
                $this->output->writeln("\n  <comment>Sin probar (".count($document['untested']).')</comment>: hechos a falta de su prueba; done no termina mientras sigan así. Con las pruebas en verde, check --tested; si no se pueden correr, lo acepta el dueño:');

                foreach ($document['untested'] as $untested) {
                    $this->output->writeln('    '.$untested['id'].' — '.$untested['check'].'  ·  '.$untested['accept']);
                }
            }

            // Sin siguiente no es sin pendientes: lo aplazado, lo que espera
            // al dueño y lo bloqueado siguen sin hacer, y decir «no queda
            // nada» con seis pasos aplazados mandaba a `done` a quien no
            // había terminado.
            $left = count(array_filter($steps, fn (array $step): bool => ! Planner::finished($step)));

            $this->output->writeln($next === null
                ? ($left > 0
                    ? "\n  <comment>Nada que hacer ahora, pero no ha terminado:</comment> {$left} ".($left === 1 ? 'paso sigue' : 'pasos siguen').' sin hacer (aplazados, en manos del dueño o bloqueados, arriba). larapack:realign next dice qué espera cada uno.'
                    : "\n  <info>No queda nada pendiente en el plan.</info> El criterio de terminado es: larapack:realign done")
                : "\n  Siguiente: <info>{$next['id']}</info> — ".OutputFormatter::escape((string) $next['title']).'  (larapack:realign next)');
        }

        return Command::SUCCESS;
    }
}
