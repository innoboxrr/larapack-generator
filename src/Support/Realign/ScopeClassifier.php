<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\ProjectSources;

/**
 * Clasifica los archivos de un ámbito comparándolos con lo regenerado
 * (Regeneration): cada archivo del manifiesto, cada archivo que el contrato
 * generaría y el manifiesto no conoce, y el código HTTP y de modelos que no
 * gestiona nadie.
 *
 * Cada entrada lleva su clase (FileClass), su categoría (Category) y el tipo
 * de paso que la resuelve (StepTypes). Es la misma función para el
 * diagnóstico entero y para comprobar un paso (`check`): un archivo no puede
 * clasificarse de dos formas según quién pregunte.
 *
 * @phpstan-import-type Scope from Scopes
 *
 * @phpstan-type Entry array{scope: string, package: string|null, file: string, path: string, model: string|null, stub: string|null, class: string, category: string|null, type: string|null, reason: string, tracked: bool, hash_current: bool|null, generator: string|null, to?: string, members?: list<string>, cause?: string, comments?: bool}
 * @phpstan-type Unmanaged array{scope: string, package: string|null, file: string, kind: string, category: string, type: string}
 */
final class ScopeClassifier
{
    private const MIGRATION = '#(^|/)database/migrations/\d{4}_\d{2}_\d{2}_\d{6}_(.+\.php)$#';

    private ?Manifest $manifest = null;

    private ?Manifest $regenerated = null;

    /**
     * Ruta relativa al ámbito => registro del manifiesto.
     *
     * @var array<string, array{model: string, stub: string, hash: string, generator: string|null, state: string}>
     */
    private array $tracked = [];

    /**
     * Ruta relativa => stub, de lo regenerado.
     *
     * @var array<string, string>
     */
    private array $produced = [];

    /**
     * `directorio|sufijo` de una migración regenerada => su ruta.
     *
     * @var array<string, string>
     */
    private array $producedMigrations = [];

    /**
     * Los modelos y controladores que declara el contrato.
     *
     * @var list<string>
     */
    private array $declared = [];

    private ?string $pintConfig;

    /**
     * @param  Scope  $scope
     * @param  string|null  $regeneratedDirectory  el directorio de lo regenerado, o null si no lo hay
     */
    public function __construct(
        private readonly array $scope,
        private readonly string $projectRoot,
        private readonly ?string $regeneratedDirectory,
        private readonly ?Exceptions $exceptions = null,
    ) {
        $root = $scope['root'];

        if (is_file($root.'/.larapack/manifest.json')) {
            $this->manifest = (new Manifest($root))->snapshot();

            foreach (['', ...$this->manifest->models()] as $model) {
                foreach (array_keys($this->manifest->filesFor($model)) as $relative) {
                    $entry = $this->manifest->entryOf((string) $relative);

                    if ($entry !== null) {
                        $this->tracked[(string) $relative] = $entry;
                    }
                }
            }
        }

        // Un contrato sin modelos (un paquete de integración) se regenera sin
        // escribir manifiesto: la referencia existe y está vacía.
        if ($regeneratedDirectory !== null) {
            $this->regenerated = (new Manifest($regeneratedDirectory))->snapshot();

            foreach (['', ...$this->regenerated->models()] as $model) {
                foreach ($this->regenerated->filesFor($model) as $relative => $entry) {
                    $relative = (string) $relative;
                    $this->produced[$relative] = $entry['stub'];

                    if (($key = self::migrationKey($relative)) !== null) {
                        $this->producedMigrations[$key] = $relative;
                    }
                }
            }
        }

        if (is_file($root.'/laraimport.json')) {
            $contract = json_decode(Files::read($root.'/laraimport.json'), true);

            foreach (['models', 'controllers'] as $section) {
                foreach (is_array($contract[$section] ?? null) ? $contract[$section] : [] as $item) {
                    if (is_array($item) && is_string($item['name'] ?? null)) {
                        $this->declared[] = $item['name'];
                    }
                }
            }
        }

        $this->pintConfig = Regeneration::pintConfigFor($root, $projectRoot);
    }

    public function hasReference(): bool
    {
        return $this->regenerated !== null;
    }

