<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Providers\AuthServiceProviderMaker;

class MakeAuthServiceProviderCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:auth-service-provider')
            ->setDescription('Create an auth service provider for the package');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $maker = new AuthServiceProviderMaker();

        $maker->create();

        $output->writeln('AuthServiceProvider added');

        return 1;

    }

}
