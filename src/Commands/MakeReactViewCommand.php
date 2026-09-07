<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\ReactView\ReactViewTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeReactViewCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {
        $this->setName('larapack:react-view')
            ->setDescription('Crea el módulo React del modelo dentro del paquete')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        (new ReactViewTool())->create($input->getArgument('name'));

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;
    }
}
