<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Register\RegisterMaker;

class MakeRegisterCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:register')
            ->setDescription('Create a new register class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new RegisterMaker();

        $maker->create($modelName);

        $output->writeln('Register added');

        return 1;

    }

}
