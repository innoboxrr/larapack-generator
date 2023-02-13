<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ModelTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'model-tool';
    }

}