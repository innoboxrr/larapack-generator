<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Innoboxrr\LarapackGenerator\Tools\Config\ConfigTool;

class MakeConfigCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:config')
            ->setDescription('Create the config file');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $maker = new ConfigTool();

        $maker->create();

        return 1;

    }

}
