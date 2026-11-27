<?php

namespace Innoboxrr\LarapackGenerator\Tools\Migration\Schema;

use Illuminate\Support\Str;

/**
 * Lee una migración —la genere LaraPack o la escriba una persona, se llame
 * como se llame— y dice qué le hace a cada tabla.
 *
 * Lee con el tokenizador de PHP, no con expresiones regulares: una columna
 * repartida en cinco líneas (`->nullable()` debajo de `->foreignId(...)`), un
 * comentario en medio o una longitud (`string('x', 500)`) son la misma
 * columna. Lo que NO sabe leer con seguridad no lo adivina: lo marca, y quien
 * escribe la alteración se abstiene sobre esa tabla o esa columna.
 *
 *  - Una columna en un bucle, una tabla en una variable o una instrucción que
 *    no es de Blueprint dejan la tabla OPACA: no se sabe qué columnas tiene.
 *  - SQL crudo (`DB::statement`) que nombra la tabla deja INCIERTAS las
 *    columnas que nombra; si el SQL se arma con variables, la tabla queda
 *    opaca.
 *  - Lo que va dentro de un `if` que no es una guarda de `Schema::hasColumn`
 *    o `hasTable` queda incierto: puede que corra y puede que no.
 *
 * Sólo cuenta lo que hace `up()` y los métodos que no son `down()`: una
 * migración que reparte su trabajo en métodos privados también se lee.
 */
final class MigrationReader
{
    /**
     * @var array<string, MigrationReading>
     */
    private static array $cache = [];

    /**
     * @var list<array{0: int|string, 1: string, 2: int}> tipo, texto, posición en
     */
    private array $tokens = [];

    /**
     * @var list<string>
     */
    private array $all = [];

    /**
     * @var list<int|string>
     */
    private array $kinds = [];

    /**
     * @var array<int, int> apertura => cierre, en índices de
     */
    private array $pairs = [];

    /** Si el último método leído iba detrás de `connection(...)->`. */
    private bool $throughConnection = false;

    /**
     * @var list<array{0: int, 1: int}> los cuerpos de down(), que no cuentan
     */
    private array $skipped = [];

    /**
     * @var list<array{kind: string, from: int, to: int, guard: bool, early: bool}>
     */
    private array $controls = [];

    /**
     * @var list<array{0: int, 1: int}> el cuerpo de cada función
     */
    private array $functions = [];

    private function __construct(private readonly string $source) {}

