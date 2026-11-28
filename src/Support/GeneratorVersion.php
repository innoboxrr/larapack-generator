<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Composer\InstalledVersions;

/**
 * La versión del generador que está corriendo.
 *
 * El manifiesto la anota en cada archivo que escribe (versión 2 del
 * manifiesto) y el diagnóstico de `larapack:realign` la usa para separar lo
 * que difiere porque el generador cambió de lo que difiere porque alguien lo
 * editó. Sin saber con qué versión se escribió un archivo, un salto de
 * plantilla entre la 7.x y la 9.x se ve igual que una edición a mano.
 *
 * Se lee de `VERSION`, que viaja con el paquete (no está en `export-ignore`),
 * y si no está, de Composer. Si ninguna de las dos lo sabe, `unknown`: es
 * mejor decir que no se sabe que inventar un número.
 */
final class GeneratorVersion
{
    public const UNKNOWN = 'unknown';

    private const PACKAGE = 'innoboxrr/larapack-generator';

    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= self::detect();
    }

    /**
     * Si `$version` es anterior a la que corre. Una versión desconocida
     * cuenta como anterior: es justo el caso del manifiesto v1, escrito
     * antes de que se anotara.
     */
    public static function isOlder(?string $version): bool
    {
        if ($version === null || $version === '' || $version === self::UNKNOWN) {
            return true;
        }

        $current = self::current();

        return $current !== self::UNKNOWN && version_compare(ltrim($version, 'v'), $current, '<');
    }

    private static function detect(): string
    {
        $file = dirname(__DIR__, 2).'/VERSION';

        if (is_file($file)) {
            $version = self::normalise((string) file_get_contents($file));

            if ($version !== null) {
                return $version;
            }
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
            $version = self::normalise((string) InstalledVersions::getPrettyVersion(self::PACKAGE));

            if ($version !== null) {
                return $version;
            }
        }

        return self::UNKNOWN;
    }

    private static function normalise(string $version): ?string
    {
        $version = ltrim(trim($version), 'v');

        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : null;
    }
}
