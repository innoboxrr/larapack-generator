<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

/**
 * Una llave foránea de una columna, venga de `foreignId()->constrained()` o de
 * `foreign()->references()->on()`: las dos escriben la misma llave.
 */
final class ForeignKey
{
    public function __construct(
        public readonly string $table,
        public readonly string $column = 'id',
        public readonly ?string $name = null,
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
    ) {}

    /**
     * Lo que hace la base cuando no se dice nada: MySQL y SQLite tratan
     * `no action` como `restrict`.
     */
    public static function effective(?string $action): string
    {
        $action = strtolower(trim((string) $action));

        return $action === '' || $action === 'no action' ? 'restrict' : $action;
    }

    public function sameTarget(self $other): bool
    {
        return $this->table === $other->table && $this->column === $other->column;
    }

    public function with(?string $onDelete = null, ?string $onUpdate = null, ?string $name = null): self
    {
        return new self($this->table, $this->column, $name ?? $this->name, $onDelete ?? $this->onDelete, $onUpdate ?? $this->onUpdate);
    }

    /**
     * `$table->foreign(...)->references(...)->on(...)` con las acciones que se
     * conozcan. Las que no se dijeron no se escriben: escribirlas sería
     * inventarlas.
     */
    public function statement(string $column): string
    {
        $statement = '$table->foreign('.Value::literal($column, '')->export()
            .($this->name !== null ? ', '.Value::literal($this->name, '')->export() : '')
            .')->references('.Value::literal($this->column, '')->export()
            .')->on('.Value::literal($this->table, '')->export().')';

        if ($this->onUpdate !== null) {
            $statement .= '->onUpdate('.Value::literal($this->onUpdate, '')->export().')';
        }

        if ($this->onDelete !== null) {
            $statement .= '->onDelete('.Value::literal($this->onDelete, '')->export().')';
        }

        return $statement.';';
    }

    /**
     * Quitarla por su nombre si se le dio uno; si no, por la columna, que es
     * como Laravel le pone nombre.
     */
    public function dropStatement(string $column): string
    {
        return $this->name !== null
            ? '$table->dropForeign('.Value::literal($this->name, '')->export().');'
            : '$table->dropForeign(['.Value::literal($column, '')->export().']);';
    }
}