    /**
     * Todas las entradas del ámbito, también las que cumplen.
     *
     * @return list<Entry>
     */
    public function classifyAll(): array
    {
        $paths = array_keys($this->tracked);

        foreach (array_keys($this->produced) as $relative) {
            if (! isset($this->tracked[$relative]) && self::migrationKey($relative) === null) {
                $paths[] = $relative;
            }
        }

        return $this->classifyMany(array_values(array_unique($paths)));
    }

    /**
     * Una ruta relativa a la raíz del proyecto, como las da el informe.
     *
     * @return Entry
     */
    public function classify(string $file): array
    {
        return $this->classifyMany([$this->scopeRelative($file)])[0];
    }

    /**
     * @param  list<string>  $paths  relativas al ámbito
     * @return list<Entry>
     */
    private function classifyMany(array $paths): array
    {
        $entries = [];
        $pintPairs = [];

        foreach ($paths as $index => $relative) {
            [$entry, $pair] = $this->first($relative);
            $entries[$index] = $entry;

            if ($pair !== null) {
                $pintPairs['f'.$index] = $pair;
            }
        }

        // Lo que sigue distinto por tokens, por la misma Pint de una vez.
        $equal = PintPass::equivalent(
            array_map(
                fn (array $pair): array => [$pair[0], $pair[1]],
                array_filter($pintPairs, fn (array $pair): bool => FileComparison::kindOf($pair[0]) === 'php')
            ),
            $this->scope['root'],
            $this->pintConfig
        );

        foreach ($entries as $index => $entry) {
            if (! isset($pintPairs['f'.$index])) {
                continue;
            }

            [$current, $regenerated, $before] = $pintPairs['f'.$index];
            $after = $equal['f'.$index] ?? $before;

            $entries[$index] = match ($after) {
                FileComparison::IDENTICAL, FileComparison::FORMAT => $this->resolve($entry, FileClass::FORMAT_ONLY, 'igual tras pasarlo por la Pint del proyecto'),
                // Sólo comentarios en lo que nadie editó es la plantilla que
                // cambió sus comentarios (version_delta), y en un hueco
                // entero, el hueco. Lo demás es formato —como lo define el
                // realineamiento— y se marca, porque adoptarlo deja que
                // --force reemplace esos comentarios.
                FileComparison::COMMENTS => $entry['hash_current'] === true || ($entry['tracked'] && Holes::wholeFile((string) $entry['stub']))
                    ? $this->different($entry, $current, $regenerated)
                    : $this->resolve($entry + ['comments' => true], FileClass::FORMAT_ONLY, 'el mismo código; sólo cambian los comentarios'),
                default => $this->different($entry, $current, $regenerated),
            };
        }

        return array_values($entries);
    }

    /**
     * La primera pasada: todo lo que se decide sin Pint. Devuelve también
     * el par a comparar con Pint, si hace falta.
     *
     * @return array{0: Entry, 1: array{0: string, 1: string, 2: string}|null}
     */
    private function first(string $relative): array
    {
        $root = $this->scope['root'];
        $absolute = $root.'/'.$relative;
        $tracked = $this->tracked[$relative] ?? null;
        $counterpart = $this->counterpart($relative);
        $entry = [
            'scope' => $this->scope['path'],
            'package' => $this->scope['package'],
            'file' => $this->projectRelative($relative),
            'path' => $relative,
            'model' => $tracked['model'] ?? null,
            'stub' => $tracked['stub'] ?? ($counterpart === null ? null : ($this->produced[$counterpart] ?? null)),
            'class' => FileClass::ABSENT,
            'category' => null,
            'type' => null,
            'reason' => '',
            'tracked' => $tracked !== null,
            'hash_current' => null,
            'generator' => $tracked['generator'] ?? null,
        ];

        if (! is_file($absolute)) {
            return [$this->missing($entry, $relative, $tracked, $counterpart), null];
        }

        if ($tracked !== null && $this->manifest !== null) {
            $entry['hash_current'] = $this->manifest->hash($absolute) === $tracked['hash'];
        }

        if ($counterpart === null) {
            if ($tracked === null) {
                return [$entry, null];
            }

            $isMigration = self::migrationKey($relative) !== null;
            $retired = $tracked['model'] !== '' && ! in_array($tracked['model'], $this->declared, true);

            return [$this->resolve($entry, FileClass::NOT_REGENERATED, match (true) {
                ! $this->hasReference() => 'sin referencia: el contrato no se pudo regenerar',
                $retired => "el modelo {$tracked['model']} ya no está en el contrato",
                $isMigration => 'una migración que hoy no produciría el contrato (una alteración, o de una tabla que ya existía)',
                default => 'import no lo regenera (proveedores, configuración, andamiaje)',
            }), null];
        }

        $regenerated = $this->regeneratedDirectory.'/'.$counterpart;
        $comparison = FileComparison::compareFiles($absolute, $regenerated);

        if ($comparison === FileComparison::IDENTICAL) {
            return [$this->resolve($entry, FileClass::IDENTICAL, 'idéntico a lo regenerado'), null];
        }

        if ($comparison === FileComparison::FORMAT) {
            return [$this->resolve($entry, FileClass::FORMAT_ONLY, 'igual salvo espacios, comentarios u orden de imports'), null];
        }

        return [$entry, [$absolute, $regenerated, $comparison]];
    }

