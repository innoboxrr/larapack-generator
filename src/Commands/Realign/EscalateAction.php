<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign escalate <id>`.
 */
final class EscalateAction extends Action
{
    /**
     * `escalate <id> --note=…`: un paso guiado que no se debe hacer sin el
     * dueño (el controlador de un protocolo que el playbook dice dejar) pasa
     * a ser una decisión suya, con su nota.
     *
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if (count($targets) !== 1) {
            return $this->fail('escalate necesita el id de un paso: '.RealignActions::ALL['escalate']['usage'][0].'.');
        }

        $note = trim((string) $this->input->getOption('note'));

        if ($note === '') {
            return $this->fail('escalate necesita la nota: --note="qué hay que decidir y por qué".', 'Es lo que lee el dueño.');
        }

        $state = $this->state();
        $plan = $state->requirePlan();
        $index = Planner::indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];

        if ($step['category'] !== Category::GUIDED) {
            return $this->fail("El paso {$step['id']} no es un paso guiado: es ".(Category::LABELS[$step['category']] ?? $step['category']).'.',
                $step['category'] === Category::DECISION ? 'Ya lo decide el dueño: larapack:realign show '.$step['id'].'.' : null);
        }

        if (Planner::finished($step)) {
            return $this->fail("El paso {$step['id']} ya está hecho.");
        }

        $now = gmdate('c');
        $plan['steps'][$index] = Navigation::escalate($step, ['note' => $note, 'at' => $now]);
        $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'escalated', 'detail' => $note];
        $state->savePlan($plan);
        $escalated = $plan['steps'][$index];
        $decide = 'larapack:realign decide '.$step['id'].' <opción> --note="por qué"';

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'escalate', 'id' => $step['id'],
                'escalated' => $escalated['escalated'], 'options' => $escalated['options'], 'decide' => $decide]);

            return Command::SUCCESS;
        }

        $this->output->writeln("  {$step['id']} es ahora una decisión del dueño. Opciones:");

        foreach ((array) $escalated['options'] as $option) {
            $this->output->writeln('    <info>'.$option['key'].'</info>: '.OutputFormatter::escape($option['label'].'. '.$option['consequences']));
        }

        $this->output->writeln("  La toma el dueño: {$decide}. Mientras, sigue con: larapack:realign next");

        return Command::SUCCESS;
    }
}
