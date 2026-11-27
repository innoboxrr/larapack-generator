<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

/**
 * El esquema que dejan las migraciones de un directorio, leídas TODAS y en el
 * orden en que las corre `migrate`: por el nombre del archivo.
 *
 * Hasta 9.3.0 el generador sólo leía su migración de creación y las que se
 * llamaban `*_alter_<tabla>_table.php`. Una migración escrita a mano con otro
 * nombre quedaba fuera, y el generador volvía a añadir lo que ella ya añadía.
 */
final class SchemaHistory
{
    /**
     * Las migraciones leídas, en orden: se leen una vez por historia.
     *
     * @var list<MigrationReading>|null
     */
    private ?array $readings = null;

    /**
     * @var array<string, TableState>
     */
    private array $tables = [];

    public function __construct(private readonly string $directory) {}

    /**
     * @return list<string>
     */
    public function files(): array
    {
        $files = glob($this->directory.'/*.php') ?: [];

        usort($files, fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        return $files;
    }

    /**
     * Las tablas que crea alguna migración del directorio y siguen existiendo
     * al final, en el orden en que se crean (9.4.0, para inferir el contrato).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $this->readings ??= array_map(fn (string $file): MigrationReading => MigrationReader::read($file), $this->files());
        $names = [];

        foreach ($this->readings as $reading) {
            foreach ($reading->operations as $operation) {
                if (($operation['kind'] ?? null) === 'create' && is_string($operation['table'] ?? null) && ! in_array($operation['table'], $names, true)) {
                    $names[] = $operation['table'];
                }
            }
        }

        return array_values(array_filter($names, fn (string $name): bool => $this->table($name)->exists));
    }

    public function table(string $name): TableState
    {
        if (isset($this->tables[$name])) {
            return $this->tables[$name];
        }

        $this->readings ??= array_map(fn (string $file): MigrationReading => MigrationReader::read($file), $this->files());

        $state = new TableState($name);

        foreach ($this->readings as $reading) {
            foreach ($reading->operations as $operation) {
                $this->apply($state, $reading, $operation);
            }
        }

        return $this->tables[$name] = $state;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function apply(TableState $state, MigrationReading $reading, array $operation): void
    {
        $file = $reading->file;
        $kind = $operation['kind'];
        $opaque = is_string($operation['opaque'] ?? null) ? $operation['opaque'] : null;

        if ($kind === 'sql') {
            $sql = is_string($operation['sql'] ?? null) ? $operation['sql'] : null;
            $effect = $operation['effect'] ?? 'unknown';

            if ($sql !== null ? ! self::names($sql, $state->name) : ! $reading->mentions($state->name)) {
                return;
            }

            $this->touch($state, $file);

            // Datos, índices, CHECK: no cambian columnas.
            if ($effect === 'none') {
                return;
            }

            if ($sql !== null) {
                $state->sql[] = ['file' => $file, 'sql' => $sql];
            } elseif ($effect === 'foreign') {
                $state->foreignOpaque[] = basename($file).': '.$opaque.' que pone o quita llaves';
            } elseif ($effect === 'columns') {
                // No se sabe qué columna toca, pero sale de algún nombre que
                // el archivo escribe: todos esos quedan inciertos. Lo que el
                // archivo no nombra no lo puede tocar, salvo un nombre armado
                // a trozos, y eso se avisa al escribir la alteración.
                foreach ($reading->identifiers as $identifier) {
                    $state->markUncertain($identifier, $file);
                }

                $state->guessed[] = basename($file);
            } else {
                $state->opaque[] = basename($file).': '.$opaque.' que crea, borra o renombra tablas';
            }

            return;
        }

        if ($kind === 'dynamic') {
            $table = $operation['table'] ?? null;

            if ($table !== $state->name && ($table !== null || ! $reading->mentions($state->name))) {
                return;
            }

            $this->touch($state, $file);

            if ($opaque !== null) {
                $state->opaque[] = basename($file).': '.$opaque;

                return;
            }

            // Se lee lo que hace, pero no a qué tabla o cuántas veces: las
            // columnas que toca quedan inciertas.
            foreach (is_array($operation['statements'] ?? null) ? $operation['statements'] : [] as $statement) {
                if (is_array($statement)) {
                    $this->statement($state, $file, $statement, true);
                }
            }

            return;
        }

        if ($kind === 'rename') {
            $tables = is_array($operation['tables'] ?? null) ? $operation['tables'] : [];

            if (in_array($state->name, $tables, true)) {
                $this->touch($state, $file);
                $state->opaque[] = basename($file).': Schema::rename()';
            }

            return;
        }

        if (($operation['table'] ?? null) !== $state->name) {
            return;
        }

        $this->touch($state, $file);

        if ($kind === 'drop') {
            $state->exists = false;
            $state->columns = [];
            $state->origin = [];
            $state->foreignOrigin = [];
            $state->indexes = [];
            $state->indexOpaque = [];

            return;
        }

        if ($kind === 'create') {
            $state->exists = true;
            $state->creator = $file;
            $state->columns = [];
            $state->origin = [];
            $state->foreignOrigin = [];
            $state->indexes = [];
            $state->indexOpaque = [];
        }

        if ($opaque !== null) {
            $state->opaque[] = basename($file).': '.$opaque;

            return;
        }

        $conditional = (bool) ($operation['conditional'] ?? false);

        foreach (is_array($operation['statements'] ?? null) ? $operation['statements'] : [] as $statement) {
            if (is_array($statement)) {
                $this->statement($state, $file, $statement, $conditional);
            }
        }
    }

    /**
     * @param  array<mixed>  $statement
     */
    private function statement(TableState $state, string $file, array $statement, bool $conditional): void
    {
        $names = is_array($statement['names'] ?? null) ? array_filter($statement['names'], 'is_string') : [];

        switch ($statement['kind'] ?? null) {
            case 'add':
            case 'change':
                foreach (is_array($statement['columns'] ?? null) ? $statement['columns'] : [] as $column) {
                    if (! $column instanceof ColumnDefinition) {
                        continue;
                    }

                    if ($conditional) {
                        $state->markUncertain($column->name, $file);
                    }

                    if ($statement['kind'] === 'change') {
                        if (! isset($state->columns[$column->name])) {
                            $state->markUncertain($column->name, $file);

                            continue;
                        }

                        // change() no toca la llave: sigue la que había.
                        $column = $column->withForeign($state->columns[$column->name]->foreign);
                    } elseif ($column->foreign !== null) {
                        $state->foreignOrigin[$column->name] = $file;
                    }

                    $state->columns[$column->name] = $column;
                    $state->origin[$column->name] = $file;

                    // `->unique()` o `->index()` sobre la columna es un índice
                    // de la tabla, igual que `$table->unique('x')`. En un
                    // `->change()` no crean uno nuevo: Laravel lo intentaría.
                    if ($statement['kind'] === 'add') {
                        foreach ($column->extras as [$modifier, $arguments]) {
                            if (in_array($modifier, ['index', 'unique', 'primary', 'fullText', 'fulltext', 'spatialIndex'], true)) {
                                $indexName = isset($arguments[0]) && $arguments[0]->isString() ? (string) $arguments[0]->value : null;

                                if ($conditional) {
                                    $state->indexOpaque[] = basename($file).": ->{$modifier}() de {$column->name} dentro de un if";
                                } else {
                                    $state->addIndex(MigrationReader::indexType($modifier), [$column->name], $indexName, $file);
                                }
                            }
                        }
                    }
                }

                return;

            case 'index':
                $columns = array_values(array_filter(is_array($statement['index_columns'] ?? null) ? $statement['index_columns'] : [], 'is_string'));
                $type = is_string($statement['type'] ?? null) ? $statement['type'] : 'index';
                $indexName = is_string($statement['name'] ?? null) ? $statement['name'] : null;

                if ($conditional) {
                    $state->indexOpaque[] = basename($file).': un índice dentro de un if';
                } else {
                    $state->addIndex($type, $columns, $indexName, $file);
                }

                return;

            case 'dropIndex':
                $columns = is_array($statement['index_columns'] ?? null) ? array_values(array_filter($statement['index_columns'], 'is_string')) : null;

                if ($conditional) {
                    $state->indexOpaque[] = basename($file).': un índice que se quita dentro de un if';
                } else {
                    $state->dropIndex(is_string($statement['type'] ?? null) ? $statement['type'] : 'index', is_string($statement['name'] ?? null) ? $statement['name'] : null, $columns);
                }

                return;

            case 'renameIndex':
                $from = is_string($statement['index_from'] ?? null) ? $statement['index_from'] : '';
                $to = is_string($statement['index_to'] ?? null) ? $statement['index_to'] : '';

                foreach ($state->indexes as $position => $index) {
                    if ($index['name'] === $from) {
                        $state->indexes[$position]['name'] = $to;
                    }
                }

                return;

            case 'indexOpaque':
                $state->indexOpaque[] = basename($file).': '.(is_string($statement['why'] ?? null) ? $statement['why'] : 'un índice que no se lee');

                return;

            case 'drop':
                foreach ($names as $name) {
                    if ($conditional) {
                        $state->markUncertain($name, $file);
                    }

                    unset($state->columns[$name], $state->origin[$name], $state->foreignOrigin[$name]);
                    $state->dropIndexesOf($name);
                }

                return;

            case 'rename':
                $from = is_string($statement['from'] ?? null) ? $statement['from'] : '';
                $to = is_string($statement['to'] ?? null) ? $statement['to'] : '';

                if ($conditional || ! isset($state->columns[$from])) {
                    $state->markUncertain($from, $file);
                    $state->markUncertain($to, $file);

                    return;
                }

                $column = $state->columns[$from];
                unset($state->columns[$from], $state->origin[$from], $state->foreignOrigin[$from]);

                $state->columns[$to] = new ColumnDefinition($to, $column->method, $column->args, $column->nullable, $column->default, $column->unsigned, $column->foreign, $column->extras);
                $state->origin[$to] = $file;

                // El índice sigue a la columna (conserva su nombre).
                foreach ($state->indexes as $position => $index) {
                    $state->indexes[$position]['columns'] = array_map(fn (string $c): string => $c === $from ? $to : $c, $index['columns']);
                }

                return;

            case 'foreign':
                $name = is_string($statement['column'] ?? null) ? $statement['column'] : '';
                $foreign = $statement['foreign'] ?? null;

                if ($conditional || ! isset($state->columns[$name]) || ! $foreign instanceof ForeignKey) {
                    $state->markUncertain($name, $file);

                    return;
                }

                $state->columns[$name] = $state->columns[$name]->withForeign($foreign);
                $state->foreignOrigin[$name] = $file;

                return;

            case 'dropForeign':
                $index = is_string($statement['name'] ?? null) ? $statement['name'] : null;

                foreach ($state->columns as $name => $column) {
                    $matches = in_array($name, $names, true)
                        || ($index !== null && $column->foreign !== null && ($column->foreign->name ?? $state->name.'_'.$name.'_foreign') === $index);

                    if (! $matches || $column->foreign === null) {
                        continue;
                    }

                    if ($conditional) {
                        $state->markUncertain($name, $file);

                        continue;
                    }

                    $state->columns[$name] = $column->withForeign(null);
                    unset($state->foreignOrigin[$name]);
                }

                return;

            case 'uncertain':
                foreach ($names as $name) {
                    $state->markUncertain($name, $file);
                }

                return;
        }
    }

    private function touch(TableState $state, string $file): void
    {
        if (! in_array($file, $state->files, true)) {
            $state->files[] = $file;
        }
    }

    /**
     * Si un SQL nombra la tabla, con o sin comillas invertidas.
     */
    private static function names(string $sql, string $table): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/', $sql) === 1;
    }
}
