<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class PolicyMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'policy-maker';
    }

}