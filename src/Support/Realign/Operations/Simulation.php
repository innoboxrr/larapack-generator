<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use FilesystemIterator;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use RuntimeException;

/**
 * El `--dry-run` de las operaciones que reescriben código o el manifiesto
 * (`apply`, `adopt`): la misma corrida que la de verdad, sobre una copia
 * temporal del proyecto, y luego se tira la copia.
 *
 * Antes cada paso simulaba contra el disco de hoy: lo que escribía un paso
 * no lo veía el siguiente, y la simulación contaba otra cosa que la corrida
 * real —un trait que `regenerate` deja bien lo volvía a «arreglar» `fix`,
 * `model-lists` no veía lo que había añadido `fix` al mismo modelo, y lo
 * fijado por `pin-foreign-keys` no cambiaba el contrato de los pasos que
 * venían después—. En dos proyectos reales: 2276 cambios simulados contra
 * 2272 reales en uno, 1215 contra 447 en otro. Con la copia la simulación
 * toma las mismas decisiones, en el mismo orden, con los mismos saltos,
 * porque ES la corrida, sólo que en otro sitio.
 *
 * La copia lleva todo menos `.git`; `vendor/` y `node_modules/` (a cualquier
 * profundidad) y la caché de lo regenerado (`.larapack/realign/cache`) son
 * enlaces simbólicos a los del proyecto: se leen, no se escriben, y copiarlos
 * costaría más que la corrida. Cada elemento de `fixed` trae el diff de lo
 * que cambió ese paso sobre lo que dejaron los anteriores. Las rutas de la
 * copia que aparezcan en el informe se devuelven como rutas del proyecto.
 */
final class Simulation
{
    /**
     * Las acciones que se simulan así: las que escriben lo que lee el paso
     * siguiente.
     */
    public const ACTIONS = ['apply', 'adopt'];

    /**
     * Lo que no se copia sino que se enlaza, por su nombre de directorio.
     */
    private const LINKED = ['vendor', 'node_modules'];

    /**
     * La caché de lo regenerado de cada copia en curso (ruta del enlace en la
     * copia => true): el único enlace hacia fuera de la raíz a través del que
     * la regeneración escribe, porque lo puso esta clase.
     *
     * @var array<string, true>
     */
    private static array $caches = [];

    /**
     * Si esa caché (`<raíz>/.larapack/realign/cache`) es el enlace que una
     * simulación en curso puso hacia la del proyecto.
     */
    public static function linkedCache(string $cache): bool
    {
        return isset(self::$caches[str_replace('\\', '/', $cache)]);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, string>  $options
     * @param  list<string>  $only
     * @return array{fixed: list<array<string, mixed>>, skipped: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, notes: list<string>}
     */
    public static function run(string $root, RealignOperation $operation, array $steps, array $options, array $only = []): array
    {
        $root = rtrim($root, '/');
        $copy = sys_get_temp_dir().'/larapack-realign-dry-run-'.bin2hex(random_bytes(6));

        try {
            self::copyTree($root, $copy, $root);
            // Con /tmp enlazado (macOS), la raíz que se fija es la real.
            $real = realpath($copy) ?: $copy;

            // Sólo si la del proyecto es un directorio de verdad: un enlace
            // que ya traía el proyecto se copia como enlace, y no es de fiar.
            if (is_dir($root.'/'.RealignState::DIRECTORY.'/cache') && ! is_link($root.'/'.RealignState::DIRECTORY.'/cache') && is_link($real.'/'.RealignState::DIRECTORY.'/cache')) {
                self::$caches[str_replace('\\', '/', $real).'/'.RealignState::DIRECTORY.'/cache'] = true;
            }

            ScopeReference::forget();
            $result = ProjectRoot::using($real, fn (): array => $operation->run(
                new OperationContext($real, new RealignState($real), $steps, false, $options, $only, true)
            )->toArray());

            /** @var array{fixed: list<array<string, mixed>>, skipped: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, notes: list<string>} $result */
            $result = self::relocate(self::relocate($result, $real, $root), $copy, $root);

            return $result;
        } finally {
            // Lo que se cacheó de la copia no vale para el proyecto.
            ScopeReference::forget();
            unset(self::$caches[str_replace('\\', '/', realpath($copy) ?: $copy).'/'.RealignState::DIRECTORY.'/cache']);
            Files::remove($copy);
        }
    }

    private static function copyTree(string $from, string $to, string $root): void
    {
        if (! is_dir($to) && ! mkdir($to, 0777, true) && ! is_dir($to)) {
            throw new RuntimeException("No se pudo crear la copia temporal para simular ({$to}).");
        }

        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            $name = $entry->getFilename();
            $source = $from.'/'.$name;
            $target = $to.'/'.$name;

            if ($name === '.git' && $from === $root) {
                continue;
            }

            if ($entry->isLink()) {
                $link = readlink($source);

                if ($link !== false) {
                    symlink($link, $target);
                }

                continue;
            }

            if ($entry->isDir()) {
                if (in_array($name, self::LINKED, true) || $source === $root.'/'.RealignState::DIRECTORY.'/cache') {
                    symlink($source, $target);

                    continue;
                }

                self::copyTree($source, $target, $root);

                continue;
            }

            copy($source, $target);
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function relocate(array $value, string $copy, string $root): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = match (true) {
                is_string($item) => str_replace($copy, $root, $item),
                is_array($item) => self::relocate($item, $copy, $root),
                default => $item,
            };
        }

        return $value;
    }
}
