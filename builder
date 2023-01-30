#!/usr/bin/env php

<?php

require __DIR__ . '/vendor/autoload.php';

$application = new Symfony\Component\Console\Application();

/** Inicio comandos de la aplicación */
$application->add(new \Desar\Generator\Commands\MakeModelCommand());
$application->add(new \Desar\Generator\Commands\MakeControllerCommand());
/** Fin comandos de la aplicación */

$application->run();