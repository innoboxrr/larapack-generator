<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Factory\FactoryMaker;

class MakeFactoryCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:factory')
            ->setDescription('Create a new factory class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new FactoryMaker();

        $maker->create($modelName);

        return 1;

    }

}
