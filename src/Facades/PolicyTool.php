<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class PolicyTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'policy-tool';
    }

}