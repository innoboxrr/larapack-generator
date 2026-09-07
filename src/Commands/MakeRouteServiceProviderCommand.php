<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Providers\RouteServiceProviderTool;

class MakeRouteServiceProviderCommand extends Command
{
    use ReportsGeneration;

    
    protected function configure(): void
    {

        $this->setName('larapack:route-service-provider')
            ->setDescription('Create an route service provider for the package');


        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);


        $maker = new RouteServiceProviderTool();

        $maker->create();

        $this->reportGeneration($input, $output);


        return Command::SUCCESS;

    }

}
