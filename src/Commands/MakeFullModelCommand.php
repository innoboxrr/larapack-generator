<?php

namespace Desar\Generator\Commands;

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
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        foreach($this->commands as $command) {

            $className = '\Desar\Generator\Tools\\' . $command . '\\' . $command . 'Tool';
    
            $class = new \ReflectionClass($className);

            ($class->newInstance())->create($modelName);

        }

        return 1;

    }

}
