<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\Observer\ObserverTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeObserverCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {

        $this->setName('larapack:observer')
            ->setDescription('Create a new observer class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the observed model');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $modelName = $input->getArgument('name');

        $maker = new ObserverTool;

        $maker->create($modelName);

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;

    }
}
