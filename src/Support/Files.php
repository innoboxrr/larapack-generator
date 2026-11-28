<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

/**
 * Leer un archivo del que depende una decisión, y borrar lo que el generador
 * creó para sí (una caché, una copia de trabajo).
 *
 * `(string) file_get_contents()` convierte el `false` de una lectura fallida en
 * `''`, y `''` decide en silencio: un manifiesto que no se pudo leer parecía
 * vacío, el hash de un archivo ilegible lo daba por editado a mano y un
 * composer.json que no se pudo abrir hacía creer que el proyecto era un
 * paquete. Aquí no se lee, se falla, con el archivo en el mensaje.
 */
final class Files
{
    public static function read(string $file): string
    {
        $content = is_file($file) ? @file_get_contents($file) : false;

        if ($content === false) {
            throw MakerException::readFailed($file);
        }

        return $content;
    }

    /**
     * Escribe un archivo del proyecto, sin salir de él por un enlace
     * simbólico (assertInside()). Falla en vez de devolver false.
     *
     * @return int los bytes escritos
     */
    public static function write(string $file, string $content): int
    {
        self::guard($file);

        $written = @file_put_contents($file, $content);

        if ($written === false) {
            throw MakerException::writeFailed($file);
        }

        return $written;
    }

    /**
     * copy() con la misma guarda sobre el destino.
     */
    public static function copy(string $from, string $to): bool
    {
        self::guard($to);

        return copy($from, $to);
    }

    /**
     * Antes de escribir, crear o borrar `$path`: si la ruta es del proyecto
     * (la raíz actual, o `$root`), tiene que quedarse en él.
     *
     * Sin raíz que resolver no hay proyecto del que salirse, y no se
     * comprueba nada.
     */
    public static function guard(string $path, ?string $root = null): void
    {
        if ($root === null) {
            try {
                $root = ProjectRoot::path();
            } catch (\RuntimeException) {
                return;
            }
        }

        self::assertInside($root, $path);
    }

    /**
     * Que escribir (o borrar) `$path` no acabe fuera de `$root` por un enlace
     * simbólico.
     *
     * Un repositorio puede traer cualquier directorio o archivo como enlace:
     * `src/Models` apuntando a un directorio de quien lo clona, o
     * `src/Models/Product.php` como enlace roto hacia un archivo que todavía
     * no existe. `larapack:import` escribía a través de ellos —copy() y
     * file_put_contents() siguen los enlaces—: código generado fuera del
     * proyecto, en la ruta que eligió quien preparó el repositorio. Y la caché
     * del realineamiento se vacía antes de regenerar: con
     * `.larapack/realign/cache` enlazado a otro directorio, lo que se borraba
     * era de ese otro.
     *
     * Se resuelve el camino real del archivo, o del primer antecesor que
     * existe, y tiene que quedar dentro de la raíz; un enlace roto en el
     * camino cuenta como fuera. Un enlace que se queda dentro vale. Una ruta
     * que ya sale de la raíz por escrito (un temporal, `--path=../x`) no se
     * comprueba salvo con `$strict`: ésa la eligió quien ejecuta el comando.
     * Con `$strict` la ruta viene de un archivo del proyecto (el manifiesto,
     * el plan del realineamiento) y salir por escrito también es un error.
     *
     * Es la misma regla que AgentHooks::assertInside() aplica a los archivos
     * de los agentes.
     */
    public static function assertInside(string $root, string $path, bool $strict = false): void
    {
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return;
        }

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $lexical = self::lexical(str_replace('\\', '/', $path));

        if (! self::within($lexical, $realRoot) && ! self::within($lexical, rtrim(self::lexical(str_replace('\\', '/', $root)), '/'))) {
            if ($strict) {
                throw MakerException::outsideProject($path, $lexical);
            }

            return;
        }

        $current = $path;

        while (! file_exists($current)) {
            if (is_link($current)) {
                throw MakerException::outsideProject($path, $current.' (un enlace simbólico roto)');
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return;
            }

            $current = $parent;
        }

        $real = str_replace('\\', '/', (string) realpath($current));

        if (! self::within($real, $realRoot)) {
            throw MakerException::outsideProject($path, $real);
        }
    }

    /**
     * Si el camino real de `$path` —o el de su primer antecesor que existe—
     * queda dentro de `$root`. Un enlace roto en el camino cuenta como fuera.
     */
    public static function isInside(string $root, string $path): bool
    {
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return false;
        }

        $current = $path;

        while (! file_exists($current)) {
            $parent = dirname($current);

            if (is_link($current) || $parent === $current) {
                return false;
            }

            $current = $parent;
        }

        return self::within(str_replace('\\', '/', (string) realpath($current)), rtrim(str_replace('\\', '/', $realRoot), '/'));
    }

    /**
     * Una ruta relativa que viene de un archivo del proyecto —una clave del
     * manifiesto, un archivo de un paso del plan—: sin `..`, sin ser absoluta
     * y sin NUL. Con una así, `<raíz>/<ruta>` sale de la raíz.
     */
    public static function isRelativeInside(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $relative), true);
    }

    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    /**
     * La ruta con `.` y `..` resueltos sobre el texto, sin mirar el disco.
     */
    private static function lexical(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $index => $part) {
            if ($part === '..' && $parts !== [] && end($parts) !== '' && end($parts) !== '..') {
                array_pop($parts);
            } elseif ($part !== '.' && ($part !== '' || $index === 0)) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Borra un archivo o un directorio entero. Un enlace simbólico se borra
     * él, nunca aquello a lo que apunta: la copia de trabajo de una
     * simulación enlaza `vendor/` y `node_modules/` del proyecto. Lo que no
     * se puede borrar se deja: es limpieza, no una decisión.
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
