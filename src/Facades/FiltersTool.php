<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class FiltersTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'filters-tool';
    }

}