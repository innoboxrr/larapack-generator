<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\IndexRequest;

final class InvokableController extends Controller
{
    public function __invoke(IndexRequest $request)
    {
        return $request->handle();
    }
}
