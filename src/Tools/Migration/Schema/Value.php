<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

use Innoboxrr\LarapackGenerator\Support\Literal;

/**
 * Un argumento de una migración: un literal que se sabe leer —una cadena, un
 * número, un booleano, null, una lista de ellos o `Clase::class`— o una
 * expresión que no, de la que sólo se guarda el texto.
 *
 * Se compara por lo que vale (`01` es el entero 1, no la cadena `'01'`) y se
 * vuelve a escribir desde ese valor, no copiando el texto.
 */
final class Value
{
    private function __construct(
        public readonly bool $literal,
        public readonly mixed $value,
        public readonly string $raw,
    ) {}

    public static function literal(mixed $value, string $raw): self
    {
        return new self(true, $value, $raw);
    }

    public static function expression(string $raw): self
    {
        return new self(false, null, trim($raw));
    }

    public function isString(): bool
    {
        return $this->literal && is_string($this->value);
    }

    /**
     * Lo que la base guarda como valor por omisión: Laravel escribe todo entre
     * comillas, y un booleano como '1' o '0'. Así `->default(5)` y
     * `->default('5')` son lo mismo, y `->default(01)` vale `'1'`.
     */
    public function stored(): ?string
    {
        if (! $this->literal) {
            return 'expr:'.$this->raw;
        }

        return match (true) {
            $this->value === null => null,
            is_bool($this->value) => $this->value ? '1' : '0',
            is_scalar($this->value) => (string) $this->value,
            default => 'expr:'.$this->raw,
        };
    }

    /**
     * El literal PHP de un argumento, como lo escribiría el generador.
     */
    public function export(): string
    {
        if (! $this->literal) {
            return $this->raw;
        }

        return self::exportValue($this->value, $this->raw);
    }

    /**
     * El literal PHP de un `default`, según la columna: en una numérica los
     * números van sin comillas; en una de texto todo lo que no es booleano va
     * entre comillas, que es lo que de verdad guarda la base.
     */
    public function exportDefault(string $method): string
    {
        if (! $this->literal || is_bool($this->value) || $this->value === null) {
            return $this->export();
        }

        if (! ColumnTypes::isNumeric($method)) {
            return is_scalar($this->value) ? Literal::php((string) $this->value) : $this->export();
        }

        // Un número que ya se escribía en decimal se deja como estaba: `10.00`
        // y no `10.0`.
        if (is_int($this->value) || is_float($this->value)) {
            return preg_match('/^-?(?:0|[1-9][0-9_]*)(?:\.[0-9_]+)?\z/', $this->raw) === 1
                ? str_replace('_', '', $this->raw)
                : var_export($this->value, true);
        }

        return $this->export();
    }

    private static function exportValue(mixed $value, string $raw): string
    {
        if (is_array($value)) {
            $items = [];
            $list = array_is_list($value);

            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : self::exportValue($key, (string) $key).' => ').self::exportValue($item, is_scalar($item) ? (string) $item : '');
            }

            return '['.implode(', ', $items).']';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]+\z/', $raw) === 1 ? $raw : var_export($value, true),
            default => Literal::php(is_scalar($value) ? (string) $value : ''),
        };
    }
}
