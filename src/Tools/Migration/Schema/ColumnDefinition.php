<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

/**
 * Una columna tal como la deja una migración: su método de Blueprint, sus
 * argumentos y modificadores, ya leídos.
 *
 * Lo que el laraimport sabe expresar —el tipo, si admite nulos, el valor por
 * omisión, la llave y, si los declara, la medida y `useCurrent()`— se compara
 * por lo que significa. Lo que no declara —una longitud que calla, un
 * `comment()`— se guarda para no perderlo al reescribir la columna con
 * `->change()`, que la sustituye entera.
 */
final class ColumnDefinition
{
    /**
     * Modificadores que crean un índice: repetirlos en un `->change()`
     * intentaría crear otra vez el índice.
     */
    private const INDEXES = ['index', 'unique', 'primary', 'fullText', 'fulltext', 'spatialIndex'];

    /**
     * @param  list<Value>  $args  lo que va después del nombre: una longitud, una precisión
     * @param  list<array{0: string, 1: list<Value>}>  $extras  los demás modificadores, en orden
     */
    public function __construct(
        public readonly string $name,
        public readonly string $method,
        public readonly array $args = [],
        public readonly bool $nullable = false,
        public readonly ?Value $default = null,
        public readonly bool $unsigned = false,
        public readonly ?ForeignKey $foreign = null,
        public readonly array $extras = [],
        public readonly bool $change = false,
    ) {}

    public function type(): string
    {
        return ColumnTypes::canonical($this->method, $this->unsigned);
    }

    public function withForeign(?ForeignKey $foreign): self
    {
        return new self($this->name, $this->method, $this->args, $this->nullable, $this->default, $this->unsigned, $foreign, $this->extras, $this->change);
    }

    /**
     * Si la columna, tal como la ve la base, es la misma: el tipo, los nulos y
     * el valor por omisión. Lo que `$desired` no dice —una longitud— no cuenta.
     */
    public function sameColumnAs(self $desired): bool
    {
        if ($this->type() !== $desired->type() || $this->nullable !== $desired->nullable) {
            return false;
        }

        if ($desired->args !== [] && array_map(fn (Value $v): string => $v->export(), $desired->args) !== array_map(fn (Value $v): string => $v->export(), $this->args)) {
            return false;
        }

        // `use_current` es lo único de los modificadores que el laraimport
        // dice: declararlo en una columna que no lo tiene la cambia. Que la
        // columna lo tenga y el laraimport calle no cuenta, como la longitud.
        if ($desired->useCurrent() && ! $this->useCurrent()) {
            return false;
        }

        $mine = $this->default?->stored();
        $theirs = $desired->default?->stored();

        if ($mine !== null && $theirs !== null && ColumnTypes::isNumeric($this->type()) && is_numeric($mine) && is_numeric($theirs)) {
            return (float) $mine === (float) $theirs;
        }

        return $mine === $theirs;
    }

    /**
     * La columna con lo que declara `$desired` y lo demás conservado: es lo que
     * se escribe con `->change()`.
     */
    public function changedTo(self $desired): self
    {
        $sameType = $this->type() === $desired->type();

        return new self(
            $this->name,
            $sameType ? $this->method : $desired->method,
            $desired->args !== [] || ! $sameType ? $desired->args : $this->args,
            $desired->nullable,
            $desired->default,
            $sameType && $this->unsigned,
            null,
            $desired->useCurrent() && ! $this->useCurrent() ? [...$this->extras, ['useCurrent', []]] : $this->extras,
        );
    }

    /**
     * Si lleva `->useCurrent()`: el valor por omisión es la hora actual.
     */
    public function useCurrent(): bool
    {
        foreach ($this->extras as [$modifier]) {
            if ($modifier === 'useCurrent') {
                return true;
            }
        }

        return false;
    }

    /**
     * La sentencia que la crea —o la cambia, con `$change`— sin la llave
     * foránea, que va aparte.
     */
    public function statement(bool $change = false): string
    {
        $arguments = [Value::literal($this->name, '')->export(), ...array_map(fn (Value $v): string => $v->export(), $this->args)];

        $statement = '$table->'.$this->method.'('.implode(', ', $arguments).')';

        if ($this->unsigned) {
            $statement .= '->unsigned()';
        }

        if ($this->nullable) {
            $statement .= '->nullable()';
        }

        if ($this->default !== null && $this->default->stored() !== null) {
            $statement .= '->default('.$this->default->exportDefault($this->method).')';
        }

        foreach ($this->extras as [$modifier, $arguments]) {
            if ($change && in_array($modifier, self::INDEXES, true)) {
                continue;
            }

            $statement .= '->'.$modifier.'('.implode(', ', array_map(fn (Value $v): string => $v->export(), $arguments)).')';
        }

        return $statement.($change ? '->change()' : '').';';
    }
}
