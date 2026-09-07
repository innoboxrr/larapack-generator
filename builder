#!/usr/bin/env php
<?php

use Symfony\Component\Console\Application;

// Carga el autoloader tanto si el paquete se ejecuta clonado como si está
// instalado dentro de vendor/ de un proyecto anfitrión.
(static function (): void {
    $candidates = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../../autoload.php',
        __DIR__ . '/../../../autoload.php',
    ];

    foreach ($candidates as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;

            return;
        }
    }
})();

/**
 * Nombres con los que se conocía cada comando antes de moverlos al espacio
 * `larapack:`. Se mantienen como alias solo aquí: en Artisan no se registran
 * porque `make:model` y compañía son comandos del propio Laravel.
 */
$legacyAlias = static function (string $name): ?string {
    return match ($name) {
        'larapack:import' => 'json:importer',
        'larapack:remove-full-model' => 'remove:full-model',
        default => str_starts_with($name, 'larapack:')
            ? 'make:' . substr($name, strlen('larapack:'))
            : null,
    };
};

$application = new Application('Larapack Generator');

foreach (glob(__DIR__ . '/src/Commands/*Command.php') ?: [] as $file) {
    $class = 'Innoboxrr\\LarapackGenerator\\Commands\\' . basename($file, '.php');

    if (! class_exists($class)) {
        continue;
    }

    $command = new $class();

    if ($alias = $legacyAlias((string) $command->getName())) {
        $command->setAliases([$alias]);
    }

    // Symfony Console 7.4 deprecó Application::add() y 8.0 lo eliminó
    // en favor de addCommand().
    if (method_exists($application, 'addCommand')) {
        $application->addCommand($command);
    } else {
        $application->add($command);
    }
}

$application->run();
