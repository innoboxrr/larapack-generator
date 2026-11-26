<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesInScope;
use App\Http\Requests\Post\IndexRequest;
use App\Models\Post;

/**
 * Lo que hace un agente cuando no hay plantilla: el trabajo en la acción.
 */
class FatController extends Controller
{
    use AuthorizesInScope;

    public function store(IndexRequest $request)
    {
        $this->authorizeInScope($request);

        $post = Post::query()->create($request->all());

        return response()->json($post);
    }

    public function computed(IndexRequest $request)
    {
        return $request->handle(Post::query()->latest()->first());
    }

    public function empty(IndexRequest $request) {}
}
