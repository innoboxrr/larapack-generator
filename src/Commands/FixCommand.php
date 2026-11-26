<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\LegacyFix;
use Innoboxrr\LarapackGenerator\Support\LegacySecurity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Corrige lo que `verify` marca como deuda de seguridad en código generado
 * con versiones anteriores (index-managed, resource-appends), igual en cada
 * archivo y a partir de la plantilla actual.
 *
 * Existe para que un proyecto con cientos de archivos así no dependa de que
 * un agente los edite uno a uno. Lo que no tiene una forma reconocida se
 * salta y se dice por qué: eso sí queda para una persona o un agente.
 *
 * Termina con código distinto de cero si algo se saltó, también con
 * --dry-run: quedó deuda que el comando no puede pagar.
 */
class FixCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:fix')
            ->setDescription('Corrige de forma determinista la deuda de seguridad que verify marca en lo generado con versiones anteriores')
            ->addArgument('checks', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Qué corregir: '.implode(', ', LegacySecurity::CHECKS))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra el diff de cada archivo sin escribir nada')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Sólo lo que cuelga de este subdirectorio de la raíz (p. ej. packages/school-library)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $checks = array_values(array_unique((array) $input->getArgument('checks')));
        $unknown = array_diff($checks, LegacySecurity::CHECKS);

        if ($unknown !== []) {
            return $this->reportFailure($input, $output,
                'Corrección desconocida: '.implode(', ', $unknown).'.',
                'Disponibles: '.implode(', ', LegacySecurity::CHECKS).'.');
        }

        $path = $input->getOption('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        if ($path !== null && ! is_dir(root_path().'/'.$path)) {
            return $this->reportFailure($input, $output, "No existe el directorio {$path} en la raíz del proyecto.");
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $fix = new LegacyFix(root_path(), $checks, $path);
        $fix->plan();

        if ($dryRun) {
            $report = $fix->withDiffs();
            $formatted = false;
        } else {
            ['report' => $report, 'formatted' => $formatted] = $fix->apply();
        }

        $skipped = array_sum(array_map(fn (array $entry): int => count($entry['skipped']), $report));

        if ($this->wantsJson($input)) {
            $this->writeJson($output, [
                'ok' => $skipped === 0,
                'dry_run' => $dryRun,
                'formatted' => $formatted,
                'checks' => $report,
            ]);

            return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($report as $check => $entry) {
            $output->writeln("<info>{$check}</info>");

            foreach ($entry['fixed'] as $fixed) {
                $output->writeln('  '.($dryRun ? 'arreglaría' : 'arreglado').'  '.OutputFormatter::escape($fixed['file']));

                if (isset($fixed['diff'])) {
                    // En bruto: es código, y el formateador se comería lo que
                    // parezca una etiqueta de estilo.
                    $output->writeln($fixed['diff'], OutputInterface::OUTPUT_RAW);
                }
            }

            foreach ($entry['skipped'] as $skip) {
                $output->writeln('  <comment>saltado</comment>    '.OutputFormatter::escape($skip['file']).': '.OutputFormatter::escape($skip['reason']));
            }

            $output->writeln(sprintf('  %d %s, %d saltados, %d ya estaban bien', count($entry['fixed']), $dryRun ? 'por arreglar' : 'arreglados', count($entry['skipped']), $entry['unchanged']));
        }

        if (isset($report[LegacySecurity::RESOURCE_APPENDS]) && $report[LegacySecurity::RESOURCE_APPENDS]['fixed'] !== []) {
            $output->writeln("\n  Los Resources arreglados ignoran `appends[]` hasta que declaras en \$appendable los accessors que pide la interfaz.");
        }

        if (! $dryRun && $formatted) {
            $output->writeln('  Formateado con el Pint del proyecto.');
        }

        return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
