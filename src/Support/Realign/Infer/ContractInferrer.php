<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Infer;

use Illuminate\Support\Str;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Innoboxrr\LarapackGenerator\Support\TablePrefix;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\ColumnDefinition;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\SchemaHistory;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\TableState;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\Value;

/**
 * Infiere un laraimport BORRADOR desde el código de un ámbito: el esquema
 * desde TODAS sus migraciones (SchemaHistory, el mismo lector con el que el
 * generador decide qué alterar) y la forma del modelo desde sus clases
 * (ModelSource). Las rutas escritas a mano, desde sus archivos (RouteSource).
 *
 * ────────────────────────────────────────────────────────────────────────
 * LO QUE PROMETE Y LO QUE NO
 *
 *  - Cada prop dice de dónde salió (la migración que la dejó así, la lista
 *    del modelo que la hizo asignable) y con qué confianza: `high` si se leyó
 *    sin dudas, `medium` si algo de ella no se pudo leer (un default que es
 *    una expresión, una migración que la toca dentro de un `if`) y `low` si
 *    hubo que suponer.
 *  - Lo que no se puede inferir no se inventa: lo de interfaz (`form`,
 *    `form_component`, `datatable`, las etiquetas de un `enum`), `display`,
 *    `routes`, las reglas de las requests. Se lista en `not_inferred`.
 *  - Lo que el laraimport no sabe expresar (un comentario de columna, un
 *    índice FULLTEXT, una llave hacia una columna que no es `id`) se lista en
 *    `lost`, con su migración: adoptar el borrador no lo borra de la base
 *    —las migraciones existentes nunca se reescriben—, pero el contrato no lo
 *    vigila.
 *  - Lo que es una decisión (dos modelos con el mismo nombre en namespaces
 *    distintos, un ciclo de llaves, una tabla sin modelo, un nombre de ruta
 *    que no se puede conservar) va a `decisions` con sus opciones. Nunca
 *    hace fallar la inferencia.
 * ────────────────────────────────────────────────────────────────────────
 *
 * @phpstan-import-type ModelInfo from ModelSource
 */
