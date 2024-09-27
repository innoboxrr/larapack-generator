<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Providers\AppServiceProviderTool;

class MakeAppServiceProviderCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:app-service-provider')
            ->setDescription('Create an app service provider for the package');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $maker = new AppServiceProviderTool();

        $maker->create();

        return 1;

    }

}
