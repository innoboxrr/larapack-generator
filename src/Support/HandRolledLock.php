<?php

namespace Innoboxrr\LarapackGenerator\Support;

use RuntimeException;

/**
 * Las excepciones #[HandRolled] que aprobó el dueño del proyecto.
 *
 * En 9.0 un #[HandRolled] con razón excusaba la forma y `verify` lo listaba
 * como aviso. Eso deja la decisión en manos de quien escribe el atributo, y
 * un agente que quiere ver `verify` en verde escribe una línea. El candado
 * separa las dos cosas: el código declara la excepción y el candado dice que
 * alguien la aprobó. Como es un archivo del repositorio, la aprobación pasa
 * por el mismo control que cualquier cambio —CODEOWNERS pide la revisión del
 * dueño (`larapack:ci --owner`)— y queda en el historial.
 *
 * Se guarda la razón y no sólo el objetivo: si alguien cambia la razón en el
 * código, la excepción ya no es la que se aprobó, y vuelve a pedir aprobación.
 *
 * El formato es una lista de {target, reason} ordenada por `target`, con
 * sangría fija y salto de línea final: dos aprobaciones de lo mismo producen
 * el mismo archivo byte a byte, y el diff de una revisión sólo enseña lo que
 * cambió.
 */
final class HandRolledLock
{
    public const PATH = '.larapack/hand-rolled.lock';

    public static function path(string $root): string
    {
        return rtrim(str_replace('\\', '/', $root), '/').'/'.self::PATH;
    }

    public static function exists(string $root): bool
    {
        return is_file(self::path($root));
    }

    /**
     * Las aprobaciones, objetivo => razón, o null si no hay candado.
     *
     * Un candado que no se puede leer no se trata como ausente: eso volvería
     * al comportamiento de 9.0 sin que nadie lo decidiera. Se lanza, y quien
     * llama decide cómo fallar.
     *
     * @return array<string, string>|null
     */
    public static function read(string $root): ?array
    {
        $file = self::path($root);

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException(self::PATH.' no es una lista JSON de {"target", "reason"}.');
        }

        $approved = [];

        foreach ($decoded as $index => $entry) {
            $target = is_array($entry) ? ($entry['target'] ?? null) : null;
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;

            if (! is_string($target) || trim($target) === '' || ! is_string($reason) || trim($reason) === '') {
                throw new RuntimeException(self::PATH." tiene una entrada sin `target` o sin `reason` (posición {$index}).");
            }

            if (isset($approved[$target])) {
                throw new RuntimeException(self::PATH." aprueba dos veces {$target}.");
            }

            $approved[$target] = $reason;
        }

        return $approved;
    }

    /**
     * @param  array<string, string>  $approved  objetivo => razón
     */
    public static function write(string $root, array $approved): void
    {
        $file = self::path($root);
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($file, self::encode($approved)) === false) {
            throw new RuntimeException("No se pudo escribir {$file}.");
        }
    }

    /**
     * @param  array<string, string>  $approved  objetivo => razón
     */
    public static function encode(array $approved): string
    {
        // Orden binario y no el de la configuración regional: el mismo en
        // cualquier máquina.
        ksort($approved, SORT_STRING);

        $entries = [];

        foreach ($approved as $target => $reason) {
            $entries[] = ['target' => (string) $target, 'reason' => $reason];
        }

        return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
