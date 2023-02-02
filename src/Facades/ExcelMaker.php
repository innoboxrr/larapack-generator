<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ExcelMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'excel-maker';
    }

}