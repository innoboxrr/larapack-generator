<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\ProjectTests;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepCheck;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign check <id>`: corre el `done_when` del paso (DoneWhen) y,
 * si cambia comportamiento, exige su prueba; lo que eso hace con el estado
 * del paso lo decide StepCheck.
 */
final class CheckAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if (count($targets) !== 1) {
            return $this->fail('check necesita el id de un paso: larapack:realign check <id>.');
        }

        $declared = $this->input->getOption('tested');

        if (is_string($declared) && trim($declared) === '') {
            return $this->fail('--tested necesita la orden de pruebas que se corrió y pasó: --tested="php artisan test --filter=…".');
        }

        $state = $this->state();
        $plan = $state->requirePlan();
        $index = Planner::indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];
        $result = (new DoneWhen($this->root))->check($step);
        $now = gmdate('c');
        // Lo que cambia comportamiento no se da por hecho sin su prueba: la
        // que declara quien la corrió, o la orden del proyecto, que se corre
        // aquí (ProjectTests). Sin ninguna, queda sin probar.
        $needsTests = StepTypes::changesBehaviour($step);
        $tests = null;
        $tested = null;

        if ($result['done'] && $needsTests && is_string($declared)) {
            $tested = ['via' => 'declared', 'command' => trim($declared), 'at' => $now];
        } elseif ($result['done'] && $needsTests && ($configured = (new ProjectTests($this->root))->command()) !== null) {
            $tests = (new ProjectTests($this->root))->run($configured);

            if ($tests['exit'] === 0) {
                $tested = ['via' => 'config', 'command' => $configured, 'at' => $now];
            } else {
                $result['done'] = false;
                $result['remaining'][] = "Las pruebas del proyecto fallan ({$configured}, sale con {$tests['exit']}): el paso cambia comportamiento y no se da por hecho. El final de la salida está en `tests.output`.";
            }
        }

        $plan['steps'][$index] = StepCheck::check($step, $result['done'], count($result['remaining']), $tested, $now);
        $state->savePlan($plan);
        $final = (string) $plan['steps'][$index]['status'];
        $warning = $final === Planner::UNTESTED
            ? "SIN PROBAR: {$step['id']} cambia comportamiento y nadie registró su prueba. "
                .'Queda `untested`: hecho a falta de la prueba, y `larapack:realign done` no termina mientras quede así. '
                .'Corre las pruebas del proyecto y, si pasan: larapack:realign check '.$step['id'].' --tested="<la orden que corriste>" '
                .'(o registra la orden en '.RealignState::DIRECTORY.'/config.json y check la corre). Si no se pueden correr, dilo en tu informe: '
                .'aceptarlo sin probar lo decide el dueño (larapack:realign accept-untested '.$step['id'].' --note="…").'
            : null;

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => $result['done'], 'action' => 'check', 'id' => $step['id'],
                'done' => $final === Planner::DONE, 'status' => $final] + $result + ['tests' => $tests] + ($warning === null ? [] : ['warning' => $warning]));

            return $result['done'] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($result['done']) {
            $this->output->writeln($warning === null
                ? "  <info>Hecho:</info> {$step['id']} · siguiente: larapack:realign next"
                : '  <comment>'.OutputFormatter::escape($warning).'</comment>');

            return Command::SUCCESS;
        }

        $this->output->writeln("  <comment>Falta</comment> en {$step['id']} (".count($result['remaining']).'):');

        foreach (array_slice($result['remaining'], 0, 50) as $line) {
            $this->output->writeln('    - '.OutputFormatter::escape($line));
        }

        if (count($result['remaining']) > 50) {
            $this->output->writeln('    … y '.(count($result['remaining']) - 50).' más (--format=json los da todos).');
        }

        if ($tests !== null && $tests['exit'] !== 0) {
            $this->output->writeln('
  Final de la salida de las pruebas:
'.OutputFormatter::escape($tests['output']));
        }

        return Command::FAILURE;
    }
}
