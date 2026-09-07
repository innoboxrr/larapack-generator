<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeFullModelCommand extends Command
{
    use ReportsGeneration;


    protected $commands = [
        'Migration',
        'Controller',
        'Events',
        'Excel',
        'Export',
        'ExportNotification',
        'Factory',
        'Filters',
        'Model',
        'ModelTraits',
        'Observer',
        'Policy',
        'Requests',
        'Resource',
        'Route',
        'Test'
    ];
    
    protected function configure(): void
    {
        $this->setName('larapack:full-model')
            ->setDescription('Create a completo model enviroment')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Genera tambien el modulo Vue del modelo')
            ->addOption('react', null, InputOption::VALUE_NONE, 'Genera tambien el modulo React del modelo')
            ->addOption('metas', 'metas', InputOption::VALUE_NONE, 'Include Metas in commands');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $modelName = $input->getArgument('name');
        $includeMetas = $input->getOption('metas');
        $commands = $this->commands;

        // La UI es opcional y no excluyente: el mismo laraimport puede generar
        // los dos modulos, que consumen el mismo contrato.
        if ($input->getOption('vue')) {
            $commands[] = 'ModelView';
        }

        if ($input->getOption('react')) {
            $commands[] = 'ReactView';
        }

        if ($includeMetas) {
            $commands[] = 'ModelMetas';
        }

        foreach($commands as $command) {
            $className = '\Innoboxrr\LarapackGenerator\Tools\\' . $command . '\\' . $command . 'Tool';
            if (class_exists($className)) {
                $class = new \ReflectionClass($className);
                ($class->newInstance())->create($modelName);
            }
        }
        $this->reportGeneration($input, $output);

        return Command::SUCCESS;
    }

}
