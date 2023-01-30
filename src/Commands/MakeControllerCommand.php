<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Controller\ControllerMaker;

class MakeControllerCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:controller')
            ->setDescription('Create a new controller class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the controller class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $controllerName = $input->getArgument('name');

        $maker = new ControllerMaker($controllerName);

        $maker->create();

        return 1;

    }

}
