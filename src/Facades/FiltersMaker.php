<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class FiltersMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'filters-maker';
    }

}