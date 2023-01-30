<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeModelCommand extends Command
{
    
    protected function configure()
    {
        $this->setName('make:model')
            ->setDescription('Create a new model class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        dd(root_path());

        /*
        $name = $input->getArgument('name');

        $stub = file_get_contents(__DIR__ . '/stubs/model.stub');
        $stub = str_replace('{{name}}', $name, $stub);

        $path = app_path() . '/' . $name . '.php';

        if (file_exists($path)) {
            $output->writeln('<error>Model already exists!</error>');
            return;
        }

        file_put_contents($path, $stub);

        $output->writeln('<info>Model created successfully!</info>');
        */

        return 1;

    }

}
