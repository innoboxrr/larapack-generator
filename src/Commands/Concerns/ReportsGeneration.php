<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

use Innoboxrr\LarapackGenerator\Support\Formatter;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\Manifest;
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
    use TargetsProject;
    use WritesJson;

    protected function addGenerationOptions(): void
    {
        $this->addRootOption();

        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenera los archivos que no se hayan editado a mano')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra lo que haría sin escribir nada')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt');
    }

    protected function applyGenerationOptions(InputInterface $input): void
    {
        $this->applyRootOption($input);

        Generation::reset();

        Generation::force((bool) $input->getOption('force'));
        Generation::dryRun((bool) $input->getOption('dry-run'));
    }

    /**
     * @param  array<string, mixed>  $extra  lo que el comando añade al informe JSON
     */
    protected function reportGeneration(InputInterface $input, OutputInterface $output, array $extra = []): void
    {
        $summary = Generation::summary();
        $entries = Generation::log();

        $formatted = Generation::isDryRun() ? 0 : Formatter::format(
            [
                ...array_map(
                    fn (array $entry): string => $entry['file'],
                    array_filter($entries, fn (array $entry): bool => in_array($entry['action'], ['create', 'overwrite'], true))
                ),
                ...Generation::writtenFiles(),
            ],
            new Manifest
        );

        if ($this->wantsJson($input)) {
            $this->writeJson($output, [
                'ok' => true,
                'dryRun' => Generation::isDryRun(),
                'formatted' => $formatted,
                'summary' => $summary,
                'files' => array_map(fn (array $entry): array => [
                    'action' => $entry['action'],
                    'file' => $this->relative($entry['file']),
                    'stub' => $entry['stub'],
                    'reason' => $entry['reason'],
                ], $entries),
            ] + $extra);

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

        if ($formatted === null) {
            $output->writeln('  <comment>Pint no está en el proyecto: lo generado queda sin formatear. composer require --dev laravel/pint</comment>');
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

        return str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;
    }
}
