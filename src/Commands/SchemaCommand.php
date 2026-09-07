<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Support\Import\Schema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emite el esquema de laraimport.
 *
 * Es lo que permite que un agente descubra el contrato en lugar de deducirlo:
 * qué se puede declarar, qué es obligatorio, qué valores admite cada campo y
 * qué defecto toma si se omite.
 */
class SchemaCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('larapack:schema')
            ->setDescription('Emite el esquema JSON de laraimport')
            ->addOption('path', null, InputOption::VALUE_NONE, 'Muestra sólo la ruta del archivo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('path')) {
            $output->writeln(Schema::path());

            return Command::SUCCESS;
        }

        $output->writeln(json_encode(
            Schema::toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return Command::SUCCESS;
    }
}
