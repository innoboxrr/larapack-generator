<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Infer;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\PhpOutline;

/**
 * Lo que un modelo escrito (a mano o por LaraPack) dice de sí mismo, leído
 * sin cargarlo: su tabla, sus listas (`$fillable`, `$hidden`…), sus casts y
 * sus relaciones.
 *
 * Como PhpOutline, con token_get_all y nada más: el código del proyecto no se
 * ejecuta. Lo que no se puede leer sin ejecutar —una lista armada con
 * `array_merge`, un cast calculado, una relación que no es un `return
 * $this->tipo(X::class)` directo— no se adivina: se devuelve en `unreadable`
 * con su motivo, y el inferidor lo lista como «no inferido».
 *
 * @phpstan-import-type Token from PhpOutline
 *
 * @phpstan-type Relation array{name: string, type: string, related: string|null, line: int}
 * @phpstan-type ModelInfo array{file: string, fqcn: string, name: string, namespace: string, extends: string|null, traits: list<string>, table: string|null, lists: array<string, list<string>|null>, casts: array<string, string>, relations: list<Relation>, unreadable: list<string>}
 */
final class ModelSource
{
    /**
     * Las relaciones que el laraimport sabe declarar.
     */
    public const RELATIONS = ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany', 'morphTo', 'morphOne', 'morphMany', 'morphToMany'];

    /**
     * Las que Eloquent tiene y el laraimport no: se listan, no se pierden.
     */
    public const OTHER_RELATIONS = ['hasManyThrough', 'hasOneThrough', 'morphedByMany', 'hasOneOfMany', 'latestOfMany', 'oldestOfMany'];

    /**
     * Las listas del modelo que interesan, por el nombre de su propiedad.
     */
    public const LISTS = ['fillable', 'guarded', 'hidden', 'creatable', 'updatable', 'export_cols', 'loadable_relations'];

