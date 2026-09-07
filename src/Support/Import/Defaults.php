<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

/**
 * Aplica al documento los valores por defecto que declara el propio esquema.
 *
 * Se leen de ahí a propósito: si estuvieran duplicados en PHP acabarían
 * divergiendo del contrato, que es exactamente el problema que este paquete
 * intenta resolver. El esquema es la única fuente de verdad.
 *
 * Gracias a esto un laraimport puede declarar sólo lo que de verdad decide
 * —el nombre, las columnas y sus tipos— en lugar de repetir catorce claves
 * booleanas por propiedad.
 */
final class Defaults
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function apply(array $document): array
    {
        $schema = Schema::toArray();

        return self::fill($document, $schema, $schema);
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return mixed
     */
    private static function fill(mixed $value, array $schema, array $root): mixed
    {
        $schema = self::resolve($schema, $root);

        if (isset($schema['properties']) && is_array($value)) {
            foreach ($schema['properties'] as $key => $property) {
                $property = self::resolve($property, $root);

                if (! array_key_exists($key, $value)) {
                    if (array_key_exists('default', $property)) {
                        $value[$key] = $property['default'];
                    }

                    continue;
                }

                $value[$key] = self::fill($value[$key], $property, $root);
            }
        }

        if (isset($schema['items']) && is_array($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = self::fill($item, $schema['items'], $root);
            }
        }

        return $value;
    }

    /**
     * El esquema sólo usa referencias locales (#/$defs/…), así que basta con
     * recorrer el puntero dentro del propio documento.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    private static function resolve(array $schema, array $root): array
    {
        if (! isset($schema['$ref']) || ! str_starts_with($schema['$ref'], '#/')) {
            return $schema;
        }

        $target = $root;

        foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! isset($target[$segment])) {
                return $schema;
            }

            $target = $target[$segment];
        }

        // Lo declarado junto al $ref gana sobre lo referenciado.
        return array_merge($target, array_diff_key($schema, ['$ref' => null]));
    }
}
