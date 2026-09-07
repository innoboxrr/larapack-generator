<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Factory\FactoryTool;

class MakeFactoryCommand extends Command
{
    use ReportsGeneration;

    
    protected function configure(): void
    {

        $this->setName('larapack:factory')
            ->setDescription('Create a new factory class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');


        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);


        $modelName = $input->getArgument('name');

        $maker = new FactoryTool();

        $maker->create($modelName);

        $this->reportGeneration($input, $output);


        return Command::SUCCESS;

    }

}
