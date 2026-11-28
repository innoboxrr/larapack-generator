<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\ColumnDefinition;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\MigrationReader;

/**
 * Lo que rompe un `git merge` y ninguna de las dos ramas tenía por separado.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ: con diez o veinte consolas trabajando a la vez sobre el mismo
 * proyecto, cada rama pasa `verify --strict` y la suma no. La auditoría de
 * enjambre de 9.4.0 midió qué deja una fusión real:
 *
 *  - marcadores de conflicto que alguien comitea sin resolver (el manifiesto
 *    con ellos se leía como vacío y `verify` sólo avisaba);
 *  - dos migraciones, una por rama, que añaden la misma columna o crean la
 *    misma tabla: cada una corre sola, las dos juntas tumban `migrate`;
 *  - un archivo de rutas generado con la misma ruta dos veces, mezcla de las
 *    dos versiones: Laravel se queda con la última y calla.
 *
 * Que el laraimport fusionado ya no sea el de ninguna rama lo dice
 * `contract-outdated` (ContractDrift), con la huella del contrato.
 * ────────────────────────────────────────────────────────────────────────
 *
 * Se corre por ámbito —la raíz y cada paquete local con manifiesto—, y
 * también cuando el manifiesto no se puede leer: es justo entonces cuando
 * hay un merge a medias.
 *
 * @phpstan-import-type Finding from Verifier
 */
final class MergeDrift
{
    public const MANIFEST_UNREADABLE = 'manifest-unreadable';

    public const MERGE_CONFLICT = 'merge-conflict';

    public const MIGRATION_DUPLICATE = 'migration-duplicate';

    public const ROUTE_DUPLICATE = 'route-duplicate';

    public const CHECKS = [self::MANIFEST_UNREADABLE, self::MERGE_CONFLICT, self::MIGRATION_DUPLICATE, self::ROUTE_DUPLICATE];

    /**
     * Los archivos de estado de LaraPack, además del manifiesto: un conflicto
     * en ellos no rompe la aplicación, rompe lo que la vigila.
     */
    private const STATE_FILES = [
        'laraimport.json',
        'composer.json',
        Manifest::RELATIVE_PATH,
        HandRolledLock::PATH,
        '.larapack/realign/plan.json',
        '.larapack/realign/report.json',
        '.larapack/realign/exceptions.json',
        '.larapack/realign/config.json',
    ];

    /**
     * Donde vive lo generado que no es una clase: se recorre entero, porque
     * una migración o un archivo de rutas escritos a mano también se fusionan.
     */
    private const DIRECTORIES = ['routes', 'database/migrations', 'database/factories', 'tests', 'config', 'lang'];

    /**
     * Más que esto no es código ni estado: un volcado, un binario.
     */
    private const MAX_BYTES = 2_000_000;

