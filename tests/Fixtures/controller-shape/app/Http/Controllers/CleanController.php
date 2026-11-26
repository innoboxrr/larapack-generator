<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\{
    IndexRequest,
    InheritedRequest,
    PoliciesRequest,
    ShowRequest as ShowPostRequest,
    TraitRequest,
};
use App\Models\Post;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * La forma que se espera: cada acción delega y nada más.
 */
class CleanController extends Controller implements HasMiddleware
{
    public function __construct(private readonly Post $posts) {}

    public static function middleware(): array
    {
        return [
            new Middleware('auth:sanctum'),
        ];
    }

    public function policies(PoliciesRequest $request)
    {
        return $request->handle($this);
    }

    public function index(IndexRequest $request)
    {
        return $request->handle();
    }

    public function show(ShowPostRequest $request, Post $post)
    {
        // La ruta enlaza el modelo; la request lo recibe.
        return $request->handle(post: $post);
    }

    public function inherited(InheritedRequest $request)
    {
        return $request->handle();
    }

    public function fromTrait(TraitRequest $request)
    {
        return $request->handle();
    }

    protected function helper(): string
    {
        return 'no es una acción';
    }
}
