<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * El esqueleto de un archivo PHP: sus clases, sus métodos, los atributos de
 * cada uno y a qué nombre completo apunta cada tipo.
 *
 * Con token_get_all y nada más. El generador no lleva un parser de PHP en
 * runtime, y para lo que `verify` pregunta —qué métodos públicos tiene una
 * clase, con qué tipa sus parámetros, qué tokens forman su cuerpo— no hace
 * falta un árbol sintáctico: basta con seguir llaves y paréntesis.
 *
 * El código del proyecto nunca se carga ni se ejecuta. `verify` tiene que
 * poder leer un proyecto sin sus dependencias instaladas, y leerlo no puede
 * tener efectos.
 *
 * @phpstan-type Token array{0: int|string, 1: string, 2: int}
 * @phpstan-type AttributeOutline array{name: string, line: int, args: list<Token>|null}
 * @phpstan-type ParameterOutline array{name: string, type: string|null, raw: string}
 * @phpstan-type MethodOutline array{name: string, line: int, visibility: string, static: bool, byRef: bool, attributes: list<AttributeOutline>, params: list<ParameterOutline>, signature: list<Token>, body: list<Token>|null}
 * @phpstan-type ClassOutline array{kind: string, name: string, fqcn: string, line: int, abstract: bool, extends: string|null, traits: list<string>, attributes: list<AttributeOutline>, methods: list<MethodOutline>}
 * @phpstan-type ClosureOutline array{call: string, line: int, arrow: bool, params: list<Token>, body: list<Token>}
 *
 * Tiene que aguantar un archivo a medio escribir —el que un agente está
 * editando cuando corre `verify`— sin avisos: bajo Artisan un aviso es una
 * excepción y la verificación entera se cae. Por eso todo acceso a un token
 * que podría no existir pregunta antes.
 */
final class PhpOutline
{
    /**
     * Lo que no es un nombre de clase aunque ocupe el sitio de un tipo.
     */
    public const BUILTIN_TYPES = [
        'self', 'static', 'parent', 'array', 'callable', 'bool', 'float', 'int', 'string',
        'iterable', 'object', 'mixed', 'void', 'null', 'never', 'false', 'true',
    ];

