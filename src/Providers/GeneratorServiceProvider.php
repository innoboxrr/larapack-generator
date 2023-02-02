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

                return new \Desar\Generator\Facades\{$class}();

            });

        }

    }

    public function boot()
    {
        //
    }

}