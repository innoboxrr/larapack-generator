<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Filters\FiltersMaker;

class MakeFiltersCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:filters')
            ->setDescription('Create a new filters class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new FiltersMaker();

        $maker->create($modelName);

        return 1;

    }

}
