<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeFullModelCommand extends Command
{

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
        $this->setName('make:full-model')
            ->setDescription('Create a completo model enviroment')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class')
            ->addOption('vue', 'vue', InputOption::VALUE_NONE, 'Include ModelView in commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modelName = $input->getArgument('name');
        $includeModelView = $input->getOption('vue');
        $commands = $this->commands;

        if ($includeModelView) {
            $commands[] = 'ModelView';
        }

        foreach($commands as $command) {
            $className = '\Innoboxrr\LarapackGenerator\Tools\\' . $command . '\\' . $command . 'Tool';
            if (class_exists($className)) {
                $class = new \ReflectionClass($className);
                ($class->newInstance())->create($modelName);
            }
        }
        return 1;
    }

}
