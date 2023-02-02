<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class EventsMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'events-maker';
    }

}