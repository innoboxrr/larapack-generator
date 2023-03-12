<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ObserverTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'observer-tool';
    }

}