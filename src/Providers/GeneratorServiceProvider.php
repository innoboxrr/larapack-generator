<?php

namespace Innoboxrr\LarapackGenerator\Providers;

use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/larapack-generator.php',
            'larapack-generator'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/larapack-generator.php' => config_path('larapack-generator.php'),
            ], 'larapack-generator-config');
        }
    }
}