    private const IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML];

    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR];

    /**
     * @var list<Token>
     */
    private array $tokens = [];

    private int $i = 0;

    private string $namespace = '';

    /**
     * Alias en minúsculas => nombre completo. PHP compara los nombres de
     * clase sin distinguir mayúsculas.
     *
     * @var array<string, string>
     */
    private array $uses = [];

    /**
     * @var list<ClassOutline>
     */
    private array $classes = [];

    private function __construct(string $code)
    {
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                // Un token de un carácter no trae su línea: es la del token
                // anterior más los saltos que éste contenga. Un espacio que
                // cruza de línea deja el `;` o el `(` que lo sigue en la
                // siguiente, no en la suya.
                $line = $token[2] + substr_count($token[1], "\n");

                if (! in_array($token[0], self::IGNORED, true)) {
                    $this->tokens[] = [$token[0], $token[1], $token[2]];
                }

                continue;
            }

            $this->tokens[] = [$token, $token, $line];
        }

        $this->parseFile();
    }

    public static function ofCode(string $code): self
    {
        return new self($code);
    }

    public static function ofFile(string $path): self
    {
        return new self(Files::read($path));
    }

    /**
     * Si un token es de alguna de esas clases: un `T_*` o, para los de un
     * carácter, el propio carácter (`'('`, `';'`). `null` —un token que no
     * existe, al final de un archivo a medio escribir— no es de ninguna.
     *
     * Toda comparación del tipo de un token pasa por aquí. Un token de un
     * carácter se guarda como `[c, c, línea]`, así que mirar su tipo
     * (`$token[0]`) o su texto (`$token[1]`) daba lo mismo, y repartida por
     * cada pieza esa comparación eran unas sesenta mutaciones que ninguna
     * prueba podía distinguir. Aquí es una, y se distingue: un `T_*` no es
     * nunca el texto de un token.
     *
     * @param  Token|null  $token
     */
    public static function is(?array $token, int|string ...$kinds): bool
    {
        return $token !== null && in_array($token[0], $kinds, true);
    }

    /**
     * @return list<ClassOutline>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * El nombre completo de una clase tal como se escribió en el archivo, con
     * sus `use` y su espacio de nombres: lo que el outline hace con los tipos,
     * para un nombre sacado de un cuerpo (`Post::class`). Con varios espacios
     * de nombres en el archivo, vale el último.
     */
    public function resolveName(string $name): string
    {
        return $this->resolve($name);
    }

    /**
     * El valor de un argumento que es una cadena literal (o varias unidas con
     * `.`), por posición o por nombre. `null` si no está o si es cualquier
     * otra cosa: una constante, una variable, una llamada. Sólo vale lo que se
     * lee sin ejecutar nada.
     *
     * @param  list<Token>|null  $args
     */
    public static function stringArgument(?array $args, int $position, string $name): ?string
    {
        foreach (self::splitTopLevel($args ?? []) as $index => $argument) {
            $named = count($argument) > 2 && self::is($argument[1], ':') && $argument[0][0] === T_STRING;

            if ($named && $argument[0][1] !== $name) {
                continue;
            }

            if (! $named && $index !== $position) {
                continue;
            }

            return self::literal($named ? array_slice($argument, 2) : $argument);
        }

        return null;
    }

    /**
     * Separa por las comas de primer nivel: las de un array o una llamada
     * anidada no cuentan. Con `;`, separa las sentencias de un cuerpo.
     *
     * @param  list<Token>  $tokens
     * @return list<list<Token>>
     */
    public static function splitTopLevel(array $tokens, string $separator = ','): array
    {
        $parts = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if (self::opens($token)) {
                $depth++;
            } elseif (self::is($token, ')', ']', '}')) {
                $depth--;
            } elseif ($depth === 0 && self::is($token, $separator)) {
                $parts[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Los closures que se pasan como argumento a una llamada `X::nombre(...)`
     * o `->nombre(...)`, con `nombre` uno de estos (en minúsculas).
     *
     * Se recorre el archivo entero, también dentro de otros closures: un
     * `Route::get()` dentro de un `group(function () { ... })` cuenta igual.
     *
     * @param  list<string>  $names
     * @return list<ClosureOutline>
     */
    public function closuresPassedTo(array $names): array
    {
        $closures = [];
        $count = count($this->tokens);

        for ($index = 1; $index < $count - 1; $index++) {
            $token = $this->tokens[$index];

            // Por el texto y no por el tipo: tras `::`, `match` llega como
            // T_MATCH, no como T_STRING.
            if (! self::is($this->tokens[$index + 1], '(')
                || ! self::is($this->tokens[$index - 1], T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR)
                || ! in_array(strtolower($token[1]), $names, true)
            ) {
                continue;
            }

            foreach (self::splitTopLevel(self::enclosed($this->tokens, $index + 1)) as $argument) {
                // Un argumento con nombre: `action: function () {}`.
                if (count($argument) > 2 && $argument[0][0] === T_STRING && self::is($argument[1], ':')) {
                    $argument = array_slice($argument, 2);
                }

                $closure = self::closure($argument);

                if ($closure !== null) {
                    $closures[] = ['call' => $token[1]] + $closure;
                }
            }
        }

        return $closures;
    }

    /**
     * Lo que hay dentro del paréntesis, corchete o llave que abre en $start,
     * sin los delimitadores. Si el archivo se corta antes del cierre, hasta
     * el final.
     *
     * @param  list<Token>  $tokens
     * @return list<Token>
     */
    private static function enclosed(array $tokens, int $start): array
    {
        $inner = [];
        $depth = 0;
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if (self::opens($token)) {
                if ($depth++ === 0) {
                    continue;
                }
            } elseif (self::is($token, ')', ']', '}') && --$depth === 0) {
                break;
            }

            $inner[] = $token;
        }

        return $inner;
    }

    /**
     * Si el argumento es un closure: sus parámetros y su cuerpo. El de una
     * función flecha es la expresión de después de `=>`.
     *
     * @param  list<Token>  $argument
     * @return array{line: int, arrow: bool, params: list<Token>, body: list<Token>}|null
     */
    private static function closure(array $argument): ?array
    {
        if (($argument[0][0] ?? null) === T_STATIC) {
            array_shift($argument);
        }

        $kind = $argument[0][0] ?? null;

        if (! in_array($kind, [T_FUNCTION, T_FN], true)) {
            return null;
        }

        $open = 1;
        $count = count($argument);

        while ($open < $count && ! self::is($argument[$open], '(')) {
            $open++;
        }

        $params = self::enclosed($argument, $open);
        $depth = 0;

        // Lo que sigue a los parámetros: `use (...)`, el tipo de retorno y,
        // al final, la llave o la flecha.
        for ($index = $open; $index < $count; $index++) {
            $token = $argument[$index];

            if (self::opens($token)) {
                if ($depth === 0 && self::is($token, '{') && $kind === T_FUNCTION) {
                    return ['line' => $argument[0][2], 'arrow' => false, 'params' => $params, 'body' => self::enclosed($argument, $index)];
                }

                $depth++;
            } elseif (self::is($token, ')', ']', '}')) {
                $depth--;
            } elseif ($depth === 0 && $token[0] === T_DOUBLE_ARROW && $kind === T_FN) {
                return ['line' => $argument[0][2], 'arrow' => true, 'params' => $params, 'body' => array_slice($argument, $index + 1)];
            }
        }

        // Cortado antes del cuerpo: sigue siendo un closure, sin cuerpo que leer.
        return ['line' => $argument[0][2], 'arrow' => $kind === T_FN, 'params' => $params, 'body' => []];
    }

    /**
     * @param  list<Token>  $tokens
     */
    private static function literal(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }

        $value = '';

        foreach ($tokens as $index => $token) {
            if ($index % 2 === 1) {
                if (! self::is($token, '.')) {
                    return null;
                }

                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }

            $inner = substr($token[1], 1, -1);

            $value .= $token[1][0] === "'"
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
                : stripcslashes($inner);
        }

        return count($tokens) % 2 === 1 ? $value : null;
    }

    /**
     * @param  Token  $token
     */
    private static function opens(array $token): bool
    {
        return self::is($token, '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE);
    }

    /**
     * @param  Token  $token
     */
    private static function isName(array $token): bool
    {
        return self::is($token, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE);
    }

    private function parseFile(): void
    {
        $attributes = [];
        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            switch (true) {
                case $token[0] === T_NAMESPACE && ($this->isNameAt($this->i + 1) || self::is($this->tokens[$this->i + 1] ?? null, '{')):
                    $this->namespace = $this->isNameAt($this->i + 1) ? ltrim($this->tokens[++$this->i][1], '\\') : '';
                    $this->uses = [];
                    $this->i++;

                    // `namespace Foo { ... }`: la llave abre el espacio de
                    // nombres, no un bloque que haya que saltarse.
                    if (self::is($this->tokens[$this->i] ?? null, '{')) {
                        $this->i++;
                    }
                    break;

                case $token[0] === T_USE:
                    $this->parseImport();
                    break;

                case $token[0] === T_ATTRIBUTE:
                    $attributes = [...$attributes, ...$this->parseAttributes()];
                    break;

                case self::is($token, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM)
                    && ($this->tokens[$this->i + 1][0] ?? null) === T_STRING:
                    // `Post::class` y `new class (…)` también son T_CLASS,
                    // pero lo que los sigue nunca es un nombre.
                    $this->parseClass($attributes);
                    $attributes = [];
                    break;

                case self::opens($token):
                    $this->balanced();
                    $attributes = [];
                    break;

                case self::is($token, ';'):
                    $attributes = [];
                    $this->i++;
                    break;

                default:
                    $this->i++;
            }
        }
    }

    private function isNameAt(int $index): bool
    {
        return isset($this->tokens[$index]) && self::isName($this->tokens[$index]);
    }

    /**
     * `use A\B;`, `use A\B as C;`, `use A\{B, C as D};`. Los `use function`
     * y `use const` no nombran clases.
     */
    private function parseImport(): void
    {
        $this->i++;

        // `function () use ($x)` fuera de una clase: no importa nada.
        if (self::is($this->tokens[$this->i] ?? null, '(')) {
            return;
        }

        // Lo que sigue hasta el `;` lo recorre parseFile() sin registrar nada:
        // ni un nombre ni unas llaves de grupo cambian su estado.
        if (in_array($this->tokens[$this->i][0] ?? null, [T_FUNCTION, T_CONST], true)) {
            return;
        }

        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if (self::is($token, ';')) {
                $this->i++;

                return;
            }

            if (! self::isName($token)) {
                $this->i++;

                continue;
            }

            $name = ltrim($token[1], '\\');
            $this->i++;

            if (($this->tokens[$this->i][0] ?? null) === T_NS_SEPARATOR && self::is($this->tokens[$this->i + 1] ?? null, '{')) {
                $this->i += 2;
                $this->parseGroupImport($name);

                continue;
            }

            $this->register($name, $this->aliasAt());
        }
    }

    private function parseGroupImport(string $prefix): void
    {
        $count = count($this->tokens);

        while ($this->i < $count && ! self::is($this->tokens[$this->i], '}')) {
            $token = $this->tokens[$this->i];
            $this->i++;

            if (self::isName($token)) {
                $this->register($prefix.'\\'.$token[1], $this->aliasAt());
            }
        }

        $this->i++;
    }

    private function aliasAt(): ?string
    {
        if (($this->tokens[$this->i][0] ?? null) !== T_AS) {
            return null;
        }

        $this->i += 2;

        return $this->tokens[$this->i - 1][1] ?? null;
    }

    private function register(string $name, ?string $alias): void
    {
        $segments = explode('\\', $name);

        $this->uses[strtolower($alias ?? end($segments))] = $name;
    }

    /**
     * Un `#[...]` con uno o varios atributos.
     *
     * @return list<AttributeOutline>
     */
    private function parseAttributes(): array
    {
        $attributes = [];
        $count = count($this->tokens);
        $this->i++;

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            if (self::is($token, ']')) {
                $this->i++;

                break;
            }

            if (! self::isName($token)) {
                self::opens($token) ? $this->balanced() : $this->i++;

                continue;
            }

            $this->i++;
            $args = self::is($this->tokens[$this->i] ?? null, '(') ? $this->balanced() : null;

            $attributes[] = ['name' => $this->resolve($token[1]), 'line' => $token[2], 'args' => $args];
        }

        return $attributes;
    }

    /**
     * @param  list<AttributeOutline>  $attributes
     */
    private function parseClass(array $attributes): void
    {
        $kind = strtolower($this->tokens[$this->i][1]);
        $line = $this->tokens[$this->i][2];
        $abstract = false;

        // `abstract final readonly class`: los modificadores van delante, en
        // cualquier orden.
        for ($before = $this->i - 1; in_array($this->tokens[$before][0] ?? null, [T_ABSTRACT, T_FINAL, T_READONLY], true); $before--) {
            $abstract = $abstract || $this->tokens[$before][0] === T_ABSTRACT;
        }

        $name = $this->tokens[$this->i + 1][1];
        $extends = null;
        $count = count($this->tokens);
        $this->i += 2;

        while ($this->i < $count && ! self::is($this->tokens[$this->i], '{')) {
            if ($this->tokens[$this->i][0] === T_EXTENDS && $kind === 'class' && $this->isNameAt($this->i + 1)) {
                $extends = $this->resolve($this->tokens[$this->i + 1][1]);
            }

            $this->i++;
        }

        $this->i++;

        [$methods, $traits] = $this->parseClassBody();

        $this->classes[] = [
            'kind' => $kind,
            'name' => $name,
            'fqcn' => $this->namespace === '' ? $name : $this->namespace.'\\'.$name,
            'line' => $line,
            'abstract' => $abstract,
            'extends' => $extends,
            'traits' => $traits,
            'attributes' => $attributes,
            'methods' => $methods,
        ];
    }

    /**
     * @return array{0: list<MethodOutline>, 1: list<string>}
     */
    private function parseClassBody(): array
    {
        $methods = [];
        $traits = [];
        $attributes = [];
        $modifiers = [];
        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i];

            switch (true) {
                case self::is($token, '}'):
                    $this->i++;

                    return [$methods, $traits];

                case $token[0] === T_USE:
                    $this->i++;

                    while ($this->i < $count && ! self::is($this->tokens[$this->i], ';', '{')) {
                        if (self::isName($this->tokens[$this->i])) {
                            $traits[] = $this->resolve($this->tokens[$this->i][1]);
                        }

                        $this->i++;
                    }

                    // Las adaptaciones (`{ a insteadof b; }`) no cambian qué
                    // traits se usan.
                    self::is($this->tokens[$this->i] ?? null, '{') ? $this->balanced() : $this->i++;
                    break;

                case $token[0] === T_ATTRIBUTE:
                    $attributes = [...$attributes, ...$this->parseAttributes()];
                    break;

                case in_array($token[0], self::MODIFIERS, true):
                    $modifiers[] = strtolower($token[1]);
                    $this->i++;
                    break;

                case $token[0] === T_FUNCTION:
                    $method = $this->parseMethod($modifiers, $attributes);

                    if ($method !== null) {
                        $methods[] = $method;
                    }

                    $attributes = [];
                    $modifiers = [];
                    break;

                    // El valor de una constante o una propiedad, o los ganchos de
                    // una propiedad (PHP 8.4: `public string $x { get => ...; }`,
                    // que no acaba en `;`). Lo que la precedía no es del método
                    // que venga después.
                case self::opens($token):
                    $this->balanced();
                    $attributes = [];
                    $modifiers = [];
                    break;

                case self::is($token, ';'):
                    $attributes = [];
                    $modifiers = [];
                    $this->i++;
                    break;

                default:
                    $this->i++;
            }
        }

        return [$methods, $traits];
    }

    /**
     * @param  list<string>  $modifiers
     * @param  list<AttributeOutline>  $attributes
     * @return MethodOutline|null
     */
    private function parseMethod(array $modifiers, array $attributes): ?array
    {
        $line = $this->tokens[$this->i][2];
        $this->i++;

        // `function &items()`: desde PHP 8.1 el `&` es un token con nombre
        // (T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG), no el carácter suelto.
        // Compararlo con '&' no lo saltaba, y el método se llamaba `&`. Se
        // salta, pero se apunta: devolver por referencia es parte de lo que
        // se aprueba (HandRolledLock::method()).
        $byRef = ($this->tokens[$this->i][0] ?? null) === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;

        if ($byRef) {
            $this->i++;
        }

        // Un closure en el valor por defecto de una propiedad: no es un método.
        if (self::is($this->tokens[$this->i] ?? null, '(')) {
            return null;
        }

        $name = $this->tokens[$this->i][1] ?? '';
        $this->i++;
        $signatureStart = $this->i;

        $params = self::is($this->tokens[$this->i] ?? null, '(') ? $this->parseParams($this->balanced()) : [];
        $count = count($this->tokens);

        while ($this->i < $count && ! self::is($this->tokens[$this->i], '{', ';')) {
            $this->i++;
        }

        // Los parámetros completos —con sus valores por defecto— y el tipo de
        // retorno, tal cual: lo que la huella de una excepción aprobada tiene
        // que ver cambiar (HandRolledLock::fingerprint).
        $signature = array_slice($this->tokens, $signatureStart, $this->i - $signatureStart);

        $body = null;

        if (self::is($this->tokens[$this->i] ?? null, '{')) {
            $body = $this->balanced();
        } else {
            $this->i++;
        }

        // Sin modificador, como `public`.
        $visibility = array_values(array_intersect($modifiers, ['protected', 'private']))[0] ?? 'public';

        return [
            'name' => $name,
            'line' => $line,
            'visibility' => $visibility,
            'static' => in_array('static', $modifiers, true),
            'byRef' => $byRef,
            'attributes' => $attributes,
            'params' => $params,
            'signature' => $signature,
            'body' => $body,
        ];
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<ParameterOutline>
     */
    private function parseParams(array $tokens): array
    {
        $params = [];

        foreach (self::splitTopLevel($tokens) as $param) {
            $type = [];
            $depth = 0;

            foreach ($param as $token) {
                // Los atributos de un parámetro no son parte de su tipo.
                if (self::opens($token)) {
                    $depth++;
                } elseif (self::is($token, ')', ']', '}')) {
                    $depth--;
                } elseif ($depth > 0) {
                    continue;
                } elseif ($token[0] === T_VARIABLE) {
                    $params[] = $this->parameter($token[1], $type);

                    break;
                } elseif (! in_array($token[0], [...self::MODIFIERS, '&', T_ELLIPSIS, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                    $type[] = $token;
                }
            }
        }

        return $params;
    }

    /**
     * @param  list<Token>  $type
     * @return ParameterOutline
     */
    private function parameter(string $name, array $type): array
    {
        $raw = implode('', array_column($type, 1));
        $names = array_values(array_filter($type, fn (array $token): bool => ! self::is($token, '?')));

        // Sólo un nombre, opcionalmente anulable. Una unión o una intersección
        // no dicen con qué clase trabaja la acción.
        $resolved = count($names) === 1 && self::isName($names[0]) ? $this->resolve($names[0][1]) : null;

        return ['name' => $name, 'type' => $resolved, 'raw' => $raw];
    }

    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $lower = strtolower($name);

        if (in_array($lower, self::BUILTIN_TYPES, true)) {
            return $lower;
        }

        if (str_starts_with($lower, 'namespace\\')) {
            $name = substr($name, 10);
        } else {
            $first = explode('\\', $name)[0];

            if (isset($this->uses[strtolower($first)])) {
                return $this->uses[strtolower($first)].substr($name, strlen($first));
            }
        }

        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    /**
     * Desde un token que abre hasta el que lo cierra. Devuelve lo de dentro y
     * deja el cursor justo después del cierre.
     *
     * @return list<Token>
     */
    private function balanced(): array
    {
        $inner = [];
        $depth = 0;
        $count = count($this->tokens);

        while ($this->i < $count) {
            $token = $this->tokens[$this->i++];

            if (self::opens($token)) {
                if ($depth++ === 0) {
                    continue;
                }
            } elseif (self::is($token, ')', ']', '}') && --$depth === 0) {
                break;
            }

            $inner[] = $token;
        }

        return $inner;
    }
}
