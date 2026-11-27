<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapClinicRoutes();
    }

    /**
     * Las rutas escritas a mano, fuera de routes/api/models/.
     */
    protected function mapClinicRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api/app')
            ->as('api.app.')
            ->group(__DIR__.'/../../routes/api/clinic.php');
    }
}
