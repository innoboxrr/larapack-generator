<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Providers\EventServiceProviderMaker;

class MakeEventServiceProviderCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:event-service-provider')
            ->setDescription('Create an event service provider for the package');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $maker = new EventServiceProviderMaker();

        $maker->create();

        $output->writeln('EventServiceProvider added');

        return 1;

    }

}
