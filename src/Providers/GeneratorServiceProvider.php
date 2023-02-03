<?php

namespace Desar\Generator\Providers;

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

                $className = '\Desar\Generator\Facades\\' . $class;
    
                $class = new ReflectionClass($className);
                
                return $class->newInstance();

            });

        }

    }

    public function boot()
    {
        //
    }

}