    private const IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML];

    /**
     * Lee los modelos de un archivo: normalmente uno, o ninguno si el archivo
     * no declara una clase concreta (un trait, una interfaz, un enum).
     *
     * @return list<ModelInfo>
     */
    public static function read(string $file): array
    {
        $outline = PhpOutline::ofFile($file);
        $models = [];

        foreach ($outline->classes() as $class) {
            if ($class['kind'] !== 'class' || $class['abstract'] || $class['extends'] === null) {
                continue;
            }

            $tokens = self::tokens(Files::read($file), $class['line']);
            $info = [
                'file' => $file,
                'fqcn' => $class['fqcn'],
                'name' => $class['name'],
                'namespace' => substr($class['fqcn'], 0, max(0, strlen($class['fqcn']) - strlen($class['name']) - 1)),
                'extends' => ltrim($class['extends'], '\\'),
                'traits' => array_map(fn (string $trait): string => ltrim($trait, '\\'), $class['traits']),
                'table' => null,
                'lists' => array_fill_keys(self::LISTS, null),
                'casts' => [],
                'relations' => [],
                'unreadable' => [],
            ];

            self::properties($tokens, $info);

            foreach ($class['methods'] as $method) {
                if ($method['static'] || $method['body'] === null) {
                    continue;
                }

                if (strtolower($method['name']) === 'casts') {
                    $entries = self::returnedArray($method['body'], $outline);

                    if ($entries === null) {
                        $info['unreadable'][] = 'casts(): no devuelve un array literal';
                    } else {
                        foreach ($entries as [$key, $value]) {
                            if ($key !== null && $value !== null) {
                                $info['casts'][$key] = $value;
                            }
                        }
                    }

                    continue;
                }

                $relation = self::relation($method['body'], $outline);

                if ($relation === null) {
                    continue;
                }

                if (! in_array($relation[0], self::RELATIONS, true)) {
                    $info['unreadable'][] = "{$method['name']}(): {$relation[0]}, que el laraimport no sabe declarar";

                    continue;
                }

                $info['relations'][] = ['name' => $method['name'], 'type' => $relation[0], 'related' => $relation[1], 'line' => $method['line']];
            }

            $models[] = $info;
        }

        return $models;
    }

    /**
     * Las relaciones que declaran los traits de un archivo, por nombre
     * completo del trait. Un modelo generado por LaraPack las tiene en
     * `Models/Traits/Relations/<Modelo>Relations.php`, no en su clase.
     *
     * @return array<string, list<Relation>>
     */
    public static function traitRelations(string $file): array
    {
        $outline = PhpOutline::ofFile($file);
        $found = [];

        foreach ($outline->classes() as $class) {
            if ($class['kind'] !== 'trait') {
                continue;
            }

            foreach ($class['methods'] as $method) {
                if ($method['static'] || $method['body'] === null) {
                    continue;
                }

                $relation = self::relation($method['body'], $outline);

                if ($relation !== null && in_array($relation[0], self::RELATIONS, true)) {
                    $found[$class['fqcn']][] = ['name' => $method['name'], 'type' => $relation[0], 'related' => $relation[1], 'line' => $method['line']];
                }
            }
        }

        return $found;
    }

    /**
     * Si la clase se ve como un modelo de Eloquent: extiende Model, Pivot,
     * Authenticatable o algo que se llama así. Un modelo que extiende otra
     * clase propia (`BaseModel`) cuenta si el nombre lo dice.
     *
     * @param  ModelInfo  $model
     */
    public static function looksLikeModel(array $model): bool
    {
        $parent = strtolower((string) $model['extends']);

        return str_ends_with($parent, 'model')
            || str_ends_with($parent, '\\pivot') || $parent === 'pivot'
            || str_ends_with($parent, '\\user') || str_ends_with($parent, 'authenticatable')
            || str_ends_with($parent, '\\morphpivot');
    }

    /**
     * @param  ModelInfo  $model
     */
    public static function isPivot(array $model): bool
    {
        return (bool) preg_match('/(^|\\\\)(Morph)?Pivot$/', (string) $model['extends']);
    }

    /**
     * @param  ModelInfo  $model
     */
    public static function isAuthenticatable(array $model): bool
    {
        return in_array($model['extends'], ['Illuminate\\Foundation\\Auth\\User', 'Illuminate\\Contracts\\Auth\\Authenticatable'], true)
            || str_ends_with((string) $model['extends'], '\\Authenticatable');
    }

    /**
     * @param  ModelInfo  $model
     */
    public static function softDeletes(array $model): bool
    {
        foreach ($model['traits'] as $trait) {
            if ($trait === 'Illuminate\\Database\\Eloquent\\SoftDeletes' || str_ends_with($trait, '\\SoftDeletes')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los tokens del archivo desde la clase, sin espacios ni comentarios.
     *
     * @return list<Token>
     */
    private static function tokens(string $code, int $fromLine): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");

                if (! in_array($token[0], self::IGNORED, true) && $token[2] >= $fromLine) {
                    $tokens[] = [$token[0], $token[1], $token[2]];
                }

                continue;
            }

            if ($line >= $fromLine) {
                $tokens[] = [$token, $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * `protected $table = 'x';`, `protected $fillable = [...]`, `protected
     * $casts = [...]`: las propiedades de la clase que se leen.
     *
     * @param  list<Token>  $tokens
     * @param  ModelInfo  $info
     */
    private static function properties(array $tokens, array &$info): void
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (PhpOutline::is($token, '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;

                continue;
            }

            if (PhpOutline::is($token, '}')) {
                $depth--;

                if ($depth <= 0) {
                    return;
                }

                continue;
            }

            // Sólo las propiedades de la clase, no variables de un método.
            if ($depth !== 1 || ! PhpOutline::is($token, T_VARIABLE) || ! PhpOutline::is($tokens[$i + 1] ?? null, '=')) {
                continue;
            }

            $name = substr($token[1], 1);
            $value = self::valueAt($tokens, $i + 2);

            if ($name === 'table') {
                $info['table'] = is_string($value) ? $value : null;

                if (! is_string($value)) {
                    $info['unreadable'][] = '$table: no es una cadena literal';
                }

                continue;
            }

            if (in_array($name, self::LISTS, true) || $name === 'casts') {
                $entries = PhpOutline::is($tokens[$i + 2] ?? null, '[') ? self::arrayAt($tokens, $i + 2, null) : null;

                if ($entries === null) {
                    $info['unreadable'][] = "\${$name}: no es un array literal";

                    continue;
                }

                if ($name === 'casts') {
                    foreach ($entries as [$key, $cast]) {
                        if ($key !== null && $cast !== null) {
                            $info['casts'][$key] = $cast;
                        }
                    }

                    continue;
                }

                $info['lists'][$name] = array_values(array_filter(array_map(fn (array $entry): ?string => $entry[0] === null ? $entry[1] : null, $entries), 'is_string'));
            }
        }
    }

    /**
     * Una cadena literal en esa posición, o null.
     *
     * @param  list<Token>  $tokens
     */
    private static function valueAt(array $tokens, int $i): ?string
    {
        $token = $tokens[$i] ?? null;

        if ($token === null || ! PhpOutline::is($token, T_CONSTANT_ENCAPSED_STRING) || ! PhpOutline::is($tokens[$i + 1] ?? null, ';', ',', ']', ')')) {
            return null;
        }

        return self::unquote($token[1]);
    }

    /**
     * Las entradas de un array literal que empieza en `$i` (`[`): cada una
     * como [clave, valor], con la clave null si no la tiene y el valor null si
     * no es una cadena ni `X::class`. null si el array no se puede leer.
     *
     * @param  list<Token>  $tokens
     * @return list<array{0: string|null, 1: string|null}>|null
     */
    private static function arrayAt(array $tokens, int $i, ?PhpOutline $outline): ?array
    {
        $depth = 0;
        $inner = [];
        $count = count($tokens);

        for ($j = $i; $j < $count; $j++) {
            $token = $tokens[$j];

            if (PhpOutline::is($token, '[', '(', '{')) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif (PhpOutline::is($token, ']', ')', '}')) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            $inner[] = $token;
        }

        if ($depth !== 0) {
            return null;
        }

        $entries = [];

        foreach (PhpOutline::splitTopLevel($inner) as $part) {
            $arrow = null;

            foreach ($part as $position => $token) {
                if (PhpOutline::is($token, T_DOUBLE_ARROW)) {
                    $arrow = $position;

                    break;
                }
            }

            $key = $arrow === null ? null : self::scalar(array_slice($part, 0, $arrow), $outline);
            $value = self::scalar($arrow === null ? $part : array_slice($part, $arrow + 1), $outline);

            if ($arrow !== null && $key === null) {
                return null;
            }

            $entries[] = [$key, $value];
        }

        return $entries;
    }

    /**
     * Una cadena literal o un `X::class` (resuelto con los `use` del archivo).
     *
     * @param  list<Token>  $tokens
     */
    private static function scalar(array $tokens, ?PhpOutline $outline): ?string
    {
        if (count($tokens) === 1 && PhpOutline::is($tokens[0], T_CONSTANT_ENCAPSED_STRING)) {
            return self::unquote((string) $tokens[0][1]);
        }

        if (count($tokens) === 3 && PhpOutline::is($tokens[1], T_DOUBLE_COLON) && strtolower((string) $tokens[2][1]) === 'class'
            && PhpOutline::is($tokens[0], T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED)) {
            return ltrim($outline !== null ? $outline->resolveName((string) $tokens[0][1]) : (string) $tokens[0][1], '\\');
        }

        return null;
    }

    /**
     * Lo que devuelve un `return [...]` que es la única sentencia del cuerpo.
     *
     * @param  list<Token>  $body
     * @return list<array{0: string|null, 1: string|null}>|null
     */
    private static function returnedArray(array $body, PhpOutline $outline): ?array
    {
        $statements = PhpOutline::splitTopLevel($body, ';');

        if (count($statements) !== 1 || ! PhpOutline::is($statements[0][0] ?? null, T_RETURN) || ! PhpOutline::is($statements[0][1] ?? null, '[')) {
            return null;
        }

        return self::arrayAt($statements[0], 1, $outline);
    }

    /**
     * `return $this->belongsTo(User::class, ...)...;`: el tipo y el modelo
     * relacionado (null en un `morphTo()`), o null si el cuerpo no es eso.
     *
     * @param  list<Token>  $body
     * @return array{0: string, 1: string|null}|null
     */
    private static function relation(array $body, PhpOutline $outline): ?array
    {
        $statements = PhpOutline::splitTopLevel($body, ';');

        if (count($statements) !== 1) {
            return null;
        }

        $s = $statements[0];

        if (! PhpOutline::is($s[0] ?? null, T_RETURN) || ! PhpOutline::is($s[1] ?? null, T_VARIABLE) || ($s[1][1] ?? '') !== '$this'
            || ! PhpOutline::is($s[2] ?? null, T_OBJECT_OPERATOR) || ! PhpOutline::is($s[3] ?? null, T_STRING) || ! PhpOutline::is($s[4] ?? null, '(')) {
            return null;
        }

        $type = (string) $s[3][1];

        if (! in_array($type, [...self::RELATIONS, ...self::OTHER_RELATIONS], true)) {
            return null;
        }

        // El primer argumento hasta su coma o su paréntesis.
        $argument = [];
        $depth = 0;

        foreach (array_slice($s, 5) as $token) {
            if (PhpOutline::is($token, '(', '[')) {
                $depth++;
            } elseif (PhpOutline::is($token, ')', ']')) {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($depth === 0 && PhpOutline::is($token, ',')) {
                break;
            }

            $argument[] = $token;
        }

        if ($argument === []) {
            return [$type, null];
        }

        $related = self::scalar($argument, $outline);

        // Un morphTo('nombre') lleva el nombre de la relación, no un modelo.
        if ($type === 'morphTo') {
            return [$type, null];
        }

        return $related === null ? null : [$type, $related];
    }

    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
    }
}
