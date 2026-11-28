<?php

// La forma de routes/web.php de school-management (recortada): una ruta de
// salud para la CI, que es un closure legítimo y se deja con una excepción
// aprobada por el dueño.

use Illuminate\Support\Facades\Route;

Route::get('/ci/test-health', function () {
    $versionFile = base_path('ci-version');

    return response()->json([
        'commit' => is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev',
        'app' => config('app.name'),
        'env' => app()->environment(),
    ]);
})->name('ci.test-health');