    /**
     * @param  Manifest|null  $manifest  null si no se pudo leer
     */
    public function __construct(private readonly string $root, private readonly ?Manifest $manifest) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return [
            ...$this->conflicts(),
            ...$this->duplicateMigrations(),
            ...$this->duplicateRoutes(),
        ];
    }

    /**
     * Los archivos con un conflicto de git sin resolver.
     *
     * @return list<Finding>
     */
    public function conflicts(): array
    {
        $findings = [];

        foreach ($this->candidates() as $relative) {
            $absolute = $this->root.'/'.$relative;

            // El manifiesto ilegible ya tiene su hallazgo, que dice cómo salir.
            if ($relative === Manifest::RELATIVE_PATH && $this->manifest === null) {
                continue;
            }

            if (! is_file($absolute) || filesize($absolute) > self::MAX_BYTES) {
                continue;
            }

            $line = MergeMarkers::firstLine((string) @file_get_contents($absolute));

            if ($line !== null) {
                $findings[] = [
                    'level' => Verifier::ERROR,
                    'check' => self::MERGE_CONFLICT,
                    'model' => null,
                    'file' => $relative,
                    'message' => "Tiene un conflicto de git sin resolver (línea {$line}). "
                        .'Si es el laraimport o un archivo generado sin editar, `larapack:reconcile` lo resuelve; '
                        .'si es código escrito a mano, lo resuelve quien lo escribió.',
                ];
            }
        }

        return $findings;
    }

    /**
     * Rutas relativas a la raíz, sin repetir y ordenadas.
     *
     * @return list<string>
     */
    private function candidates(): array
    {
        $candidates = array_fill_keys(self::STATE_FILES, true);

        if ($this->manifest !== null) {
            foreach (['', ...$this->manifest->models()] as $model) {
                foreach (array_keys($this->manifest->filesFor($model)) as $relative) {
                    $candidates[$relative] = true;
                }
            }
        }

        $sources = new ProjectSources($this->root);
        $directories = array_map(fn (string $directory): string => $this->root.'/'.$directory, self::DIRECTORIES);

        foreach (ProjectSources::composer($this->root) as $section => $value) {
            if (! in_array($section, ['autoload', 'autoload-dev'], true) || ! is_array($value)) {
                continue;
            }

            foreach (is_array($value['psr-4'] ?? null) ? $value['psr-4'] : [] as $paths) {
                foreach ((array) $paths as $path) {
                    if (is_string($path)) {
                        $directories[] = $this->root.'/'.trim($path, '/');
                    }
                }
            }
        }

        foreach (array_unique($directories) as $directory) {
            foreach ($sources->filesWithExtensions($directory, ['php', 'json', 'js', 'ts', 'vue', 'jsx', 'tsx']) as $path) {
                $path = str_replace('\\', '/', $path);

                if (str_starts_with($path, $this->root.'/')) {
                    $candidates[substr($path, strlen($this->root) + 1)] = true;
                }
            }
        }

        $candidates = array_map('strval', array_keys($candidates));
        sort($candidates);

        return $candidates;
    }

    /**
     * Una tabla que se crea dos veces o una columna que se añade dos veces,
     * en dos migraciones distintas, sin que nada la quite entre medias.
     *
     * Sólo cuenta si al menos una de las dos la generó LaraPack: de dos
     * migraciones escritas a mano no se sabe lo bastante (una puede correr
     * sólo en un entorno), y un falso error aquí dejaría la CI en rojo. Lo
     * que no se lee con seguridad —dentro de un `if`, un bucle, SQL crudo,
     * otra conexión— no se cuenta.
     *
     * @return list<Finding>
     */
    public function duplicateMigrations(): array
    {
        return array_map(fn (array $collision): array => [
            'level' => Verifier::ERROR,
            'check' => self::MIGRATION_DUPLICATE,
            'model' => null,
            'file' => 'database/migrations/'.basename($collision['file']),
            'message' => ucfirst($collision['what']).', que ya '.$collision['verb'].' '.basename($collision['previous']).': las dos juntas tumban `php artisan migrate`. '
                .'Suele salir de fusionar dos ramas que declararon lo mismo. `larapack:reconcile`, corrido en la fusión, retira las que ninguna rama tenía antes '
                .'y deja que la importación las reescriba en una; si alguna ya corrió en una base compartida, decide una persona cuál se queda.',
        ], $this->migrationCollisions());
    }

    /**
     * Cada choque: la migración que repite y la que ya lo hacía.
     *
     * @return list<array{file: string, previous: string, what: string, verb: string}>
     */
    public function migrationCollisions(): array
    {
        $directory = $this->root.'/database/migrations';
        $files = glob($directory.'/*.php') ?: [];

        usort($files, fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        /** @var array<string, string> $creators tabla => migración que la creó */
        $creators = [];
        /** @var array<string, array<string, string>> $columns tabla => columna => migración */
        $columns = [];
        $findings = [];

        foreach ($files as $file) {
            // Una que pregunta antes (`Schema::hasColumn`, `hasTable`) no
            // repite: si ya está, no hace nada. El lector la da por segura a
            // propósito, así que se mira aquí.
            $guarded = preg_match('/\bhas(Column|Columns|Table)\s*\(/', (string) @file_get_contents($file)) === 1;

            foreach (MigrationReader::read($file)->operations as $operation) {
                $kind = $operation['kind'] ?? null;
                $table = $operation['table'] ?? null;

                if (! is_string($table) || ($operation['conditional'] ?? false) || ($operation['opaque'] ?? null) !== null) {
                    continue;
                }

                if ($kind === 'drop') {
                    unset($creators[$table], $columns[$table]);

                    continue;
                }

                if (! in_array($kind, ['create', 'table'], true)) {
                    continue;
                }

                if ($kind === 'create') {
                    if (! $guarded && isset($creators[$table]) && $creators[$table] !== $file && $this->generated($file, $creators[$table])) {
                        $findings[] = ['file' => $file, 'previous' => $creators[$table], 'what' => "crea la tabla `{$table}`", 'verb' => 'crea'];

                        continue;
                    }

                    $creators[$table] = $file;
                    $columns[$table] = [];
                }

                foreach (is_array($operation['statements'] ?? null) ? $operation['statements'] : [] as $statement) {
                    if (! is_array($statement)) {
                        continue;
                    }

                    if (($statement['kind'] ?? null) === 'drop') {
                        foreach (is_array($statement['names'] ?? null) ? $statement['names'] : [] as $name) {
                            if (is_string($name)) {
                                unset($columns[$table][$name]);
                            }
                        }
                    }

                    if (($statement['kind'] ?? null) === 'rename' && is_string($statement['from'] ?? null)) {
                        unset($columns[$table][$statement['from']]);
                    }

                    if (($statement['kind'] ?? null) !== 'add') {
                        continue;
                    }

                    foreach (is_array($statement['columns'] ?? null) ? $statement['columns'] : [] as $column) {
                        if (! $column instanceof ColumnDefinition || $column->change) {
                            continue;
                        }

                        $previous = $columns[$table][$column->name] ?? null;

                        if (! $guarded && $previous !== null && $previous !== $file && $this->generated($file, $previous)) {
                            $findings[] = ['file' => $file, 'previous' => $previous, 'what' => "añade la columna `{$column->name}` a `{$table}`", 'verb' => 'añade'];

                            continue;
                        }

                        $columns[$table][$column->name] = $file;
                    }
                }
            }
        }

        return $findings;
    }

    private function generated(string ...$files): bool
    {
        if ($this->manifest === null) {
            return true;
        }

        foreach ($files as $file) {
            if ($this->manifest->knows($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un archivo de rutas generado que registra dos veces el mismo verbo y la
     * misma URI: la segunda tapa a la primera sin avisar.
     *
     * @return list<Finding>
     */
    public function duplicateRoutes(): array
    {
        if ($this->manifest === null) {
            return [];
        }

        $findings = [];

        foreach ($this->manifest->models() as $model) {
            foreach ($this->manifest->filesFor($model) as $relative => $entry) {
                $absolute = $this->root.'/'.$relative;

                if (! str_starts_with($entry['stub'], 'Route/') || ! is_file($absolute)) {
                    continue;
                }

                foreach (self::repeatedRoutes(Files::read($absolute)) as $route) {
                    $findings[] = [
                        'level' => Verifier::ERROR,
                        'check' => self::ROUTE_DUPLICATE,
                        'model' => $model,
                        'file' => $relative,
                        'message' => "Registra dos veces {$route}: Laravel se queda con la última y la otra deja de responder. "
                            .'El archivo de rutas sale del laraimport: `larapack:reconcile` (o `larapack:import --force` si no está editado) lo rehace.',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string> `POST close`, una vez por ruta repetida
     */
    public static function repeatedRoutes(string $php): array
    {
        preg_match_all('/Route::(get|post|put|patch|delete|options|any)\(\s*([\'"])([^\'"]*)\2/', $php, $matches, PREG_SET_ORDER);

        $seen = [];
        $repeated = [];

        foreach ($matches as $match) {
            $route = strtoupper($match[1]).' '.$match[3];

            if (isset($seen[$route])) {
                $repeated[$route] = true;
            }

            $seen[$route] = true;
        }

        return array_map('strval', array_keys($repeated));
    }
}
