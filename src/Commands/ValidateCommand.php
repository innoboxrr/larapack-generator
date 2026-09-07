<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Valida un laraimport antes de generar nada.
 *
 * Hasta ahora un archivo incompleto no daba un mensaje sino un TypeError a
 * mitad de la generación, con parte de los archivos ya escritos. Aquí falla
 * antes de tocar el disco y dice exactamente qué dato está mal.
 */
class ValidateCommand extends Command
{
    use TargetsProject;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:validate')
            ->setDescription('Valida un laraimport.json contra el esquema y comprueba sus referencias')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'Ruta del archivo; por omisión laraimport.json en la raíz')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Trata los avisos como errores');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $path = $input->getArgument('jsonPath') ?? root_path() . '/laraimport.json';

        ['document' => $document, 'errors' => $findings] = ImportDocument::fromFile($path);

        $errors = $this->count($findings, SemanticValidator::ERROR);
        $warnings = $this->count($findings, SemanticValidator::WARNING);

        $failed = $errors > 0 || ($input->getOption('strict') && $warnings > 0);

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode([
                'ok' => ! $failed,
                'file' => $path,
                'errors' => $errors,
                'warnings' => $warnings,
                'models' => $document ? array_column($document->models(), 'name') : [],
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $output->writeln(sprintf(
                '  %s <fg=cyan>%s</> %s',
                $finding['level'] === SemanticValidator::ERROR ? '<fg=red>error</>' : '<comment>aviso</comment>',
                $finding['path'],
                $finding['message']
            ));
        }

        if ($findings === []) {
            $output->writeln('  <info>El archivo es válido.</info>');
        }

        if ($document !== null) {
            // El orden es el que seguirán las migraciones.
            $output->writeln(sprintf(
                "\n  Modelos (en orden de migración): %s",
                implode(', ', array_column($document->models(), 'name'))
            ));
        }

        $output->writeln(sprintf("\n  %d errores, %d avisos", $errors, $warnings));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function count(array $findings, string $level): int
    {
        return count(array_filter($findings, fn (array $f): bool => $f['level'] === $level));
    }
}
