<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Como InstalledModulesController: valida y consulta en el controlador.
 */
class ValidatesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['scope_id' => ['required', 'integer']]);

        return response()->json(['scope_id' => (int) $data['scope_id']]);
    }
}
