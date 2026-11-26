<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\IndexRequest;
use Illuminate\Http\Request;
use Innoboxrr\LarapackGenerator\Attributes\HandRolled;

/**
 * Ganchos de propiedad (PHP 8.4): no terminan en `;`, y lo que llevan
 * delante no puede pasar al método siguiente.
 */
class HookedController extends Controller
{
    #[HandRolled('cache')]
    public string $label {
        get => 'x';
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required']);

        return [];
    }

    private string $secret {
        get => 'y';
    }

    function destroy(Request $request)
    {
        return response()->noContent();
    }

    public function index(IndexRequest $request)
    {
        return $request->handle();
    }
}
