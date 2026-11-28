<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Apply;

use PhpToken;

/**
 * Dónde está cada pieza de un archivo PHP con una sola clase, en bytes: el
 * namespace, los `use` de importación, el cuerpo de la clase y cada método
 * con su docblock, sus atributos y su sangría. Con los tokens de PHP y nada
 * más, como PhpOutline, pero con posiciones: lo necesita quien copia un
 * método de un archivo a otro o inserta un `use` sin tocar lo demás.
 *
 * Si algo no se reconoce con certeza —dos clases, una importación agrupada,
 * llaves que no cierran— of() devuelve null y quien lo usa no toca nada.
 *
 * @phpstan-type Method array{name: string, start: int, end: int, code: string, visibility: string}
 * @phpstan-type Import array{name: string, alias: string, start: int, end: int}
 */
final class PhpMembers
{
    /**
     * @param  array<string, Method>  $methods  por nombre en minúsculas
     * @param  list<Import>  $imports
     */
    private function __construct(
        public readonly string $code,
        public readonly ?string $namespace,
        public readonly ?int $namespaceEnd,
        public readonly array $imports,
        public readonly string $className,
        public readonly int $classClose,
        public readonly array $methods,
    ) {}

    public static function of(string $code): ?self
    {
        try {
            $tokens = array_values(PhpToken::tokenize($code, TOKEN_PARSE));
        } catch (\ParseError) {
            return null;
        }

        $namespace = null;
        $namespaceEnd = null;
        $imports = [];
        $className = null;
        $classOpen = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($className === null && $token->is(T_NAMESPACE)) {
                $j = self::next($tokens, $i);

                if ($j !== null && $tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                    $namespace = $tokens[$j]->text;
                    $end = self::next($tokens, $j);

                    if ($end !== null && $tokens[$end]->text === ';') {
                        $namespaceEnd = $tokens[$end]->pos + 1;
                    }
                }

                continue;
            }

            if ($className === null && $token->is(T_USE)) {
                $import = self::import($tokens, $i);

                if ($import === false) {
                    return null;
                }

                if ($import !== null) {
                    $imports[] = $import;
                }

                continue;
            }

            if ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM])) {
                $previous = self::previous($tokens, $i);

                if ($previous !== null && $tokens[$previous]->is([T_DOUBLE_COLON, T_NEW])) {
                    continue;
                }

                if ($className !== null) {
                    return null;
                }

                $name = self::next($tokens, $i);

                if ($name === null || ! $tokens[$name]->is(T_STRING)) {
                    return null;
                }

                $className = $tokens[$name]->text;

                for ($j = $name; $j < $count; $j++) {
                    if ($tokens[$j]->text === '{') {
                        $classOpen = $j;

                        break;
                    }
                }

                if ($classOpen === null) {
                    return null;
                }

                $i = self::close($tokens, $classOpen) ?? $count;
            }
        }

        if ($className === null || $classOpen === null) {
            return null;
        }

        $close = self::close($tokens, $classOpen);

        if ($close === null) {
            return null;
        }

        $methods = [];

        for ($i = $classOpen + 1; $i < $close; $i++) {
            if ($tokens[$i]->text === '{' || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $i = self::close($tokens, $i) ?? $close;

                continue;
            }

            if (! $tokens[$i]->is(T_FUNCTION)) {
                continue;
            }

            $name = self::next($tokens, $i);

            if ($name !== null && $tokens[$name]->text === '&') {
                $name = self::next($tokens, $name);
            }

            if ($name === null || ! $tokens[$name]->is(T_STRING)) {
                continue;
            }

            [$start, $visibility] = self::methodStart($tokens, $i, $code);
            $end = null;

            for ($j = $name; $j < $close; $j++) {
                if ($tokens[$j]->text === ';') {
                    $end = $tokens[$j]->pos + 1;
                    $i = $j;

                    break;
                }

                if ($tokens[$j]->text === '{') {
                    $closing = self::close($tokens, $j);

                    if ($closing === null) {
                        return null;
                    }

                    $end = $tokens[$closing]->pos + 1;
                    $i = $closing;

                    break;
                }
            }

            if ($end === null) {
                return null;
            }

            $methods[strtolower($tokens[$name]->text)] = [
                'name' => $tokens[$name]->text,
                'start' => $start,
                'end' => $end,
                'code' => substr($code, $start, $end - $start),
                'visibility' => $visibility,
            ];
        }

        return new self($code, $namespace, $namespaceEnd, $imports, $className, $tokens[$close]->pos, $methods);
    }

    /**
     * El nombre completo que importa un alias, o null.
     */
    public function imported(string $alias): ?string
    {
        foreach ($this->imports as $import) {
            if (strtolower($import['alias']) === strtolower($alias)) {
                return $import['name'];
            }
        }

        return null;
    }

    /**
     * Los nombres que el código usa como clase (T_STRING, sin `->`, `::` delante de un método, ni `$`), en minúsculas.
     *
     * @return array<string, true>
     */
    public static function namesUsedIn(string $snippet): array
    {
        try {
            $tokens = PhpToken::tokenize('<?php '.$snippet);
        } catch (\ParseError) {
            return [];
        }

        $names = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAME_QUALIFIED)) {
                $names[strtolower(explode('\\', $token->text)[0])] = true;

                continue;
            }

            if (! $token->is(T_STRING)) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;

            if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
                continue;
            }

            $names[strtolower($token->text)] = true;
        }

        return $names;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return Import|false|null
     */
    private static function import(array $tokens, int $i): array|false|null
    {
        $j = self::next($tokens, $i);

        if ($j === null) {
            return false;
        }

        // `use function`, `use const`: no son clases, y no se tocan.
        if ($tokens[$j]->is([T_FUNCTION, T_CONST])) {
            return null;
        }

        if (! $tokens[$j]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
            return false;
        }

        $name = ltrim($tokens[$j]->text, '\\');
        $alias = substr($name, (int) strrpos('\\'.$name, '\\'));
        $k = self::next($tokens, $j);

        if ($k !== null && $tokens[$k]->is(T_AS)) {
            $aliasToken = self::next($tokens, $k);

            if ($aliasToken === null) {
                return false;
            }

            $alias = $tokens[$aliasToken]->text;
            $k = self::next($tokens, $aliasToken);
        }

        if ($k === null || $tokens[$k]->text !== ';') {
            return false;
        }

        return ['name' => $name, 'alias' => $alias, 'start' => $tokens[$i]->pos, 'end' => $tokens[$k]->pos + 1];
    }

    /**
     * Desde dónde empieza un método: el principio de la línea de su
     * docblock, sus atributos o sus modificadores.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{0: int, 1: string}
     */
    private static function methodStart(array $tokens, int $function, string $code): array
    {
        $start = $tokens[$function]->pos;
        $visibility = 'public';

        for ($i = $function - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token->is(T_WHITESPACE)) {
                continue;
            }

            if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_DOC_COMMENT])) {
                if ($token->is([T_PROTECTED, T_PRIVATE])) {
                    $visibility = strtolower($token->text);
                }

                $start = $token->pos;

                continue;
            }

            if ($token->text === ']') {
                // Un atributo: hasta su `#[`.
                for ($j = $i; $j >= 0; $j--) {
                    if ($tokens[$j]->is(T_ATTRIBUTE)) {
                        $start = $tokens[$j]->pos;
                        $i = $j;

                        continue 2;
                    }
                }
            }

            break;
        }

        $lineStart = strrpos(substr($code, 0, $start), "\n");

        return [$lineStart === false ? $start : $lineStart + 1, $visibility];
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function close(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '{' || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function next(array $tokens, int $i): ?int
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            if (! $tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function previous(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }
}
