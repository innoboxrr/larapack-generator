<?php

namespace Innoboxrr\LarapackGenerator\Support;

use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Completa las listas de un modelo editado a mano con lo que el laraimport
 * declara, sin tocar nada más.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ: con --force un modelo editado a mano se conserva entero, y con él
 * sus listas. Al añadir una columna al laraimport, `$fillable`, `$export_cols`
 * o `casts()` se quedaban sin ella: en school-management, 30 modelos, y en
 * `ExternalIdentity` la columna `shared` no se convertía a booleano. Las listas
 * son del generador —el contrato de esos proyectos prohíbe editarlas a mano—,
 * así que se completan; lo que alguien escribió en el resto del modelo, no.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Reglas, todas para no destruir nada:
 *
 *  - Sólo se AÑADE lo que falta. Una entrada que el laraimport ya no declara
 *    no se quita (se informa en `extra`), y un cast que existe con otro valor
 *    no se cambia (se informa en `differs`): pudo cambiarlo una persona.
 *  - Se trabaja sobre tokens: cada región se localiza con certeza —la
 *    propiedad declarada una sola vez en la clase, con `= [ ... ];`, o el
 *    método casts() cuyo cuerpo es sólo `return [ ... ];`— y se inserta en
 *    su sitio. El resto del archivo queda byte a byte.
 *  - Si una región no se reconoce con certeza (no existe, está dos veces,
 *    lleva expresiones que no son cadenas literales, un spread, `array()`), no
 *    se toca y se devuelve en `skipped`, con la orden manual exacta.
 *  - Si el resultado no parsea, no se escribe nada y todo va a `skipped`.
 *
 * @phpstan-import-type Expected from ModelLists
 *
 * @phpstan-type Region array{open: int, close: int, items: list<array{start: int, end: int, key: string|null, value: string}>, comma: int|null}
 * @phpstan-type Skip array{region: string, reason: string, manual: string}
 * @phpstan-type Result array{code: string, added: array<string, list<string>>, skipped: list<Skip>, differs: array<string, array{expected: string, actual: string}>, extra: array<string, list<string>>}
 */
