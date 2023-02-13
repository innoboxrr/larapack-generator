<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class RouteTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'route-tool';
    }

}