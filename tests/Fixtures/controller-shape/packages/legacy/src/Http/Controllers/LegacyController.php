<?php

namespace Http\Controllers;

use Http\Requests\LegacyRequest;

/**
 * Un paquete con el prefijo PSR-4 vacío: su request también se revisa.
 */
class LegacyController
{
    public function index(LegacyRequest $request)
    {
        return $request->handle();
    }
}
