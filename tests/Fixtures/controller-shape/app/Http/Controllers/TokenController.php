<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Innoboxrr\LarapackGenerator\Attributes as Larapack;

class TokenController extends Controller
{
    #[\Innoboxrr\LarapackGenerator\Attributes\HandRolled(reason: 'RFC 6749: los errores del canje son los de OAuth2, no los de Laravel.')]
    public function issue(Request $request)
    {
        return response()->json(['error' => 'unsupported_grant_type'], 400);
    }

    #[Larapack\HandRolled]
    public function revoke(Request $request)
    {
        return response()->noContent();
    }

    #[Larapack\HandRolled('   ')]
    public function introspect(Request $request)
    {
        return response()->json(['active' => false]);
    }
}
