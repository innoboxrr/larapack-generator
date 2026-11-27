<?php

namespace Innoboxrr\LarapackGenerator\Support;

use RuntimeException;

/**
 * Resuelve la raíz del proyecto sobre el que escribe el generador.
 *
 * Por orden:
 *
 *  1. La que se fije (`--root`, ProjectRoot::set()).
 *  2. La del directorio de trabajo: subiendo desde él, el primer directorio
 *     con una marca de proyecto LaraPack —un `laraimport.json`, un
 *     `.larapack/manifest.json` o un composer.json que requiere el
 *     generador—.
 *  3. La de siempre: subiendo desde este paquete hasta dar con un
 *     `vendor/autoload.php`. Dentro de una aplicación que lo instaló es la
 *     aplicación.
 *
 * El 3 solo, que era lo que había, depende de dónde esté instalado el
 * paquete y no de dónde se trabaja: con el `builder` de un clon, o con un
 * `vendor/bin/builder` que lleva a uno por un repositorio `path` con enlace
 * simbólico, es el propio generador, que tiene su vendor. Así `verify` sin
 * --root medía el generador y salía en verde. Por eso también existe
 * ensureProject(): el propio generador sin manifiesto no es un proyecto.
 */
final class ProjectRoot
{
    private const PACKAGE = 'innoboxrr/larapack-generator';

    private static ?string $override = null;

    /**
     * Fija la raíz de forma explícita. `null` restaura el descubrimiento.
     */
    public static function set(?string $path): void
    {
        if ($path === null) {
            self::$override = null;

            return;
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new RuntimeException("La raíz indicada no existe: {$path}");
        }

        self::$override = $resolved;
    }

    /**
     * Ejecuta el callback con la raíz fijada, restaurándola después aunque
     * el callback lance.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(string $path, callable $callback): mixed
    {
        $previous = self::$override;

        self::set($path);

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    public static function path(): string
    {
        return self::$override ?? self::discover();
    }

    /**
     * La raíz, si es un proyecto sobre el que trabajar. El propio generador
     * sin manifiesto ni laraimport no lo es: medirlo da un verde que no dice
     * nada del proyecto que se quería medir, y generar en él escribe dentro
     * del paquete.
     */
    public static function ensureProject(): string
    {
        $root = self::path();

        if (! self::isGenerator($root) || is_file($root.'/.larapack/manifest.json') || is_file($root.'/laraimport.json')) {
            return $root;
        }

        $how = self::$override !== null
            ? "La raíz indicada ({$root}) es el propio LaraPack, no un proyecto."
            : 'No se encontró el proyecto desde '.(getcwd() ?: 'el directorio de trabajo')
                .' (se busca hacia arriba un laraimport.json, un .larapack/manifest.json o un composer.json que requiera '.self::PACKAGE
                ."), y la raíz que queda ({$root}) es el propio LaraPack, no un proyecto.";

        throw new RuntimeException(
            $how.' Ejecuta el comando desde el proyecto, o pásale --root=<ruta del proyecto>.'
        );
    }

    /**
     * Subiendo desde $directory (por omisión, el de trabajo), el primer
     * directorio que es un proyecto LaraPack; null si no hay ninguno.
     */
    public static function fromWorkingDirectory(?string $directory = null): ?string
    {
        $directory ??= getcwd();
        $path = $directory === false ? false : realpath($directory);

        if ($path === false) {
            return null;
        }

        while (! self::isProject($path)) {
            $parent = dirname($path);

            if ($parent === $path) {
                return null;
            }

            $path = $parent;
        }

        return $path;
    }

    /**
     * Las marcas de un proyecto LaraPack. Un composer.json cualquiera no
     * basta: tiene que requerir el generador, o sería medir un proyecto que
     * no lo usa. Y un `.larapack/` cuenta por su manifiesto, no por existir:
     * un directorio suelto con un candado de #[HandRolled] —uno así puede
     * quedar en `/` por un comando lanzado desde ahí— convertía en «el
     * proyecto» a cualquier directorio debajo.
     */
    public static function isProject(string $path): bool
    {
        if (is_file($path.'/laraimport.json') || is_file($path.'/.larapack/manifest.json')) {
            return true;
        }

        $composer = ProjectSources::composer($path);

        foreach (['require', 'require-dev'] as $section) {
            if (is_array($composer[$section] ?? null) && array_key_exists(self::PACKAGE, $composer[$section])) {
                return true;
            }
        }

        return false;
    }

    /**
     * El propio generador: este clon o una copia suya (la de vendor/).
     */
    public static function isGenerator(string $path): bool
    {
        $real = realpath($path);

        return ($real !== false && $real === realpath(dirname(__DIR__, 2)))
            || (ProjectSources::composer($path)['name'] ?? null) === self::PACKAGE;
    }

    private static function discover(): string
    {
        return self::fromWorkingDirectory() ?? self::fromPackage();
    }

    private static function fromPackage(): string
    {
        $path = dirname(__DIR__, 2);

        while (! file_exists($path.'/vendor/autoload.php')) {
            $parent = dirname($path);

            // dirname() de una raíz ('D:/' o '/') se devuelve a sí mismo: sin
            // este corte el bucle nunca termina si no hay autoloader arriba.
            if ($parent === $path) {
                throw new RuntimeException(
                    'No se encontró vendor/autoload.php partiendo de '.dirname(__DIR__, 2).'. '
                    .'Ejecuta el generador desde un proyecto con dependencias instaladas, '
                    .'o fija la raíz con ProjectRoot::set().'
                );
            }

            $path = $parent;
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new RuntimeException("La raíz descubierta ya no existe: {$path}");
        }

        return $resolved;
    }
}
