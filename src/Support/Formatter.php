<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Formatea con Pint lo que acaba de escribir el generador.
 *
 * Los stubs no seguían ningún estilo, y un paquete creado con larapack:new
 * corre `pint --test` en su CI: el primer push salía en rojo por código que
 * nadie había escrito a mano. Reescribir cada stub al estilo de Pint no
 * aguantaría el primer cambio de reglas; pasar lo generado por el Pint del
 * propio proyecto sí, y con su pint.json.
 *
 * Sólo se formatea lo que se creó o regeneró en esta ejecución, nunca lo
 * editado a mano. Y lo que el manifiesto conoce se vuelve a anotar con el hash
 * ya formateado: si no, todo lo generado parecería editado.
 */
final class Formatter
{
    /**
     * Pint pasa los archivos en la línea de órdenes; en Windows no caben
     * muchos más de una vez.
     */
    private const CHUNK = 40;

    /**
     * El Pint del proyecto. LARAPACK_PINT apunta a otro, para quien genera
     * sin tener vendor —la suite de LaraPack—.
     */
    public static function pint(): ?string
    {
        $override = getenv('LARAPACK_PINT');

        if (is_string($override) && $override !== '') {
            return is_file($override) ? $override : null;
        }

        $candidate = root_path().'/vendor/laravel/pint/builds/pint';

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * @param  array<int, string>  $files
     * @return int|null los archivos PHP formateados, o null si hacía falta Pint y no está
     */
    public static function format(array $files, Manifest $manifest): ?int
    {
        $files = array_values(array_unique(array_filter(
            $files,
            fn (string $file): bool => str_ends_with($file, '.php') && is_file($file)
        )));

        if ($files === []) {
            return 0;
        }

        $pint = self::pint();

        if ($pint === null) {
            return null;
        }

        $tracked = array_values(array_filter(
            $files,
            fn (string $file): bool => $manifest->knows($file) && ! $manifest->wasCustomised($file)
        ));

        foreach (array_chunk($files, self::CHUNK) as $chunk) {
            self::run([PHP_BINARY, $pint, ...$chunk]);
        }

        $manifest->rehash($tracked);

        return count($files);
    }

    /**
     * Desde la raíz del proyecto, para que Pint lea su pint.json. Un fallo de
     * Pint no tumba la generación: lo generado ya está escrito y es correcto,
     * sólo queda sin formatear, y la CI del paquete lo dirá.
     *
     * @param  array<int, string>  $command
     */
    private static function run(array $command): void
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, root_path());

        if (! is_resource($process)) {
            return;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);
    }
}
