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
     * La guía del realineamiento para un agente: un segundo skill, aparte,
     * porque se usa en otro momento (llevar un proyecto de vuelta al
     * contrato) y un agente que sólo genera no necesita leerla entera.
     */
    private const REALIGN_RELATIVE_PATH = '/../../skill/realign/SKILL.md';

    /**
     * Destino por omisión dentro del proyecto anfitrión.
     */
    public const DEFAULT_TARGET = '.claude/skills/larapack/SKILL.md';

    /**
     * Destino de la guía del realineamiento, al lado del skill.
     */
    public const REALIGN_TARGET = '.claude/skills/larapack-realign/SKILL.md';

    public static function path(): string
    {
        $path = realpath(__DIR__.self::RELATIVE_PATH);

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

    public static function realignPath(): string
    {
        $path = realpath(__DIR__.self::REALIGN_RELATIVE_PATH);

        if ($path === false) {
            throw new RuntimeException('No se encontró skill/realign/SKILL.md en el paquete.');
        }

        return $path;
    }

    public static function realignContents(): string
    {
        $contents = file_get_contents(self::realignPath());

        if ($contents === false) {
            throw new RuntimeException('No se pudo leer skill/realign/SKILL.md.');
        }

        return $contents;
    }

    /**
     * Dónde va la guía del realineamiento si el skill va en `$target`: en un
     * directorio de skills (`…/larapack/SKILL.md`), su hermano
     * `…/larapack-realign/SKILL.md`, que es donde un agente busca skills. En
     * otro destino (`AGENTS.md`, un archivo suelto) no hay dónde ponerla sin
     * adivinar: null, y el bloque de AGENTS.md apunta a la guía del paquete.
     */
    public static function realignTargetFor(string $target): ?string
    {
        $normalised = str_replace('\\', '/', $target);

        if (basename($normalised) !== 'SKILL.md') {
            return null;
        }

        $directory = dirname($normalised);

        return (dirname($directory) === '.' ? '' : dirname($directory).'/').'larapack-realign/SKILL.md';
    }

    /**
     * Copia la guía del realineamiento al proyecto, con las mismas reglas que
     * el skill: no pisa una modificada sin `$force`.
     *
     * @return array{written: bool, target: string, reason: string}
     */
    public static function installRealign(string $target, bool $force): array
    {
        return self::write($target, self::realignContents(), $force);
    }

    /**
     * Copia el skill al proyecto.
     *
     * @return array{written: bool, target: string, reason: string}
     */
    public static function install(string $target, bool $force): array
    {
        return self::write($target, self::contents(), $force);
    }

    /**
     * @return array{written: bool, target: string, reason: string}
     */
    private static function write(string $target, string $contents, bool $force): array
    {
        $absolute = self::absolute($target);

        // Una ruta relativa es del proyecto, y tiene que quedarse en él aunque
        // `.claude` sea un enlace (AgentHooks::assertInside()). Una absoluta
        // la eligió quien ejecuta el comando.
        if (! self::isAbsolute($target)) {
            AgentHooks::assertInside(root_path(), $absolute);
        }

        if (file_exists($absolute) && ! $force) {
            // Puede haberse ampliado con reglas del proyecto; sobrescribir
            // sin avisar se llevaría por delante ese trabajo.
            return [
                'written' => $contents === file_get_contents($absolute),
                'target' => $absolute,
                'reason' => 'ya existe',
            ];
        }

        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new RuntimeException("No se pudo escribir {$absolute}.");
        }

        return ['written' => true, 'target' => $absolute, 'reason' => ''];
    }

    private static function absolute(string $target): string
    {
        $normalised = str_replace('\\', '/', $target);

        return self::isAbsolute($target) ? $normalised : root_path().'/'.ltrim($normalised, '/');
    }

    private static function isAbsolute(string $target): bool
    {
        $normalised = str_replace('\\', '/', $target);

        return str_starts_with($normalised, '/')
            || preg_match('/^[A-Za-z]:\//', $normalised) === 1;
    }
}
