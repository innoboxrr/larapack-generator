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

    /**
     * Los índices de la tabla (9.4.0): los de `$table->index()` y los de un
     * modificador de columna (`->unique()`), con el nombre que les pone
     * Laravel si no lo declaran y el archivo que los puso.
     *
     * @var list<array{type: string, columns: list<string>, name: string, origin: string}>
     */
    public array $indexes = [];

    /**
     * Por qué no se sabe qué índices tiene: uno con columnas que no se leen,
     * o uno que se pone o se quita dentro de un `if`.
     *
     * @var list<string>
     */
    public array $indexOpaque = [];

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

    /**
     * El nombre que Laravel le pone a un índice que no lo declara
     * (Blueprint::createIndexName): tabla, columnas y tipo en minúsculas.
     *
     * @param  list<string>  $columns
     */
    public function defaultIndexName(string $type, array $columns): string
    {
        return str_replace(['-', '.'], '_', strtolower($this->name.'_'.implode('_', $columns).'_'.$type));
    }

    /**
     * Si ya hay un índice del tipo y con esas columnas (en ese orden), o con
     * ese nombre. Un `unique` cuenta como índice normal: ya sirve a las mismas
     * consultas, y añadir otro normal sobre las mismas columnas no aporta.
     *
     * @param  list<string>  $columns
     */
    public function hasIndex(string $type, array $columns, ?string $name = null): bool
    {
        foreach ($this->indexes as $index) {
            if ($name !== null && $index['name'] === $name) {
                return true;
            }

            if ($index['columns'] === $columns && ($index['type'] === $type || ($type === 'index' && in_array($index['type'], ['unique', 'primary'], true)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    public function addIndex(string $type, array $columns, ?string $name, string $file): void
    {
        $this->indexes[] = ['type' => $type, 'columns' => $columns, 'name' => $name ?? $this->defaultIndexName($type, $columns), 'origin' => $file];
    }

    /**
     * Quita un índice por su nombre o, sin nombre, por sus columnas.
     *
     * @param  list<string>|null  $columns
     */
    public function dropIndex(string $type, ?string $name, ?array $columns): void
    {
        $name ??= $columns === null ? null : $this->defaultIndexName($type, $columns);

        $this->indexes = array_values(array_filter($this->indexes, fn (array $index): bool => $index['name'] !== $name));
    }

    /**
     * Al quitar una columna, la base quita los índices que la llevan.
     */
    public function dropIndexesOf(string $column): void
    {
        $this->indexes = array_values(array_filter($this->indexes, fn (array $index): bool => ! in_array($column, $index['columns'], true)));
    }

    public function markUncertain(string $column, string $file): void
    {
        $this->uncertain[$column][] = $file;
    }
}