    /**
     * @param  Entry  $entry
     * @return Entry
     */
    private function different(array $entry, string $current, string $regenerated): array
    {
        if (self::migrationKey($entry['path']) !== null) {
            return $this->resolve($entry, FileClass::MIGRATION_HISTORY, 'una migración no se reescribe nunca: lo que difiere se resuelve con otra migración o con el contrato');
        }

        if (! $entry['tracked']) {
            return $this->resolve($entry, FileClass::UNTRACKED, 'en la ruta de un archivo generado, distinto de lo que generaría el contrato');
        }

        if ($entry['hash_current'] === true) {
            $older = GeneratorVersion::isOlder($entry['generator']);
            $entry['cause'] = $older ? 'generator' : 'contract';

            return $this->resolve($entry, FileClass::VERSION_DELTA, $older
                ? 'sin editar, escrito con una versión anterior del generador ('.($entry['generator'] ?? 'sin versión: manifiesto v1').')'
                : 'sin editar, pero el contrato cambió desde que se generó');
        }

        $members = FileComparison::kindOf($current) === 'php'
            ? FileComparison::members(Files::read($current), Files::read($regenerated))
            : ['outside'];
        $entry['members'] = $members;

        if (Holes::onlyHoles((string) $entry['stub'], $members)) {
            return $this->resolve($entry, FileClass::HOLE_EDITED, 'editado sólo en sus huecos');
        }

        // Escrito con una versión anterior: parte de lo que difiere fuera de
        // los huecos puede ser la plantilla nueva, no una edición. No se
        // puede separar sin las plantillas viejas, y se dice.
        if (GeneratorVersion::isOlder($entry['generator'])) {
            $entry['cause'] = 'edited_or_generator';
        }

        $outside = array_values(array_diff($members, array_map(fn (string $method): string => 'method:'.strtolower($method), Holes::methods((string) $entry['stub']))));

        return $this->resolve($entry, FileClass::EDITED_OUTSIDE, 'editado fuera de los huecos: '.implode(', ', $outside));
    }

    /**
     * @param  Entry  $entry
     * @param  array{model: string, stub: string, hash: string, generator: string|null, state: string}|null  $tracked
     * @return Entry
     */
    private function missing(array $entry, string $relative, ?array $tracked, ?string $counterpart): array
    {
        if ($tracked === null) {
            return $counterpart === null
                ? $entry
                : $this->resolve($entry, FileClass::MISSING, 'el contrato lo generaría y no está');
        }

        // Una migración registrada dos veces —generada otra vez con otra
        // fecha— cuya otra copia sigue ahí: la que falta sobra en el
        // manifiesto (school-xapi).
        $key = self::migrationKey($relative);

        if ($key !== null) {
            foreach (array_keys($this->tracked) as $other) {
                if ($other !== $relative && self::migrationKey($other) === $key && is_file($this->scope['root'].'/'.$other)) {
                    $entry['cause'] = 'superseded';
                    $entry['to'] = $this->projectRelative($other);

                    return $this->resolve($entry, FileClass::MISSING, "borrado; el manifiesto registra la misma migración en {$entry['to']}");
                }
            }
        }

        $renamed = $this->renamed($relative, $tracked['hash']);

        if ($renamed !== null) {
            $entry['to'] = $this->projectRelative($renamed);

            return $this->resolve($entry, FileClass::RENAMED, "está en {$entry['to']}");
        }

        if ($counterpart === null) {
            $retired = $tracked['model'] !== '' && ! in_array($tracked['model'], $this->declared, true);
            $entry['cause'] = $retired ? 'retired' : 'not_regenerated';

            return $this->resolve($entry, FileClass::MISSING, $retired
                ? "borrado, y el modelo {$tracked['model']} ya no está en el contrato"
                : 'borrado, y el contrato no lo volvería a generar');
        }

        $entry['cause'] = 'deleted';

        return $this->resolve($entry, FileClass::MISSING, 'borrado, y el contrato lo sigue generando');
    }

