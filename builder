#!/usr/bin/env php

<?php

$application = new Symfony\Component\Console\Application();

/** Inicio comandos de la aplicación */

$commandFiles = glob(__DIR__ . '/src/Commands/*Command.php');

foreach ($commandFiles as $file) {

    $className = '\Innoboxrr\LarapackGenerator\Commands\\' . basename($file, '.php');
    
    $class = new ReflectionClass($className);

    $application->add($class->newInstance());
    
}

/** Fin comandos de la aplicación */

$application->run();