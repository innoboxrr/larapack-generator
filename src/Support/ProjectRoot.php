<?php

namespace Innoboxrr\LarapackGenerator\Support;

use RuntimeException;

/**
 * Resuelve la raíz del proyecto sobre el que escribe el generador.
 *
 * Por defecto se descubre subiendo directorios desde este paquete hasta dar
 * con un `vendor/autoload.php`, que es el comportamiento histórico. Ese
 * descubrimiento depende de dónde esté instalado el paquete, así que puede
 * fijarse explícitamente — imprescindible para poder generar contra un
 * directorio concreto (tests, `--dry-run`, `verify`).
 */
final class ProjectRoot
{
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

    private static function discover(): string
    {
        $path = dirname(__DIR__, 2);

        while (! file_exists($path . '/vendor/autoload.php')) {
            $parent = dirname($path);

            // dirname() de una raíz ('D:/' o '/') se devuelve a sí mismo: sin
            // este corte el bucle nunca termina si no hay autoloader arriba.
            if ($parent === $path) {
                throw new RuntimeException(
                    'No se encontró vendor/autoload.php partiendo de ' . dirname(__DIR__, 2) . '. '
                    . 'Ejecuta el generador desde un proyecto con dependencias instaladas, '
                    . 'o fija la raíz con ProjectRoot::set().'
                );
            }

            $path = $parent;
        }

        return realpath($path);
    }
}
