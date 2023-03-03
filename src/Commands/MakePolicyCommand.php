<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Policy\PolicyTool;

class MakePolicyCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:policy')
            ->setDescription('Create a new policy class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new PolicyTool();

        $maker->create($modelName);

        return 1;

    }

}
