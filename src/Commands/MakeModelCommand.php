<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Model\ModelMaker;

class MakeModelCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:model')
            ->setDescription('Create a new model class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new ModelMaker();

        $maker->create($modelName);

        $output->writeln('Model added');

        return 1;

    }

}
