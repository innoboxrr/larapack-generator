<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\Providers\AuthServiceProviderTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeAuthServiceProviderCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {

        $this->setName('larapack:auth-service-provider')
            ->setDescription('Create an auth service provider for the package');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $maker = new AuthServiceProviderTool;

        $maker->create();

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;

    }
}
