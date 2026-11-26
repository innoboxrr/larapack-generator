<?php

namespace App\Http\Controllers\Concerns;

/**
 * Sólo declara middleware: no es lógica de una acción.
 */
trait HasMiddlewareOnly
{
    abstract protected function scope(): string;

    public static function middleware(): array
    {
        return ['auth:sanctum'];
    }
}
