<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ResourceMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'resource-maker';
    }

}