<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Formatter;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;

/**
 * El último nivel de «igual salvo formato»: pasar una copia del archivo del
 * proyecto por la misma Pint, con la misma configuración, con la que se
 * formateó lo regenerado, y comparar. Reconoce lo que la comparación por
 * tokens no puede: imports agrupados o sueltos, comillas, paréntesis que
 * Pint pone o quita.
 *
 * El archivo del proyecto nunca se toca: se copia a un directorio temporal,
 * se formatea la copia y se borra al terminar. Sin Pint (LARAPACK_PINT=off o
 * ninguna instalada) no se hace nada y se dice en el informe.
 */
final class PintPass
{
    /**
     * Menos archivos que estos no se parten en otro proceso.
     */
    private const MINIMUM_PER_PROCESS = 8;

    /**
     * La Pint que se usaría en este ámbito, o null si no hay.
     */
    public static function available(string $scopeRoot): ?string
    {
        return ProjectRoot::using($scopeRoot, fn (): ?string => Formatter::pint());
    }

    /**
     * Cuáles de los pares quedan iguales a lo regenerado tras formatear la
     * copia del archivo del proyecto.
     *
     * @param  array<string, array{0: string, 1: string}>  $pairs  clave => [archivo del proyecto, regenerado]
     * @return array<string, string> clave => la comparación tras Pint (FileComparison::IDENTICAL, FORMAT o COMMENTS) de las que ya no son distintas
     */
    public static function equivalent(array $pairs, string $scopeRoot, ?string $config, int $jobs = 1): array
    {
        return self::equivalentMany([['pairs' => $pairs, 'root' => $scopeRoot, 'config' => $config]], $jobs)[0];
    }

    /**
     * Lo mismo para varios ámbitos de una vez. Pint tarda en arrancar y
     * después va archivo a archivo en un solo núcleo (unos 25 ms por
     * archivo): lanzada una vez por ámbito, y una detrás de otra, era casi
     * todo lo que tardaba un diagnóstico con la caché caliente. Aquí los
     * archivos de todos los ámbitos que comparten Pint y configuración —en
     * un monorepo, todos— se reparten en hasta `$jobs` procesos a la vez.
     *
     * El resultado no depende del reparto: Pint formatea cada archivo por
     * separado, y el ámbito sólo decide qué Pint y qué configuración.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{pairs: array<string, array{0: string, 1: string}>, root: string, config: string|null}>  $batches
     * @return array<TKey, array<string, string>> por lote, lo que devuelve equivalent()
     */
    public static function equivalentMany(array $batches, int $jobs = 1): array
    {
        $equal = array_map(fn (array $batch): array => [], $batches);
        /** @var array<string, array{pint: string, config: string|null, items: list<array{0: TKey, 1: string, 2: string, 3: string}>}> $groups */
        $groups = [];

        foreach ($batches as $key => $batch) {
            $pint = $batch['pairs'] === [] ? null : self::available($batch['root']);

            if ($pint === null) {
                continue;
            }

            $group = $pint."\0".$batch['config'];
            $groups[$group] ??= ['pint' => $pint, 'config' => $batch['config'], 'items' => []];

            foreach ($batch['pairs'] as $pair => [$current, $regenerated]) {
                $groups[$group]['items'][] = [$key, $pair, $current, $regenerated];
            }
        }

        if ($groups === []) {
            return $equal;
        }

        $directory = sys_get_temp_dir().'/larapack-realign-pint-'.bin2hex(random_bytes(6));
        $jobs = max(1, $jobs);
        $pending = [];
        $copies = [];

        foreach (array_values($groups) as $number => $group) {
            // Un proceso más sólo compensa su arranque con unos cuantos
            // archivos que formatear.
            $size = max(self::MINIMUM_PER_PROCESS, (int) ceil(count($group['items']) / $jobs));

            foreach (array_chunk($group['items'], $size) as $part => $items) {
                $target = $directory.'/'.$number.'-'.$part;

                foreach ($items as $index => [$key, $pair, $current, $regenerated]) {
                    $copy = $target.'/'.$index.'/'.basename($current);
                    @mkdir(dirname($copy), 0777, true);
                    copy($current, $copy);
                    $copies[] = [$key, $pair, $copy, $regenerated];
                }

                $pending[] = ['command' => [PHP_BINARY, $group['pint'], ...($group['config'] === null ? [] : ['--config='.$group['config']]), $target], 'directory' => $target];
            }
        }

        self::runAll($pending, $jobs);

        foreach ($copies as [$key, $pair, $copy, $regenerated]) {
            $comparison = FileComparison::compareFiles($copy, $regenerated);

            if ($comparison !== FileComparison::DIFFERENT) {
                $equal[$key][$pair] = $comparison;
            }
        }

        Files::remove($directory);

        return $equal;
    }

    /**
     * Corre las órdenes, hasta `$jobs` a la vez. La salida de Pint no se
     * usa y va a un archivo: con varios procesos a la vez, una tubería sin
     * leer se llena y deja al hijo esperando.
     *
     * @param  list<array{command: list<string>, directory: string}>  $pending
     */
    private static function runAll(array $pending, int $jobs): void
    {
        $running = [];

        while ($pending !== [] || $running !== []) {
            while ($pending !== [] && count($running) < $jobs) {
                $job = array_shift($pending);
                $process = proc_open($job['command'], [0 => ['pipe', 'r'], 1 => ['file', $job['directory'].'.out', 'w'], 2 => ['file', $job['directory'].'.out', 'a']], $pipes, $job['directory']);

                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $running[] = $process;
                }
            }

            // Con uno solo no hay nada que vigilar: se espera a que acabe.
            if (count($running) === 1 && $pending === []) {
                proc_close(array_pop($running));

                continue;
            }

            foreach ($running as $index => $process) {
                if (! proc_get_status($process)['running']) {
                    proc_close($process);
                    unset($running[$index]);
                }
            }

            if ($running !== []) {
                usleep(5_000);
            }
        }
    }
}
