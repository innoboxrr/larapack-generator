<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ControllerTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'controller-tool';
    }

}