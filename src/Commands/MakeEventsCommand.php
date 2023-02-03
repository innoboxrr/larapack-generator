<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Events\EventsMaker;

class MakeEventsCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:events')
            ->setDescription('Create CURD events and listeners for model')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new EventsMaker();

        $maker->create($modelName);

        $output->writeln('Events added');

        return 1;

    }

}
