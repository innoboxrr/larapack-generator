<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

/**
 * Lo que una migración le hace a las tablas, ya leído.
 *
 * Cada operación es un array con:
 *  - `kind`: create | table | drop | rename | sql | dynamic
 *  - `table`: la tabla, o null si va en una expresión
 *  - `conditional`: si puede que no corra
 *  - `statements`: lo que hace con las columnas
 *  - `opaque`: por qué no se puede leer, o null
 *  - `sql`: el SQL crudo, si se pudo leer
 *
 * @phpstan-type Statement array<string, mixed>
 * @phpstan-type Operation array<string, mixed>
 */
final class MigrationReading
{
    /**
     * @param  list<array<string, mixed>>  $operations
     * @param  list<string>  $mentions  las cadenas que aparecen en el archivo
     */
    public function __construct(
        public readonly string $file,
        public readonly array $operations,
        public readonly array $mentions,
    ) {
        $this->identifiers = array_values(array_filter(
            $mentions,
            fn (string $mention): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $mention) === 1
        ));
    }

    /**
     * Las cadenas del archivo que podrían ser el nombre de una columna.
     *
     * @var list<string>
     */
    public readonly array $identifiers;

    public function mentions(string $table): bool
    {
        return in_array($table, $this->mentions, true);
    }
}
