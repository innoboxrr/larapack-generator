<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Commands\MakeFullModelCommand;
use Innoboxrr\LarapackGenerator\Tools\PivotMigration\PivotMigrationTool;

class JsonImporterCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {
        $this->setName('larapack:import')
            ->setDescription('Import models and migrations from a JSON file')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'The path to the JSON file')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Include ModelView in commands');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $jsonPath = $input->getArgument('jsonPath') ?? root_path() . '/laraimport.json';

        // Se valida antes de tocar el disco. Antes un archivo incompleto no
        // daba un mensaje sino un TypeError a mitad de la generación, con
        // parte de los archivos ya escritos.
        ['document' => $document, 'errors' => $findings] = ImportDocument::fromFile($jsonPath);

        foreach ($findings as $finding) {
            $output->writeln(sprintf(
                '  %s <fg=cyan>%s</> %s',
                $finding['level'] === SemanticValidator::ERROR ? '<fg=red>error</>' : '<comment>aviso</comment>',
                $finding['path'],
                $finding['message']
            ));
        }

        if ($document === null) {
            $output->writeln("\n<error>El laraimport no es válido; no se ha generado nada.</error>");

            return Command::FAILURE;
        }

        Tool::setFromJsonImporter(true);
        Tool::setJsonContent($document->toArray());

        try {

            // Los modelos vienen ordenados por sus dependencias, así que las
            // migraciones quedan en un orden que `migrate` puede ejecutar.
            foreach ($document->models() as $model) {
                $output->writeln("Processing model: {$model['name']}");

                $this->callMakeFullModelCommand(
                    $model['name'],
                    $input->getOption('vue'),
                    $model['metas'],
                    $output
                );
            }

            foreach ($document->pivots() as $pivot) {
                $output->writeln("Processing pivot: {$pivot['name']}");

                (new PivotMigrationTool())->create($pivot['name']);
            }

        } finally {

            // En un finally: si algo lanza, el flag no puede quedarse activo
            // para el resto del proceso.
            Tool::setFromJsonImporter(false);

        }

        $output->writeln('<info>JSON import completed successfully</info>');
        $this->reportGeneration($input, $output);

        return Command::SUCCESS;
    }

    private function callMakeFullModelCommand($modelName, $includeVue, $metas, OutputInterface $output)
    {
        // Crear la instancia del comando MakeFullModelCommand
        $command = new MakeFullModelCommand();

        // Crear los argumentos para el comando MakeFullModelCommand
        $arguments = [
            'name' => $modelName
        ];

        // Si la opción --vue está presente, añadirla a los argumentos
        if ($includeVue) {
            $arguments['--vue'] = true;
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
