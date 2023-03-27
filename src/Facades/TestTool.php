<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class TestTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'test-tool';
    }

}