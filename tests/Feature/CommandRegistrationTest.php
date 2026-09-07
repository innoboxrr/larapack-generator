<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Los comandos solo se cargan al arrancar el binario, así que una firma
 * incompatible con la clase base de Symfony Console pasa desapercibida hasta
 * que alguien ejecuta el generador. Esto lo detecta en CI.
 */
final class CommandRegistrationTest extends TestCase
{
    /**
     * @return array<int, array{0: string}>
     */
    public static function commandClasses(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Commands/*Command.php') as $file) {
            $classes[basename($file, '.php')] = ['Innoboxrr\\LarapackGenerator\\Commands\\' . basename($file, '.php')];
        }

        return $classes;
    }

    #[DataProvider('commandClasses')]
    public function test_cada_comando_se_instancia_y_se_registra(string $class): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $command = new $class();

        $this->assertInstanceOf(Command::class, $command);

        $application->addCommand($command);

        $this->assertNotSame('', $command->getName(), "{$class} no declara nombre.");
        $this->assertNotSame('', $command->getDescription(), "{$class} no declara descripción.");
    }

    public function test_no_hay_nombres_de_comando_duplicados(): void
    {
        $names = [];

        foreach (self::commandClasses() as [$class]) {
            $names[] = (new $class())->getName();
        }

        $this->assertSame(
            array_unique($names),
            $names,
            'Hay comandos que comparten nombre: ' . implode(', ', array_diff_assoc($names, array_unique($names)))
        );
    }
}
