<?php

namespace Hrauvc\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Hrauvc\LarapackGenerator\Tools\Providers\EventServiceProviderTool;

class MakeEventServiceProviderCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:event-service-provider')
            ->setDescription('Create an event service provider for the package');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $maker = new EventServiceProviderTool();

        $maker->create();

        return 1;

    }

}
