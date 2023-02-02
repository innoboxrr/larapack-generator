<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ExportMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'export-maker';
    }

}