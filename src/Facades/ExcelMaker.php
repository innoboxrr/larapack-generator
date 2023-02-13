<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ExcelTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'excel-tool';
    }

}