<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class TestMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'test-maker';
    }

}