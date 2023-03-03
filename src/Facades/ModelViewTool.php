<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ModelViewTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'model-view-tool';
    }

}