<?php

use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

// Sin bootstrap/app.php ni proveedor que la cargue: no se sabe su prefijo.
Route::get('dashboard', [PanelController::class, 'index'])->name('dashboard');
