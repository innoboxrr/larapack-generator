<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

use Illuminate\Support\Pluralizer;

/**
 * Comprobaciones que el esquema no puede expresar porque miran a varias partes
 * del documento a la vez.
 *
 * Un JSON Schema valida la forma de cada pieza; esto valida que las piezas
 * encajen entre sí: que una clave foránea apunte a algo que exista, que una
 * relación pueda resolverse, que no haya nombres repetidos.
 */
final class SemanticValidator
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param  array<string, mixed>  $document  Ya normalizado con los defaults.
     * @param  array<string, mixed>  $raw  Tal como se escribió. Hace falta para
     *                                     distinguir un valor declarado de uno
     *                                     puesto por omisión.
     * @return array<int, array{level: string, path: string, message: string}>
     */
    public static function validate(array $document, array $raw = []): array
    {
        $models = $document['models'] ?? [];
        $pivots = $document['pivots'] ?? [];

        return [
            ...self::duplicateModels($models),
            ...self::duplicateProps($models),
            ...self::duplicatePivots($pivots),
            ...self::foreignKeys($models, $pivots),
            ...self::relations($models),
            ...self::ruleFields($models),
            ...self::enums($models),
            ...self::routes($models),
            ...self::secrets($models, $raw['models'] ?? []),
        ];
    }

    /**
     * Lo que sólo importa si además se genera el módulo de interfaz.
     *
     * Va aparte a propósito. Un paquete que sólo expone una API declara modelos
     * sin index constantemente, y un aviso que aparece siempre y no hay que
     * atender es como se deja de leer la lista entera.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, array{level: string, path: string, message: string}>
     */
    public static function interface(array $document): array
    {
        $findings = [];

        foreach ($document['models'] ?? [] as $index => $model) {
            $actions = $model['actions'] ?? Actions::resolve($model);
            $path = "/models/{$index}/routes";

            if (! in_array('index', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} no tiene index: las vistas cuelgan del índice, así que su módulo de interfaz sólo trae el contrato y el store.",
                ];

                continue;
            }

            if (! in_array('policies', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} tiene index pero no policies: la tabla consulta las políticas para decidir qué acciones ofrece, así que no se generan vistas.",
                ];

                continue;
            }

            if (in_array('update', $actions, true) && ! in_array('show', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} tiene update pero no show: la edición cuelga de la vista de detalle, así que la interfaz no trae formulario de edición.",
                ];
            }
        }

        return $findings;
    }

    /**
     * La coherencia entre las acciones que quedan.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function routes(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $actions = Actions::resolve($model);
            $path = "/models/{$index}/routes";

            // Declarar a la vez que la fila no cambia y que se puede cambiar.
            // Con `except` o sin `routes` no hay contradicción: immutable
            // simplemente las quita.
            if (! empty($model['immutable'])) {
                $contradicted = array_intersect(Actions::MODIFY, $model['routes']['only'] ?? []);

                if ($contradicted !== []) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "{$path}/only",
                        'message' => "{$model['name']} es immutable y declara " . implode(', ', $contradicted) . ': una fila inmutable no se modifica ni se borra.',
                    ];
                }
            }

            // No es error: una fila que borra un proceso en segundo plano y se
            // restaura desde la API es una forma legítima.
            if (! in_array('delete', $actions, true)) {
                $orphans = array_intersect(['restore', 'forceDelete'], $actions);

                if ($orphans !== []) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => $path,
                        'message' => "{$model['name']} declara " . implode(' y ', $orphans) . ' sin delete: nada de la API puede producir una fila borrada. Es correcto si la borra otro proceso.',
                    ];
                }
            }

            if (in_array('export', $actions, true) && ! in_array('index', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} declara export sin index: la exportación reutiliza los filtros del índice.",
                ];
            }

            $writable = array_intersect(['create', 'update'], $actions) !== [];

            foreach ($model['props'] as $position => $prop) {
                if (! empty($prop['form']) && ! $writable) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props/{$position}/form",
                        'message' => "'{$prop['name']}' va en el formulario, pero {$model['name']} no tiene create ni update: el campo no tiene dónde vivir.",
                    ];
                }
            }

            foreach ($model['requests'] as $position => $request) {
                $action = strtolower($request['name']);

                if (! in_array($action, $actions, true)) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/models/{$index}/requests/{$position}",
                        'message' => "Reglas para {$request['name']}, pero {$model['name']} no tiene {$action}: no se genera ese request y las reglas no se usan.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Un secreto que se declara también visible.
     *
     * Se mira el documento tal como se escribió: `exports_cols` vale true por
     * omisión, así que en el normalizado todos los secretos parecerían
     * contradecirse.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private static function secrets(array $models, array $raw): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                if (empty($prop['secret'])) {
                    continue;
                }

                $written = $raw[$index]['props'][$position] ?? [];

                foreach (['datatable' => 'la tabla', 'exports_cols' => 'la exportación'] as $key => $where) {
                    if (is_array($written) && ($written[$key] ?? null) === true) {
                        $findings[] = [
                            'level' => self::ERROR,
                            'path' => "/models/{$index}/props/{$position}/{$key}",
                            'message' => "'{$prop['name']}' es secret y a la vez pide salir en {$where}. Quita una de las dos.",
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function duplicateModels(array $models): array
    {
        $seen = [];
        $findings = [];

        foreach ($models as $index => $model) {
            $name = $model['name'];

            if (isset($seen[$name])) {
                $findings[] = [
                    'level' => self::ERROR,
                    'path' => "/models/{$index}/name",
                    'message' => "El modelo '{$name}' está declarado dos veces (también en /models/{$seen[$name]}).",
                ];
            }

            $seen[$name] = $index;
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function duplicateProps(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $seen = [];

            foreach ($model['props'] as $position => $prop) {
                if (in_array($prop['name'], $seen, true)) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props/{$position}/name",
                        'message' => "La columna '{$prop['name']}' está declarada dos veces en {$model['name']}.",
                    ];
                }

                $seen[] = $prop['name'];
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pivots
     * @return array<int, array<string, mixed>>
     */
    private static function duplicatePivots(array $pivots): array
    {
        $seen = [];
        $findings = [];

        foreach ($pivots as $index => $pivot) {
            if (in_array($pivot['name'], $seen, true)) {
                $findings[] = [
                    'level' => self::ERROR,
                    'path' => "/pivots/{$index}/name",
                    'message' => "La tabla pivote '{$pivot['name']}' está declarada dos veces.",
                ];
            }

            $seen[] = $pivot['name'];
        }

        return $findings;
    }

    /**
     * Una clave foránea que apunta a una tabla definida en este mismo archivo
     * obliga a un orden de migración. Si además ese orden es circular, no hay
     * ninguna secuencia válida y `php artisan migrate` fallará siempre.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, array<string, mixed>>  $pivots
     * @return array<int, array<string, mixed>>
     */
    private static function foreignKeys(array $models, array $pivots): array
    {
        $tables = [];

        foreach ($models as $model) {
            $tables[self::tableOf($model['name'])] = $model['name'];
        }

        $findings = [];
        $edges = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                if ($prop['type'] !== 'foreignId' || ! $prop['constraint']) {
                    continue;
                }

                if (! isset($tables[$prop['constraint']])) {
                    // Apuntar fuera del archivo es normal: users, por ejemplo.
                    continue;
                }

                $edges[$model['name']][] = $tables[$prop['constraint']];

                if ($tables[$prop['constraint']] === $model['name'] && ! $prop['nullable']) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props/{$position}",
                        'message' => "'{$prop['name']}' apunta a la propia tabla de {$model['name']} y no es nullable: no se podrá insertar la primera fila.",
                    ];
                }
            }
        }

        foreach ($pivots as $index => $pivot) {
            foreach ($pivot['props'] as $position => $prop) {
                if ($prop['type'] !== 'foreignId' || ! $prop['constraint']) {
                    continue;
                }

                if (! isset($tables[$prop['constraint']]) && ! self::isKnownExternalTable($prop['constraint'])) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/pivots/{$index}/props/{$position}/constraint",
                        'message' => "'{$prop['constraint']}' no es tabla de ningún modelo de este archivo; asegúrate de que exista en la aplicación.",
                    ];
                }
            }
        }

        return [...$findings, ...self::cycles($edges)];
    }

    /**
     * @param  array<string, array<int, string>>  $edges
     * @return array<int, array<string, mixed>>
     */
    private static function cycles(array $edges): array
    {
        $findings = [];
        $state = [];

        $walk = function (string $node, array $path) use (&$walk, &$state, $edges, &$findings): void {
            if (($state[$node] ?? null) === 'done') {
                return;
            }

            if (($state[$node] ?? null) === 'visiting') {
                $cycle = array_slice($path, array_search($node, $path, true));

                $findings[] = [
                    'level' => self::ERROR,
                    'path' => '/models',
                    'message' => 'Ciclo de claves foráneas: ' . implode(' -> ', [...$cycle, $node]) . '. No hay orden de migración posible.',
                ];

                return;
            }

            $state[$node] = 'visiting';

            foreach ($edges[$node] ?? [] as $next) {
                if ($next !== $node) {
                    $walk($next, [...$path, $node]);
                }
            }

            $state[$node] = 'done';
        };

        foreach (array_keys($edges) as $node) {
            $walk($node, []);
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function relations(array $models): array
    {
        $declared = array_column($models, 'name');
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['load_relations'] as $position => $relation) {
                if (in_array($relation['related'], $declared, true)) {
                    continue;
                }

                if (! empty($relation['namespace'])) {
                    continue;
                }

                $findings[] = [
                    'level' => self::WARNING,
                    'path' => "/models/{$index}/load_relations/{$position}/related",
                    'message' => "'{$relation['related']}' no está en este archivo y no declara namespace: se resolverá contra App\\Models.",
                ];
            }
        }

        return $findings;
    }

    /**
     * Una regla sobre un campo que no existe no valida nada, y suele ser un
     * nombre mal escrito.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function ruleFields(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $columns = array_column($model['props'], 'name');
            $identifier = self::snake($model['name']) . '_id';

            foreach ($model['requests'] as $position => $request) {
                foreach (array_keys($request['rules'] ?? []) as $field) {
                    if (in_array($field, $columns, true) || $field === $identifier) {
                        continue;
                    }

                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/models/{$index}/requests/{$position}/rules/{$field}",
                        'message' => "'{$field}' no es columna de {$model['name']}; la regla no valida nada.",
                    ];
                }
            }

            foreach ($model['requests'] as $position => $request) {
                // Sin la acción no se genera el request, así que no hay handle()
                // que necesite el identificador.
                if ($request['name'] !== 'Update' || ! in_array('update', Actions::resolve($model), true)) {
                    continue;
                }

                if (! isset($request['rules'][$identifier])) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/requests/{$position}/rules",
                        'message' => "Update debe validar '{$identifier}': su authorize() y su handle() hacen findOrFail con ese valor.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function enums(array $models): array
    {
        $selects = ['SelectInputComponent', 'SelectSearchInputComponent', 'RadioInputComponent', 'MultiCheckboxInputComponent'];
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                if (! isset($prop['enum']) || ! $prop['form']) {
                    continue;
                }

                if (in_array($prop['form_component'] ?? null, $selects, true)) {
                    continue;
                }

                $findings[] = [
                    'level' => self::WARNING,
                    'path' => "/models/{$index}/props/{$position}/enum",
                    'message' => "'{$prop['name']}' declara enum pero su componente no lo pinta; las opciones se ignorarán.",
                ];
            }
        }

        return $findings;
    }

    private static function tableOf(string $model): string
    {
        return Pluralizer::plural(self::snake($model));
    }

    private static function snake(string $value): string
    {
        return mb_strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private static function isKnownExternalTable(string $table): bool
    {
        return in_array($table, ['users', 'roles', 'permissions', 'teams'], true);
    }
}
