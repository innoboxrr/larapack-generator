<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Diagnosis;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `larapack:realign diagnose`: corre el diagnóstico (Diagnosis) y lo resume
 * por ámbito, por clase y por categoría.
 */
final class DiagnoseAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        $jobs = (int) $this->input->getOption('jobs');

        if ($jobs < 1) {
            return $this->fail('--jobs tiene que ser un entero mayor que cero.');
        }

        $report = (new Diagnosis($this->root, $jobs, ! $this->input->getOption('no-cache')))->run($this->packages());
        $state = $this->state();
        $scopes = array_values(array_map(fn (array $scope): array => array_intersect_key($scope, array_flip(['package', 'path', 'contract', 'manifest', 'manifest_version', 'regeneration', 'counts', 'total', 'conforming', 'breakdown'])), (array) $report['scopes']));

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'diagnose',
                'generator' => $report['generator'], 'generated_at' => $report['generated_at'], 'totals' => $report['totals'],
                'verify' => $report['verify'], 'scopes' => $scopes, 'report' => $this->relative($state->reportPath())]);

            return Command::SUCCESS;
        }

        $totals = $report['totals'];

        foreach ($scopes as $scope) {
            $regeneration = is_array($scope['regeneration']) ? $scope['regeneration'] : null;
            $breakdown = is_array($scope['breakdown'] ?? null) ? $scope['breakdown'] : null;
            $this->output->writeln(sprintf('  %s: %d archivos, %d cumplen%s%s',
                $scope['package'] === null ? 'app (núcleo)' : OutputFormatter::escape((string) $scope['package']),
                $scope['total'],
                $scope['conforming'],
                $breakdown === null || $scope['total'] === 0 ? '' : sprintf(', %d por adoptar, %d con otro paso, %d fuera de alcance',
                    $breakdown['to_adopt'], $breakdown['other_steps'], $breakdown['out_of_scope']),
                match (true) {
                    ! $scope['contract'] => ' <comment>(sin contrato: no hay con qué comparar)</comment>',
                    $regeneration !== null && ! $regeneration['ok'] => ' <fg=red>(no se pudo regenerar: '.OutputFormatter::escape((string) $regeneration['error']).')</>',
                    default => '',
                }));
        }

        $this->output->writeln('  <comment>«cumplen»: sin paso pendiente —idénticos o iguales salvo formato que el manifiesto ya registra con su hash, editados sólo en sus huecos o con excepción—. Un archivo identical o format_only que el manifiesto no registra con su hash actual no cumple todavía: cuenta en «por adoptar» (el paso adopt, que sólo anota el manifiesto). Por eso «cumplen» puede ser menos que identical.</comment>');
        $this->output->writeln("\n  Archivos por clase:");

        foreach ($totals['classes'] as $class => $count) {
            if ($count > 0) {
                $this->output->writeln(sprintf('    %-17s %6d  %s', $class, $count, FileClass::DESCRIPTIONS[$class] ?? ''));
            }
        }

        $this->output->writeln("\n  Por categoría (archivos, código sin gestionar y hallazgos de verify):");

        foreach ($totals['categories'] as $category => $count) {
            $this->output->writeln(sprintf('    %-17s %6d', Category::LABELS[$category] ?? $category, $count));
        }

        $this->output->writeln("\n  Informe: ".$this->relative($state->reportPath()).'. Siguiente: larapack:realign plan');

        return Command::SUCCESS;
    }
}
