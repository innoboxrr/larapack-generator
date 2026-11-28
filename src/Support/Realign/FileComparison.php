<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;

/**
 * Compara un archivo del proyecto con el que generaría hoy el contrato.
 *
 * Tres niveles, de más estricto a menos:
 *
 *  1. Idénticos: los mismos bytes, con los finales de línea normalizados
 *     (como el hash del manifiesto).
 *  2. Iguales salvo formato: en PHP, la misma secuencia de tokens sin
 *     espacios, con los comentarios sin espacios dentro, sin la coma final
 *     de una lista y con los `use` de la cabecera ordenados —lo que cambia
 *     Pint—; en JSON, el mismo valor; en lo demás (jsx, vue, ts, md…),
 *     las mismas líneas salvo la indentación y el espacio al final de cada
 *     una (lines()). Lo que esto no reconoce lo intenta después Diagnosis
 *     pasando el archivo del proyecto por la misma Pint (PintPass).
 *  3. Distintos: y entonces members() dice qué piezas cambiaron, para saber
 *     si la edición cae dentro de los huecos (Holes).
 *
 * Entre el 2 y el 3, COMMENTS (sólo PHP): el mismo código y otros
 * comentarios. No es formato: adoptarlo dejaría que un `--force` reemplace
 * los comentarios y docblocks del usuario por los de la plantilla.
 * ScopeClassifier lo trata como una edición que cumple —se conserva, sin
 * adoptar— salvo en lo que nadie editó (un salto de plantilla) y en lo que
 * el manifiesto no conoce (una decisión).
 *
 * @phpstan-type Piece array{0: int|string, 1: string}
 */
final class FileComparison
{
    public const IDENTICAL = 'identical';

    public const FORMAT = 'format';

    /** El mismo código; sólo cambian los comentarios. */
    public const COMMENTS = 'comments';

    public const DIFFERENT = 'different';

    private const OPENING = ['{', '(', '['];

    private const CLOSING = ['}', ')', ']'];

    public static function compareFiles(string $current, string $regenerated): string
    {
        return self::compare(Files::read($current), Files::read($regenerated), self::kindOf($current));
    }

    /**
     * @param  'php'|'json'|'text'  $kind
     */
    public static function compare(string $current, string $regenerated, string $kind): string
    {
        $current = str_replace("\r\n", "\n", $current);
        $regenerated = str_replace("\r\n", "\n", $regenerated);

        if ($current === $regenerated) {
            return self::IDENTICAL;
        }

        if (self::normalise($current, $kind, true) === self::normalise($regenerated, $kind, true)) {
            return self::FORMAT;
        }

        return $kind === 'php' && self::normalise($current, $kind, false) === self::normalise($regenerated, $kind, false)
            ? self::COMMENTS
            : self::DIFFERENT;
    }

