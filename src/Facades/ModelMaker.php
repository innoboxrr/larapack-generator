<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ModelMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'model-maker';
    }

}