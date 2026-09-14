<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Tools\PivotMigration\PivotMigrationTool;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class JsonImporterCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {
        $this->setName('larapack:import')
            ->setDescription('Import models and migrations from a JSON file')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'The path to the JSON file')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Genera tambien el modulo Vue de cada modelo')
            ->addOption('react', null, InputOption::VALUE_NONE, 'Genera tambien el modulo React de cada modelo');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $jsonPath = $input->getArgument('jsonPath') ?? root_path().'/laraimport.json';

        // Se valida antes de tocar el disco. Antes un archivo incompleto no
        // daba un mensaje sino un TypeError a mitad de la generación, con
        // parte de los archivos ya escritos.
        ['document' => $document, 'errors' => $findings] = ImportDocument::fromFile($jsonPath);

        // Los avisos de interfaz sólo tienen sentido si se genera la interfaz.
        if ($document !== null && ($input->getOption('vue') || $input->getOption('react'))) {
            $findings = [...$findings, ...SemanticValidator::interface($document->toArray())];
        }

        // Con --format=json la salida es el informe y nada más: quien la lee la
        // decodifica entera. Tampoco va a stderr, porque un agente suele leer
        // las dos salidas juntas. El progreso no dice nada que el informe no
        // diga, y los avisos van dentro de él.
        $progress = $this->wantsJson($input) ? new NullOutput : $output;

        foreach ($findings as $finding) {
            $progress->writeln(sprintf(
                '  %s <fg=cyan>%s</> %s',
                $finding['level'] === SemanticValidator::ERROR ? '<fg=red>error</>' : '<comment>aviso</comment>',
                $finding['path'],
                $finding['message']
            ));
        }

        if ($document === null) {
            $progress->writeln('');

            return $this->reportFailure($input, $output, 'El laraimport no es válido; no se ha generado nada.', extra: ['findings' => $findings]);
        }

        Tool::setFromJsonImporter(true);
        Tool::setJsonContent($document->toArray());

        foreach ($document->models() as $model) {
            Declaration::fromModel($model);
        }

        try {

            // Los modelos vienen ordenados por sus dependencias, así que las
            // migraciones quedan en un orden que `migrate` puede ejecutar.
            foreach ($document->models() as $model) {
                $progress->writeln("Processing model: {$model['name']}");

                $this->callMakeFullModelCommand(
                    $model['name'],
                    [
                        'vue' => (bool) $input->getOption('vue'),
                        'react' => (bool) $input->getOption('react'),
                    ],
                    $model['metas'],
                    $progress
                );
            }

            foreach ($document->pivots() as $pivot) {
                $progress->writeln("Processing pivot: {$pivot['name']}");

                (new PivotMigrationTool)->create($pivot['name']);
            }

        } finally {

            // En un finally: si algo lanza, el flag no puede quedarse activo
            // para el resto del proceso.
            Tool::setFromJsonImporter(false);
            Declaration::reset();

        }

        $progress->writeln('<info>JSON import completed successfully</info>');
        $this->reportGeneration($input, $output, ['findings' => $findings]);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, bool>  $ui  que modulos de interfaz generar
     */
    private function callMakeFullModelCommand($modelName, array $ui, $metas, OutputInterface $output)
    {
        // Crear la instancia del comando MakeFullModelCommand
        $command = new MakeFullModelCommand;

        // Crear los argumentos para el comando MakeFullModelCommand
        $arguments = [
            'name' => $modelName,
        ];

        foreach ($ui as $framework => $requested) {
            if ($requested) {
                $arguments['--'.$framework] = true;
            }
        }

        // Si la opción --metas está presente, añadirla a los argumentos
        if ($metas) {
            $arguments['--metas'] = true;
        }

        // Crear el input para el comando
        $input = new ArrayInput($arguments);

        // Ejecutar el comando
        $output->writeln("Calling MakeFullModelCommand for model: {$modelName}");

        $resultCode = $command->run($input, $output);

        // Verificar si el comando fue exitoso
        if ($resultCode === Command::SUCCESS) {
            $output->writeln("<info>Model {$modelName} created successfully</info>");
        } else {
            $output->writeln("<error>Model {$modelName} could not be created</error>");
        }
    }
}
