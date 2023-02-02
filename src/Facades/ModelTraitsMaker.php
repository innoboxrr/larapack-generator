<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ModelTraitsMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'model-traits-maker';
    }

}