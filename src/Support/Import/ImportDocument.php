<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

use Illuminate\Support\Pluralizer;
use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

/**
 * Un laraimport ya validado y normalizado.
 *
 * Los generadores leen de aquí y no del JSON crudo: eso permite que el archivo
 * declare sólo lo que decide de verdad, que las referencias entre modelos
 * estén resueltas, y que ninguna herramienta tenga que defenderse con `??` de
 * una clave ausente.
 */
final class ImportDocument
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(public readonly array $data)
    {
    }

    /**
     * @return array{document: self|null, errors: array<int, array{level: string, path: string, message: string}>}
     */
    public static function fromFile(string $path): array
    {
        if (! is_file($path)) {
            return ['document' => null, 'errors' => [[
                'level' => SemanticValidator::ERROR,
                'path' => '/',
                'message' => "No se encontró el archivo {$path}.",
            ]]];
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (! is_array($decoded)) {
            return ['document' => null, 'errors' => [[
                'level' => SemanticValidator::ERROR,
                'path' => '/',
                'message' => 'El archivo no contiene un objeto JSON válido: ' . json_last_error_msg(),
            ]]];
        }

        return self::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{document: self|null, errors: array<int, array{level: string, path: string, message: string}>}
     */
    public static function fromArray(array $raw): array
    {
        $structural = Schema::validate($raw);

        if ($structural !== []) {
            return ['document' => null, 'errors' => array_map(
                fn (array $error): array => [
                    'level' => SemanticValidator::ERROR,
                    'path' => $error['path'],
                    'message' => $error['message'],
                ],
                $structural
            )];
        }

        $normalised = Defaults::apply($raw);

        $semantic = SemanticValidator::validate($normalised);

        $blocking = array_filter($semantic, fn (array $f): bool => $f['level'] === SemanticValidator::ERROR);

        return [
            'document' => $blocking === [] ? new self(self::resolve($normalised)) : null,
            'errors' => $semantic,
        ];
    }

    /**
     * Resuelve lo que hasta ahora quedaba implícito: el namespace de cada
     * relación y el orden en que deben correr las migraciones.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function resolve(array $document): array
    {
        $document['models'] = self::sortByDependencies($document['models']);

        $declared = array_column($document['models'], 'name');

        foreach ($document['models'] as $index => $model) {
            foreach ($model['load_relations'] as $position => $relation) {
                if (! empty($relation['namespace'])) {
                    continue;
                }

                // Un modelo declarado en este mismo archivo vive en el paquete;
                // antes se asumia siempre App\Models y el `use` generado
                // apuntaba a una clase inexistente.
                $document['models'][$index]['load_relations'][$position]['namespace'] =
                    in_array($relation['related'], $declared, true) ? null : 'App\\Models';
            }
        }

        return $document;
    }

    /**
     * Orden topológico por claves foráneas: una tabla se crea después de
     * aquellas a las que apunta.
     *
     * El orden del array decidía el de las migraciones, así que una foreignId
     * hacia un modelo declarado más abajo hacía fallar `php artisan migrate`.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private static function sortByDependencies(array $models): array
    {
        $byName = [];
        $byTable = [];

        foreach ($models as $model) {
            $byName[$model['name']] = $model;
            $byTable[Pluralizer::plural(self::snake($model['name']))] = $model['name'];
        }

        $sorted = [];
        $state = [];

        $visit = function (string $name) use (&$visit, &$sorted, &$state, $byName, $byTable): void {
            if (isset($state[$name])) {
                return;
            }

            $state[$name] = true;

            foreach ($byName[$name]['props'] as $prop) {
                if ($prop['type'] !== 'foreignId' || ! $prop['constraint']) {
                    continue;
                }

                $target = $byTable[$prop['constraint']] ?? null;

                if ($target !== null && $target !== $name) {
                    $visit($target);
                }
            }

            $sorted[] = $byName[$name];
        };

        foreach (array_keys($byName) as $name) {
            $visit($name);
        }

        return $sorted;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function models(): array
    {
        return $this->data['models'];
    }

    /**
     * @return array<string, mixed>
     */
    public function model(string $name): array
    {
        foreach ($this->models() as $model) {
            if ($model['name'] === $name) {
                return $model;
            }
        }

        throw new MakerException("El laraimport no declara el modelo '{$name}'.");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pivots(): array
    {
        return $this->data['pivots'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function snake(string $value): string
    {
        return mb_strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
