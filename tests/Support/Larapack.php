<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Ejecuta un comando del generador igual que lo haría el binario `builder`:
 * registrando todos los comandos en una Application.
 *
 * Vive fuera del TestCase porque la prueba de punta a punta genera una sola
 * vez por clase, desde un contexto estático donde no hay instancia de test.
 */
final class Larapack
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: string} el código de salida y lo que escribió
     */
    public static function run(string $name, array $arguments = []): array
    {
        $application = new Application('larapack-generator-tests');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        foreach (glob(dirname(__DIR__, 2).'/src/Commands/*Command.php') as $file) {
            $class = 'Innoboxrr\\LarapackGenerator\\Commands\\'.basename($file, '.php');
            $application->addCommand(new $class);
        }

        $output = new BufferedOutput;

        $exitCode = $application->run(
            new ArrayInput(['command' => $name] + $arguments),
            $output
        );

        return [$exitCode, $output->fetch()];
    }
}
