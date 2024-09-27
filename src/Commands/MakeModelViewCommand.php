<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\ModelView\ModelViewTool;

class MakeModelViewCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:model-view')
            ->setDescription('Crea la sección de administración de vue para el paquete laravel-setup')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $modelName = $input->getArgument('name');

        $maker = new ModelViewTool();

        $maker->create($modelName);

        return 1;

    }

}
