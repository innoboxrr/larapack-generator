<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

/**
 * Lo que el generador sabe de los métodos de columna de Blueprint: cuáles
 * crean una sola columna, cuáles son números y cuáles son la misma columna
 * escrita de otra forma.
 *
 * Es lo que permite comparar el esquema por lo que SIGNIFICA y no por cómo se
 * escribió: `foreignId('x')` y `unsignedBigInteger('x')` son la misma columna,
 * e `integer('x')->unsigned()` es `unsignedInteger('x')`.
 */
final class ColumnTypes
{
    /**
     * Métodos que crean UNA columna cuyo nombre es el primer argumento.
     */
    public const SINGLE = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'computed', 'date', 'dateTime', 'dateTimeTz',
        'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignUlid', 'foreignUuid', 'geography', 'geometry',
        'increments', 'integer', 'ipAddress', 'json', 'jsonb', 'longText', 'macAddress', 'mediumIncrements',
        'mediumInteger', 'mediumText', 'set', 'smallIncrements', 'smallInteger', 'string', 'text', 'time', 'timeTz',
        'timestamp', 'timestampTz', 'tinyIncrements', 'tinyInteger', 'tinyText', 'unsignedBigInteger',
        'unsignedDecimal', 'unsignedDouble', 'unsignedFloat', 'unsignedInteger', 'unsignedMediumInteger',
        'unsignedSmallInteger', 'unsignedTinyInteger', 'year', 'vector',
    ];

    /**
     * Columnas en las que un default numérico se escribe sin comillas: en las
     * demás, la base guarda una cadena y `1.50` sin comillas llega como 1.5.
     */
    private const NUMERIC = [
        'bigIncrements', 'bigInteger', 'boolean', 'decimal', 'double', 'float', 'foreignId', 'id', 'increments',
        'integer', 'mediumIncrements', 'mediumInteger', 'smallIncrements', 'smallInteger', 'tinyIncrements',
        'tinyInteger', 'unsignedBigInteger', 'unsignedDecimal', 'unsignedDouble', 'unsignedFloat', 'unsignedInteger',
        'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'year',
    ];

    /**
     * La misma columna, escrita de otra forma.
     */
    private const SYNONYMS = [
        'foreignId' => 'unsignedBigInteger',
        'foreignUuid' => 'uuid',
        'foreignUlid' => 'ulid',
        'id' => 'bigIncrements',
    ];

    /**
     * `->unsigned()` sobre un entero es el entero sin signo.
     */
    private const UNSIGNED = [
        'bigInteger' => 'unsignedBigInteger',
        'integer' => 'unsignedInteger',
        'mediumInteger' => 'unsignedMediumInteger',
        'smallInteger' => 'unsignedSmallInteger',
        'tinyInteger' => 'unsignedTinyInteger',
    ];

    public static function isNumeric(string $method): bool
    {
        return in_array($method, self::NUMERIC, true);
    }

    /**
     * El tipo con el que se compara: dos métodos con el mismo canónico crean
     * la misma columna.
     */
    public static function canonical(string $method, bool $unsigned = false): string
    {
        $method = self::SYNONYMS[$method] ?? $method;

        return $unsigned ? (self::UNSIGNED[$method] ?? $method) : $method;
    }
}
