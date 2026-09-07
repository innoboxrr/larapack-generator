<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

use Innoboxrr\LarapackGenerator\Support\Generation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Opciones y salida comunes a todos los generadores.
 *
 * Hasta ahora los comandos eran mudos: descartaban el `false` que devolvía
 * `create()` cuando el archivo ya existía, así que no había forma de saber si
 * habían hecho algo. Y sin --dry-run no había forma de averiguarlo antes.
 */
trait ReportsGeneration
{
    protected function addGenerationOptions(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenera los archivos que no se hayan editado a mano')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra lo que haría sin escribir nada')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt');
    }

    protected function applyGenerationOptions(InputInterface $input): void
    {
        Generation::reset();

        Generation::force((bool) $input->getOption('force'));
        Generation::dryRun((bool) $input->getOption('dry-run'));
    }

    protected function reportGeneration(InputInterface $input, OutputInterface $output): void
    {
        $summary = Generation::summary();
        $entries = Generation::log();

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode([
                'dryRun' => Generation::isDryRun(),
                'summary' => $summary,
                'files' => array_map(fn (array $entry): array => [
                    'action' => $entry['action'],
                    'file' => $this->relative($entry['file']),
                    'stub' => $entry['stub'],
                    'reason' => $entry['reason'],
                ], $entries),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if (Generation::isDryRun()) {
            $output->writeln('<comment>Simulacion: no se ha escrito nada.</comment>');
        }

        foreach ($entries as $entry) {
            $output->writeln(sprintf(
                '  %s %s%s',
                $this->tag($entry['action']),
                $this->relative($entry['file']),
                $entry['reason'] ? " <comment>({$entry['reason']})</comment>" : ''
            ));
        }

        $output->writeln(sprintf(
            "\n  <info>%d creados</info>, %d regenerados, %d omitidos, %d conservados",
            $summary['create'],
            $summary['overwrite'],
            $summary['skipped'],
            $summary['preserved']
        ));

        if ($summary['preserved'] > 0) {
            $output->writeln('  <comment>Los conservados se editaron a mano; --force no los sobrescribe.</comment>');
        }
    }

    private function tag(string $action): string
    {
        return match ($action) {
            'create' => '<info>creado    </info>',
            'overwrite' => '<info>regenerado</info>',
            'preserved' => '<comment>conservado</comment>',
            default => '<comment>omitido   </comment>',
        };
    }

    private function relative(string $file): string
    {
        $root = str_replace('\\', '/', root_path());
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }
}
