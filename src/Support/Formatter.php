<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Composer\InstalledVersions;

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
     * La Pint con la que se formatea: su ruta, o null si no hay ninguna.
     */
    public static function pint(): ?string
    {
        return self::resolve()['path'];
    }

    /**
     * Qué Pint se usa, de dónde sale, con qué configuración, y qué decir.
     *
     * Por orden: LARAPACK_PINT (una ruta, o `off` para no formatear a
     * propósito), la del proyecto y la que trae el generador. Antes sólo se
     * miraba la del proyecto: con `builder` usado desde fuera y el proyecto sin
     * vendor/, lo generado quedaba sin formatear —en school-management, unos
     * 5 600 archivos que sólo diferían en el formato— y el manifiesto los daba
     * después por editados a mano.
     *
     * La configuración es siempre el pint.json del proyecto si lo tiene, se
     * use la Pint que se use. Sin él, el preset por omisión de Pint (laravel).
     *
     * @return array{path: string|null, source: 'override'|'project'|'generator'|'none'|'disabled', config: string|null, message: string}
     */
    public static function resolve(): array
    {
        $config = is_file(root_path().'/pint.json') ? 'pint.json' : null;
        $with = $config === null ? 'el preset laravel, porque el proyecto no tiene pint.json' : 'el pint.json del proyecto';
        $override = getenv('LARAPACK_PINT');

        if (is_string($override) && $override !== '') {
            if (strtolower($override) === 'off') {
                return ['path' => null, 'source' => 'disabled', 'config' => $config,
                    'message' => 'LARAPACK_PINT=off: lo generado queda sin formatear a propósito.'];
            }

            return is_file($override)
                ? ['path' => $override, 'source' => 'override', 'config' => $config, 'message' => "Formateado con la Pint de LARAPACK_PINT y {$with}."]
                : ['path' => null, 'source' => 'none', 'config' => $config,
                    'message' => "LARAPACK_PINT apunta a {$override}, que no existe: lo generado queda sin formatear. Corrígela o quítala para usar la del proyecto o la del generador."];
        }

        $project = root_path().'/vendor/laravel/pint/builds/pint';

        if (is_file($project)) {
            return ['path' => $project, 'source' => 'project', 'config' => $config, 'message' => "Formateado con la Pint del proyecto y {$with}."];
        }

        $generator = self::generatorPint();

        if ($generator !== null) {
            return ['path' => $generator, 'source' => 'generator', 'config' => $config, 'message' => "Formateado con la Pint del generador (el proyecto no la tiene) y {$with}."];
        }

        return ['path' => null, 'source' => 'none', 'config' => $config,
            'message' => 'No hay Pint ni en el proyecto ni junto al generador: lo generado queda sin formatear y el manifiesto lo anotará así. composer require --dev laravel/pint'];
    }

    /**
     * La Pint instalada junto al generador: en su vendor/ si se usa clonado,
     * o en el vendor/ que lo contiene si está instalado como dependencia.
     */
    private static function generatorPint(): ?string
    {
        $candidates = [dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint', dirname(__DIR__, 4).'/laravel/pint/builds/pint'];

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('laravel/pint')) {
            $installed = InstalledVersions::getInstallPath('laravel/pint');

            if ($installed !== null) {
                array_unshift($candidates, $installed.'/builds/pint');
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
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

        ['path' => $pint, 'config' => $config] = self::resolve();

        if ($pint === null) {
            return null;
        }

        $tracked = array_values(array_filter(
            $files,
            fn (string $file): bool => $manifest->knows($file) && ! $manifest->wasCustomised($file)
        ));

        foreach (array_chunk($files, self::CHUNK) as $chunk) {
            // La configuración, explícita: la Pint del generador no está en el
            // proyecto y no tiene por qué buscarla en él.
            self::run([PHP_BINARY, $pint, ...($config === null ? [] : ['--config='.root_path().'/'.$config]), ...$chunk]);
        }

        $manifest->rehash($tracked);

        return count($files);
    }

    /**
     * Desde la raíz del proyecto, para que Pint lea su pint.json. Un fallo de
     * Pint no tumba la generación: lo generado ya está escrito y es correcto,
     * sólo queda sin formatear, y la CI del paquete lo dirá.
     *
     * @param  list<string>  $command
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
