<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Tool;

class JsonImporterCommand extends Command
{
    protected static $defaultName = 'json:importer';

    // Comandos que se ejecutarán para cada modelo
    protected $commands = [
        'Migration',
        'Controller',
        'Events',
        'Excel',
        'Export',
        'ExportNotification',
        'Factory',
        'Filters',
        'Model',
        'ModelTraits',
        'Observer',
        'Policy',
        'Requests',
        'Resource',
        'Route',
        'Test'
    ];

    protected function configure()
    {
        $this->setName('json:importer')
            ->setDescription('Import models and migrations from a JSON file')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'The path to the JSON file')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Include ModelView in commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonPath = $input->getArgument('jsonPath') ?? base_path('laraimport.json');

        if (!file_exists($jsonPath)) {
            $output->writeln("<error>File not found at {$jsonPath}</error>");
            return Command::FAILURE;
        }

        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);

        if (is_null($data)) {
            $output->writeln("<error>Invalid JSON content. Null returned</error>");
            return Command::FAILURE;
        }

        Tool::setFromJsonImporter(true);

        foreach ($data['models'] as $model) {
            $output->writeln("Processing model: {$model['name']}");
            $this->runToolsForModel($model['name'], $input->getOption('vue'), $output);
        }

        // Procesar pivotes (si es necesario)
        foreach ($data['pivots'] as $pivot) {
            $output->writeln("Processing pivot: {$pivot['name']}");
            // Aquí puedes implementar el manejo de los pivotes si es necesario
        }

        Tool::setFromJsonImporter(false);
        $output->writeln('<info>JSON import completed successfully</info>');

        return Command::SUCCESS;
    }

    private function runToolsForModel($modelName, $includeVue, OutputInterface $output)
    {
        $commands = $this->commands;
        if ($includeVue) {
            $commands[] = 'ModelView';
        }

        foreach ($commands as $command) {
            $className = '\Innoboxrr\LarapackGenerator\Tools\\' . $command . '\\' . $command . 'Tool';
            if (class_exists($className)) {
                $output->writeln("Running tool: {$className} for model: {$modelName}");
                $class = new \ReflectionClass($className);
                $toolInstance = $class->newInstance();
                $toolInstance->create($modelName);
                $output->writeln("Finished tool: {$className} for model: {$modelName}");
            } else {
                $output->writeln("<error>Tool class not found: {$className}</error>");
            }
        }
    }
}
