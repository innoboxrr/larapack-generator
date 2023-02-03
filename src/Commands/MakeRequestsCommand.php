<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\Requests\RequestsMaker;

class MakeRequestsCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:requests')
            ->setDescription('Create a new requests class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new RequestsMaker();

        $maker->create($modelName);

        $output->writeln('Requests added');

        return 1;

    }

}
