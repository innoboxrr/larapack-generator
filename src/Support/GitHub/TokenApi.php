<?php

namespace Innoboxrr\LarapackGenerator\Support\GitHub;

/**
 * La API con un token: el `GITHUB_TOKEN` de un job de Actions (que el workflow
 * pasa como `GH_TOKEN`) o uno personal en la máquina.
 *
 * Sin dependencias: `file_get_contents` con un contexto de flujo. El paquete
 * no exige illuminate/http ni ext-curl, y una consulta de vez en cuando no
 * los justifica.
 *
 * Con `HTTPS_PROXY` (o `https_proxy`) definido, la conexión a una API https
 * pasa por él, que es lo que haría `gh` o `curl` en esa máquina.
 */
final class TokenApi implements Api
{
    public const DEFAULT_BASE = 'https://api.github.com';

    /**
     * Las variables de donde se toma el token, en su orden: la de `gh`
     * primero, como hace `gh`.
     */
    public const TOKEN_VARIABLES = ['GH_TOKEN', 'GITHUB_TOKEN'];

    public function __construct(
        private readonly string $token,
        private readonly string $variable,
        private readonly string $base = self::DEFAULT_BASE,
        private readonly float $timeout = 15.0,
    ) {}

    /**
     * La del entorno, o null si no hay token. La base sale de
     * `GITHUB_API_URL`, que Actions define (en GitHub Enterprise Server es
     * otra).
     */
    public static function fromEnvironment(): ?self
    {
        foreach (self::TOKEN_VARIABLES as $variable) {
            $token = getenv($variable);

            if (is_string($token) && trim($token) !== '') {
                $base = getenv('GITHUB_API_URL');

                return new self(trim($token), $variable, is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : self::DEFAULT_BASE);
            }
        }

        return null;
    }

    public function describe(): string
    {
        return "la API de GitHub con {$this->variable}";
    }

    public function get(string $path): Response
    {
        $url = rtrim($this->base, '/').'/'.ltrim($path, '/');

        $http = [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer '.$this->token,
                'User-Agent: larapack-generator',
                'X-GitHub-Api-Version: 2022-11-28',
            ]),
            // Un 4xx también trae cuerpo, y es el que dice por qué.
            'ignore_errors' => true,
            'timeout' => $this->timeout,
        ];

        $proxy = str_starts_with($url, 'https://') ? self::proxy() : null;

        if ($proxy !== null) {
            $http['proxy'] = $proxy;
        }

        $context = stream_context_create(['http' => $http]);

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $body = file_get_contents($url, false, $context);

            // PHP 8.4 trae la función; antes, la variable mágica. Si no hubo
            // conexión, la variable no existe: el aviso lo recoge el manejador
            // de arriba y queda null.
            if (function_exists('http_get_last_response_headers')) {
                $headers = http_get_last_response_headers();
            } else {
                $headers = $http_response_header;
            }
        } finally {
            restore_error_handler();
        }

        $status = self::status(is_array($headers) ? $headers : []);

        if ($status === null) {
            return Response::failure('Sin respuesta de '.parse_url($url, PHP_URL_HOST).($error === null ? '.' : ': '.$error));
        }

        return Response::fromBody($status, $body === false ? '' : $body);
    }

    /**
     * El estado de la última respuesta: con redirecciones hay varias líneas
     * `HTTP/…`, y vale la última.
     *
     * @param  array<int|string, mixed>  $headers
     */
    public static function status(array $headers): ?int
    {
        $status = null;

        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }

    /**
     * `HTTPS_PROXY=http://host:puerto` en la forma que entiende el contexto
     * de flujo (`tcp://host:puerto`), o null.
     */
    public static function proxy(): ?string
    {
        foreach (['HTTPS_PROXY', 'https_proxy'] as $variable) {
            $value = getenv($variable);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $parts = parse_url(trim($value));

            if (! is_array($parts) || ! isset($parts['host'])) {
                return null;
            }

            return 'tcp://'.$parts['host'].':'.($parts['port'] ?? 80);
        }

        return null;
    }
}
