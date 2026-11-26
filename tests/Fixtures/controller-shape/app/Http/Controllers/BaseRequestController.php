<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * Tiene la forma, pero con requests que no traen handle().
 */
class BaseRequestController extends Controller
{
    public function index(Request $request)
    {
        return $request->handle();
    }

    public function show(FormRequest $request)
    {
        return $request->handle();
    }

    public function untyped($request)
    {
        return $request->handle();
    }
}
