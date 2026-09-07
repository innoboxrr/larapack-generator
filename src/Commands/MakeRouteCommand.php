<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Route\RouteTool;

class MakeRouteCommand extends Command
{
    use ReportsGeneration;

    
    protected function configure(): void
    {

        $this->setName('larapack:route')
            ->setDescription('Create a new route class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');


        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);


        $modelName = $input->getArgument('name');

        $maker = new RouteTool();

        $maker->create($modelName);

        $this->reportGeneration($input, $output);


        return Command::SUCCESS;

    }

}
