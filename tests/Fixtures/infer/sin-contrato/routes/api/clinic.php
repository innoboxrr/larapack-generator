<?php

use App\Http\Controllers\ContactoController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\ScribeController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('publico')->as('publico.')->group(function () {
    Route::post('contacto', [ContactoController::class, 'enviar'])
        ->middleware('throttle:6,1')
        ->name('contacto');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('scribe')->as('scribe.')->group(function () {
        Route::post('dictate', [ScribeController::class, 'dictate'])->name('dictate');
        Route::get('today', [ScribeController::class, 'today'])->name('today');
        Route::get('index', [ScribeController::class, 'index'])->name('list');
    });

    Route::post('site/{site_id}/publish', [SiteController::class, 'publish'])->name('site.publish');

    // No empieza por api/app/<x>/: declarada cambiaría de nombre.
    Route::get('panel', [PanelController::class, 'index'])->name('panel');

    Route::get('ping', fn () => response()->json(['ok' => true]))->name('ping');
});
