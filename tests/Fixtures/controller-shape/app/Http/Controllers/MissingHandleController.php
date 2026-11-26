<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\NoHandleRequest;
use Vendor\Package\Http\Requests\VendorRequest;

class MissingHandleController extends Controller
{
    public function index(NoHandleRequest $request)
    {
        return $request->handle();
    }

    public function again(NoHandleRequest $request)
    {
        return $request->handle();
    }

    public function vendor(VendorRequest $request)
    {
        return $request->handle();
    }
}
