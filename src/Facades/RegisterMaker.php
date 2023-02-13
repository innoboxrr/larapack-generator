<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class RegisterTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'register-tool';
    }

}