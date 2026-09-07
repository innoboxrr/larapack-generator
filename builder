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

$application = new Application('Larapack Generator');

foreach (glob(__DIR__ . '/src/Commands/*Command.php') ?: [] as $file) {
    $class = 'Innoboxrr\\LarapackGenerator\\Commands\\' . basename($file, '.php');

    if (! class_exists($class)) {
        continue;
    }

    $command = new $class();

    // Symfony Console 7.4 deprecó Application::add() y 8.0 lo eliminó
    // en favor de addCommand().
    if (method_exists($application, 'addCommand')) {
        $application->addCommand($command);
    } else {
        $application->add($command);
    }
}

$application->run();
