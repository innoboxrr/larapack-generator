<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Migration\MigrationMaker;

class MakeMigrationCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:migration')
            ->setDescription('Create a new migration class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new MigrationMaker();

        $maker->create($modelName);

        $output->writeln('Migration added');

        return 1;

    }

}
