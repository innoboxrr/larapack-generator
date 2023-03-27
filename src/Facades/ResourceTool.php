<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ResourceTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'resource-tool';
    }

}