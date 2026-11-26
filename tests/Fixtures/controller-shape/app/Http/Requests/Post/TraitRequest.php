<?php

namespace App\Http\Requests\Post;

use App\Http\Controllers\Concerns\FindsRows;
use Illuminate\Foundation\Http\FormRequest;

/**
 * handle() viene de un trait.
 */
class TraitRequest extends FormRequest
{
    use FindsRows, HandlesPosts;
}
