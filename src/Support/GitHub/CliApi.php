<?php

namespace Innoboxrr\LarapackGenerator\Support\GitHub;

/**
 * La API a través de la CLI de GitHub (`gh api`), con la sesión que ya tiene
 * abierta quien la corre: su cuenta, su host de Enterprise y su proxy.
 *
 * `--include` antepone las cabeceras a la respuesta: así se sabe el estado
 * HTTP también cuando `gh` sale con error (un 404 o un 403 son respuestas,
 * no fallos de la consulta).
 */
final class CliApi implements Api
{
    public function __construct(private readonly string $binary = 'gh') {}

    /**
     * La de `gh` si está en el PATH, o null.
     */
    public static function find(): ?self
    {
        $path = getenv('PATH');

        if (! is_string($path) || $path === '') {
            return null;
        }

        $names = PHP_OS_FAMILY === 'Windows' ? ['gh.exe', 'gh.cmd', 'gh.bat'] : ['gh'];

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach ($names as $name) {
                $candidate = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name;

                if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                    return new self($candidate);
                }
            }
        }

        return null;
    }

    public function describe(): string
    {
        return 'gh api';
    }

    public function get(string $path): Response
    {
        $process = @proc_open(
            [$this->binary, 'api', '--include', '--method', 'GET', '-H', 'Accept: application/vnd.github+json', ltrim($path, '/')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return Response::failure('No se pudo ejecutar gh.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return self::parse($stdout, $stderr, proc_close($process));
    }

    /**
     * La salida de `gh api --include`: la línea de estado, las cabeceras, una
     * línea en blanco y el cuerpo. Sin línea de estado no hubo respuesta HTTP
     * —sin sesión, sin red, sin `gh`—, y el porqué está en stderr.
     */
    public static function parse(string $stdout, string $stderr, int $code): Response
    {
        $stdout = ltrim($stdout);

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $stdout, $match) !== 1) {
            if ($code === 127) {
                return Response::failure('gh no está instalado.');
            }

            // En una línea: va en un informe y en una anotación de Actions.
            $why = (string) preg_replace('/\s*\R\s*/', ' ', trim($stderr) !== '' ? trim($stderr) : trim($stdout));

            return Response::failure('gh no respondió'.($why === '' ? '.' : ': '.$why));
        }

        $parts = preg_split("/\r?\n\r?\n/", $stdout, 2);
        $body = is_array($parts) && isset($parts[1]) ? $parts[1] : '';

        return Response::fromBody((int) $match[1], $body);
    }
}
