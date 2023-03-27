<?php

namespace LaravelersAcademy\LaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

class ExportNotificationTool extends Facade
{
    
    protected static function getFacadeAccessor()
    {
        return 'export-notification-tool';
    }

}