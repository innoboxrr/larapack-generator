<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class RequestsMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'requests-maker';
    }

}