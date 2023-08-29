<?php

namespace Innoboxrr\LarapackGenerator\Providers;

use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    
    public function register()
    {

        $files = glob(__DIR__ . '/../Facades/*.php');

        foreach ($files as $file) {

            $class = basename($file, '.php');

            $accesor = get_accessor($class);

            $this->app->bind($accesor, function($app) use ($class) {

                $className = '\Innoboxrr\LarapackGenerator\Facades\\' . $class;
    
                $class = new ReflectionClass($className);
                
                return $class->newInstance();

            });

        }

        $this->mergeConfigFrom(__DIR__ . '/../../config/larapack-generator.php', 'larapack-generator');

    }

    public function boot()
    {

        if ($this->app->runningInConsole()) {

            $this->publishes([__DIR__.'/../../config/larapack-generator.php' => config_path('larapack-generator.php')], 'config');

        }

    }

}