    public static function read(string $file): MigrationReading
    {
        $key = $file.'|'.@filemtime($file).'|'.@filesize($file);

        return self::$cache[$key] ??= (new self((string) @file_get_contents($file)))->reading($file);
    }

    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Las columnas de una sentencia suelta, `$table->string('x')->nullable();`:
     * así se lee también lo que escribe el propio generador, con las mismas
     * reglas que lo que ya está en el disco.
     *
     * @return list<ColumnDefinition>
     */
    public static function columns(string $statement): array
    {
        $reader = new self('<?php '.$statement);
        $reader->tokenize();

        $statements = $reader->body(0, count($reader->tokens) - 1, '$table');

        if (is_string($statements)) {
            return [];
        }

        $columns = [];

        foreach ($statements as $parsed) {
            if ($parsed['kind'] !== 'add' || ! is_array($parsed['columns'] ?? null)) {
                continue;
            }

            foreach ($parsed['columns'] as $column) {
                if ($column instanceof ColumnDefinition) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    private function reading(string $file): MigrationReading
    {
        $this->tokenize();
        $this->outline();

        $operations = [];
        $mentions = [];

        foreach ($this->tokens as $i => [$type, $text]) {
            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                $mentions[] = self::unquote($text);
            }

            if ($this->isSkipped($i) || ! in_array($type, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $class = ltrim(strrchr('\\'.$text, '\\') ?: $text, '\\');

            if (($this->tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON) {
                continue;
            }

            $operation = match ($class) {
                'Schema' => $this->schemaCall($i),
                'DB' => $this->sqlCall($i),
                default => null,
            };

            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        return new MigrationReading($file, $operations, array_values(array_unique($mentions)));
    }

    // LA LLAMADA

    /**
     * @return array<string, mixed>|null
     */
    private function schemaCall(int $i): ?array
    {
        [$method, $open] = $this->methodAfter($i + 2);

        if ($method === null || $open === null) {
            return null;
        }

        $args = $this->arguments($open);
        $context = $this->context($i);
        $table = isset($args[0]) ? $this->value($args[0][0], $args[0][1]) : null;
        $name = $table !== null && $table->isString() ? (string) $table->value : null;

        if (! in_array($method, ['create', 'table', 'drop', 'dropIfExists', 'rename', 'dropColumns'], true)) {
            return null;
        }

        $base = ['table' => $name, 'conditional' => $context === 'conditional', 'statements' => [], 'opaque' => null, 'sql' => null];

        // `Schema::connection('otra')->table('posts', …)` puede ser otra base:
        // sus columnas no se suman a la tabla de la conexión por omisión, y
        // tampoco se adivina que no lo es. La tabla queda sin leer.
        if ($this->throughConnection) {
            return array_merge($base, ['kind' => 'table', 'opaque' => "Schema::connection()->{$method}()".($name !== null ? " de '{$name}'" : '').' en una conexión que puede no ser la de la aplicación']);
        }

        if ($name === null || $context === 'loop') {
            $why = $name === null
                ? "Schema::{$method}() con la tabla en una expresión"
                : "Schema::{$method}('{$name}') dentro de un bucle";

            // Si lo que hace se lee, sólo quedan inciertas las columnas que
            // toca: no se sabe a qué tabla, o cuántas veces.
            $statements = in_array($method, ['table', 'create'], true) && isset($args[1])
                ? $this->callback($args[1][0], $args[1][1])
                : $why;

            return array_merge($base, ['kind' => 'dynamic'], is_string($statements)
                ? ['opaque' => $why.($statements === $why ? '' : ', y '.$statements)]
                : ['statements' => array_map(self::uncertain(...), $statements), 'reason' => $why]);
        }

        if ($method === 'rename') {
            $to = isset($args[1]) ? $this->value($args[1][0], $args[1][1]) : null;

            return array_merge($base, ['kind' => 'rename', 'tables' => array_values(array_filter([$name, $to?->isString() ? (string) $to->value : null]))]);
        }

        if ($method === 'drop' || $method === 'dropIfExists') {
            return array_merge($base, ['kind' => 'drop']);
        }

        if ($method === 'dropColumns') {
            $names = isset($args[1]) ? $this->names($args[1][0], $args[1][1]) : null;

            return array_merge($base, ['kind' => 'table'], $names === null
                ? ['opaque' => 'Schema::dropColumns() con columnas en una expresión']
                : ['statements' => [['kind' => 'drop', 'names' => $names]]]);
        }

        $statements = isset($args[1]) ? $this->callback($args[1][0], $args[1][1]) : 'Schema::'.$method.'() sin función';

        return array_merge($base, ['kind' => $method], is_string($statements)
            ? ['opaque' => $statements]
            : ['statements' => $statements]);
    }

    /**
     * `DB::statement()` y `DB::unprepared()`: SQL que no pasa por Blueprint.
     *
     * @return array<string, mixed>|null
     */
    private function sqlCall(int $i): ?array
    {
        [$method, $open] = $this->methodAfter($i + 2);

        if (! in_array($method, ['statement', 'unprepared'], true) || $open === null) {
            return null;
        }

        $args = $this->arguments($open);
        $sql = isset($args[0]) ? $this->value($args[0][0], $args[0][1]) : null;
        $static = $sql !== null && $sql->isString() ? (string) $sql->value : null;

        // Del SQL armado en el momento se lee lo que está escrito: un
        // `ADD CONSTRAINT … CHECK` no toca columnas aunque la tabla vaya en
        // una variable; un `DROP COLUMN` sí, y entonces no se sabe cuál.
        $written = $static ?? (isset($args[0]) ? $this->fragments($args[0][0], $args[0][1]) : '');

        return [
            'kind' => 'sql',
            'table' => null,
            'conditional' => true,
            'statements' => [],
            'opaque' => $static !== null ? null : "DB::{$method}() con SQL armado en el momento",
            'sql' => $static,
            'effect' => $written === '' ? 'unknown' : self::sqlEffect($written),
        ];
    }

    /**
     * Lo que le hace un SQL a las columnas de una tabla:
     *  - `none`: datos, índices, CHECK, disparadores. No cambia columnas.
     *  - `foreign`: pone o quita llaves.
     *  - `columns`: añade, quita o cambia columnas.
     *  - `unknown`: crea, borra o renombra tablas.
     */
    public static function sqlEffect(string $sql): string
    {
        $sql = strtoupper((string) preg_replace('/\s+/', ' ', $sql));

        if (preg_match('/\b(ALTER|CREATE|DROP|RENAME)\b/', $sql) !== 1) {
            return 'none';
        }

        if (preg_match('/\b(CREATE|DROP|RENAME|TRUNCATE)\s+(TEMPORARY\s+)?TABLE\b|\bRENAME\s+TO\b/', $sql) === 1) {
            return 'unknown';
        }

        if (preg_match('/\b(MODIFY|CHANGE)\b|\b(ALTER|RENAME)\s+COLUMN\b/', $sql) === 1) {
            return 'columns';
        }

        $notColumns = ['CONSTRAINT', 'INDEX', 'KEY', 'UNIQUE', 'PRIMARY', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK', 'TRIGGER', 'VIEW', 'PARTITION', 'DEFAULT'];

        preg_match_all('/\b(ADD|DROP)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?([A-Z_`?]+)/', $sql, $matches, PREG_SET_ORDER);

        foreach ($matches as [, , $next]) {
            if (! in_array(trim($next, '`'), $notColumns, true)) {
                return 'columns';
            }
        }

        return preg_match('/\bFOREIGN\s+KEY\b/', $sql) === 1 ? 'foreign' : 'none';
    }

    /**
     * El texto escrito de una expresión que arma un SQL: las cadenas y los
     * trozos de una cadena con variables, con `?` donde va lo que no se sabe.
     */
    private function fragments(int $from, int $to): string
    {
        $parts = [];

        for ($i = $from; $i <= $to; $i++) {
            [$type, $text] = $this->tokens[$i];

            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                $parts[] = self::unquote($text);
            } elseif ($type === T_ENCAPSED_AND_WHITESPACE) {
                $parts[] = $text;
            } else {
                $parts[] = '?';
            }
        }

        return trim((string) preg_replace('/(\s*\?\s*)+/', ' ? ', implode('', $parts)));
    }

    /**
     * El método tras `Clase::`, saltando `connection(...)->`.
     *
     * @return array{0: string|null, 1: int|null} el nombre y su paréntesis
     */
    private function methodAfter(int $i): array
    {
        $this->throughConnection = false;

        return $this->methodAfterConnection($i);
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function methodAfterConnection(int $i): array
    {
        if (($this->tokens[$i][0] ?? null) !== T_STRING) {
            return [null, null];
        }

        $method = $this->tokens[$i][1];
        $open = $i + 1;

        if (($this->tokens[$open][1] ?? null) !== '(') {
            return [null, null];
        }

        if ($method === 'connection') {
            $close = $this->pairs[$open] ?? null;

            if ($close === null || ($this->tokens[$close + 1][0] ?? null) !== T_OBJECT_OPERATOR) {
                return [null, null];
            }

            $this->throughConnection = true;

            return $this->methodAfterConnection($close + 2);
        }

        return [$method, $open];
    }

    /**
     * Lo que hace la función que recibe el Blueprint.
     *
     * @return list<array<string, mixed>>|string la lista de sentencias, o por qué no se lee
     */
    private function callback(int $from, int $to): array|string
    {
        $i = $from;

        if (($this->tokens[$i][0] ?? null) === T_STATIC) {
            $i++;
        }

        $kind = $this->tokens[$i][0] ?? null;

        if (! in_array($kind, [T_FUNCTION, T_FN], true) || ($this->tokens[$i + 1][1] ?? null) !== '(') {
            return 'el Blueprint lo recibe algo que no es una función escrita ahí';
        }

        $paramsClose = $this->pairs[$i + 1] ?? $to;
        $param = null;

        for ($j = $i + 2; $j < $paramsClose; $j++) {
            if ($this->tokens[$j][0] === T_VARIABLE) {
                $param = $this->tokens[$j][1];

                break;
            }
        }

        if ($param === null) {
            return [];
        }

        if ($kind === T_FN) {
            for ($j = $paramsClose + 1; $j <= $to && $this->tokens[$j][0] !== T_DOUBLE_ARROW; $j++);

            return $this->body($j + 1, $to, $param);
        }

        for ($j = $paramsClose + 1; $j <= $to && $this->tokens[$j][1] !== '{'; $j++);

        $close = $this->pairs[$j] ?? null;

        return $close === null ? 'una función sin cuerpo' : $this->body($j + 1, $close - 1, $param);
    }

    /**
     * Las sentencias de un cuerpo. Todas tienen que ser `$table->...`: una que
     * no lo es deja todo el cuerpo sin leer.
     *
     * @return list<array<string, mixed>>|string
     */
    private function body(int $from, int $to, string $param): array|string
    {
        $statements = [];

        for ($i = $from; $i <= $to;) {
            if ($this->tokens[$i][0] === T_IF) {
                $guarded = $this->guarded($i, $to, $param);

                if (is_string($guarded[0])) {
                    return $guarded[0];
                }

                $statements = [...$statements, ...$guarded[0]];
                $i = $guarded[1];

                continue;
            }

            for ($end = $i; $end <= $to && $this->tokens[$end][1] !== ';'; $end++) {
                if (isset($this->pairs[$end])) {
                    $end = $this->pairs[$end];
                }
            }

            if ($i <= $end - 1) {
                $parsed = $this->statement($i, $end - 1, $param);

                if (is_string($parsed)) {
                    return $parsed;
                }

                $statements[] = $parsed;
            }

            $i = $end + 1;
        }

        return $statements;
    }

    /**
     * Un `if` dentro de la función: con una guarda de `Schema::hasColumn`, lo
     * que hay dentro cuenta como hecho; con cualquier otra condición —y en
     * sus `else`—, lo que toca queda incierto.
     *
     * @return array{0: list<array<string, mixed>>|string, 1: int} las sentencias y dónde sigue
     */
    private function guarded(int $i, int $to, string $param): array
    {
        $statements = [];

        while ($i <= $to && in_array($this->tokens[$i][0], [T_IF, T_ELSEIF, T_ELSE], true)) {
            $condition = $this->tokens[$i][0] === T_ELSE ? null : $i + 1;

            if ($condition !== null && ($this->tokens[$condition][1] ?? null) !== '(') {
                return ['un if que no se lee', $to + 1];
            }

            $open = $condition === null ? $i + 1 : ($this->pairs[$condition] ?? $to) + 1;

            if (($this->tokens[$open][1] ?? null) !== '{') {
                return ['un if sin llaves', $to + 1];
            }

            $close = $this->pairs[$open] ?? $to;
            $inner = $this->body($open + 1, $close - 1, $param);

            if (is_string($inner)) {
                return [$inner, $to + 1];
            }

            $guard = $condition !== null && $this->tokens[$i][0] === T_IF
                && $this->isGuard($condition + 1, ($this->pairs[$condition] ?? $condition) - 1);

            $statements = [...$statements, ...($guard ? $inner : array_map(self::uncertain(...), $inner))];
            $i = $close + 1;
        }

        return [$statements, $i];
    }

    /**
     * Lo mismo que hace la sentencia, pero sin saber si se hace: las columnas
     * que toca quedan inciertas.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public static function uncertain(array $statement): array
    {
        $names = [];

        foreach (is_array($statement['columns'] ?? null) ? $statement['columns'] : [] as $column) {
            if ($column instanceof ColumnDefinition) {
                $names[] = $column->name;
            }
        }

        foreach (['names', 'from', 'to', 'column'] as $key) {
            foreach ((array) ($statement[$key] ?? []) as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
        }

        return ($statement['kind'] ?? null) === 'ignore' || $names === []
            ? $statement
            : ['kind' => 'uncertain', 'names' => array_values(array_unique($names))];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function statement(int $from, int $to, string $param): array|string
    {
        if ($this->tokens[$from][1] !== $param || ($this->tokens[$from + 1][0] ?? null) !== T_OBJECT_OPERATOR) {
            for ($i = $from; $i <= $to; $i++) {
                if (in_array($this->tokens[$i][0], [T_FOREACH, T_FOR, T_WHILE, T_DO], true)) {
                    return 'columnas en un bucle';
                }
            }

            return 'una instrucción que no es de Blueprint: «'.$this->text($from, min($to, $from + 6)).'»';
        }

        $calls = [];

        for ($i = $from + 1; $i <= $to;) {
            if ($this->tokens[$i][0] !== T_OBJECT_OPERATOR || ($this->tokens[$i + 1][0] ?? null) !== T_STRING || ($this->tokens[$i + 2][1] ?? null) !== '(') {
                return 'una llamada que no se lee: «'.$this->text($from, $to).'»';
            }

            $open = $i + 2;
            $close = $this->pairs[$open] ?? $to;

            $calls[] = [$this->tokens[$i + 1][1], array_map(
                fn (array $range): Value => $this->value($range[0], $range[1]),
                $this->arguments($open)
            )];

            $i = $close + 1;
        }

        return $this->interpret($calls);
    }

    // LO QUE SIGNIFICA

    /**
     * @param  list<array{0: string, 1: list<Value>}>  $calls
     * @return array<string, mixed>|string
     */
    private function interpret(array $calls): array|string
    {
        [$method, $args] = array_shift($calls) ?? ['', []];
        $first = $args[0] ?? null;
        $name = $first?->isString() ? (string) $first->value : null;

        if (in_array($method, ColumnTypes::SINGLE, true) || in_array($method, ['id', 'uuid', 'ulid'], true)) {
            $name ??= $first === null ? ($method === 'id' ? 'id' : ($method === 'uuid' || $method === 'ulid' ? $method : null)) : null;

            if ($name === null) {
                return "una columna {$method}() sin nombre que se pueda leer";
            }

            return $this->column($name, $method, array_slice($args, 1), $calls);
        }

        return match ($method) {
            'timestamps', 'timestampsTz', 'nullableTimestamps' => $this->several($calls, [
                ['created_at', 'timestamp', true], ['updated_at', 'timestamp', true],
            ]),
            'softDeletes', 'softDeletesTz' => $this->several($calls, [[$name ?? 'deleted_at', 'timestamp', true]]),
            'rememberToken' => $this->several($calls, [['remember_token', 'string', true]]),
            'morphs', 'nullableMorphs', 'uuidMorphs', 'nullableUuidMorphs', 'ulidMorphs', 'nullableUlidMorphs', 'numericMorphs', 'nullableNumericMorphs' => $name === null
                ? "{$method}() sin nombre que se pueda leer"
                : $this->several($calls, [
                    [$name.'_type', 'string', str_starts_with($method, 'nullable')],
                    [$name.'_id', str_contains($method, 'Uuid') ? 'uuid' : (str_contains($method, 'Ulid') ? 'ulid' : 'unsignedBigInteger'), str_starts_with($method, 'nullable')],
                ]),
            'foreignIdFor' => $this->foreignIdFor($args, $calls),
            'dropColumn', 'dropColumns' => $this->drop($args),
            'dropConstrainedForeignId' => $name === null ? 'dropConstrainedForeignId() sin columna' : ['kind' => 'drop', 'names' => [$name]],
            'dropTimestamps', 'dropTimestampsTz' => ['kind' => 'drop', 'names' => ['created_at', 'updated_at']],
            'dropSoftDeletes', 'dropSoftDeletesTz' => ['kind' => 'drop', 'names' => [$name ?? 'deleted_at']],
            'dropRememberToken' => ['kind' => 'drop', 'names' => ['remember_token']],
            'dropMorphs' => $name === null ? 'dropMorphs() sin nombre' : ['kind' => 'drop', 'names' => [$name.'_type', $name.'_id']],
            'renameColumn' => isset($args[1]) && $name !== null && $args[1]->isString()
                ? ['kind' => 'rename', 'from' => $name, 'to' => (string) $args[1]->value]
                : 'renameColumn() con nombres que no se leen',
            'foreign' => $this->foreign($args, $calls),
            'dropForeign' => $this->dropForeign($first),
            'index', 'unique', 'primary', 'fullText', 'fulltext', 'spatialIndex', 'rawIndex', 'dropIndex', 'dropUnique',
            'dropPrimary', 'dropFullText', 'dropSpatialIndex', 'renameIndex', 'engine', 'charset', 'collation', 'comment',
            'temporary', 'dropIndexIfExists', 'dropUniqueIfExists' => ['kind' => 'ignore'],
            default => $name !== null ? ['kind' => 'uncertain', 'names' => [$name]] : "{$method}(), que no se sabe qué columnas toca",
        };
    }

    /**
     * @param  list<Value>  $args
     * @param  list<array{0: string, 1: list<Value>}>  $modifiers
     * @return array<string, mixed>
     */
    private function column(string $name, string $method, array $args, array $modifiers): array
    {
        $nullable = false;
        $default = null;
        $unsigned = false;
        $change = false;
        $extras = [];
        $foreign = null;

        foreach ($modifiers as [$modifier, $arguments]) {
            $argument = $arguments[0] ?? null;

            switch ($modifier) {
                case 'nullable':
                    $nullable = $argument === null || ($argument->literal && (bool) $argument->value);
                    break;
                case 'default':
                    $default = $argument !== null && $argument->literal && $argument->value === null ? null : $argument;
                    break;
                case 'unsigned':
                    $unsigned = true;
                    break;
                case 'change':
                    $change = true;
                    break;
                case 'constrained':
                    $column = isset($arguments[1]) && $arguments[1]->isString() ? (string) $arguments[1]->value : 'id';
                    $table = $argument?->isString()
                        ? (string) $argument->value
                        : Str::plural(Str::beforeLast($name, '_'.$column));
                    $index = isset($arguments[2]) && $arguments[2]->isString() ? (string) $arguments[2]->value : null;
                    $foreign = new ForeignKey($table, $column, $index, $foreign?->onDelete, $foreign?->onUpdate);
                    break;
                case 'references':
                    $foreign = new ForeignKey($foreign !== null ? $foreign->table : '', $argument?->isString() ? (string) $argument->value : 'id', $foreign?->name, $foreign?->onDelete, $foreign?->onUpdate);
                    break;
                case 'on':
                    $foreign = new ForeignKey($argument?->isString() ? (string) $argument->value : '', $foreign !== null ? $foreign->column : 'id', $foreign?->name, $foreign?->onDelete, $foreign?->onUpdate);
                    break;
                default:
                    $action = self::action($modifier, $argument);

                    if ($action !== null) {
                        $foreign = ($foreign ?? new ForeignKey('', 'id'))->with(
                            $action[0] === 'delete' ? $action[1] : null,
                            $action[0] === 'update' ? $action[1] : null,
                        );

                        break;
                    }

                    $extras[] = [$modifier, $arguments];
            }
        }

        if ($foreign !== null && $foreign->table === '') {
            $foreign = null;
        }

        return ['kind' => $change ? 'change' : 'add', 'columns' => [
            new ColumnDefinition($name, $method, $args, $nullable, $default, $unsigned, $foreign, $extras, $change),
        ]];
    }

    /**
     * @param  list<array{0: string, 1: list<Value>}>  $modifiers
     * @param  list<array{0: string, 1: string, 2: bool}>  $columns
     * @return array<string, mixed>
     */
    private function several(array $modifiers, array $columns): array
    {
        $nullable = false;

        foreach ($modifiers as [$modifier]) {
            $nullable = $nullable || $modifier === 'nullable';
        }

        return ['kind' => 'add', 'columns' => array_map(
            fn (array $column): ColumnDefinition => new ColumnDefinition($column[0], $column[1], [], $column[2] || $nullable),
            $columns
        )];
    }

    /**
     * @param  list<Value>  $args
     * @param  list<array{0: string, 1: list<Value>}>  $modifiers
     * @return array<string, mixed>|string
     */
    private function foreignIdFor(array $args, array $modifiers): array|string
    {
        $model = $args[0] ?? null;
        $column = $args[1] ?? null;

        if ($column !== null && $column->isString()) {
            return $this->column((string) $column->value, 'foreignId', [], $modifiers);
        }

        if ($model === null || ! $model->isString()) {
            return 'foreignIdFor() con un modelo que no se lee';
        }

        return $this->column(Str::snake(Str::afterLast((string) $model->value, '\\')).'_id', 'foreignId', [], $modifiers);
    }

    /**
     * @param  list<Value>  $args
     * @return array<string, mixed>|string
     */
    private function drop(array $args): array|string
    {
        $names = [];

        foreach ($args as $arg) {
            $values = $arg->literal && is_array($arg->value) ? $arg->value : [$arg->value];

            foreach ($values as $value) {
                if (! $arg->literal || ! is_string($value)) {
                    return 'dropColumn() con columnas que no se leen';
                }

                $names[] = $value;
            }
        }

        return ['kind' => 'drop', 'names' => $names];
    }

    /**
     * @param  list<Value>  $args
     * @param  list<array{0: string, 1: list<Value>}>  $modifiers
     * @return array<string, mixed>|string
     */
    private function foreign(array $args, array $modifiers): array|string
    {
        $columns = $args[0] ?? null;
        $names = $columns === null ? null : $this->names($columns);

        if ($names === null || count($names) !== 1) {
            // Una llave de varias columnas no es de ninguna columna del
            // laraimport: no cambia ninguna.
            return ['kind' => 'ignore'];
        }

        $parsed = $this->column($names[0], 'foreignId', [], $modifiers);
        $foreign = $parsed['columns'][0]->foreign;

        if ($foreign === null) {
            return 'foreign() sin la tabla a la que apunta';
        }

        $index = isset($args[1]) && $args[1]->isString() ? (string) $args[1]->value : null;

        return ['kind' => 'foreign', 'column' => $names[0], 'foreign' => $foreign->with(name: $index)];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function dropForeign(?Value $argument): array|string
    {
        if ($argument?->isString()) {
            return ['kind' => 'dropForeign', 'name' => (string) $argument->value];
        }

        $names = $argument === null ? null : $this->names($argument);

        return $names === null ? 'dropForeign() con una llave que no se lee' : ['kind' => 'dropForeign', 'names' => $names];
    }

    /**
     * @return list<string>|null
     */
    private function names(Value|int $argument, ?int $to = null): ?array
    {
        $value = $argument instanceof Value ? $argument : $this->value($argument, (int) $to);

        if (! $value->literal) {
            return null;
        }

        $names = [];

        foreach (is_array($value->value) ? $value->value : [$value->value] as $name) {
            if (! is_string($name)) {
                return null;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array{0: 'delete'|'update', 1: string}|null
     */
    private static function action(string $modifier, ?Value $argument): ?array
    {
        $fixed = [
            'cascadeOnDelete' => ['delete', 'cascade'], 'restrictOnDelete' => ['delete', 'restrict'],
            'nullOnDelete' => ['delete', 'set null'], 'noActionOnDelete' => ['delete', 'no action'],
            'cascadeOnUpdate' => ['update', 'cascade'], 'restrictOnUpdate' => ['update', 'restrict'],
            'nullOnUpdate' => ['update', 'set null'], 'noActionOnUpdate' => ['update', 'no action'],
        ];

        if (isset($fixed[$modifier])) {
            return $fixed[$modifier];
        }

        if (($modifier === 'onDelete' || $modifier === 'onUpdate') && $argument?->isString()) {
            return [$modifier === 'onDelete' ? 'delete' : 'update', strtolower((string) $argument->value)];
        }

        return null;
    }

    // EL VALOR DE UN ARGUMENTO

    private function value(int $from, int $to): Value
    {
        $raw = $this->text($from, $to);

        if ($from > $to) {
            return Value::expression($raw);
        }

        [$type, $text] = $this->tokens[$from];

        // Un heredoc o nowdoc sin variables es una cadena más.
        if ($type === T_START_HEREDOC && $this->tokens[$to][0] === T_END_HEREDOC
            && ($to === $from + 1 || ($to === $from + 2 && $this->tokens[$from + 1][0] === T_ENCAPSED_AND_WHITESPACE))) {
            return Value::literal($to === $from + 1 ? '' : $this->tokens[$from + 1][1], $raw);
        }

        // Cadenas unidas con punto: 'a'.'b'.
        if ($type === T_CONSTANT_ENCAPSED_STRING) {
            $string = '';

            for ($i = $from; $i <= $to; $i += 2) {
                if ($this->tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING || ($i + 1 <= $to && $this->tokens[$i + 1][1] !== '.')) {
                    return Value::expression($raw);
                }

                $string .= self::unquote($this->tokens[$i][1]);
            }

            return Value::literal($string, $raw);
        }

        $sign = 1;

        if (($text === '-' || $text === '+') && $from < $to) {
            $sign = $text === '-' ? -1 : 1;
            [$type, $text] = $this->tokens[++$from];
        }

        if ($from === $to && ($type === T_LNUMBER || $type === T_DNUMBER)) {
            $number = self::number($type, $text);

            return $number === null ? Value::expression($raw) : Value::literal($sign * $number, $raw);
        }

        if ($from === $to && $type === T_STRING) {
            return match (strtolower($text)) {
                'true' => Value::literal(true, $raw),
                'false' => Value::literal(false, $raw),
                'null' => Value::literal(null, $raw),
                default => Value::expression($raw),
            };
        }

        // Clase::class
        if ($to === $from + 2 && in_array($type, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            && $this->tokens[$from + 1][0] === T_DOUBLE_COLON && $this->tokens[$to][0] === T_CLASS) {
            return Value::literal(ltrim($text, '\\'), $raw);
        }

        if ($text === '[' && ($this->pairs[$from] ?? null) === $to) {
            return $this->listValue($from + 1, $to - 1, $raw);
        }

        if ($type === T_ARRAY && ($this->tokens[$from + 1][1] ?? null) === '(' && ($this->pairs[$from + 1] ?? null) === $to) {
            return $this->listValue($from + 2, $to - 1, $raw);
        }

        return Value::expression($raw);
    }

    private function listValue(int $from, int $to, string $raw): Value
    {
        $items = [];

        foreach ($this->split($from, $to) as [$start, $end]) {
            $arrow = null;

            for ($i = $start; $i <= $end; $i++) {
                if ($this->tokens[$i][0] === T_DOUBLE_ARROW) {
                    $arrow = $i;

                    break;
                }

                if (isset($this->pairs[$i])) {
                    $i = $this->pairs[$i];
                }
            }

            $item = $this->value($arrow === null ? $start : $arrow + 1, $end);

            if (! $item->literal) {
                return Value::expression($raw);
            }

            if ($arrow === null) {
                $items[] = $item->value;

                continue;
            }

            $key = $this->value($start, $arrow - 1);

            if (! $key->literal || ! (is_int($key->value) || is_string($key->value))) {
                return Value::expression($raw);
            }

            $items[$key->value] = $item->value;
        }

        return Value::literal($items, $raw);
    }

    private static function number(int $type, string $text): int|float|null
    {
        $text = str_replace('_', '', $text);

        if ($type === T_DNUMBER) {
            return is_numeric($text) ? (float) $text : null;
        }

        // 08 no compila en PHP: no se sabe qué quiso decir.
        if (preg_match('/^0[0-9]+$/', $text) === 1 && preg_match('/^0[0-7]+$/', $text) !== 1) {
            return null;
        }

        return intval(preg_replace('/^0o/i', '0', $text) ?? $text, 0);
    }

    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        if ($literal[0] === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }

        return stripcslashes(str_replace('\\$', '$', $body));
    }

    // EL TEXTO

    private function tokenize(): void
    {
        foreach (token_get_all($this->source) as $token) {
            $index = count($this->all);
            $type = is_array($token) ? $token[0] : $token;

            $this->all[] = is_array($token) ? $token[1] : $token;
            $this->kinds[] = $type;

            if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                continue;
            }

            $this->tokens[] = [$type, is_array($token) ? $token[1] : $token, $index];
        }

        $stack = [];

        foreach ($this->tokens as $i => [$type, $text]) {
            if (in_array($text, ['(', '[', '{'], true) || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = $i;
            } elseif (in_array($text, [')', ']', '}'], true) && $stack !== []) {
                $open = (int) array_pop($stack);
                $this->pairs[$open] = $i;
            }
        }
    }

    /**
     * Dónde empieza y acaba cada función, cada bucle y cada `if`.
     */
    private function outline(): void
    {
        foreach ($this->tokens as $i => [$type]) {
            if ($type === T_FUNCTION || $type === T_FN) {
                $name = $this->tokens[$i + 1][0] === T_STRING ? strtolower($this->tokens[$i + 1][1]) : null;
                $body = $this->blockAfter($i);

                if ($body !== null) {
                    $this->functions[] = $body;

                    if ($name === 'down') {
                        $this->skipped[] = $body;
                    }
                }

                continue;
            }

            $kind = match ($type) {
                T_FOREACH, T_FOR, T_WHILE, T_DO => 'loop',
                T_IF, T_ELSEIF, T_ELSE, T_SWITCH, T_MATCH, T_CATCH => 'if',
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $condition = ($this->tokens[$i + 1][1] ?? null) === '(' ? [$i + 2, ($this->pairs[$i + 1] ?? $i + 1) - 1] : null;
            $after = $condition === null ? $i + 1 : $condition[1] + 2;

            if (($this->tokens[$after][1] ?? null) === '{') {
                $block = [$after, $this->pairs[$after] ?? $after];
            } else {
                for ($end = $after; isset($this->tokens[$end]) && $this->tokens[$end][1] !== ';'; $end++) {
                    if (isset($this->pairs[$end])) {
                        $end = $this->pairs[$end];
                    }
                }

                $block = [$after, $end];
            }

            $guard = $kind === 'if' && $type !== T_ELSE && $condition !== null && $this->isGuard($condition[0], $condition[1]);

            $this->controls[] = [
                'kind' => $kind,
                'from' => $block[0],
                'to' => $block[1],
                'guard' => $guard,
                'early' => $kind === 'if' && $this->onlyReturns($block[0], $block[1]),
            ];
        }
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function blockAfter(int $i): ?array
    {
        for ($j = $i + 1; isset($this->tokens[$j]); $j++) {
            if ($this->tokens[$j][1] === '(' && ! isset($open)) {
                $open = $j;
                $j = $this->pairs[$j] ?? $j;

                continue;
            }

            if ($this->tokens[$j][1] === '{') {
                return [$j, $this->pairs[$j] ?? $j];
            }

            if ($this->tokens[$j][0] === T_DOUBLE_ARROW) {
                // fn () => …: el cuerpo es la expresión, hasta donde acabe.
                $end = $j;

                for ($k = $j + 1; isset($this->tokens[$k]) && ! in_array($this->tokens[$k][1], [';', ',', ')', ']'], true); $k++) {
                    $end = $this->pairs[$k] ?? $k;
                    $k = $end;
                }

                return [$j + 1, $end];
            }

            if ($this->tokens[$j][1] === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * `! Schema::hasColumn('t', 'c')` y compañía: lo que hay dentro corre
     * cuando hace falta y deja la tabla igual corra o no.
     */
    private function isGuard(int $from, int $to): bool
    {
        if ($from > $to) {
            return false;
        }

        for ($i = $from; $i <= $to; $i++) {
            [$type, $text] = $this->tokens[$i];

            if (in_array($text, ['!', '(', ')', ',', '[', ']'], true) || in_array($type, [T_BOOLEAN_AND, T_BOOLEAN_OR, T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_COLON], true)) {
                continue;
            }

            if ($type === T_STRING && in_array($text, ['Schema', 'hasColumn', 'hasColumns', 'hasTable', 'hasIndex'], true)) {
                continue;
            }

            if ($type === T_NAME_FULLY_QUALIFIED && str_ends_with($text, '\\Schema')) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function onlyReturns(int $from, int $to): bool
    {
        $first = $this->tokens[$from][1] === '{' ? $from + 1 : $from;

        return ($this->tokens[$first][0] ?? null) === T_RETURN;
    }

    /**
     * Dónde corre una llamada: sin condiciones, dentro de un bucle, o sólo a
     * veces.
     */
    private function context(int $i): string
    {
        $context = 'always';

        foreach ($this->controls as $control) {
            $inside = $i > $control['from'] && $i < $control['to'];

            if ($inside && $control['kind'] === 'loop') {
                return 'loop';
            }

            if ($inside && ! $control['guard']) {
                $context = 'conditional';
            }

            // `if (...) { return; }` antes de la llamada y en la misma función.
            if ($control['early'] && ! $control['guard'] && $control['to'] < $i && $this->sameFunction($control['from'], $i)) {
                $context = 'conditional';
            }
        }

        return $context;
    }

    private function sameFunction(int $a, int $b): bool
    {
        $innermost = function (int $i): ?array {
            $found = null;

            foreach ($this->functions as $range) {
                if ($i > $range[0] && $i < $range[1] && ($found === null || $range[0] > $found[0])) {
                    $found = $range;
                }
            }

            return $found;
        };

        return $innermost($a) === $innermost($b);
    }

    private function isSkipped(int $i): bool
    {
        foreach ($this->skipped as [$from, $to]) {
            if ($i > $from && $i < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los argumentos de la llamada que abre en `$open`.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function arguments(int $open): array
    {
        $close = $this->pairs[$open] ?? $open;

        return $this->split($open + 1, $close - 1);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function split(int $from, int $to): array
    {
        if ($from > $to) {
            return [];
        }

        $ranges = [];
        $start = $from;

        for ($i = $from; $i <= $to; $i++) {
            if ($this->tokens[$i][1] === ',') {
                $ranges[] = [$start, $i - 1];
                $start = $i + 1;

                continue;
            }

            if (isset($this->pairs[$i])) {
                $i = $this->pairs[$i];
            }
        }

        if ($start <= $to) {
            $ranges[] = [$start, $to];
        }

        return $ranges;
    }

    private function text(int $from, int $to): string
    {
        if ($from > $to || ! isset($this->tokens[$from], $this->tokens[$to])) {
            return '';
        }

        $text = '';

        // Los espacios y comentarios entre piezas cuentan como un espacio; lo
        // que hay dentro de una cadena, tal cual.
        for ($i = $this->tokens[$from][2]; $i <= $this->tokens[$to][2]; $i++) {
            $text .= in_array($this->kinds[$i], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $this->all[$i];
        }

        return trim((string) preg_replace('/ {2,}/', ' ', $text));
    }
}
