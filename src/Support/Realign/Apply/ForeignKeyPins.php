<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Apply;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\ForeignKey;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\SchemaHistory;

/**
 * Lo que de verdad borra o actualiza en cascada cada llave foránea, leído de
 * las migraciones que ya existen, para escribirlo en el contrato cuando el
 * contrato no lo dice.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ: hasta 7.x el generador escribía `onUpdate('cascade')` y
 * `onDelete('cascade')` en toda llave; desde 8.0 la acción por omisión es
 * `restrict`. Un laraimport de 7.x no dice nada (no podía), y la base de
 * producción borra en cascada. Mientras el contrato calle, lo que se borre al
 * borrar un padre depende de la versión del generador que escriba la
 * siguiente migración que toque esa llave. Fijarlo en el contrato hace que el
 * contrato diga lo que hace producción, y que ningún salto de versión lo
 * cambie sin que alguien lo decida (declarando otra cosa).
 * ────────────────────────────────────────────────────────────────────────
 *
 * Sólo se propone lo que se lee con certeza (SchemaHistory: todas las
 * migraciones, por tokens) y difiere de lo que generaría hoy el contrato
 * callado: `restrict`. Una llave que alguna migración toca de forma que no
 * se lee (SQL armado, una columna en un bucle) no se propone: lo dice
 * `larapack:import` en sus avisos. Los pivotes no: su acción por omisión
 * (`cascade`) no cambió.
 *
 * @phpstan-type Pin array{model: string, column: string, table: string, path: string, on_delete?: string, on_update?: string, migration: string|null}
 */
final class ForeignKeyPins
{
    /**
     * Las llaves que conviene fijar en el contrato de un ámbito.
     *
     * @return list<Pin>
     */
    public static function find(string $scopeRoot): array
    {
        $raw = self::raw($scopeRoot);
        $directory = $scopeRoot.'/database/migrations';

        if ($raw === null || ! is_dir($directory)) {
            return [];
        }

        $history = new SchemaHistory($directory);
        $prefix = self::prefix($raw);
        $pins = [];

        foreach (is_array($raw['models'] ?? null) ? $raw['models'] : [] as $index => $model) {
            if (! is_array($model) || ! is_string($model['name'] ?? null)) {
                continue;
            }

            // La tabla declarada va tal cual, sin prefijo; sólo la deducida
            // del nombre lo lleva. Con `table: legacy_people` se leía `people`.
            $table = is_string($model['table'] ?? null) && $model['table'] !== ''
                ? SemanticValidator::tableFor($model)
                : $prefix.SemanticValidator::tableFor($model);
            $state = $history->table($table);

            if (! $state->exists || $state->foreignOpaque !== []) {
                continue;
            }

            foreach (is_array($model['props'] ?? null) ? $model['props'] : [] as $position => $prop) {
                if (! is_array($prop) || ! is_string($prop['name'] ?? null) || ! is_string($prop['constraint'] ?? null)) {
                    continue;
                }

                $column = $state->columns[$prop['name']] ?? null;
                $foreign = $column?->foreign;

                if ($foreign === null || $state->doubtAbout($prop['name']) !== null) {
                    continue;
                }

                $pin = [];

                foreach (['on_delete' => $foreign->onDelete, 'on_update' => $foreign->onUpdate] as $key => $action) {
                    if (! array_key_exists($key, $prop) && ForeignKey::effective($action) !== 'restrict') {
                        $pin[$key] = ForeignKey::effective($action);
                    }
                }

                if ($pin !== []) {
                    $origin = $state->foreignOrigin[$prop['name']] ?? $state->origin[$prop['name']] ?? null;
                    $pins[] = ['model' => $model['name'], 'column' => $prop['name'], 'table' => $table, 'path' => "models.{$index}.props.{$position}"]
                        + $pin + ['migration' => $origin === null ? null : basename($origin)];
                }
            }
        }

        return $pins;
    }

    /**
     * Escribe las acciones en el laraimport del ámbito, en su sitio y sin
     * reformatear nada más.
     *
     * @param  list<array{model: string, column: string, on_delete?: string, on_update?: string}>  $pins
     * @return array{json: string, pinned: list<string>, already: list<string>}|string el laraimport nuevo, o por qué no
     */
    public static function apply(string $scopeRoot, array $pins): array|string
    {
        $path = $scopeRoot.'/laraimport.json';
        $raw = self::raw($scopeRoot);

        if ($raw === null) {
            return 'El laraimport.json no se puede leer.';
        }

        $edit = JsonEdit::of(Files::read($path));

        if ($edit === null) {
            return 'El laraimport.json no es un JSON que se pueda editar sin reescribirlo.';
        }

        $additions = [];
        $pinned = [];
        $already = [];

        foreach ($pins as $pin) {
            $located = self::locate($raw, $pin['model'], $pin['column']);

            if ($located === null) {
                return "El laraimport ya no declara {$pin['model']}.{$pin['column']}.";
            }

            [$at, $prop] = $located;
            $label = "{$pin['model']}.{$pin['column']}";

            foreach (['on_delete', 'on_update'] as $key) {
                if (! isset($pin[$key])) {
                    continue;
                }

                if (array_key_exists($key, $prop)) {
                    if (ForeignKey::effective(is_string($prop[$key]) ? $prop[$key] : null) !== ForeignKey::effective($pin[$key])) {
                        return "{$label} ya declara {$key}: {$prop[$key]}, y la migración hace {$pin[$key]}: es una decisión, no se pisa.";
                    }

                    $already[] = "{$label} {$key}";

                    continue;
                }

                $additions[$at][$key] = $pin[$key];
                $pinned[] = "{$label} {$key}: {$pin[$key]}";
            }
        }

        $json = $additions === [] ? Files::read($path) : $edit->add($additions);

        if ($json === null) {
            return 'No se localiza en el texto del laraimport el objeto de alguna columna.';
        }

        $decoded = json_decode($json, true);
        $errors = is_array($decoded) ? array_filter(
            ImportDocument::fromArray($decoded)['errors'],
            fn (array $error): bool => $error['level'] === SemanticValidator::ERROR
        ) : [['message' => 'no es JSON']];

        if ($errors !== []) {
            return 'Con las acciones fijadas el laraimport no validaría ('.array_values($errors)[0]['message'].'): no se escribe.';
        }

        return ['json' => $json, 'pinned' => $pinned, 'already' => $already];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: array<string, mixed>}|null el camino del objeto de la columna y su contenido
     */
    private static function locate(array $raw, string $model, string $column): ?array
    {
        foreach (is_array($raw['models'] ?? null) ? $raw['models'] : [] as $index => $entry) {
            if (! is_array($entry) || ($entry['name'] ?? null) !== $model) {
                continue;
            }

            foreach (is_array($entry['props'] ?? null) ? $entry['props'] : [] as $position => $prop) {
                if (is_array($prop) && ($prop['name'] ?? null) === $column) {
                    return ["models.{$index}.props.{$position}", $prop];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function raw(string $scopeRoot): ?array
    {
        $path = $scopeRoot.'/laraimport.json';
        $raw = is_file($path) ? json_decode(Files::read($path), true) : null;

        return is_array($raw) ? $raw : null;
    }

    /**
     * El prefijo de tablas del contrato, normalizado como TablePrefix::set.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function prefix(array $raw): string
    {
        $prefix = is_string($raw['table_prefix'] ?? null) ? trim($raw['table_prefix']) : '';

        return $prefix === '' ? '' : rtrim($prefix, '_').'_';
    }
}