final class ContractInferrer
{
    /**
     * Las tablas que trae Laravel o sus paquetes de siempre: no son del
     * dominio, y un modelo que las use (User) se infiere igual.
     */
    public const FRAMEWORK_TABLES = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions',
        'password_reset_tokens', 'password_resets', 'personal_access_tokens', 'notifications',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring', 'pulse_values', 'pulse_entries', 'pulse_aggregates',
    ];

    /**
     * Los tipos de columna que el laraimport admite (columnType del esquema),
     * y cómo se acerca uno que no (perdiendo lo que se dice en `lost`).
     */
    private const APPROXIMATE = [
        'unsignedDecimal' => 'decimal',
        'unsignedDouble' => 'double',
        'unsignedFloat' => 'float',
        'unsignedMediumInteger' => 'unsignedInteger',
        'mediumIncrements' => 'increments',
        'smallIncrements' => 'increments',
        'tinyIncrements' => 'increments',
    ];

    /**
     * Modificadores de columna que el laraimport no expresa y se pierden.
     */
    private const LOST_MODIFIERS = ['comment', 'charset', 'collation', 'virtualAs', 'storedAs', 'generatedAs', 'autoIncrement', 'useCurrentOnUpdate', 'invisible', 'always', 'srid', 'from', 'startingValue'];

    private const TEMPLATE_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * @var list<string>
     */
    private array $columnTypes;

    /**
     * @var array<string, mixed>
     */
    private array $result = [];

    /**
     * @param  list<string>  $sharedMigrations  Los directorios de migraciones de
     *                                          los demás ámbitos que corren contra
     *                                          la misma base: un modelo de un
     *                                          paquete puede tener su tabla en
     *                                          las migraciones de la raíz.
     */
    public function __construct(private readonly string $directory, private readonly array $sharedMigrations = [])
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/schema/laraimport.schema.json'), true);
        $this->columnTypes = array_values((array) ($schema['$defs']['columnType']['enum'] ?? []));
    }

    /**
     * @param  list<string>|null  $only  Nombres de modelo (o rutas de archivo relativas) a inferir; null, todos.
     * @param  bool  $http  Inferir también controladores y rutas escritos a mano.
     * @return array<string, mixed>
     */
    public function infer(?array $only = null, bool $http = true): array
    {
        $this->result = [
            'contract' => ['models' => []],
            'provenance' => [],
            'not_inferred' => [],
            'lost' => [],
            'decisions' => [],
            'out_of_scope' => [],
            'stats' => ['models' => 0, 'props' => 0, 'relations' => 0, 'indexes' => 0, 'pivots' => 0, 'controllers' => 0, 'endpoints' => 0],
        ];

        $mine = self::migrationDirectories($this->directory);
        $own = $mine[0] ?? $this->directory.'/database/migrations';
        $others = array_values(array_filter([...array_slice($mine, 1), ...$this->sharedMigrations], fn (string $other): bool => rtrim($other, '/') !== $own && is_dir($other)));
        $history = new SchemaHistory($own, $others);
        $tables = $history->tables();
        // Las tablas que crea ESTE ámbito: sólo de ésas se dice que no tienen
        // modelo o que son un pivote; las de otro ámbito son asunto suyo.
        $ownTables = array_values(array_filter($tables, function (string $table) use ($history, $mine): bool {
            foreach ($mine as $directory) {
                if (str_starts_with((string) $history->table($table)->creator, $directory.'/')) {
                    return true;
                }
            }

            return false;
        }));
        $models = $this->models($only);
        $metas = $this->metaModels($models, $tables);
        $byTable = [];

        foreach ($models as $fqcn => $model) {
            if (isset($metas[$fqcn])) {
                continue;
            }

            $table = $model['table'] ?? self::laravelTable($model['name']);

            if (! in_array($table, $tables, true)) {
                $this->decision('model-without-table', $model['name'], "{$model['name']} usa la tabla {$table}, que no crea ninguna migración de este ámbito.", [
                    ['key' => 'outside', 'label' => 'La tabla es de otro paquete o de la aplicación: declararlo allí'],
                    ['key' => 'skip', 'label' => 'Dejar el modelo fuera del contrato'],
                ], $this->relative($model['file']));

                continue;
            }

            $byTable[$table] = $fqcn;
        }

        $prefix = $this->prefix($byTable, $models);

        if ($prefix !== '') {
            $this->result['contract'] = ['table_prefix' => $prefix] + $this->result['contract'];
        }

        // Lo que se usa para escribir el `constraint` de una llave: la tabla
        // de cada modelo tal como se escribe en el laraimport.
        $constraintOf = [];

        foreach ($byTable as $table => $fqcn) {
            $constraintOf[$table] = $table === $prefix.TablePrefix::tableOf($models[$fqcn]['name'])
                ? TablePrefix::tableOf($models[$fqcn]['name'])
                : $table;
        }

        $names = [];

        foreach ($byTable as $fqcn) {
            $names[$fqcn] = $models[$fqcn]['name'];
        }

        foreach ($byTable as $table => $fqcn) {
            $model = $models[$fqcn];
            $state = $history->table($table);
            $entry = $this->model($model, $state, $table, $prefix, $constraintOf, $names, in_array($fqcn, $metas, true));

            if ($entry !== null) {
                $this->result['contract']['models'][] = $entry;
            }
        }

        $this->pivots($history, $ownTables, $byTable, $models, $constraintOf);
        $this->cycles();

        foreach ($ownTables as $table) {
            if (in_array($table, self::FRAMEWORK_TABLES, true)) {
                $this->result['out_of_scope'][] = ['table' => $table, 'reason' => 'tabla de Laravel o de un paquete del framework'];
            }
        }

        if ($http) {
            (new RouteSource($this->directory))->inferInto($this->result, array_values($names));
        }

        $this->result['stats']['models'] = count($this->result['contract']['models']);
        $this->result['validation'] = $this->validate($this->result['contract']);

        return $this->result;
    }

    /**
     * Los directorios de migraciones de un ámbito: `database/migrations` y los
     * que sus proveedores cargan con `loadMigrationsFrom(__DIR__.'/…')`. El
     * primero existente es el «propio».
     *
     * @return list<string>
     */
    public static function migrationDirectories(string $directory): array
    {
        $directories = is_dir($directory.'/database/migrations') ? [$directory.'/database/migrations'] : [];
        $providers = [
            ...(glob($directory.'/app/Providers/*.php') ?: []),
            ...(glob($directory.'/src/Providers/*.php') ?: []),
            ...(glob($directory.'/src/*.php') ?: []),
        ];

        foreach ($providers as $provider) {
            preg_match_all("#loadMigrationsFrom\(\s*__DIR__\s*\.\s*'([^']+)'#", (string) file_get_contents($provider), $matches);

            foreach ($matches[1] as $relative) {
                $path = realpath(dirname($provider).$relative);

                if ($path !== false && is_dir($path)) {
                    $path = str_replace('\\', '/', $path);
                    $base = str_replace('\\', '/', (string) (realpath($directory) ?: $directory));

                    // Se escribe con la raíz tal como llegó, para que las rutas
                    // casen con las de SchemaHistory.
                    $directories[] = str_starts_with($path, $base.'/') ? $directory.substr($path, strlen($base)) : $path;
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * La tabla que Laravel deduce para un modelo sin `$table`.
     */
    public static function laravelTable(string $class): string
    {
        return Str::snake(Str::pluralStudly($class));
    }

    /**
     * Los modelos del ámbito, por nombre completo. Dos con el mismo nombre
     * corto en namespaces distintos son una decisión: el laraimport exige
     * nombres únicos. Se queda el del namespace más corto.
     *
     * @param  list<string>|null  $only
     * @return array<string, ModelInfo>
     */
    private function models(?array $only): array
    {
        $files = [];
        $traits = [];

        foreach ($this->sourceDirectories() as $source) {
            $directory = $this->directory.'/'.$source.'/Models';

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', (string) $file);

                if (! str_ends_with($path, '.php')) {
                    continue;
                }

                if (preg_match('#/Models/(Traits|Concerns)/#', $path)) {
                    $traits[] = $path;
                } elseif (! preg_match('#/Models/(Filters|Scopes|Casts|Observers|Enums)/#', $path)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        // Las relaciones que viven en un trait del modelo (las de LaraPack, en
        // Models/Traits/Relations): cuentan como del modelo que lo usa.
        $traitRelations = [];

        foreach ($traits as $trait) {
            $traitRelations += ModelSource::traitRelations($trait);
        }

        $found = [];

        foreach ($files as $file) {
            foreach (ModelSource::read($file) as $model) {
                if (! ModelSource::looksLikeModel($model)) {
                    continue;
                }

                if ($only !== null && ! in_array($model['name'], $only, true) && ! in_array($this->relative($file), $only, true)) {
                    continue;
                }

                if (ModelSource::isPivot($model)) {
                    $this->result['not_inferred'][] = ['model' => $model['name'], 'key' => 'pivot-model', 'reason' => 'extiende Pivot: su tabla se infiere como pivote, y el modelo de pivote no tiene sitio en el laraimport'];

                    continue;
                }

                foreach ($model['traits'] as $trait) {
                    foreach ($traitRelations[$trait] ?? [] as $relation) {
                        if (! in_array($relation['name'], array_column($model['relations'], 'name'), true)) {
                            $model['relations'][] = $relation;
                        }
                    }
                }

                $found[$model['fqcn']] = $model;
            }
        }

        $byName = [];

        foreach ($found as $fqcn => $model) {
            $byName[$model['name']][] = $fqcn;
        }

        foreach ($byName as $name => $classes) {
            if (count($classes) < 2) {
                continue;
            }

            usort($classes, fn (string $a, string $b): int => [substr_count($a, '\\'), $a] <=> [substr_count($b, '\\'), $b]);
            $kept = array_shift($classes);

            foreach ($classes as $other) {
                unset($found[$other]);
            }

            $this->decision('duplicate-model-name', $name, 'Hay '.(count($classes) + 1)." modelos {$name} en namespaces distintos ({$kept}, ".implode(', ', $classes).'): el laraimport exige nombres únicos. Se infirió sólo '.$kept.'.', [
                ['key' => 'rename', 'label' => 'Renombrar las clases (cambia código que las usa)'],
                ['key' => 'split', 'label' => 'Un laraimport por paquete o carpeta'],
                ['key' => 'keep-out', 'label' => 'Dejar fuera del contrato las demás'],
            ]);
        }

        return $found;
    }

    /**
     * Los modelos `<X>Meta` sobre la tabla `<x>_metas` de un modelo X que
     * también está: son las metas de LaraPack (X declara `metas: true`).
     *
     * @param  array<string, ModelInfo>  $models
     * @param  list<string>  $tables
     * @return array<string, string> el meta => el modelo que las tiene
     */
    private function metaModels(array $models, array $tables): array
    {
        $byName = [];

        foreach ($models as $fqcn => $model) {
            $byName[$model['name']] = $fqcn;
        }

        $metas = [];

        foreach ($models as $fqcn => $model) {
            if (! str_ends_with($model['name'], 'Meta')) {
                continue;
            }

            $owner = substr($model['name'], 0, -4);
            $table = $model['table'] ?? self::laravelTable($model['name']);

            if (isset($byName[$owner]) && str_ends_with($table, TablePrefix::singularOf($owner).'_metas') && in_array($table, $tables, true)) {
                $metas[$fqcn] = $byName[$owner];
            }
        }

        return $metas;
    }

    /**
     * El `table_prefix` que comparten las tablas: el más común entre los
     * modelos cuya tabla es `<prefijo>_<la deducida>`, si son la mitad o más.
     *
     * @param  array<string, string>  $byTable
     * @param  array<string, ModelInfo>  $models
     */
    private function prefix(array $byTable, array $models): string
    {
        $candidates = [];

        foreach ($byTable as $table => $fqcn) {
            $derived = TablePrefix::tableOf($models[$fqcn]['name']);

            if ($table !== $derived && str_ends_with($table, '_'.$derived)) {
                $prefix = substr($table, 0, -strlen($derived));
                $candidates[$prefix] = ($candidates[$prefix] ?? 0) + 1;
            }
        }

        if ($candidates === []) {
            return '';
        }

        arsort($candidates);
        $best = (string) array_key_first($candidates);

        return count($byTable) <= $candidates[$best] * 2 && preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)*_$/', $best) ? $best : '';
    }

    /**
     * @param  ModelInfo  $model
     * @param  array<string, string>  $constraintOf
     * @param  array<string, string>  $names  fqcn => nombre en el contrato
     * @return array<string, mixed>|null
     */
    private function model(array $model, TableState $state, string $table, string $prefix, array $constraintOf, array $names, bool $hasMetas): ?array
    {
        $name = $model['name'];
        $file = $this->relative($model['file']);
        $entry = ['name' => $name];

        if ($table !== $prefix.TablePrefix::tableOf($name)) {
            $entry['table'] = $table;
            $this->provenance($name, null, 'table', $model['table'] !== null ? "{$file}: \$table" : "deducida por Laravel de {$name}", 'high');
        }

        $casts = $model['casts'];
        $lists = $model['lists'];
        $props = [];
        $morphs = [];

        foreach ($state->columns as $column => $definition) {
            if (preg_match('/^(.+)_type$/', $column, $m) && isset($state->columns[$m[1].'_id'])
                && $definition->type() === 'string' && in_array($state->columns[$m[1].'_id']->type(), ['unsignedBigInteger'], true)
                && $state->columns[$m[1].'_id']->foreign === null) {
                $morphs[$m[1]] = $definition->nullable && $state->columns[$m[1].'_id']->nullable ? 'nullableMorphs' : 'morphs';
            }
        }

        foreach ($state->columns as $column => $definition) {
            if (in_array($column, self::TEMPLATE_COLUMNS, true)) {
                if ($column === 'id' && $definition->type() !== 'bigIncrements') {
                    $this->decision('primary-key', $name, "La clave primaria de {$table} es {$definition->method}('id'): la plantilla de LaraPack escribe \$table->id().", [
                        ['key' => 'keep', 'label' => 'Conservarla (la migración existente no se reescribe; un modelo nuevo saldría con id())'],
                        ['key' => 'keep-out', 'label' => 'Dejar el modelo fuera del contrato'],
                    ], $file);
                }

                continue;
            }

            $morph = preg_match('/^(.+)_(type|id)$/', $column, $m) && isset($morphs[$m[1]]) ? $m[1] : null;

            if ($morph !== null) {
                if ($m[2] === 'type') {
                    $props[] = ['name' => $morph, 'type' => $morphs[$morph]] + ($morphs[$morph] === 'nullableMorphs' ? ['nullable' => true] : []);
                    $this->provenance($name, $morph, 'type', basename((string) ($state->origin[$column] ?? '')).': '.$morph.'_type y '.$morph.'_id', 'medium');
                }

                continue;
            }

            $prop = $this->prop($name, $column, $definition, $state, $constraintOf, $casts, $lists, $model);

            if ($prop !== null) {
                $props[] = $prop;
            }
        }

        // Una columna que alguna migración añade de una forma que no se lee (en
        // un bucle con la tabla en una variable, dentro de un `if` que no es
        // una guarda) no se infiere: puede existir o no. Se dice cuál.
        foreach ($state->uncertain as $column => $files) {
            if (! isset($state->columns[$column]) && ! in_array($column, self::TEMPLATE_COLUMNS, true)) {
                $this->lost($name, $column, implode(', ', array_unique(array_map('basename', $files))), 'la columna la toca una migración de una forma que no se lee (un bucle, un if): no se sabe si existe, y no se infirió');
            }
        }

        if ($props === []) {
            // No se inventa una columna para que valide: el modelo queda fuera
            // del borrador y se dice.
            $this->decision('model-without-columns', $name, "{$table} no tiene columnas que el laraimport pueda declarar además de id y fechas, y el laraimport exige al menos una prop. No se escribió en el borrador.", [
                ['key' => 'add', 'label' => 'Declarar a mano la prop que falta'],
                ['key' => 'keep-out', 'label' => 'Dejar el modelo fuera del contrato'],
            ], $file);

            return null;
        }

        $entry['props'] = $props;
        $this->result['stats']['props'] += count($props);

        if ($hasMetas) {
            $entry['metas'] = true;
        }

        if (ModelSource::isAuthenticatable($model)) {
            $entry['authenticatable'] = true;
        }

        $indexes = $this->indexes($name, $state, $props);

        if ($indexes !== []) {
            $entry['indexes'] = $indexes;
        }

        $relations = $this->relations($model, $names);

        if ($relations !== []) {
            $entry['load_relations'] = $relations;
        }

        if (! isset($state->columns['deleted_at'])) {
            $this->result['not_inferred'][] = ['model' => $name, 'key' => 'routes', 'reason' => "{$table} no tiene deleted_at: si el modelo no borra en blando, declara routes.except [\"delete\", \"restore\", \"forceDelete\"]; un modelo nuevo saldría con softDeletes()"];
        }

        foreach (['display', 'form', 'form_component', 'datatable', 'enum (etiquetas)', 'requests.rules', 'routes'] as $key) {
            $this->result['not_inferred'][] = ['model' => $name, 'key' => $key, 'reason' => 'es una decisión de interfaz o de producto: no está en el código que se lee'];
        }

        foreach ($model['unreadable'] as $reason) {
            $this->result['not_inferred'][] = ['model' => $name, 'key' => 'model', 'reason' => "{$file}: {$reason}"];
        }

        foreach ($state->opaque as $reason) {
            $this->result['not_inferred'][] = ['model' => $name, 'key' => 'schema', 'reason' => $reason];
        }

        return $entry;
    }

    /**
     * Una columna como prop del laraimport, o null si no se puede expresar.
     *
     * @param  array<string, string>  $constraintOf
     * @param  array<string, string>  $casts
     * @param  array<string, list<string>|null>  $lists
     * @param  ModelInfo  $info
     * @return array<string, mixed>|null
     */
    private function prop(string $model, string $column, ColumnDefinition $definition, TableState $state, array $constraintOf, array $casts, array $lists, array $info): ?array
    {
        $origin = basename((string) ($state->origin[$column] ?? ''));
        $confidence = 'high';
        $doubt = $state->doubtAbout($column);

        if ($doubt !== null) {
            $confidence = 'medium';
        }

        $type = $definition->type();
        $foreign = $definition->foreign;
        $prop = ['name' => $column];

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
            $this->lost($model, $column, $origin, 'el nombre no es snake_case: el laraimport no lo admite');

            return null;
        }

        if ($foreign !== null && $type === 'unsignedBigInteger' && $foreign->column === 'id') {
            $type = 'foreignId';
            $prop['constraint'] = $constraintOf[$foreign->table] ?? $foreign->table;
        } elseif ($foreign !== null) {
            $this->lost($model, $column, basename((string) ($state->foreignOrigin[$column] ?? $origin)), "la llave hacia {$foreign->table}.{$foreign->column} ({$definition->method}): el laraimport sólo declara llaves foreignId hacia id");
            $confidence = 'medium';
        }

        if (! in_array($type, $this->columnTypes, true)) {
            if (isset(self::APPROXIMATE[$type])) {
                $this->lost($model, $column, $origin, "el tipo {$type}: se declara como ".self::APPROXIMATE[$type]);
                $type = self::APPROXIMATE[$type];
                $confidence = 'medium';
            } else {
                $this->lost($model, $column, $origin, "el tipo {$definition->method}, que el laraimport no admite: la columna queda fuera del contrato");

                return null;
            }
        }

        $prop['type'] = $type;
        // El orden de las claves, el del esquema: name, type, nullable, default…
        $prop = ['name' => $column, 'type' => $type] + $prop;

        if ($definition->nullable) {
            $prop['nullable'] = true;
        }

        if ($definition->default !== null) {
            [$readable, $default] = self::jsonDefault($definition->default, $type);

            if (! $readable) {
                $this->lost($model, $column, $origin, 'el default '.$definition->default->raw.', que es una expresión');
                $confidence = 'medium';
            } elseif ($default !== null) {
                $prop['default'] = $default;
            }
        }

        $this->measures($model, $column, $definition, $origin, $prop);

        if ($foreign !== null && $type === 'foreignId') {
            foreach (['onDelete' => 'on_delete', 'onUpdate' => 'on_update'] as $from => $to) {
                // Se escriben SIEMPRE, también la omisión de la base: un
                // laraimport sin ellas pide restrict, y una llave de 7.x en
                // cascada no se puede adoptar «por omisión» (9.3.1).
                $prop[$to] = $foreign->{$from} !== null ? str_replace('_', ' ', strtolower($foreign->{$from})) : 'restrict';

                if (! in_array($prop[$to], ['cascade', 'restrict', 'set null', 'no action'], true)) {
                    $this->lost($model, $column, $origin, "la acción {$foreign->{$from}} de la llave");
                    $prop[$to] = 'restrict';
                }
            }
        }

        if ($type === 'enum') {
            $values = $definition->args[0] ?? null;

            if ($values instanceof Value && $values->literal && is_array($values->value)) {
                $prop['enum'] = array_combine(array_map('strval', $values->value), array_map('strval', $values->value));
            }
        }

        if (isset($casts[$column])) {
            $prop['cast'] = $casts[$column];
        }

        $fillable = $lists['fillable'];
        $guarded = $lists['guarded'];

        if ($fillable !== null && ! in_array($column, $fillable, true)) {
            $prop['fillable'] = false;
        } elseif ($fillable === null && $guarded !== null && $guarded !== [] && $guarded !== ['*'] && in_array($column, $guarded, true)) {
            $prop['fillable'] = false;
        } elseif ($fillable === null && $guarded === ['*']) {
            $prop['fillable'] = false;
        }

        foreach (['creatable' => 'creatable', 'updatable' => 'updatable', 'export_cols' => 'exports_cols'] as $list => $key) {
            if ($lists[$list] !== null && ! in_array($column, $lists[$list], true)) {
                $prop[$key] = false;
            }
        }

        if ($lists['hidden'] !== null && in_array($column, $lists['hidden'], true)
            && ! (ModelSource::isAuthenticatable($info) && in_array($column, ['password', 'remember_token'], true))) {
            $prop['secret'] = true;
        }

        $source = $origin !== '' ? $origin : 'migraciones';
        $this->provenance($model, $column, 'schema', $source.($doubt !== null ? " (además la toca {$doubt})" : ''), $confidence);

        if (isset($prop['cast'])) {
            $this->provenance($model, $column, 'cast', $this->relative($info['file']).': casts', 'high');
        }

        foreach ($definition->extras as [$modifier]) {
            if (in_array($modifier, self::LOST_MODIFIERS, true)) {
                $this->lost($model, $column, $origin, "->{$modifier}()");
            }
        }

        return $prop;
    }

    /**
     * `length`, `precision`, `scale` y `use_current`, leídos de la migración.
     *
     * @param  array<string, mixed>  $prop
     */
    private function measures(string $model, string $column, ColumnDefinition $definition, string $origin, array &$prop): void
    {
        $numbers = array_map(fn (Value $value): mixed => $value->literal ? $value->value : null, $definition->args);

        if (in_array($prop['type'], SemanticValidator::LENGTH_TYPES, true) && isset($numbers[0])) {
            if (is_int($numbers[0]) && $numbers[0] > 0) {
                $prop['length'] = $numbers[0];
            } else {
                $this->lost($model, $column, $origin, 'una longitud que no es un entero literal');
            }
        } elseif (in_array($prop['type'], SemanticValidator::PRECISION_TYPES, true) && isset($numbers[0])) {
            if (is_int($numbers[0]) && (! isset($numbers[1]) || is_int($numbers[1]))) {
                $prop['precision'] = $numbers[0];
                $prop['scale'] = $numbers[1] ?? 2;
            } else {
                $this->lost($model, $column, $origin, 'una precisión que no es un entero literal');
            }
        } elseif ($definition->args !== [] && $prop['type'] !== 'enum') {
            $this->lost($model, $column, $origin, $definition->method.'() con argumentos ('.implode(', ', array_map(fn (Value $v): string => $v->raw, $definition->args)).') que el laraimport no expresa');
        }

        foreach ($definition->extras as [$modifier]) {
            if ($modifier === 'useCurrent') {
                if (in_array($prop['type'], SemanticValidator::USE_CURRENT_TYPES, true) && ! isset($prop['default'])) {
                    $prop['use_current'] = true;
                } else {
                    $this->lost($model, $column, $origin, '->useCurrent()');
                }
            }
        }
    }

    /**
     * El default como valor JSON: [si se puede escribir en el laraimport, el
     * valor]. Una expresión (`DB::raw(...)`, una constante) no se puede.
     *
     * @return array{0: bool, 1: string|int|float|bool|null}
     */
    private static function jsonDefault(Value $value, string $type): array
    {
        if (! $value->literal) {
            return [false, null];
        }

        // Un decimal con ceros (10.00) se guarda como cadena para no
        // perderlos al pasar por JSON: el generador escribe lo mismo.
        if (is_float($value->value) && $type === 'decimal') {
            return [true, $value->raw];
        }

        if (is_bool($value->value) || is_int($value->value) || is_float($value->value) || is_string($value->value) || $value->value === null) {
            return [true, $value->value];
        }

        return [false, null];
    }

    /**
     * Los índices de la tabla que el laraimport puede declarar.
     *
     * @param  list<array<string, mixed>>  $props
     * @return list<array<string, mixed>>
     */
    private function indexes(string $model, TableState $state, array $props): array
    {
        $columns = [...array_column($props, 'name'), ...self::TEMPLATE_COLUMNS];

        foreach ($props as $prop) {
            if (in_array($prop['type'], ['morphs', 'nullableMorphs'], true)) {
                $columns[] = $prop['name'].'_type';
                $columns[] = $prop['name'].'_id';
            }
        }

        $found = [];

        foreach ($state->indexOpaque as $reason) {
            $this->lost($model, null, null, 'índices que no se leen: '.$reason);
        }

        foreach ($state->indexes as $index) {
            if ($index['type'] === 'primary' && $index['columns'] === ['id']) {
                continue;
            }

            if (! in_array($index['type'], ['index', 'unique'], true) || array_diff($index['columns'], $columns) !== []) {
                $this->lost($model, null, basename($index['origin']), "el índice {$index['type']} {$index['name']} sobre ".implode(', ', $index['columns']));

                continue;
            }

            $entry = ['columns' => $index['columns']];

            if ($index['type'] === 'unique') {
                $entry['unique'] = true;
            }

            if ($index['name'] !== $state->defaultIndexName($index['type'], $index['columns'])) {
                if (strlen($index['name']) > 64 || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index['name'])) {
                    $this->lost($model, null, basename($index['origin']), "el nombre del índice {$index['name']}");
                } else {
                    $entry['name'] = $index['name'];
                }
            }

            $key = json_encode([$entry['columns'], $entry['unique'] ?? false]);

            if (isset($found[$key])) {
                continue;
            }

            $found[$key] = $entry;
            $this->result['stats']['indexes']++;
        }

        return array_values($found);
    }

    /**
     * @param  ModelInfo  $model
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function relations(array $model, array $names): array
    {
        $relations = [];
        $file = $this->relative($model['file']);

        foreach ($model['relations'] as $relation) {
            if (! preg_match('/^[a-z][A-Za-z0-9_]*$/', $relation['name'])) {
                $this->lost($model['name'], null, $file, "la relación {$relation['name']}(): su nombre no cabe en el esquema");

                continue;
            }

            $entry = ['type' => $relation['type'], 'name' => $relation['name']];

            if ($relation['related'] !== null) {
                $related = $relation['related'];

                if (isset($names[$related])) {
                    $entry['related'] = $names[$related];
                } else {
                    $short = str_contains($related, '\\') ? substr($related, (int) strrpos($related, '\\') + 1) : $related;
                    $namespace = str_contains($related, '\\') ? substr($related, 0, (int) strrpos($related, '\\')) : '';

                    if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $short)) {
                        $this->lost($model['name'], null, $file, "la relación {$relation['name']}() hacia {$related}");

                        continue;
                    }

                    $entry['related'] = $short;

                    if ($namespace !== '') {
                        $entry['namespace'] = $namespace;
                    }
                }
            } elseif ($relation['type'] !== 'morphTo') {
                continue;
            }

            $relations[] = $entry;
            $this->result['stats']['relations']++;
            $this->provenance($model['name'], null, 'relation:'.$relation['name'], "{$file}:{$relation['line']}", 'high');
        }

        return $relations;
    }

    /**
     * Las tablas sin modelo: un pivote (dos llaves o más y nada más que
     * llaves y datos del vínculo) se infiere en `pivots`; lo demás es una
     * decisión.
     *
     * @param  list<string>  $tables
     * @param  array<string, string>  $byTable
     * @param  array<string, ModelInfo>  $models
     * @param  array<string, string>  $constraintOf
     */
    private function pivots(SchemaHistory $history, array $tables, array $byTable, array $models, array $constraintOf): void
    {
        $metaTables = [];

        foreach ($models as $model) {
            $metaTables[] = $model['table'] ?? self::laravelTable($model['name']);
        }

        foreach ($tables as $table) {
            if (isset($byTable[$table]) || in_array($table, self::FRAMEWORK_TABLES, true) || in_array($table, $metaTables, true)) {
                continue;
            }

            $state = $history->table($table);
            $foreign = array_filter($state->columns, fn (ColumnDefinition $column): bool => $column->foreign !== null || (str_ends_with($column->name, '_id') && $column->type() === 'unsignedBigInteger'));

            if (count($foreign) < 2 || ! preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                $this->decision('table-without-model', $table, "La tabla {$table} no tiene modelo en este ámbito.", [
                    ['key' => 'model', 'label' => 'Escribir su modelo y volver a inferir'],
                    ['key' => 'leave', 'label' => 'Dejarla fuera del contrato (la sigue creando su migración)'],
                ]);

                continue;
            }

            $props = [];

            foreach ($state->columns as $column => $definition) {
                if (in_array($column, self::TEMPLATE_COLUMNS, true) || ! preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                    continue;
                }

                $type = $definition->type();
                $prop = ['name' => $column];

                if ($definition->foreign !== null && $type === 'unsignedBigInteger' && $definition->foreign->column === 'id') {
                    $prop['type'] = 'foreignId';
                    $prop['constraint'] = $constraintOf[$definition->foreign->table] ?? $definition->foreign->table;
                    $prop['on_delete'] = $definition->foreign->onDelete !== null ? strtolower($definition->foreign->onDelete) : 'restrict';
                    $prop['on_update'] = $definition->foreign->onUpdate !== null ? strtolower($definition->foreign->onUpdate) : 'restrict';
                } elseif (in_array($type, $this->columnTypes, true)) {
                    $prop['type'] = $type;
                } else {
                    $this->lost($table, $column, null, "el tipo {$definition->method} del pivote");

                    continue;
                }

                if ($definition->nullable) {
                    $prop['nullable'] = true;
                }

                $props[] = $prop;
            }

            $this->result['contract']['pivots'][] = ['name' => $table, 'props' => $props];
            $this->result['stats']['pivots']++;
            $this->provenance($table, null, 'pivot', 'tabla sin modelo con '.count($foreign).' llaves', 'medium');
        }
    }

    /**
     * Un ciclo de llaves entre modelos: el generador lo sabe ordenar si alguna
     * llave admite nulos (9.3.1), pero es una decisión del dominio.
     */
    private function cycles(): void
    {
        $tables = [];

        foreach ($this->result['contract']['models'] as $model) {
            $tables[SemanticValidator::tableFor($model)] = $model['name'];
        }

        $edges = [];

        foreach ($this->result['contract']['models'] as $model) {
            foreach ($model['props'] as $prop) {
                $target = $tables[$prop['constraint'] ?? ''] ?? null;

                if ($target !== null && $target !== $model['name']) {
                    $edges[$model['name']][$target] = true;
                }
            }
        }

        $reported = [];

        foreach (array_keys($edges) as $start) {
            $stack = [[$start, [$start]]];

            while ($stack !== []) {
                [$node, $path] = array_pop($stack);

                foreach (array_keys($edges[$node] ?? []) as $next) {
                    if ($next === $start) {
                        $cycle = $path;
                        sort($cycle);
                        $key = implode('>', $cycle);

                        if (! isset($reported[$key])) {
                            $reported[$key] = true;
                            $this->decision('foreign-key-cycle', implode(' → ', [...$path, $start]), 'Las llaves forman un ciclo: '.implode(' → ', [...$path, $start]).'. Si alguna admite nulos, el generador pone esa llave en una migración aparte; si ninguna, no se puede insertar la primera fila.', [
                                ['key' => 'nullable', 'label' => 'Hacer nullable una de las llaves del ciclo'],
                                ['key' => 'keep', 'label' => 'Conservarlo (las migraciones existentes no se reescriben)'],
                            ]);
                        }
                    } elseif (! in_array($next, $path, true) && count($path) < 8) {
                        $stack[] = [$next, [...$path, $next]];
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{ok: bool, errors: int, warnings: int, findings: list<array<string, mixed>>}
     */
    private function validate(array $contract): array
    {
        $findings = ImportDocument::fromArray($contract)['errors'];
        $errors = count(array_filter($findings, fn (array $f): bool => $f['level'] === SemanticValidator::ERROR));

        return ['ok' => $errors === 0, 'errors' => $errors, 'warnings' => count($findings) - $errors, 'findings' => array_values($findings)];
    }

    /**
     * Los directorios de código del ámbito, de su composer.json (`app`, `src`).
     *
     * @return list<string>
     */
    private function sourceDirectories(): array
    {
        $psr4 = ProjectSources::composer($this->directory)['autoload']['psr-4'] ?? [];
        $directories = [];

        foreach (is_array($psr4) ? $psr4 : [] as $paths) {
            foreach ((array) $paths as $path) {
                $path = trim(str_replace('\\', '/', (string) $path), '/');

                if ($path !== '' && is_dir($this->directory.'/'.$path) && ! $this->insideAnotherPackage($path)) {
                    $directories[] = $path;
                }
            }
        }

        if ($directories === []) {
            $directories = array_values(array_filter(['app', 'src'], fn (string $path): bool => is_dir($this->directory.'/'.$path)));
        }

        return array_values(array_unique($directories));
    }

    /**
     * Si un directorio de fuentes es de un paquete local con su propio
     * composer.json (la raíz de brandai autocarga `packages/x/src`): ése es
     * otro ámbito, y sus modelos no son de éste.
     */
    private function insideAnotherPackage(string $path): bool
    {
        $segments = explode('/', $path);

        for ($i = count($segments) - 1; $i >= 1; $i--) {
            if (is_file($this->directory.'/'.implode('/', array_slice($segments, 0, $i)).'/composer.json')) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->directory.'/') ? substr($file, strlen($this->directory) + 1) : $file;
    }

    private function provenance(string $model, ?string $prop, string $key, string $source, string $confidence): void
    {
        $this->result['provenance'][] = array_filter(['model' => $model, 'prop' => $prop, 'key' => $key, 'source' => $source, 'confidence' => $confidence], fn (mixed $value): bool => $value !== null);
    }

    private function lost(string $model, ?string $column, ?string $file, string $what): void
    {
        $this->result['lost'][] = array_filter(['model' => $model, 'column' => $column, 'file' => $file, 'what' => $what], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<array{key: string, label: string}>  $options
     */
    private function decision(string $type, string $subject, string $why, array $options, ?string $file = null): void
    {
        $this->result['decisions'][] = array_filter(['type' => $type, 'subject' => $subject, 'file' => $file, 'why' => $why, 'options' => $options], fn (mixed $value): bool => $value !== null);
    }
}
