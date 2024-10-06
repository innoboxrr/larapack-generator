<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Commands\MakeFullModelCommand;
use Symfony\Component\Console\Input\ArrayInput;

class JsonImporterCommand extends Command
{
    protected static $defaultName = 'json:importer';

    protected function configure()
    {
        $this->setDescription('Import models and migrations from a JSON file')
            ->addArgument('jsonPath', InputArgument::REQUIRED, 'The path to the JSON file')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Include ModelView in commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonPath = $input->getArgument('jsonPath');

        if (!file_exists($jsonPath)) {
            $output->writeln("<error>File not found at {$jsonPath}</error>");
            return Command::FAILURE;
        }

        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);

        if (!$data) {
            $output->writeln("<error>Invalid JSON content</error>");
            return Command::FAILURE;
        }

        // Establecer la variable global `fromJson` en true
        Tool::setFromJsonImporter(true);

        // Procesar cada modelo
        foreach ($data['models'] as $model) {
            $output->writeln("Processing model: {$model['name']}");

            // Llamar al comando `make:full-model` para cada modelo
            $this->callMakeFullModelCommand($model['name'], $input->getOption('vue'), $output);
        }

        // Procesar pivotes
        foreach ($data['pivots'] as $pivot) {
            $output->writeln("Processing pivot: {$pivot['name']}");

            // Llamar al comando para manejar pivotes (crearás un comando para esto más adelante)
            $this->callMakePivotCommand($pivot['name'], $output);
        }

        // Limpiar la variable global
        Tool::setFromJsonImporter(false);
        $output->writeln('<info>JSON import completed successfully</info>');

        return Command::SUCCESS;
    }

    private function callMakeFullModelCommand($name, $includeVue, OutputInterface $output)
    {
        // Instanciar el comando MakeFullModelCommand
        $command = new MakeFullModelCommand();

        // Crear la entrada de los argumentos del comando
        $arguments = [
            'name' => $name
        ];

        if ($includeVue) {
            $arguments['--vue'] = true;
        }

        // Crear el input para el comando
        $input = new ArrayInput($arguments);

        // Ejecutar el comando manualmente
        $command->run($input, $output);
    }

    private function callMakePivotCommand($name, OutputInterface $output)
    {
        // Aquí deberías implementar la lógica para manejar los pivotes
        // Por ejemplo, puedes crear un comando MakePivotCommand similar a MakeFullModelCommand
        $output->writeln("Processing pivot: {$name}");

        // Lógica para manejar los pivotes, o un comando específico para manejar pivotes
    }
}
