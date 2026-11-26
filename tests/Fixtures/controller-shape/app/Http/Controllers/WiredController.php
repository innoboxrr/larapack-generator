<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\IndexRequest;
use App\Models\Post;

/**
 * Un constructor que sólo conecta: propiedades, el padre y middleware.
 */
class WiredController extends Controller
{
    private Post $posts;

    private int $limit;

    private \ArrayObject $cache;

    public function __construct(Post $posts, ?int $limit = null)
    {
        parent::__construct();
        $this->middleware('auth:sanctum')->only(['index']);
        $this->middleware(['throttle:60,1'])->except('show');
        $this->posts = $posts;
        $this->limit = $limit ?? 15;
        $this->cache = new \ArrayObject([]);
    }

    public function index(IndexRequest $request)
    {
        return $request->handle();
    }
}