final class ModelRegions
{
    private const TRIVIA = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_VAR, T_READONLY];

    /** Lo que puede ir entre el modificador y el nombre de una propiedad con tipo. */
    private const TYPE_TOKENS = [T_ARRAY, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, '?', '|'];

    /**
     * @param  Expected  $expected
     * @param  string  $file  la ruta, sólo para la orden manual
     * @return Result
     */
    public static function sync(string $code, array $expected, string $file): array
    {
        $tokens = PhpToken::tokenize($code);
        $insertions = [];
        $added = [];
        $skipped = [];
        $differs = [];
        $extra = [];

        foreach (ModelLists::LISTS as $property) {
            $wanted = $expected[$property];

            try {
                $region = self::property($tokens, $property);

                if ($region === null) {
                    if ($wanted !== []) {
                        throw new RuntimeException("la clase no declara \${$property}");
                    }

                    continue;
                }

                $present = [];

                foreach ($region['items'] as $item) {
                    if ($item['key'] !== null) {
                        throw new RuntimeException("\${$property} tiene claves: no es una lista de nombres");
                    }

                    $present[] = self::literal($item['value']) ?? throw new RuntimeException("\${$property} lleva algo que no es una cadena literal ({$item['value']})");
                }
            } catch (RuntimeException $e) {
                if ($wanted !== []) {
                    $skipped[] = self::skip("\${$property}", $e->getMessage(), $file, ModelLists::render($wanted));
                }

                continue;
            }

            $missing = array_values(array_diff($wanted, $present));
            $surplus = array_values(array_diff($present, $wanted));

            if ($surplus !== []) {
                $extra[$property] = $surplus;
            }

            if ($missing === []) {
                continue;
            }

            $entries = array_map(fn (string $name): string => "'{$name}'", $missing);
            $insertions[] = self::insertion($code, $tokens, $region, $entries);
            $added[$property] = $entries;
        }

        self::casts($code, $tokens, $expected['casts'], $file, $insertions, $added, $skipped, $differs, $extra);

        $result = $code;

        usort($insertions, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($insertions as [$offset, $length, $text]) {
            $result = substr($result, 0, $offset).$text.substr($result, $offset + $length);
        }

        if ($result !== $code && ! self::parses($result)) {
            foreach ($added as $region => $entries) {
                $skipped[] = self::skip($region === 'casts' ? 'casts()' : "\${$region}", 'completarla dejaba el archivo sin parsear', $file, implode(', ', $entries));
            }

            return ['code' => $code, 'added' => [], 'skipped' => $skipped, 'differs' => $differs, 'extra' => $extra];
        }

        return ['code' => $result, 'added' => $added, 'skipped' => $skipped, 'differs' => $differs, 'extra' => $extra];
    }

    /**
     * El valor de una cadena literal de PHP, o null si no lo es.
     */
    public static function literal(string $code): ?string
    {
        $tokens = PhpToken::tokenize('<?php '.$code.';');
        $significant = array_values(array_filter(array_slice($tokens, 1), fn (PhpToken $token): bool => ! $token->is(self::TRIVIA)));

        if (count($significant) !== 2 || ! $significant[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $text = $significant[0]->text;
        $inner = substr($text, 1, -1);

        return $text[0] === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner)
            : stripcslashes($inner);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<string, string>  $wanted
     * @param  list<array{0: int, 1: int, 2: string}>  $insertions
     * @param  array<string, list<string>>  $added
     * @param  list<Skip>  $skipped
     * @param  array<string, array{expected: string, actual: string}>  $differs
     * @param  array<string, list<string>>  $extra
     */
    private static function casts(string $code, array $tokens, array $wanted, string $file, array &$insertions, array &$added, array &$skipped, array &$differs, array &$extra): void
    {
        try {
            $region = self::castsMethod($tokens) ?? self::property($tokens, 'casts');

            if ($region === null) {
                if ($wanted === []) {
                    return;
                }

                throw new RuntimeException('la clase no tiene casts() ni $casts');
            }

            $present = [];

            foreach ($region['items'] as $item) {
                $key = $item['key'] === null ? null : self::literal($item['key']);

                if ($key === null) {
                    throw new RuntimeException('casts() lleva una entrada sin clave literal ('.trim($item['value']).')');
                }

                $present[$key] = trim($item['value']);
            }
        } catch (RuntimeException $e) {
            if ($wanted !== []) {
                $skipped[] = self::skip('casts()', $e->getMessage(), $file, ModelLists::renderCasts($wanted));
            }

            return;
        }

        $missing = array_diff_key($wanted, $present);

        foreach (array_intersect_key($wanted, $present) as $name => $cast) {
            $actual = $present[$name];

            if ($cast !== $actual && self::literal($cast) !== self::literal($actual)) {
                $differs[$name] = ['expected' => $cast, 'actual' => $actual];
            }
        }

        $surplus = array_keys(array_diff_key($present, $wanted));

        if ($surplus !== []) {
            $extra['casts'] = array_map('strval', $surplus);
        }

        if ($missing === []) {
            return;
        }

        $entries = array_map(fn (string $name, string $cast): string => "'{$name}' => {$cast}", array_keys($missing), $missing);
        $insertions[] = self::insertion($code, $tokens, $region, $entries);
        $added['casts'] = $entries;
    }

    /**
     * @return Skip
     */
    private static function skip(string $region, string $reason, string $file, string $entries): array
    {
        return [
            'region' => ltrim(rtrim($region, '()'), '$'),
            'reason' => $reason,
            'manual' => "Añade a mano en {$file}, dentro de {$region}: {$entries}",
        ];
    }

    /**
     * Dónde y qué insertar para que las entradas queden al final de la lista,
     * con la forma que ya tiene: en la misma línea si las entradas van en una,
     * una por línea si van así, y en una línea propia si la lista está vacía
     * (como la deja el generador pasado por Pint). Devuelve el offset, cuánto
     * se sustituye a partir de él y el texto.
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  Region  $region
     * @param  list<string>  $entries
     * @return array{0: int, 1: int, 2: string}
     */
    private static function insertion(string $code, array $tokens, array $region, array $entries): array
    {
        $close = $tokens[$region['close']]->pos;

        if ($region['items'] === []) {
            // Lo que hubiera dentro aparte de espacios (un comentario) se
            // conserva delante.
            $openEnd = $tokens[$region['open']]->pos + 1;
            $interior = substr($code, $openEnd, $close - $openEnd);
            $indent = self::indentAt($code, $close);

            return [$openEnd, strlen($interior), rtrim($interior)."\n{$indent}    ".implode(', ', $entries).",\n{$indent}"];
        }

        $items = $region['items'];
        $last = $items[count($items) - 1];
        $sameLine = count($items) === 1 || self::lineOf($code, $items[count($items) - 2]['start']) === self::lineOf($code, $last['start']);
        $indent = self::indentAt($code, $last['start']);

        if ($region['comma'] !== null) {
            return $sameLine
                ? [$region['comma'] + 1, 0, ' '.implode(', ', $entries).',']
                : [self::endOfLine($code, $tokens, $region['comma'] + 1), 0, implode('', array_map(fn (string $entry): string => "\n{$indent}{$entry},", $entries))];
        }

        return $sameLine
            ? [$last['end'], 0, ', '.implode(', ', $entries)]
            : [$last['end'], 0, implode('', array_map(fn (string $entry): string => ",\n{$indent}{$entry}", $entries))];
    }

    /**
     * El final de la línea de la coma, detrás de un comentario `// …` que la
     * acompañe; si el salto de línea no cae en un espacio en blanco —un
     * comentario de bloque que sigue—, justo detrás de la coma.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function endOfLine(string $code, array $tokens, int $offset): int
    {
        $newline = strpos($code, "\n", $offset);

        if ($newline === false) {
            return $offset;
        }

        foreach ($tokens as $token) {
            if ($token->pos <= $newline && $newline < $token->pos + strlen($token->text)) {
                return $token->is(T_WHITESPACE) ? $newline : $offset;
            }
        }

        return $offset;
    }

    private static function lineOf(string $code, int $offset): int
    {
        return substr_count($code, "\n", 0, $offset);
    }

    /**
     * La sangría de la línea donde está el offset.
     */
    private static function indentAt(string $code, int $offset): string
    {
        $lineStart = strrpos(substr($code, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $line = substr($code, $lineStart);

        return substr($line, 0, strspn($line, " \t"));
    }

    /**
     * La propiedad `$name` declarada una sola vez en la clase con `= [ ... ]`.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return Region|null null si no está
     */
    private static function property(array $tokens, string $name): ?array
    {
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_VARIABLE) || $token->text !== '$'.$name) {
                continue;
            }

            $previous = self::previous($tokens, $index);

            // Una propiedad con tipo (`protected array $fillable`, `?array`,
            // `array|null`) lleva el tipo entre el modificador y el nombre.
            while ($previous !== null && $tokens[$previous]->is(self::TYPE_TOKENS)) {
                $previous = self::previous($tokens, $previous);
            }

            if ($previous === null || ! $tokens[$previous]->is(self::MODIFIERS)) {
                continue;
            }

            $found[] = $index;
        }

        if ($found === []) {
            return null;
        }

        if (count($found) > 1) {
            throw new RuntimeException("\${$name} se declara más de una vez");
        }

        $equals = self::next($tokens, $found[0]);

        if ($equals === null || $tokens[$equals]->text !== '=') {
            throw new RuntimeException("\${$name} no tiene un valor inicial");
        }

        $open = self::next($tokens, $equals);

        if ($open === null || $tokens[$open]->text !== '[') {
            throw new RuntimeException("\${$name} no es un array con corchetes");
        }

        $region = self::array($tokens, $open, $name);
        $after = self::next($tokens, $region['close']);

        if ($after === null || $tokens[$after]->text !== ';') {
            throw new RuntimeException("\${$name} es una expresión, no sólo un array");
        }

        return $region;
    }

    /**
     * El array de casts(): el método existe una vez y su cuerpo es sólo
     * `return [ ... ];`.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return Region|null null si el método no está
     */
    private static function castsMethod(array $tokens): ?array
    {
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_FUNCTION)) {
                continue;
            }

            $name = self::next($tokens, $index);

            if ($name !== null && $tokens[$name]->is(T_STRING) && strtolower($tokens[$name]->text) === 'casts') {
                $found[] = $name;
            }
        }

        if ($found === []) {
            return null;
        }

        if (count($found) > 1) {
            throw new RuntimeException('casts() se declara más de una vez');
        }

        $index = $found[0];

        while (isset($tokens[$index]) && $tokens[$index]->text !== '{' && $tokens[$index]->text !== ';') {
            $index++;
        }

        if (! isset($tokens[$index]) || $tokens[$index]->text !== '{') {
            throw new RuntimeException('casts() no tiene cuerpo');
        }

        $return = self::next($tokens, $index);

        if ($return === null || ! $tokens[$return]->is(T_RETURN)) {
            throw new RuntimeException('casts() hace algo más que devolver un array');
        }

        $open = self::next($tokens, $return);

        if ($open === null || $tokens[$open]->text !== '[') {
            throw new RuntimeException('casts() no devuelve un array con corchetes');
        }

        $region = self::array($tokens, $open, 'casts');
        $semicolon = self::next($tokens, $region['close']);
        $end = $semicolon === null ? null : self::next($tokens, $semicolon);

        if ($semicolon === null || $tokens[$semicolon]->text !== ';' || $end === null || $tokens[$end]->text !== '}') {
            throw new RuntimeException('casts() hace algo más que devolver un array');
        }

        return $region;
    }

    /**
     * Las entradas de primer nivel de un array literal.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return Region
     */
    private static function array(array $tokens, int $open, string $name): array
    {
        $items = [];
        $depth = 0;
        $start = null;
        $arrow = null;
        $lastSignificant = null;
        $comma = null;

        for ($index = $open + 1; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];

            if ($depth === 0 && ($token->text === ']' || $token->text === ',')) {
                if ($start !== null && $lastSignificant !== null) {
                    $items[] = self::item($tokens, $start, $lastSignificant, $arrow, $name);
                    $comma = $token->text === ',' ? $token->pos : null;
                } elseif ($token->text === ',') {
                    throw new RuntimeException("\${$name} tiene una coma sin entrada");
                }

                if ($token->text === ']') {
                    return ['open' => $open, 'close' => $index, 'items' => $items, 'comma' => $items === [] ? null : $comma];
                }

                $start = null;
                $arrow = null;
                $lastSignificant = null;

                continue;
            }

            if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($token->is(self::TRIVIA)) {
                continue;
            }

            if ($token->is(T_ELLIPSIS) && $depth === 0) {
                throw new RuntimeException("\${$name} se arma con un spread (...)");
            }

            $start ??= $index;
            $comma = null;

            if ($depth === 0 && $token->is(T_DOUBLE_ARROW) && $arrow === null) {
                $arrow = $index;
            }

            $lastSignificant = $index;
        }

        throw new RuntimeException("\${$name} no se cierra");
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{start: int, end: int, key: string|null, value: string}
     */
    private static function item(array $tokens, int $start, int $last, ?int $arrow, string $name): array
    {
        $text = fn (int $from, int $to): string => implode('', array_map(fn (PhpToken $token): string => $token->text, array_slice($tokens, $from, $to - $from + 1)));

        $end = $tokens[$last]->pos + strlen($tokens[$last]->text);

        if ($arrow === null) {
            return ['start' => $tokens[$start]->pos, 'end' => $end, 'key' => null, 'value' => $text($start, $last)];
        }

        $value = self::next($tokens, $arrow) ?? throw new RuntimeException("\${$name} tiene una clave sin valor");

        return ['start' => $tokens[$start]->pos, 'end' => $end, 'key' => trim($text($start, $arrow - 1)), 'value' => $text($value, $last)];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function previous(array $tokens, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            if (! $tokens[$index]->is(self::TRIVIA)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function next(array $tokens, int $index): ?int
    {
        for ($index++; isset($tokens[$index]); $index++) {
            if (! $tokens[$index]->is(self::TRIVIA)) {
                return $index;
            }
        }

        return null;
    }

    private static function parses(string $code): bool
    {
        try {
            PhpToken::tokenize($code, TOKEN_PARSE);

            return true;
        } catch (ParseError) {
            return false;
        }
    }
}
