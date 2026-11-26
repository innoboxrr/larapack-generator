<?php

use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], 'invoices/export', static function () {
    return response()->streamDownload(fn () => print('csv'), 'invoices.csv');
});
