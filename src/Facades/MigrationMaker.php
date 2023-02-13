<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class MigrationTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'migration-tool';
    }

}