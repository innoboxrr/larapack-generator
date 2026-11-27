<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\Artifacts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeProvidersCommand extends Command
{
    use ReportsGeneration;

    /** @var list<string> */
    protected array $commands = [
        'App',
        'Auth',
        'Event',
        'Route',
    ];

    protected function configure(): void
    {

        $this->setName('larapack:providers')
            ->setDescription('Crea todos los proveedores de servicio');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        foreach ($this->commands as $command) {
            Artifacts::provider($command)->create();
        }

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;

    }
}
