<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Innoboxrr\LarapackGenerator\Tools\Tool;
use Innoboxrr\LarapackGenerator\Commands\MakeFullModelCommand;

class JsonImporterCommand extends Command
{
    protected static $defaultName = 'json:importer';

    protected function configure()
    {
        $this->setName('json:importer')
            ->setDescription('Import models and migrations from a JSON file')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'The path to the JSON file')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Include ModelView in commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Obtener la ruta del archivo JSON o usar una predeterminada
        $jsonPath = $input->getArgument('jsonPath') ?? root_path() . '/laraimport.json';

        // Verificar si el archivo existe
        if (!file_exists($jsonPath)) {
            $output->writeln("<error>File not found at {$jsonPath}</error>");
            return Command::FAILURE;
        }

        // Leer y decodificar el contenido JSON
        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);

        // Validar si el contenido JSON es correcto
        if (is_null($data)) {
            $output->writeln("<error>Invalid JSON content. Null returned</error>");
            return Command::FAILURE;
        }

        // Establecer la variable global `fromJson` en true
        Tool::setFromJsonImporter(true);
        Tool::setJsonContent($data);

        // Procesar cada modelo
        foreach ($data['models'] as $model) {
            $output->writeln("Processing model: {$model['name']}");
            $this->callMakeFullModelCommand($model['name'], $input->getOption('vue'), $output);
        }

        // Procesar pivotes (si es necesario)
        foreach ($data['pivots'] as $pivot) {
            $output->writeln("Processing pivot: {$pivot['name']}");
            $output->writeln("For now we are not processing pivots");
            // Aquí puedes implementar el manejo de los pivotes si es necesario
        }

        // Limpiar la variable global `fromJson`
        Tool::setFromJsonImporter(false);

        $output->writeln('<info>JSON import completed successfully</info>');
        return Command::SUCCESS;
    }

    private function callMakeFullModelCommand($modelName, $includeVue, OutputInterface $output)
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

        // Crear el input para el comando
        $input = new ArrayInput($arguments);

        // Ejecutar el comando
        $output->writeln("Calling MakeFullModelCommand for model: {$modelName}");

        $resultCode = $command->run($input, $output);

        // Verificar si el comando fue exitoso
        if ($resultCode == 1) {
            $output->writeln("<info>Model {$modelName} created successfully</info>");
        } else {
            $output->writeln("<error>Model {$modelName} could not be created</error>");
        }
    }
}
