<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign help [<acción>]`: lo que hay, o una acción en detalle.
 * Todo sale de RealignActions; no necesita un proyecto.
 */
final class HelpAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if ($targets !== []) {
            return $this->helpFor($targets);
        }

        $actions = [];

        foreach (array_keys(RealignActions::ALL) as $action) {
            if ($action !== 'help') {
                $actions[$action] = RealignActions::summary($action);
            }
        }

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'help', 'actions' => $actions,
                'categories' => Category::ALL, 'classes' => FileClass::ALL, 'guide' => RealignActions::GUIDE,
                'guides' => array_map(fn (array $definition): string => $definition['guide'], RealignActions::ALL),
                'entry_point' => 'larapack:realign status']);

            return Command::SUCCESS;
        }

        $this->output->writeln('  <info>larapack:realign</info> <acción>   (guía: '.RealignActions::GUIDE.' del paquete)');
        $this->output->writeln('');

        foreach ($actions as $name => $description) {
            $this->output->writeln(sprintf('  %-9s %s', $name, OutputFormatter::escape($description)));
        }

        $this->output->writeln('
  El ciclo: diagnose → plan → next → (hacer el paso) → check → next …');
        $this->output->writeln('  Si llegas sin contexto: larapack:realign status. Una acción en detalle: larapack:realign help <acción>.');

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function helpFor(array $targets): int
    {
        $action = strtolower($targets[0]);

        if (count($targets) > 1 || ! isset(RealignActions::ALL[$action])) {
            return $this->fail('help recibe a lo sumo una acción conocida: '.implode(', ', array_keys(RealignActions::ALL)).'.');
        }

        $definition = RealignActions::ALL[$action];
        $summary = RealignActions::summary($action);

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'help', 'topic' => $action,
                'summary' => $summary, 'usage' => $definition['usage'], 'writes' => $definition['writes'],
                'options' => $definition['options'], 'keys' => (object) $definition['keys'], 'guide' => $definition['guide'],
                'available' => ! in_array($action, Operations::ACTIONS, true) || Operations::available($action)]);

            return Command::SUCCESS;
        }

        $this->output->writeln('  <info>larapack:realign '.$action.'</info>');
        $this->output->writeln('  '.OutputFormatter::escape($summary));
        $this->output->writeln('');

        foreach ($definition['usage'] as $usage) {
            $this->output->writeln('    '.OutputFormatter::escape($usage));
        }

        foreach ($definition['keys'] as $key => $meaning) {
            $this->output->writeln('    --option '.$key.'=…  '.OutputFormatter::escape($meaning));
        }

        $this->output->writeln('');
        $this->output->writeln('  Escribe: '.OutputFormatter::escape($definition['writes']));
        $this->output->writeln('  Opciones: '.($definition['options'] === [] ? '—' : implode(' ', array_map(fn (string $option): string => '--'.$option, $definition['options']))).' (y --root, --format en todas)');
        $this->output->writeln('  Guía: '.$definition['guide'].' del paquete');

        return Command::SUCCESS;
    }
}
