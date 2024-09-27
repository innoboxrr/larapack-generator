<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveFullModelCommand extends Command
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
        'Observer',
        'Policy',
        'Requests',
        'Resource',
        'Route',
        'Test'
    ];
    
    protected function configure()
    {

        $this->setName('remove:full-model')
            ->setDescription('Elimina todas las entidades relacionadas con un modelo')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $modelName = $input->getArgument('name');
        
        foreach($this->commands as $command) {

            $className = '\Innoboxrr\LarapackGenerator\Tools\\' . $command . '\\' . $command . 'Tool';

            if (class_exists($className)) {

                $class = new \ReflectionClass($className);

                ($class->newInstance())->remove($modelName);

            }

        }

        return 1;

    }

}
