<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeProvidersCommand extends Command
{
    use ReportsGeneration;


    protected $commands = [
        'App',
        'Auth',
        'Event',
        'Route'
    ];
    
    protected function configure(): void
    {

        $this->setName('larapack:providers')
            ->setDescription('Crea todos los proveedores de servicio');


        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);


        foreach($this->commands as $command) {

            $className = '\Innoboxrr\LarapackGenerator\Tools\Providers\\' . $command . 'ServiceProviderTool';
    
            $class = new \ReflectionClass($className);

            ($class->newInstance())->create();

        }

        $this->reportGeneration($input, $output);


        return Command::SUCCESS;

    }

}
