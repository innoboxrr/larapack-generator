<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Observer\ObserverTool;

class MakeObserverCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:observer')
            ->setDescription('Create a new observer class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the observed model');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new ObserverTool();

        $maker->create($modelName);

        return 1;

    }

}