    /**
     * La categoría y el tipo de paso de cada clase, que dependen del
     * contexto (si está registrado, si el hash es el del manifiesto).
     *
     * @param  Entry  $entry
     * @return Entry
     */
    private function resolve(array $entry, string $class, string $reason): array
    {
        $entry['class'] = $class;
        $entry['reason'] = $reason;

        [$category, $type] = match ($class) {
            FileClass::IDENTICAL, FileClass::FORMAT_ONLY => $entry['tracked'] && $entry['hash_current'] === true
                ? [null, null]
                : [Category::DETERMINISTIC, 'adopt'],
            FileClass::HOLE_EDITED => [null, null],
            FileClass::VERSION_DELTA => [Category::DETERMINISTIC, 'regenerate'],
            FileClass::EDITED_OUTSIDE => [Category::GUIDED, 'edited-outside'],
            FileClass::RENAMED => [Category::DETERMINISTIC, 'relink'],
            FileClass::MISSING => match ($entry['cause'] ?? null) {
                'retired', 'not_regenerated', 'superseded' => [Category::DETERMINISTIC, 'prune'],
                'deleted' => [Category::DECISION, 'missing'],
                // Ni el manifiesto lo conoce: el contrato lo pide y nunca se generó.
                default => [Category::DETERMINISTIC, 'regenerate'],
            },
            FileClass::UNTRACKED => [Category::DECISION, 'untracked-edited'],
            default => [Category::OUT_OF_SCOPE, null],
        };

        if ($type !== null && $this->exceptions?->covers(['type' => $type, 'scope' => $entry['scope'], 'file' => $entry['file']]) === true) {
            [$category, $type] = [null, null];
            $entry['reason'] .= ' (excepción aprobada)';
        }

        $entry['category'] = $category;
        $entry['type'] = $type;

        return $entry;
    }

    /**
     * Lo regenerado que corresponde a un archivo: la misma ruta, o, en una
     * migración, la que tiene el mismo nombre sin la fecha (la fecha la pone
     * la hora a la que se generó).
     */
    private function counterpart(string $relative): ?string
    {
        $key = self::migrationKey($relative);

        if ($key !== null) {
            return $this->producedMigrations[$key] ?? null;
        }

        return isset($this->produced[$relative]) ? $relative : null;
    }

