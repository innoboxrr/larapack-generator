<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Lo usa un controlador: le mete autorización que no se ve en la acción.
 */
trait AuthorizesInScope
{
    protected function authorizeInScope(Request $request): void
    {
        abort_unless($request->user() !== null, 403);
    }
}
