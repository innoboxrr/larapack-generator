<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class RouteMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'route-maker';
    }

}