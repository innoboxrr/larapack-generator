<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Providers\RouteServiceProviderTool;

class MakeRouteServiceProviderCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:route-service-provider')
            ->setDescription('Create an route service provider for the package');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $maker = new RouteServiceProviderTool();

        $maker->create();

        return 1;

    }

}
