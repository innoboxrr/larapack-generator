<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\Regeneration;
use Innoboxrr\LarapackGenerator\Support\Realign\ScopeClassifier;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;

/**
 * Lo que una operación que escribe necesita de un ámbito para no fiarse del
 * informe: lo regenerado AHORA (de la caché si nada cambió, la misma que el
 * diagnóstico) y la misma clasificación (ScopeClassifier). Cada operación
 * vuelve a clasificar cada archivo justo antes de tocarlo y sólo actúa si
 * sigue siendo lo que el paso dice: entre el diagnóstico y la operación
 * alguien pudo editarlo.
 *
 * @phpstan-import-type Scope from Scopes
 * @phpstan-import-type Entry from ScopeClassifier
 */
final class ScopeReference
{
    /**
     * Ruta del ámbito => la referencia, para no regenerar dos veces en la
     * misma corrida.
     *
     * @var array<string, self|null>
     */
    private static array $cache = [];

    private ScopeClassifier $classifier;

    private ?Manifest $regenerated = null;

    /**
     * @param  Scope  $scope
     */
    private function __construct(
        public readonly array $scope,
        public readonly ?string $directory,
        RealignState $state,
        private readonly string $projectRoot,
    ) {
        $this->classifier = new ScopeClassifier($scope, $projectRoot, $directory, new Exceptions($state));

        if ($directory !== null && is_file($directory.'/.larapack/manifest.json')) {
            $this->regenerated = (new Manifest($directory))->snapshot();
        }
    }

    /**
     * La referencia del ámbito al que pertenece una ruta (la ruta del ámbito:
     * `.` o `packages/x`), o null si el ámbito ya no existe. Sin contrato o
     * con un contrato que no regenera, la referencia existe sin directorio:
     * se puede clasificar lo registrado, no compararlo.
     */
    public static function for(string $root, string $path, RealignState $state): ?self
    {
        $key = $root.'|'.$path;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        foreach ((new Scopes($root))->all() as $scope) {
            if ($scope['path'] === $path) {
                $directory = null;

                if ($scope['contract']) {
                    $outcome = (new Regeneration($state))->run([$scope])[$path] ?? null;
                    $directory = $outcome !== null && $outcome['ok'] ? $outcome['directory'] : null;
                }

                return self::$cache[$key] = new self($scope, $directory, $state, $root);
            }
        }

        return self::$cache[$key] = null;
    }

    /**
     * El manifiesto del ámbito al que pertenece un archivo (el más largo que
     * lo contiene y tiene manifiesto), o null.
     */
    public static function ownerOf(string $root, string $file): ?Manifest
    {
        $best = null;

        foreach ((new Scopes($root))->all() as $scope) {
            if ($scope['manifest'] && ($scope['path'] === '.' || str_starts_with($file, $scope['path'].'/'))
                && ($best === null || strlen($scope['path']) > strlen($best['path']))) {
                $best = $scope;
            }
        }

        return $best === null ? null : new Manifest($best['root']);
    }

    /**
     * Olvida las referencias: después de escribir el manifiesto o el
     * contrato, la siguiente pregunta tiene que leerlos otra vez.
     */
    public static function forget(): void
    {
        self::$cache = [];
    }

    public function hasReference(): bool
    {
        return $this->directory !== null;
    }

    /**
     * La clasificación de un archivo (ruta relativa a la raíz del proyecto),
     * calculada ahora.
     *
     * @return Entry
     */
    public function classify(string $file): array
    {
        return $this->classifier->classify($file);
    }

    public function manifest(): Manifest
    {
        return new Manifest($this->scope['root']);
    }

    /**
     * La ruta absoluta de un archivo del proyecto.
     */
    public function absolute(string $file): string
    {
        return rtrim($this->projectRoot, '/').'/'.$file;
    }

    /**
     * La ruta relativa al ámbito de una ruta relativa al proyecto.
     */
    public function relative(string $file): string
    {
        return $this->scope['path'] === '.' || ! str_starts_with($file, $this->scope['path'].'/') ? $file : substr($file, strlen($this->scope['path']) + 1);
    }

    /**
     * El archivo regenerado que corresponde a una ruta relativa al ámbito
     * (la misma ruta), o null si el contrato no lo produce.
     */
    public function regeneratedFile(string $relative): ?string
    {
        return $this->directory !== null && is_file($this->directory.'/'.$relative) ? $this->directory.'/'.$relative : null;
    }

    /**
     * Quién registra un archivo en lo regenerado: el modelo (`''` el
     * paquete), su namespace y su stub. null si lo regenerado no lo conoce.
     *
     * @return array{model: string, namespace: string, stub: string}|null
     */
    public function producedBy(string $relative): ?array
    {
        if ($this->regenerated === null) {
            return null;
        }

        foreach (['', ...$this->regenerated->models()] as $model) {
            $files = $this->regenerated->filesFor((string) $model);

            if (isset($files[$relative])) {
                return ['model' => (string) $model, 'namespace' => (string) $this->regenerated->namespaceOf((string) $model), 'stub' => $files[$relative]['stub']];
            }
        }

        return null;
    }
}
