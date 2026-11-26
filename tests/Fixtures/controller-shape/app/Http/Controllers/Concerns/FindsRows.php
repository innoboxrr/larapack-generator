<?php

namespace App\Http\Controllers\Concerns;

/**
 * Sólo lo usan requests: la lógica está donde va, el trait no.
 */
trait FindsRows
{
    protected function rowFrom(string $key): ?int
    {
        return null;
    }
}
