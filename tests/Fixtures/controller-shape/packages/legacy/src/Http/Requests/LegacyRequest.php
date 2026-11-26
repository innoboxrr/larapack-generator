<?php

namespace Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Autoriza, pero nadie escribió la operación.
 */
class LegacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
