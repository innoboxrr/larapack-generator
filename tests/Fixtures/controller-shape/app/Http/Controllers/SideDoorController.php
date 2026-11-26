<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\IndexRequest;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Las acciones delegan, y la lógica entra por las puertas laterales.
 */
class SideDoorController extends Controller implements HasMiddleware
{
    private $user;

    public function __construct()
    {
        $this->user = auth()->user();
    }

    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                abort_unless($request->user()?->isAdmin(), 403);

                return $next($request);
            }),
        ];
    }

    public function index(IndexRequest $request)
    {
        return $request->handle();
    }

    public function __call($method, $parameters)
    {
        return response()->json(['action' => $method]);
    }
}
