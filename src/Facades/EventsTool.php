<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class EventsTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'events-tool';
    }

}