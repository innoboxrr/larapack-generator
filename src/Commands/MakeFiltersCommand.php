<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Filters\FiltersTool;

class MakeFiltersCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:filters')
            ->setDescription('Create a new filters class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $modelName = $input->getArgument('name');

        $maker = new FiltersTool();

        $maker->create($modelName);

        return 1;

    }

}
