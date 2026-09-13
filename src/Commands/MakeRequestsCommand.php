<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Tools\Requests\RequestsTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeRequestsCommand extends Command
{
    use ReportsGeneration;

    protected function configure(): void
    {

        $this->setName('larapack:requests')
            ->setDescription('Create a new requests class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $modelName = $input->getArgument('name');

        $maker = new RequestsTool;

        $maker->create($modelName);

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;

    }
}
