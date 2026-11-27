<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use Innoboxrr\LarapackGenerator\Support\GitHub\Api;
use Innoboxrr\LarapackGenerator\Support\GitHub\Response;

/**
 * GitHub de mentira para las pruebas: cada ruta, la respuesta que se le
 * diga. Lo que no se dijo es un 404, como en GitHub. Nunca hay red.
 */
final class FakeGitHubApi implements Api
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  array<string, Response>  $responses  ruta (con su query) => respuesta
     */
    public function __construct(private array $responses = []) {}

    public function on(string $path, Response $response): self
    {
        $this->responses[$path] = $response;

        return $this;
    }

    public function json(string $path, mixed $json, int $status = 200): self
    {
        return $this->on($path, new Response($status, $json));
    }

    public function get(string $path): Response
    {
        $this->calls[] = $path;

        return $this->responses[$path] ?? new Response(404, ['message' => 'Not Found']);
    }

    public function describe(): string
    {
        return 'GitHub de prueba';
    }
}