    /**
     * El archivo que ocupa el lugar de uno registrado que ya no está: una
     * migración con el mismo nombre y otra fecha, o un archivo del mismo
     * directorio con el mismo contenido. Sólo entre los que el manifiesto no
     * conoce.
     */
    private function renamed(string $relative, string $hash): ?string
    {
        $directory = dirname($relative);
        $absolute = $this->scope['root'].'/'.$directory;
        $key = self::migrationKey($relative);

        if (! is_dir($absolute)) {
            return null;
        }

        $candidates = [];

        foreach (scandir($absolute) ?: [] as $name) {
            $candidate = ($directory === '.' ? '' : $directory.'/').$name;

            if (! is_file($absolute.'/'.$name) || isset($this->tracked[$candidate])) {
                continue;
            }

            if ($key !== null ? self::migrationKey($candidate) === $key : $this->manifest?->hash($absolute.'/'.$name) === $hash) {
                $candidates[] = $candidate;
            }
        }

        // Dos candidatos es ambiguo: no se adivina.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * El código HTTP y de modelos que nadie gestiona: controladores, rutas
     * y modelos que ni el manifiesto ni el contrato conocen. Lo demás que no
     * es de LaraPack (servicios, soportes) no se lista: se cuenta en
     * otherCode().
     *
     * @return list<Unmanaged>
     */
    public function unmanaged(): array
    {
        $found = [];

        foreach ($this->sourceDirectories() as $source) {
            foreach ($this->phpUnder($source.'/Http/Controllers') as $relative) {
                if (basename($relative) !== 'Controller.php') {
                    $found[] = [$relative, 'controller', 'unmanaged-http'];
                }
            }

            foreach (glob($this->scope['root'].'/'.$source.'/Models/*.php') ?: [] as $file) {
                $found[] = [$source.'/Models/'.basename($file), 'model', 'unmanaged-models'];
            }
        }

        foreach ($this->phpUnder('routes') as $relative) {
            $found[] = [$relative, 'routes', 'unmanaged-http'];
        }

        $unmanaged = [];

        foreach ($found as [$relative, $kind, $type]) {
            if (isset($this->tracked[$relative]) || isset($this->produced[$relative])) {
                continue;
            }

            $file = $this->projectRelative($relative);

            if ($this->exceptions?->covers(['type' => $type, 'scope' => $this->scope['path'], 'file' => $file]) === true) {
                continue;
            }

            $unmanaged[] = ['scope' => $this->scope['path'], 'package' => $this->scope['package'], 'file' => $file, 'kind' => $kind,
                'category' => Category::DECISION, 'type' => $type];
        }

        return $unmanaged;
    }

    /**
     * Cuántos PHP de las fuentes no son de LaraPack, por el primer
     * directorio bajo la fuente (`Services`, `Support`…): lo que se declara
     * fuera de alcance sin listarlo.
     *
     * @return array<string, int>
     */
    public function otherCode(): array
    {
        $counts = [];

        foreach ($this->sourceDirectories() as $source) {
            foreach ($this->phpUnder($source) as $relative) {
                if (isset($this->tracked[$relative]) || isset($this->produced[$relative])
                    || preg_match('#^'.preg_quote($source, '#').'/(Http/Controllers/|Models/[^/]+$)#', $relative) === 1) {
                    continue;
                }

                $parts = explode('/', substr($relative, strlen($source) + 1));
                // Los traits del modelo los crea el generador una vez y no los
                // registra: son huecos. Se cuentan aparte para no mezclarlos
                // con el dominio.
                $top = match (true) {
                    count($parts) === 1 => '(raíz)',
                    $parts[0] === 'Models' && $parts[1] === 'Traits' => 'Models/Traits',
                    default => $parts[0],
                };
                $counts[$top] = ($counts[$top] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Los directorios PSR-4 del ámbito, relativos a él.
     *
     * @return list<string>
     */
    private function sourceDirectories(): array
    {
        $composer = ProjectSources::composer($this->scope['root']);
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        $directories = [];

        foreach (is_array($psr4) ? $psr4 : [] as $paths) {
            foreach ((array) $paths as $path) {
                $path = trim(str_replace('\\', '/', (string) $path), '/');

                if ($path !== '' && is_dir($this->scope['root'].'/'.$path)) {
                    $directories[] = $path;
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * @return list<string> relativas al ámbito
     */
    private function phpUnder(string $directory): array
    {
        $absolute = $this->scope['root'].'/'.$directory;

        if (! is_dir($absolute)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules'], true) && ! str_starts_with($file->getFilename(), '.')
        ));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $directory.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($absolute) + 1));
            }
        }

        sort($files);

        return $files;
    }

    private static function migrationKey(string $relative): ?string
    {
        return preg_match(self::MIGRATION, $relative, $match) === 1 ? dirname($relative).'|'.$match[2] : null;
    }

    private function projectRelative(string $relative): string
    {
        return $this->scope['path'] === '.' ? $relative : $this->scope['path'].'/'.$relative;
    }

    private function scopeRelative(string $file): string
    {
        return $this->scope['path'] === '.' || ! str_starts_with($file, $this->scope['path'].'/') ? $file : substr($file, strlen($this->scope['path']) + 1);
    }
}
