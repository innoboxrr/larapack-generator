#!/usr/bin/env php

<?php

$application = new Symfony\Component\Console\Application();

/** Inicio comandos de la aplicación */

$commandFiles = glob(__DIR__ . '/Desar/Generator/Commands/*Command.php');

foreach ($commandFiles as $file) {

    require_once $file;

    $className = 'Desar\Generator\Commands\\' . basename($file, '.php');

    $application->add(new $className());
    
}

/** Fin comandos de la aplicación */

$application->run();