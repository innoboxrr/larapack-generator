<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

/**
 * Las acciones que LaraPack sabe generar para un modelo, y cuáles quedan una
 * vez aplicados `routes` e `immutable`.
 *
 * Es el único sitio donde se decide. Las herramientas no leen `only` ni
 * `except`: preguntan si una acción está, y la respuesta sale de aquí.
 */
final class Actions
{
    /**
     * En el orden en que aparecen en las rutas y en el controlador, que es el
     * orden en que se devuelven siempre.
     */
    public const ALL = [
        'policies',
        'policy',
        'index',
        'show',
        'create',
        'update',
        'delete',
        'restore',
        'forceDelete',
        'export',
    ];

    /**
     * Lo que modifica o elimina una fila que ya existe.
     *
     * `create` no está a propósito. Una fila inmutable nace —un consentimiento,
     * un movimiento contable, un registro de envío— y lo que no hace es cambiar
     * después. Que además no se pueda crear por HTTP es una decisión distinta,
     * y se declara con `routes`.
     */
    public const MODIFY = [
        'update',
        'delete',
        'restore',
        'forceDelete',
    ];

    /**
     * Las que usan SoftDeletes. Sin ninguna de ellas, la columna deleted_at
     * sería una columna que nadie puede escribir.
     */
    public const SOFT_DELETES = [
        'delete',
        'restore',
        'forceDelete',
    ];

    /**
     * @param  array<string, mixed>  $model
     * @return array<int, string>
     */
    public static function resolve(array $model): array
    {
        $routes = is_array($model['routes'] ?? null) ? $model['routes'] : [];

        $actions = $routes['only'] ?? self::ALL;
        $actions = array_diff($actions, $routes['except'] ?? []);

        if (! empty($model['immutable'])) {
            $actions = array_diff($actions, self::MODIFY);
        }

        return array_values(array_intersect(self::ALL, $actions));
    }

    /**
     * @param  array<int, string>  $actions
     */
    public static function isAll(array $actions): bool
    {
        return array_values(array_intersect(self::ALL, $actions)) === self::ALL;
    }

    /**
     * El nombre con el que la acción se registra en el archivo de rutas.
     */
    public static function routeName(string $action): string
    {
        return $action === 'forceDelete' ? 'force.delete' : $action;
    }

    /**
     * La clase de request que atiende la acción.
     */
    public static function requestClass(string $action): string
    {
        return ucfirst($action) . 'Request';
    }
}
