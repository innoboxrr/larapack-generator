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
    public static function equivalent(array $pairs, string $scopeRoot, ?string $config): array
    {
        $pint = self::available($scopeRoot);

        if ($pint === null || $pairs === []) {
            return [];
        }

        $directory = sys_get_temp_dir().'/larapack-realign-pint-'.bin2hex(random_bytes(6));
        $copies = [];
        $index = 0;

        foreach ($pairs as $key => [$current]) {
            $copy = $directory.'/'.($index++).'/'.basename($current);
            @mkdir(dirname($copy), 0777, true);
            copy($current, $copy);
            $copies[$key] = $copy;
        }

        $command = [PHP_BINARY, $pint, ...($config === null ? [] : ['--config='.$config]), $directory];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);

        if (is_resource($process)) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        $equal = [];

        foreach ($copies as $key => $copy) {
            $comparison = FileComparison::compareFiles($copy, $pairs[$key][1]);

            if ($comparison !== FileComparison::DIFFERENT) {
                $equal[$key] = $comparison;
            }
        }

        Files::remove($directory);

        return $equal;
    }
}
