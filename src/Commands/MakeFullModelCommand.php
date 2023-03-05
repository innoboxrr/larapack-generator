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
        'Controller',
        'Events',
        'Excel',
        'Export',
        'ExportNotification',
        'Factory',
        'Filters',
        'Migration',
        'Model',
        'ModelTraits',
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
            ->addOption('view', 'v', InputOption::VALUE_NONE, 'Include ModelView in commands');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        // Verificar si la opción --view está presente y agregar ModelView al array de commands
        $includeModelView = $input->getOption('view');

        $commands = $this->commands;

        if ($includeModelView) {

            $commands[] = 'ModelView';

        }

        foreach($commands as $command) {

            $className = '\Innoboxrr\LarapackGenerator\Tools\\' . $command . '\\' . $command . 'Tool';
    
            $class = new \ReflectionClass($className);

            ($class->newInstance())->create($modelName);

        }

        return 1;

    }

}
