<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Completion;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign done`: el criterio de terminado (Completion).
 */
final class DoneAction extends Action
{
    /**
     * El criterio de terminado (Completion): sale con 0 sólo si no falta
     * nada en ningún ámbito; si no, con 1 y lo que falta, por fuente.
     */
    public function run(array $targets): int
    {
        $jobs = (int) $this->input->getOption('jobs');

        if ($jobs < 1) {
            return $this->fail('--jobs tiene que ser un entero mayor que cero.');
        }

        $packages = $this->packages();
        $roots = $packages === [] ? [] : (new Scopes($this->root))->resolve($packages);
        $result = (new Completion($this->root, $jobs, ! $this->input->getOption('no-cache')))->run($packages, $roots, $this->scopePaths());

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => $result['done'], 'action' => 'done']
                + ($result['done'] ? [] : ['error' => 'El realineamiento no ha terminado: '.implode('; ', $result['reasons']).'.'])
                + $result);

            return $result['done'] ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($result['declared'] as $declared) {
            $this->output->writeln('  <fg=gray>fuera, por decisión ('.OutputFormatter::escape((string) $declared['step']).'):</> '.OutputFormatter::escape((string) $declared['message']));
        }

        foreach ($result['accepted_untested'] as $accepted) {
            $this->output->writeln('  <comment>aceptado sin probar ('.OutputFormatter::escape((string) $accepted['id']).($accepted['by'] === null ? '' : ', '.OutputFormatter::escape((string) $accepted['by'])).'):</comment> '.OutputFormatter::escape((string) $accepted['note']));
        }

        if ($result['done']) {
            $this->output->writeln('  <info>Terminado.</info> Nada pendiente en el plan, ninguna decisión ni excepción por aprobar, y validate y verify --strict en verde en cada ámbito.');
            $this->output->writeln('  Las pruebas del proyecto no las corre done: las registró check en cada paso que cambia comportamiento (lo aceptado sin probar sale arriba).');

            return Command::SUCCESS;
        }

        $this->output->writeln('  <comment>No ha terminado.</comment> Falta:');

        foreach ($result['reasons'] as $reason) {
            $this->output->writeln('    - '.OutputFormatter::escape($reason));
        }

        $lines = [
            ...array_map(fn (array $row): string => 'paso '.$row['id'].' ('.$row['status'].'): '.$row['check'], $result['missing']['steps']),
            ...array_map(fn (array $row): string => 'decisión '.$row['id'].': '.$row['decide'], $result['missing']['decisions']),
            ...array_map(fn (array $row): string => 'sin probar '.$row['id'].': '.$row['check'].'  ·  el dueño: '.$row['accept'], $result['missing']['untested']),
            ...array_map(fn (array $row): string => 'excepción '.$row['target'].' ('.$row['status'].')', $result['missing']['exceptions']),
            ...array_map(fn (array $row): string => 'validate '.$row['file'].' '.$row['path'].': '.$row['message'], $result['missing']['validate']),
            ...array_map(fn (array $row): string => 'verify '.$row['check'].' '.($row['file'] ?? $row['model'] ?? '').': '.$row['message'], $result['missing']['verify']),
        ];

        $this->output->writeln('');

        foreach (array_slice($lines, 0, 50) as $line) {
            $this->output->writeln('    '.OutputFormatter::escape($line));
        }

        if (count($lines) > 50) {
            $this->output->writeln('    … y '.(count($lines) - 50).' más (--format=json los da todos).');
        }

        return Command::FAILURE;
    }
}
