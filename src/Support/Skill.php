<?php

namespace Innoboxrr\LarapackGenerator\Support;

use RuntimeException;

/**
 * El texto que enseña a un agente a usar el generador.
 *
 * Vive en el paquete y no en el proyecto anfitrión a propósito: es el paquete
 * quien sabe qué genera y dónde deja los huecos, así que actualizar el
 * generador actualiza también las instrucciones. Hasta ahora eso estaba
 * repartido entre un README, varios AGENTS.md y un prompt de 66 KB con copias
 * del código de los tools, que ya habían divergido entre sí.
 */
final class Skill
{
    private const RELATIVE_PATH = '/../../skill/SKILL.md';

    /**
     * Destino por omisión dentro del proyecto anfitrión.
     */
    public const DEFAULT_TARGET = '.claude/skills/larapack/SKILL.md';

    public static function path(): string
    {
        $path = realpath(__DIR__ . self::RELATIVE_PATH);

        if ($path === false) {
            throw new RuntimeException('No se encontró skill/SKILL.md en el paquete.');
        }

        return $path;
    }

    public static function contents(): string
    {
        $contents = file_get_contents(self::path());

        if ($contents === false) {
            throw new RuntimeException('No se pudo leer skill/SKILL.md.');
        }

        return $contents;
    }

    /**
     * Copia el skill al proyecto.
     *
     * @return array{written: bool, target: string, reason: string}
     */
    public static function install(string $target, bool $force): array
    {
        $absolute = self::absolute($target);

        if (file_exists($absolute) && ! $force) {
            // Puede haberse ampliado con reglas del proyecto; sobrescribir
            // sin avisar se llevaría por delante ese trabajo.
            return [
                'written' => self::contents() === file_get_contents($absolute),
                'target' => $absolute,
                'reason' => 'ya existe',
            ];
        }

        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($absolute, self::contents()) === false) {
            throw new RuntimeException("No se pudo escribir {$absolute}.");
        }

        return ['written' => true, 'target' => $absolute, 'reason' => ''];
    }

    private static function absolute(string $target): string
    {
        $normalised = str_replace('\\', '/', $target);

        $isAbsolute = str_starts_with($normalised, '/')
            || preg_match('/^[A-Za-z]:\//', $normalised) === 1;

        return $isAbsolute ? $normalised : root_path() . '/' . ltrim($normalised, '/');
    }
}
