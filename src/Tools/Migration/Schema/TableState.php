<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

/**
 * Una tabla tal como la dejan, en orden, TODAS las migraciones del
 * directorio: las que generó LaraPack y las que escribió una persona, se
 * llamen como se llamen.
 *
 * No sólo dice qué columnas hay: dice qué archivo puso cada una —para no
 * deshacer lo que alguien escribió a mano— y qué no se pudo leer, para no
 * adivinarlo.
 */
final class TableState
{
    /**
     * @var array<string, ColumnDefinition>
     */
    public array $columns = [];

    /**
     * El archivo que definió por última vez cada columna.
     *
     * @var array<string, string>
     */
    public array $origin = [];

    /**
     * El archivo que puso la llave foránea de cada columna.
     *
     * @var array<string, string>
     */
    public array $foreignOrigin = [];

    /**
     * Columnas que algún archivo toca de una forma que no se lee: con un `if`
     * que no es una guarda, con un método desconocido.
     *
     * @var array<string, list<string>>
     */
    public array $uncertain = [];

    /**
     * SQL crudo que nombra la tabla: las columnas que nombre quedan inciertas.
     *
     * @var list<array{file: string, sql: string}>
     */
    public array $sql = [];

    /**
     * Por qué no se sabe qué columnas tiene la tabla, archivo por archivo.
     *
     * @var list<string>
     */
    public array $opaque = [];

    /**
     * Por qué no se sabe qué llaves foráneas tiene: SQL armado en el momento
     * que pone o quita llaves de una tabla en una variable.
     *
     * @var list<string>
     */
    public array $foreignOpaque = [];

    /**
     * Archivos que cambian columnas con SQL armado en el momento: se dejan
     * fuera las columnas que nombran, y lo que se escriba sobre la tabla lo
     * dice.
     *
     * @var list<string>
     */
    public array $guessed = [];

    /**
     * Todos los archivos que tocan la tabla, en orden.
     *
     * @var list<string>
     */
    public array $files = [];

    public ?string $creator = null;

    public bool $exists = false;

    public function __construct(public readonly string $name) {}

    /**
     * Los archivos que la tocan además de `$file`.
     *
     * @return list<string>
     */
    public function filesBesides(string $file): array
    {
        return array_values(array_filter($this->files, fn (string $other): bool => $other !== $file));
    }

    /**
     * Por qué una columna no se puede comparar, o null si se puede.
     */
    public function doubtAbout(string $column): ?string
    {
        if (isset($this->uncertain[$column])) {
            return implode(', ', array_map('basename', array_unique($this->uncertain[$column])));
        }

        foreach ($this->sql as $statement) {
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($column, '/').'(?![A-Za-z0-9_])/', $statement['sql']) === 1) {
                return basename($statement['file']).' (SQL crudo)';
            }
        }

        return null;
    }

    public function markUncertain(string $column, string $file): void
    {
        $this->uncertain[$column][] = $file;
    }
}
