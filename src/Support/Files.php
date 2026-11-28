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
