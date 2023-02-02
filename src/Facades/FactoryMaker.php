<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class FactoryMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'factory-maker';
    }

}