<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class FactoryTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'factory-tool';
    }

}