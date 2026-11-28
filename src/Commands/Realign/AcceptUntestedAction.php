<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\NamedApproval;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign accept-untested <id>`: el dueño acepta sin probar un
 * paso que quedó `untested`.
 */
final class AcceptUntestedAction extends Action
{
    /**
     * El dueño acepta sin probar un paso que quedó `untested`: lo confirma
     * él, como una aprobación (OwnerConfirmation), en su terminal o
     * nombrándose sin ella, y queda en el plan con su nota, su nombre si lo
     * dio y la hora, a la vista en el diff y en lo que lista `done`.
     *
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if (count($targets) !== 1) {
            return $this->fail('accept-untested necesita el id de un paso: larapack:realign accept-untested <id> --note="por qué".');
        }

        $note = Exceptions::sanitise((string) $this->input->getOption('note'));

        if ($note === '') {
            return $this->fail('accept-untested necesita --note: por qué se acepta sin probar y cuándo se probará. Queda en el plan.');
        }

        $state = $this->state();
        $plan = $state->requirePlan();
        $index = Planner::indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];

        if (($step['status'] ?? null) !== Planner::UNTESTED) {
            return $this->fail("El paso {$step['id']} no está sin probar (está ".($step['status'] ?? Planner::PENDING).'): sólo se acepta lo que check o plan dejaron `untested`.');
        }

        $option = $this->input->getOption('approver');
        $named = OwnerConfirmation::namedApprover(is_string($option) ? $option : null);

        if ($named['error'] !== null) {
            return $this->fail($named['error']);
        }

        $summary = [
            '  Aceptar SIN PROBAR '.$step['id'].' — '.Exceptions::sanitise((string) $step['title']),
            '  Cambia comportamiento: '.Exceptions::sanitise((string) $step['risk']['why']),
            '  Archivos: '.Exceptions::sanitise(implode(', ', array_slice(Navigation::files($step), 0, 10))).(count(Navigation::files($step)) > 10 ? '…' : ''),
            '  Nota: «'.$note.'»',
            '  Nadie vio pasar sus pruebas; queda hecho con tu aceptación en '.RealignState::DIRECTORY.'/plan.json, y done deja de pedirlas.',
        ];
        $via = 'approver';

        if ($named['approver'] === null) {
            $terminal = OwnerConfirmation::openTerminal();

            if ($terminal === null) {
                return $this->fail('Aceptar un paso sin probar lo confirma el dueño del proyecto en su terminal, y este proceso no tiene ninguna (/dev/tty): no se cambió nada. '
                    .'Si eres un agente, no lo intentes por otra vía: déjalo en tu informe (larapack:realign brief lo junta para el dueño). '
                    .'Si eres una persona sin terminal, lee '.NamedApproval::DOCS.'.');
            }

            $phrase = 'acepto sin probar';
            $terminal->write(implode("\n", $summary)."\n\n  Si eres el dueño del proyecto y lo aceptas tú, escribe «{$phrase}»: ");
            $answer = $terminal->readLine();
            $terminal->write("\n");

            if ($answer === null || trim($answer) !== $phrase) {
                return $this->fail("No se confirmó (había que escribir «{$phrase}»): no se cambió nada.");
            }

            $via = 'terminal';
        } elseif (! $this->wantsJson()) {
            $this->output->writeln(array_map(fn (string $line): string => OutputFormatter::escape($line), $summary));
        }

        $now = gmdate('c');
        $accepted = ['note' => $note, 'at' => $now, 'via' => $via, 'by' => $named['approver'] === null ? null : Exceptions::sanitise($named['approver'])];
        $plan['steps'][$index] = StepCheck::acceptUntested($step, $accepted);
        $state->savePlan($plan);

        if ($this->wantsJson()) {
            $this->json(self::failureContext($this->name) + ['ok' => true, 'id' => $step['id'], 'status' => Planner::DONE, 'untested_accepted' => $accepted]);

            return Command::SUCCESS;
        }

        $this->output->writeln("  <info>Aceptado sin probar:</info> {$step['id']}. Revisa el diff de ".RealignState::DIRECTORY.'/plan.json y comitéalo.');

        return Command::SUCCESS;
    }
}
