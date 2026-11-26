<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Autoriza y valida, pero nadie escribio la operacion.
 */
class NoHandleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
