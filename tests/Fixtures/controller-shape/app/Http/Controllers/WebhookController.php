<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Innoboxrr\LarapackGenerator\Attributes\HandRolled;

#[HandRolled('La firma se calcula sobre el cuerpo en bruto: '
    .'un FormRequest lo decodifica antes de poder comprobarla.')]
class WebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(hash_equals('x', (string) $request->header('X-Signature')), 401);

        return response()->noContent();
    }
}
