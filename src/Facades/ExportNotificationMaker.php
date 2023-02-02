<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ExportNotificationMaker extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'export-notification-maker';
    }

}