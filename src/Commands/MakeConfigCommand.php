<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\Config\ConfigTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeConfigCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {

        $this->setName('larapack:config')
            ->setDescription('Create the config file');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $maker = new ConfigTool;

        $maker->create();

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;

    }
}
