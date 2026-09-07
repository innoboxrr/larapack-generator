<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Sellos de tiempo crecientes para los nombres de archivo de migración.
 *
 * El nombre lleva `Y_m_d_His`, con resolución de un segundo, así que generar
 * varios modelos seguidos producía colisiones. Se resolvía durmiendo: dos
 * segundos por migración y tres por pivote, es decir cerca de un minuto para
 * un laraimport.json de veinte modelos.
 *
 * Un contador monotónico da lo mismo —nombres distintos y en orden— sin
 * esperar, que además es lo que de verdad importa: el orden decide en qué
 * secuencia corre `php artisan migrate`.
 */
final class MigrationTimestamp
{
    private static ?int $last = null;

    public static function next(): string
    {
        $now = time();

        self::$last = self::$last === null ? $now : max($now, self::$last + 1);

        // gmdate en lugar de date: el nombre no debe depender de la zona
        // horaria de quien ejecuta el generador.
        return gmdate('Y_m_d_His', self::$last);
    }

    public static function reset(): void
    {
        self::$last = null;
    }
}
