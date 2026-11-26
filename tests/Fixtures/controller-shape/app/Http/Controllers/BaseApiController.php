<?php

namespace App\Http\Controllers;

/**
 * La base de una API: el router nunca la llama, así que su método público
 * no es una acción.
 */
abstract class BaseApiController extends Controller
{
    public function sendResponse(mixed $data, string $message = '')
    {
        return response()->json(['data' => $data, 'message' => $message]);
    }
}
