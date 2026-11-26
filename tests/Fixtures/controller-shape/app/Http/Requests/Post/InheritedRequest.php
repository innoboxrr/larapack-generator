<?php

namespace App\Http\Requests\Post;

/**
 * handle() viene del padre.
 */
class InheritedRequest extends BaseRequest
{
    public function rules(): array
    {
        return [];
    }
}
