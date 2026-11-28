<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;

/**
 * La huella de lo que el laraimport declara de cada modelo, de cada
 * `controllers[]` y de cada pivote.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ: `verify` comparaba el código con el manifiesto, y el manifiesto no
 * decía con qué contrato se generó. Con varias ramas a la vez eso deja pasar
 * justo lo que más duele: dos agentes generan cada uno con su laraimport, las
 * ramas se fusionan, el laraimport resultante no es el de ninguno de los dos
 * y nada lo nota. Tampoco se notaba lo más simple: editar el laraimport (un
 * `string` que pasa a `text`, un `nullable`) y no volver a importar dejaba
 * `validate` y `verify --strict` en verde.
 * ────────────────────────────────────────────────────────────────────────
 *
 * La importación anota la huella en el manifiesto (`Manifest::stampContracts`)
 * y `verify` la compara con la del laraimport que hay (`contract-outdated`).
 *
 * Se calcula sobre lo que está ESCRITO en el laraimport, no sobre el
 * documento resuelto: los valores por omisión son del generador, y una
 * versión nueva que añada uno no debe dejar en rojo a todos los proyectos.
 * Sólo cambia si alguien cambia la declaración. El orden de las claves de un
 * objeto no cuenta; el de una lista sí (el de `props` es el de las columnas).
 *
 * Es una huella por pieza y no una del documento entero a propósito: con una
 * sola, dos ramas que añaden modelos distintos chocarían siempre en esa línea
 * del manifiesto. Por pieza, el manifiesto de las dos se fusiona solo y cada
 * huella se sigue comparando con el laraimport fusionado. Para que sea exacta
 * lleva, además de la declaración del modelo:
 *
 *  - el `context`: todo lo del documento que no es `models`, `controllers` ni
 *    `pivots` (`table_prefix`, lo que venga después): cambia lo generado de
 *    todos;
 *  - las `references`: de cada tabla a la que apunta una clave ajena del
 *    modelo, qué modelo del laraimport la declara. Es lo único que una
 *    herramienta lee de OTRO modelo al generar éste (la migración, para saber
 *    si la tabla es del mismo laraimport; la factory, para llamar a la del
 *    modelo relacionado). `ContractFingerprintTest` falla si otra herramienta
 *    empieza a leer de otros modelos sin que esto lo recoja.
 */
final class ContractFingerprint
{
    /**
     * Las claves del documento que tienen huella propia; el resto es contexto.
     */
    private const PIECES = ['models', 'controllers', 'pivots'];

    /**
     * @param  array<string, mixed>  $raw  el laraimport tal como está escrito
     * @return array{models: array<string, string>, pivots: array<string, string>} nombre => huella; `models` lleva también los `controllers[]`, como el manifiesto
     */
    public static function of(array $raw): array
    {
        $context = array_diff_key($raw, array_flip(self::PIECES));
        $models = self::named($raw['models'] ?? null);
        $owners = [];

        foreach ($models as $name => $model) {
            $owners[SemanticValidator::tableFor($model)] = $name;
        }

        $fingerprints = ['models' => [], 'pivots' => []];

        foreach ($models as $name => $model) {
            $references = [];

            foreach (is_array($model['props'] ?? null) ? $model['props'] : [] as $prop) {
                $table = is_array($prop) ? ($prop['constraint'] ?? null) : null;

                if (is_string($table)) {
                    $references[$table] = $owners[$table] ?? null;
                }
            }

            $fingerprints['models'][$name] = self::hash(['model' => $model, 'context' => $context, 'references' => $references]);
        }

        foreach (self::named($raw['controllers'] ?? null) as $name => $controller) {
            $fingerprints['models'][$name] = self::hash(['controller' => $controller, 'context' => $context]);
        }

        foreach (self::named($raw['pivots'] ?? null) as $name => $pivot) {
            $fingerprints['pivots'][$name] = self::hash(['pivot' => $pivot, 'context' => $context]);
        }

        return $fingerprints;
    }

    /**
     * Las huellas del laraimport de un archivo, o null si no existe o no es
     * un objeto JSON (eso lo dice `larapack:validate`).
     *
     * @return array{models: array<string, string>, pivots: array<string, string>}|null
     */
    public static function ofFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = json_decode(Files::read($path), true);

        return is_array($raw) ? self::of($raw) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function named(mixed $entries): array
    {
        $named = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $named[$entry['name']] = $entry;
            }
        }

        return $named;
    }

    private static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
