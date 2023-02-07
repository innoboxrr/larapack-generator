<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Providers\RouteServiceProviderMaker;

class MakeRouteServiceProviderCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:route-service-provider')
            ->setDescription('Create an route service provider for the package');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $maker = new RouteServiceProviderMaker();

        $maker->create();

        $output->writeln('RouteServiceProvider added');

        return 1;

    }

}
