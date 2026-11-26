<?php

use App\Http\Controllers\CleanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Lo que trae una aplicación nueva: equivale a Route::view().
Route::get('/', function () {
    return view('welcome');
});

Route::get('/about', fn () => view('about'));

Route::middleware('auth')->group(function () {
    Route::get('/posts', [CleanController::class, 'index']);

    Route::post('/posts', function (Request $request) {
        $request->validate(['title' => 'required']);

        return response()->json([], 201);
    });
});

Route::fallback(fn () => abort(404));
