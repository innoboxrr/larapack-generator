<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Resource\ResourceTool;

class MakeResourceCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:resource')
            ->setDescription('Create a new resource class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new ResourceTool();

        $maker->create($modelName);

        return 1;

    }

}