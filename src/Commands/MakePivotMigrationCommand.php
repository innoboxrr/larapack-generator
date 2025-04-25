<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Migration\PivotMigrationTool;

class MakePivotMigrationCommand extends Command
{
    
    protected function configure()
    {
        $this->setName('make:pivot-migration')
            ->setDescription('Create a new pivot migration class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tableName = $input->getArgument('name');
        $maker = new PivotMigrationTool();
        $maker->create($tableName);
        return 1;
    }

}
