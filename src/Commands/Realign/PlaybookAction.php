<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign playbook [<nombre>]`: los playbooks del paquete, o uno
 * sin rellenar. No necesita un proyecto.
 */
final class PlaybookAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        if (count($targets) > 1) {
            return $this->fail('playbook recibe a lo sumo un nombre: larapack:realign playbook [<nombre>].');
        }

        if ($targets === []) {
            $list = [];

            foreach (Playbooks::names() as $name) {
                $first = strtok(Playbooks::describe($name), "\n");
                $title = trim(ltrim($first === false ? '' : $first, '# '));
                // Un playbook titulado con el del paso (`# {{title}}`) se
                // nombra en la lista por su tipo, no por el marcador.
                $list[$name] = str_starts_with($title, '<') ? ($name === Playbooks::GENERIC
                    ? 'Cualquier paso sin playbook propio: leer el hallazgo, el contrato antes que el código'
                    : (StepTypes::exists($name) ? (string) StepTypes::get($name)['title'] : $title)) : $title;
            }

            if ($this->wantsJson()) {
                $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'playbook', 'playbooks' => $list]);

                return Command::SUCCESS;
            }

            foreach ($list as $name => $title) {
                $this->output->writeln(sprintf('  %-32s %s', $name, OutputFormatter::escape($title)));
            }

            $this->output->writeln("\n  Uno entero: larapack:realign playbook <nombre>. Rellenado con lo de un paso, lo da larapack:realign next.");

            return Command::SUCCESS;
        }

        if (! Playbooks::exists($targets[0])) {
            return $this->fail("No hay un playbook {$targets[0]}.", 'Hay: '.implode(', ', Playbooks::names()).'.');
        }

        $text = Playbooks::describe($targets[0]);

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'playbook', 'name' => $targets[0], 'instructions' => $text]);

            return Command::SUCCESS;
        }

        $this->output->writeln(OutputFormatter::escape($text));

        return Command::SUCCESS;
    }
}