    /**
     * @return 'php'|'json'|'text'
     */
    public static function kindOf(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'json' => 'json',
            default => 'text',
        };
    }

    /**
     * Las piezas de un PHP que difieren entre los dos: `method:<nombre>` (en
     * minúsculas, como los compara PHP) por cada método distinto, añadido o
     * quitado; `imports` si cambiaron los `use` de la cabecera (el código de
     * un hueco suele traer los suyos), y `outside` si cambió algo más fuera
     * de los métodos —una propiedad, la cabecera de la clase—. Sin espacios
     * ni comentarios: aquí importa dónde está la diferencia, no si es de
     * formato.
     *
     * @return list<string>
     */
    public static function members(string $current, string $regenerated): array
    {
        $a = self::split($current);
        $b = self::split($regenerated);
        $differing = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $member) {
            if (($a[$member] ?? null) !== ($b[$member] ?? null)) {
                $differing[] = $member;
            }
        }

        sort($differing);

        return $differing;
    }

    /**
     * Las piezas de un PHP, como las compara members(): `method:<nombre>`
     * => sus tokens sin espacios ni comentarios, `imports` y `outside`.
     * Holes las usa para saber si un método de lo regenerado es el marcador
     * de algo sin implementar.
     *
     * @return array<string, string>
     */
    public static function pieces(string $code): array
    {
        return self::split($code);
    }

    /**
     * @param  'php'|'json'|'text'  $kind
     */
    private static function normalise(string $content, string $kind, bool $comments): string
    {
        if ($kind === 'json') {
            $decoded = json_decode($content, true);

            return $decoded === null && trim($content) !== 'null'
                ? self::lines($content)
                : (string) json_encode(self::sortKeys($decoded));
        }

        if ($kind === 'text') {
            return self::lines($content);
        }

        [$head, $imports, $rest] = self::header(self::tokens($content, $comments));

        return implode(' ', [...$head, ...$imports, ...$rest]);
    }

    /**
     * Un texto que no es PHP (jsx, tsx, vue, js, ts, md…) sin lo que es
     * formato con certeza: la indentación, el espacio al final de cada línea
     * y las líneas en blanco del final. El espacio dentro de una línea no se
     * toca: en una cadena, en el texto de JSX o entre dos operadores
     * (`a - -b` y `a--b`) cambia lo que hace el código, y sin un analizador
     * de cada lenguaje no se distingue. Tampoco se juntan ni se parten
     * líneas. Ante la duda, distinto: dar por formato una edición la adopta
     * y el siguiente `--force` la borra.
     */
    private static function lines(string $content): string
    {
        return implode("\n", array_map(
            fn (string $line): string => trim($line, " \t"),
            explode("\n", rtrim($content, " \t\n"))
        ));
    }

    /**
     * El orden de las claves de un objeto JSON no cambia su valor; el de una
     * lista sí.
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Los tokens de un PHP sin espacios y sin coma final; con $comments, los
     * comentarios van sin espacios dentro, y sin él, no van.
     *
     * @return list<string>
     */
    private static function tokens(string $code, bool $comments): array
    {
        $tokens = [];

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                $tokens[] = $token;

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_WHITESPACE || (! $comments && ($id === T_COMMENT || $id === T_DOC_COMMENT))) {
                continue;
            }

            // `<?php` lleva su salto de línea dentro del token.
            $tokens[] = in_array($id, [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)
                ? (string) preg_replace('/\s+/', '', $text)
                : $text;
        }

        return self::withoutTrailingCommas($tokens);
    }

    /**
     * La cabecera de un PHP partida en tres: lo que va antes (`<?php`,
     * `namespace`, `declare`), los `use` de importación —ordenados: Pint los
     * ordena (ordered_imports) y el orden no cambia lo que significan— y el
     * resto. La cabecera termina en la primera sentencia que no es
     * `namespace`, `declare` ni `use`; los `use` de traits van dentro de la
     * clase y no se tocan.
     *
     * @param  list<string>  $tokens
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private static function header(array $tokens): array
    {
        $head = [];
        $imports = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);
        $index = 0;

        for (; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($current === [] && $depth === 0 && ! in_array(strtolower($token), ['<?php', 'namespace', 'declare', 'use'], true)) {
                break;
            }

            $current[] = $token;
            $depth += in_array($token, self::OPENING, true) ? 1 : (in_array($token, self::CLOSING, true) ? -1 : 0);

            if (strtolower($token) === '<?php' || ($token === ';' && $depth === 0)) {
                if (strtolower($current[0]) === 'use') {
                    $imports[] = implode(' ', $current);
                } else {
                    array_push($head, ...$current);
                }

                $current = [];
            }
        }

        sort($imports);

        return [$head, $imports, [...$current, ...array_slice($tokens, $index)]];
    }

    /**
     * Pieza => su texto normalizado: un método por nombre y todo lo demás en
     * `outside`.
     *
     * @return array<string, string>
     */
    private static function split(string $code): array
    {
        /** @var list<Piece> $tokens */
        $tokens = [];

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            $tokens[] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
        }

        $methods = [];
        $outside = [];
        $depth = 0;
        $classDepth = null;
        $pendingClass = false;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            $previous = $i > 0 ? $tokens[$i - 1][0] : null;

            if ($classDepth === null && in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && ! in_array($previous, [T_DOUBLE_COLON, T_NEW], true)) {
                $pendingClass = true;
            }

            if ($classDepth !== null && $depth === $classDepth && $id === T_FUNCTION) {
                [$name, $end] = self::method($tokens, $i);
                $methods['method:'.$name] = implode(' ', self::withoutTrailingCommas(array_column(array_slice($tokens, $i, $end - $i + 1), 1)));
                $i = $end;

                continue;
            }

            if ($text === '{' || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                if ($pendingClass) {
                    $classDepth = $depth;
                    $pendingClass = false;
                }
            } elseif ($text === '}') {
                $depth--;
            }

            $outside[] = $text;
        }

        [$head, $imports, $rest] = self::header(self::withoutTrailingCommas($outside));

        return ['imports' => implode(' ', $imports), 'outside' => implode(' ', [...$head, ...$rest])] + $methods;
    }

    /**
     * El nombre de un método (en minúsculas) y dónde acaba: el `}` de su
     * cuerpo o el `;` de uno abstracto.
     *
     * @param  list<Piece>  $tokens
     * @return array{0: string, 1: int}
     */
    private static function method(array $tokens, int $start): array
    {
        $count = count($tokens);
        $j = $start + 1;

        while ($j < $count && ($tokens[$j][1] === '&' || $tokens[$j][0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
            $j++;
        }

        $name = $j < $count && $tokens[$j][1] !== '(' ? strtolower($tokens[$j][1]) : '';
        $level = 0;

        for (; $j < $count; $j++) {
            $piece = $tokens[$j][1];

            if ($level === 0 && $piece === ';') {
                break;
            }

            if (in_array($piece, self::OPENING, true) || in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $level++;
            } elseif (in_array($piece, self::CLOSING, true)) {
                $level--;

                if ($level === 0 && $piece === '}') {
                    break;
                }
            }
        }

        return [$name, min($j, $count - 1)];
    }

    /**
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private static function withoutTrailingCommas(array $pieces): array
    {
        $clean = [];

        foreach ($pieces as $piece) {
            if (in_array($piece, self::CLOSING, true) && end($clean) === ',') {
                array_pop($clean);
            }

            $clean[] = $piece;
        }

        return $clean;
    }
}
