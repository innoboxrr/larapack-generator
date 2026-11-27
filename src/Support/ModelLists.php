<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Las listas del modelo que salen del laraimport: `$fillable`, `$hidden`,
 * `$creatable`, `$updatable`, `$export_cols`, `$loadable_relations`,
 * `$loadable_counts` y `casts()`.
 *
 * Es la única fuente de lo que el modelo tiene que llevar en cada una: la usa
 * ModelTool al generar, ModelRegions al completar un modelo editado a mano y
 * ContractDrift al comprobar en `verify` que el modelo no se ha quedado atrás
 * del contrato. Si cada uno lo calculara por su cuenta, el día que cambie una
 * regla —un secreto que no se exporta, el cast de la contraseña— dirían cosas
 * distintas.
 *
 * Los nombres van tal cual, sin escapar, como los ha escrito siempre el
 * generador: el validador del laraimport sólo deja pasar identificadores.
 *
 * @phpstan-type Expected array{fillable: list<string>, hidden: list<string>, creatable: list<string>, updatable: list<string>, export_cols: list<string>, loadable_relations: list<string>, loadable_counts: list<string>, casts: array<string, string>}
 */
final class ModelLists
{
    /**
     * Las propiedades que son una lista de nombres, en el orden del modelo.
     */
    public const LISTS = ['fillable', 'hidden', 'creatable', 'updatable', 'export_cols', 'loadable_relations', 'loadable_counts'];

    /**
     * Lo que el modelo tiene que llevar, de un modelo ya normalizado por
     * ImportDocument. En `casts`, el nombre y el valor como código PHP.
     *
     * @param  array<string, mixed>  $model
     * @return Expected
     */
    public static function expected(array $model): array
    {
        /** @var list<array<string, mixed>> $props */
        $props = $model['props'] ?? [];
        $authenticatable = ! empty($model['authenticatable']);

        return [
            'fillable' => self::flagged($props, 'fillable'),
            'hidden' => self::hidden($props, $authenticatable),
            'creatable' => self::flagged($props, 'creatable'),
            'updatable' => self::flagged($props, 'updatable'),
            'export_cols' => self::flagged($props, 'exports_cols'),
            'loadable_relations' => array_values(array_map(
                fn (array $relation): string => (string) $relation['name'],
                (array) ($model['load_relations'] ?? [])
            )),
            'loadable_counts' => array_values(array_map('strval', (array) ($model['load_counts'] ?? []))),
            'casts' => self::casts($props, $authenticatable),
        ];
    }

    /**
     * Una lista de nombres como la escribe el generador: `'a', 'b'`.
     *
     * @param  list<string>  $names
     */
    public static function render(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => "'{$name}'", $names));
    }

    /**
     * Los casts como los escribe el generador: `'a' => 'json', 'b' => 'boolean'`.
     *
     * @param  array<string, string>  $casts
     */
    public static function renderCasts(array $casts): string
    {
        return implode(', ', array_map(
            fn (string $name, string $cast): string => "'{$name}' => {$cast}",
            array_keys($casts),
            $casts
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $props
     * @return list<string>
     */
    private static function flagged(array $props, string $field): array
    {
        $names = [];

        foreach ($props as $prop) {
            if (! empty($prop[$field])) {
                $names[] = (string) $prop['name'];
            }
        }

        return $names;
    }

    /**
     * Las columnas que nunca salen por la API: las secretas y, en un usuario
     * que inicia sesión, la contraseña y el token de "recordarme" aunque no se
     * declaren.
     *
     * @param  list<array<string, mixed>>  $props
     * @return list<string>
     */
    private static function hidden(array $props, bool $authenticatable): array
    {
        $hidden = array_map('strval', array_column(array_filter($props, fn (array $prop): bool => ! empty($prop['secret'])), 'name'));

        if ($authenticatable) {
            $hidden = [...$hidden, 'password', 'remember_token'];
        }

        return array_values(array_unique($hidden));
    }

    /**
     * Los casts declarados y, en un usuario que inicia sesión, los que Laravel
     * espera: la fecha de verificación y la contraseña cifrada al asignarla.
     *
     * @param  list<array<string, mixed>>  $props
     * @return array<string, string>
     */
    private static function casts(array $props, bool $authenticatable): array
    {
        $casts = [];

        foreach ($props as $prop) {
            if (isset($prop['cast'])) {
                $casts[(string) $prop['name']] = Literal::php((string) $prop['cast']);
            }
        }

        if ($authenticatable) {
            $names = array_column($props, 'name');

            foreach (['email_verified_at' => 'datetime', 'password' => 'hashed'] as $name => $cast) {
                if (in_array($name, $names, true) && ! isset($casts[$name])) {
                    $casts[$name] = "'{$cast}'";
                }
            }
        }

        return $casts;
    }
}
