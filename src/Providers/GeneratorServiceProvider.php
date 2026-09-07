<?php

namespace Innoboxrr\LarapackGenerator\Providers;

use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    private const COMMAND_NAMESPACE = 'Innoboxrr\\LarapackGenerator\\Commands\\';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/larapack-generator.php',
            'larapack-generator'
        );
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/larapack-generator.php' => config_path('larapack-generator.php'),
        ], 'larapack-generator-config');

        // Los comandos son de Symfony Console, no de Illuminate. Artisan los
        // acepta igual: Application::resolve() instancia cualquier subclase de
        // Symfony\Command y solo inyecta el contenedor si además es una de
        // Illuminate. Así la misma clase sirve a `php artisan` y al binario
        // `builder`, que corre fuera de Laravel.
        $this->commands($this->commandClasses());
    }

    /**
     * @return array<int, class-string<\Symfony\Component\Console\Command\Command>>
     */
    private function commandClasses(): array
    {
        $classes = [];

        foreach (glob(__DIR__ . '/../Commands/*Command.php') ?: [] as $file) {
            $classes[] = self::COMMAND_NAMESPACE . basename($file, '.php');
        }

        return $classes;
    }
}
