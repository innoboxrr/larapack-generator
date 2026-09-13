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
     *
     * Las masivas van al final para que un paquete generado antes sólo gane
     * líneas al regenerarse, sin que se muevan las que ya tenía.
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
        'bulkUpdate',
        'bulkDelete',
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
        'bulkUpdate',
        'bulkDelete',
    ];

    /**
     * Una acción masiva es la individual aplicada a varios registros: pasa por
     * la misma política y, en la actualización, por las mismas reglas. Sin la
     * individual no tiene de dónde sacarlas.
     */
    public const REQUIRES = [
        'bulkUpdate' => 'update',
        'bulkDelete' => 'delete',
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

        // Quitar `delete` con except quita también el borrado masivo.
        foreach (self::REQUIRES as $bulk => $single) {
            if (! in_array($single, $actions, true)) {
                $actions = array_diff($actions, [$bulk]);
            }
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
        return match ($action) {
            'forceDelete' => 'force.delete',
            'bulkUpdate' => 'bulk.update',
            'bulkDelete' => 'bulk.delete',
            default => $action,
        };
    }

    /**
     * La clase de request que atiende la acción.
     */
    public static function requestClass(string $action): string
    {
        return ucfirst($action).'Request';
    }
}
