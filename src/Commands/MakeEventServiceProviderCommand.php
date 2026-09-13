<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\Providers\EventServiceProviderTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeEventServiceProviderCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {

        $this->setName('larapack:event-service-provider')
            ->setDescription('Create an event service provider for the package');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $maker = new EventServiceProviderTool;

        $maker->create();

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;

    }
}
