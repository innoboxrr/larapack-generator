<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Desar\Generator\Tools\Makers\ExportNotification\ExportNotificationMaker;

class MakeExportNotificationCommand extends Command
{
    
    protected function configure()
    {

        $this->setName('make:export-notification')
            ->setDescription('Create a new export-notification class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $modelName = $input->getArgument('name');

        $maker = new ExportNotificationMaker();

        $maker->create($modelName);

        $output->writeln('Export Notification added');

        return 1;

    }

}
