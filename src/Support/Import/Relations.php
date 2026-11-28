<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

/**
 * Cómo se escribe una relación declarada en `load_relations`.
 *
 * Es el único sitio que lo decide, para que el trait de relaciones que genera
 * el importador y la relación que propone `larapack:fix missing-relation` no
 * puedan escribirla distinto.
 *
 * Un `morphTo` no apunta a un modelo: el modelo de cada fila lo dicen sus
 * columnas `<name>_type` y `<name>_id`. Por eso es la única relación que se
 * declara sin `related` (9.4.0); hasta entonces el esquema la exigía y un
 * `morphTo` real no se podía describir.
 */
final class Relations
{
    /**
     * Si la relación apunta a un modelo concreto: todas menos un `morphTo`
     * sin `related`.
     *
     * @param  array<string, mixed>  $relation
     */
    public static function hasRelated(array $relation): bool
    {
        return isset($relation['related']) && is_string($relation['related']) && $relation['related'] !== '';
    }

    /**
     * La expresión que devuelve el método: `$this->belongsTo(User::class)` o
     * `$this->morphTo()`.
     *
     * @param  array<string, mixed>  $relation
     */
    public static function call(array $relation): string
    {
        $type = (string) $relation['type'];

        return self::hasRelated($relation)
            ? "\$this->{$type}({$relation['related']}::class)"
            : "\$this->{$type}()";
    }
}
