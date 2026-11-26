<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseRequest extends FormRequest
{
    public function handle()
    {
        return $this->all();
    }
}
