<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign decide <id> <opción> [--target=…]`: registra la decisión
 * del dueño, entera o por elemento. Decidir no es hacer.
 */
final class DecideAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if (count($targets) !== 2) {
            return $this->fail('decide necesita el id del paso y la opción: larapack:realign decide <id> <opción> --note="por qué".');
        }

        [$id, $choice] = $targets;
        $state = $this->state();
        $plan = $state->requirePlan();
        $index = Planner::indexOf($plan, $id);
        $step = $plan['steps'][$index];

        if ($step['category'] !== Category::DECISION) {
            return $this->fail("El paso {$id} no es una decisión: es ".(Category::LABELS[$step['category']] ?? $step['category']).'.', 'Sigue lo que dice `larapack:realign next`.');
        }

        $option = Planner::option($step, $choice);

        if ($option === null) {
            return $this->fail("El paso {$id} no tiene la opción {$choice}.",
                'Tiene: '.implode(', ', array_map(fn (array $option): string => (string) $option['key'], (array) ($step['options'] ?? []))).'.');
        }

        $note = $this->input->getOption('note');
        $decision = ['option' => $option['key'], 'note' => is_string($note) && $note !== '' ? $note : null, 'at' => gmdate('c')];
        $files = array_values(array_unique(array_map('strval', (array) $this->input->getOption('target'))));

        if ($files === []) {
            // Entera: sustituye también lo decidido elemento por elemento.
            $plan['steps'][$index]['decision'] = $decision;
            unset($plan['steps'][$index]['decisions']);
        } else {
            $known = Navigation::files($step);
            $unknown = array_values(array_diff($files, $known));

            if ($unknown !== []) {
                return $this->fail("El paso {$id} no tiene ".implode(', ', $unknown).'.',
                    'Sus elementos: '.implode(', ', array_slice($known, 0, 20)).(count($known) > 20 ? '… (larapack:realign show '.$id.' --format=json los da todos)' : '').'.');
            }

            foreach ($files as $file) {
                $plan['steps'][$index]['decisions'][$file] = $decision;
            }
        }

        $plan['steps'][$index]['history'][] = ['at' => $decision['at'], 'event' => 'decided', 'detail' => $option['key']
            .($files === [] ? '' : ' en '.implode(', ', $files)).($decision['note'] === null ? '' : ': '.$decision['note'])];
        $state->savePlan($plan);

        $then = $option['run'] ?? null;
        $undecided = Navigation::undecided($plan['steps'][$index]);

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'decide', 'id' => $id, 'decision' => $decision,
                'targets' => $files, 'undecided' => $undecided, 'then' => $then, 'check' => 'larapack:realign check '.$id]);

            return Command::SUCCESS;
        }

        $this->output->writeln("  Decidido en {$id}".($files === [] ? '' : ' para '.count($files).' de '.count(Navigation::files($step))).": <info>{$option['key']}</info> — ".OutputFormatter::escape((string) $option['consequences']));

        if ($undecided !== []) {
            $this->output->writeln('  Faltan por decidir '.count($undecided).': '.implode(', ', array_slice($undecided, 0, 10)).(count($undecided) > 10 ? '…' : '').'.');
        }

        $this->output->writeln($then === null ? '  Hazlo como dice la opción, y después: larapack:realign check '.$id : "  Ahora: {$then}\n  Y después: larapack:realign check {$id}");

        return Command::SUCCESS;
    }
}
