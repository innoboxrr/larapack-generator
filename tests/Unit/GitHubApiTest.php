<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\GitHub\CliApi;
use Innoboxrr\LarapackGenerator\Support\GitHub\Response;
use Innoboxrr\LarapackGenerator\Support\GitHub\TokenApi;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

/**
 * Por dónde se le pregunta a GitHub: `gh api` y un token con
 * `file_get_contents`.
 *
 * Sin red: `gh` es un script que responde lo que se le diga, y la API con
 * token habla con un servidor de PHP en 127.0.0.1 que devuelve las cabeceras
 * que recibió. Lo que importa es lo mismo en los dos: un 4xx es una
 * respuesta con su porqué, y no poder preguntar no es un 404.
 */
final class GitHubApiTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    private ?string $directory = null;

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if ($this->directory !== null) {
            array_map('unlink', glob($this->directory.'/*') ?: []);
            rmdir($this->directory);
        }
    }

    private function env(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->environment)) {
            $this->environment[$name] = getenv($name);
        }

        putenv($value === null ? $name : "{$name}={$value}");
    }

    private function directory(): string
    {
        return $this->directory ??= (function (): string {
            $directory = sys_get_temp_dir().'/larapack-gh-'.bin2hex(random_bytes(6));
            mkdir($directory);

            return $directory;
        })();
    }

    // GH API

    public function test_la_salida_de_gh_con_cabeceras(): void
    {
        $ok = CliApi::parse("HTTP/2.0 200 OK\r\nContent-Type: application/json\r\nX-Github-Request-Id: 1\r\n\r\n[{\"type\":\"deletion\"}]", '', 0);

        $this->assertSame(200, $ok->status);
        $this->assertSame([['type' => 'deletion']], $ok->json);
        $this->assertTrue($ok->ok());

        // gh sale con 1 en un 404, pero es una respuesta, con su mensaje.
        $missing = CliApi::parse("HTTP/2.0 404 Not Found\nContent-Type: application/json\n\n{\"message\":\"Not Found\"}", 'gh: Not Found (HTTP 404)', 1);

        $this->assertSame(404, $missing->status);
        $this->assertFalse($missing->ok());
        $this->assertSame('HTTP 404: Not Found', $missing->describe());

        $empty = CliApi::parse("HTTP/1.1 204 No Content\r\n\r\n", '', 0);

        $this->assertSame(204, $empty->status);
        $this->assertNull($empty->json);
    }

    public function test_sin_respuesta_http_no_es_un_404(): void
    {
        $login = CliApi::parse('', 'To get started with GitHub CLI, please run:  gh auth login', 4);

        $this->assertNull($login->status);
        $this->assertFalse($login->ok());
        $this->assertSame('gh no respondió: To get started with GitHub CLI, please run:  gh auth login', $login->describe());

        $this->assertSame('gh no está instalado.', CliApi::parse('', 'sh: gh: not found', 127)->describe());
        $this->assertSame('gh no respondió.', CliApi::parse('', '', 1)->describe());
        $this->assertSame('gh no respondió: algo', CliApi::parse('algo', '', 1)->describe());
        // En una línea: va a un informe y a una anotación de Actions.
        $this->assertSame('gh no respondió: uno dos', CliApi::parse('', "uno\n  dos\r\n", 4)->describe());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_gh_recibe_un_get_con_cabeceras_y_la_ruta_sin_barra(): void
    {
        $gh = $this->directory().'/gh';
        $log = $this->directory().'/argv';
        file_put_contents($gh, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($log)."\nprintf 'HTTP/2.0 403 Forbidden\\r\\n\\r\\n{\"message\":\"Resource not accessible by integration\"}'\nexit 1\n");
        chmod($gh, 0755);

        $response = (new CliApi($gh))->get('/repos/acme/notes/branches/main/protection');

        $this->assertSame(403, $response->status);
        $this->assertSame('HTTP 403: Resource not accessible by integration', $response->describe());
        $this->assertSame(
            ['api', '--include', '--method', 'GET', '-H', 'Accept: application/vnd.github+json', 'repos/acme/notes/branches/main/protection'],
            file($log, FILE_IGNORE_NEW_LINES)
        );
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_gh_que_no_existe(): void
    {
        $response = (new CliApi($this->directory().'/no-hay-gh'))->get('repos/acme/notes');

        $this->assertNull($response->status);
        $this->assertContains($response->describe(), ['gh no está instalado.', 'No se pudo ejecutar gh.']);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_gh_se_busca_en_el_path(): void
    {
        $this->env('PATH', '/no/existe'.PATH_SEPARATOR.$this->directory());

        $this->assertNull(CliApi::find());

        // Un archivo que no se puede ejecutar no es gh.
        file_put_contents($this->directory().'/gh', "#!/bin/sh\n");
        chmod($this->directory().'/gh', 0644);

        $this->assertNull(CliApi::find());

        chmod($this->directory().'/gh', 0755);

        $this->assertInstanceOf(CliApi::class, CliApi::find());

        $this->env('PATH', '');

        $this->assertNull(CliApi::find());
    }

    // UN TOKEN

    public function test_el_token_del_entorno(): void
    {
        $this->env('GH_TOKEN', null);
        $this->env('GITHUB_TOKEN', null);
        $this->env('GITHUB_API_URL', null);

        $this->assertNull(TokenApi::fromEnvironment());

        $this->env('GITHUB_TOKEN', '  ');

        $this->assertNull(TokenApi::fromEnvironment());

        $this->env('GITHUB_TOKEN', 'del-job');

        $this->assertSame('la API de GitHub con GITHUB_TOKEN', TokenApi::fromEnvironment()?->describe());

        // GH_TOKEN primero, como gh.
        $this->env('GH_TOKEN', 'personal');

        $this->assertSame('la API de GitHub con GH_TOKEN', TokenApi::fromEnvironment()?->describe());
    }

    public function test_el_estado_es_el_de_la_ultima_respuesta(): void
    {
        $this->assertSame(200, TokenApi::status(['HTTP/1.1 301 Moved Permanently', 'Location: x', 'HTTP/1.1 200 OK', 'Content-Type: application/json']));
        $this->assertSame(404, TokenApi::status(['HTTP/2 404']));
        $this->assertNull(TokenApi::status([]));
        $this->assertNull(TokenApi::status(['Content-Type: text/html', 42]));
    }

    public function test_el_proxy_del_entorno(): void
    {
        $this->env('HTTPS_PROXY', null);
        $this->env('https_proxy', null);

        $this->assertNull(TokenApi::proxy());

        $this->env('https_proxy', 'http://proxy.local:3128');

        $this->assertSame('tcp://proxy.local:3128', TokenApi::proxy());

        $this->env('HTTPS_PROXY', 'http://user:secret@corp');

        $this->assertSame('tcp://corp:80', TokenApi::proxy());

        $this->env('HTTPS_PROXY', 'http://');

        $this->assertNull(TokenApi::proxy());
    }

    /**
     * Una petición de verdad, contra un servidor local: las cabeceras que
     * pide la API de GitHub, el cuerpo de un 4xx, y una conexión rechazada,
     * que no tiene estado.
     */
    public function test_un_get_con_token_contra_un_servidor_local(): void
    {
        $port = $this->serve(<<<'PHP'
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        header('Content-Type: application/json');
        if ($path === '/api/repos/acme/notes') {
            echo json_encode([
                'query' => $_SERVER['QUERY_STRING'] ?? '',
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
                'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'version' => $_SERVER['HTTP_X_GITHUB_API_VERSION'] ?? null,
                'agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'],
            ]);
            return;
        }
        http_response_code(404);
        echo json_encode(['message' => 'Not Found']);
        PHP);

        $api = new TokenApi('t0k3n', 'GH_TOKEN', "http://127.0.0.1:{$port}/api/", 5.0);
        $response = $api->get('/repos/acme/notes?per_page=100');

        $this->assertSame(200, $response->status);
        $this->assertSame([
            'query' => 'per_page=100',
            'authorization' => 'Bearer t0k3n',
            'accept' => 'application/vnd.github+json',
            'version' => '2022-11-28',
            'agent' => 'larapack-generator',
            'method' => 'GET',
        ], $response->json);

        $missing = $api->get('repos/acme/otro');

        $this->assertSame(404, $missing->status);
        $this->assertSame('HTTP 404: Not Found', $missing->describe());

        // El servidor se apaga: no hay respuesta, y eso no es un 404.
        proc_terminate($this->server);
        proc_close($this->server);
        $this->server = null;
        usleep(100_000);

        $refused = $api->get('repos/acme/notes');

        $this->assertNull($refused->status);
        $this->assertStringStartsWith('Sin respuesta de 127.0.0.1', $refused->describe());
    }

    public function test_una_respuesta_sin_json(): void
    {
        $response = Response::fromBody(502, '<html>Bad gateway</html>');

        $this->assertNull($response->json);
        $this->assertSame('HTTP 502', $response->describe());
        $this->assertSame('sin respuesta', (new Response(null))->describe());
    }

    /**
     * Levanta `php -S` en un puerto libre de 127.0.0.1 con ese router y
     * espera a que acepte conexiones.
     */
    private function serve(string $router): int
    {
        $file = $this->directory().'/router.php';
        file_put_contents($file, $router);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $file],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        ) ?: null;

        $this->assertIsResource($this->server);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return $port;
            }

            usleep(50_000);
        }

        $this->fail('El servidor de prueba no arrancó.');
    }
}
