<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\Manifest;
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
            ->addOption('metas', 'metas', InputOption::VALUE_NONE, 'Include Metas in commands')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Genera sólo estas acciones, separadas por coma')
            ->addOption('except', null, InputOption::VALUE_REQUIRED, 'Genera todas las acciones menos estas, separadas por coma')
            ->addOption('immutable', null, InputOption::VALUE_NONE, 'Las filas no se modifican ni se borran una vez creadas');

        $this->addGenerationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyGenerationOptions($input);

        $modelName = $input->getArgument('name');
        $includeMetas = $input->getOption('metas');
        $commands = $this->commands;

        $invalid = $this->declareShape($modelName, $input);

        if ($invalid !== null) {
            $output->writeln("<error>{$invalid}</error>");

            return Command::FAILURE;
        }

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

        if (! Generation::isDryRun()) {
            (new Manifest())->declare($modelName, Declaration::toManifest($modelName));
        }

        $this->reportGeneration($input, $output);

        return Command::SUCCESS;
    }

    /**
     * Las mismas primitivas que el laraimport, para que generar un modelo suelto
     * y generarlo desde el archivo no den dos resultados distintos.
     *
     * Sin ninguna de las opciones no se toca nada: si el modelo viene del
     * importador, su forma ya está declarada.
     *
     * @return string|null  El error, si las opciones no son válidas.
     */
    private function declareShape(string $modelName, InputInterface $input): ?string
    {
        $only = $input->getOption('only');
        $except = $input->getOption('except');
        $immutable = (bool) $input->getOption('immutable');

        if ($only === null && $except === null && ! $immutable) {
            return null;
        }

        if ($only !== null && $except !== null) {
            return 'Usa --only o --except, no los dos: juntos no tienen una lectura única.';
        }

        $routes = [];

        foreach (['only' => $only, 'except' => $except] as $key => $value) {
            if ($value === null) {
                continue;
            }

            $routes[$key] = array_values(array_filter(array_map('trim', explode(',', $value))));

            $unknown = array_diff($routes[$key], Actions::ALL);

            if ($unknown !== []) {
                return 'Acciones desconocidas: ' . implode(', ', $unknown) . '. Las válidas son ' . implode(', ', Actions::ALL) . '.';
            }
        }

        if ($immutable && array_intersect(Actions::MODIFY, $routes['only'] ?? []) !== []) {
            return 'Un modelo inmutable no puede declarar ' . implode(', ', array_intersect(Actions::MODIFY, $routes['only'])) . '.';
        }

        Declaration::set(
            $modelName,
            Actions::resolve(['routes' => $routes, 'immutable' => $immutable]),
            $immutable
        );

        return null;
    }

}
