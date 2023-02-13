<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class RequestsTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'requests-tool';
    }

}