<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ExportTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'export-tool';
    }

}