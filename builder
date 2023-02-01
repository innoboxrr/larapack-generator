#!/usr/bin/env php

<?php

$application = new Symfony\Component\Console\Application();

/** Inicio comandos de la aplicación */
$application->add(new \Desar\Generator\Commands\MakeModelCommand());
$application->add(new \Desar\Generator\Commands\MakeControllerCommand());
/** Fin comandos de la aplicación */

$application->run();