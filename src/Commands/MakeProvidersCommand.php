<?php

namespace Desar\Generator\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeProvidersCommand extends Command
{

    protected $commands = [
        'App',
        'Auth',
        'Event',
        'Route'
    ];
    
    protected function configure()
    {

        $this->setName('make:providers')
            ->setDescription('Crea todos los proveedores de servicio');

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        foreach($this->commands as $command) {

            $className = '\Desar\Generator\Tools\Providers\\' . $command . 'ServiceProviderTool';
    
            $class = new \ReflectionClass($className);

            ($class->newInstance())->create();

        }

        return 1;

    }

}
