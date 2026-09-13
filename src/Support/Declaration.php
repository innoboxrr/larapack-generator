<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Import\Actions;

/**
 * Lo que cada modelo declara sobre su propia forma, mientras se genera.
 *
 * Las herramientas reciben sólo el nombre del modelo, así que necesitan un
 * sitio del que leer qué acciones tiene, si es inmutable y qué columnas no
 * salen nunca. Lo rellenan el importador, desde el laraimport, y
 * `larapack:full-model`, desde sus opciones.
 *
 * Un modelo del que no se ha declarado nada tiene todas las acciones, no es
 * inmutable y no tiene secretos: es exactamente lo que se generaba antes.
 */
final class Declaration
{
    /**
     * Las condiciones de los stubs que no son una acción.
     */
    public const FLAGS = ['immutable', 'secret', 'metas', 'authenticatable'];

    /**
     * @var array<string, array{actions: array<int, string>, immutable: bool, secret: array<int, string>, metas: bool, display: string, authenticatable: bool}>
     */
    private static array $models = [];

    /**
     * @param  array<int, string>  $actions
     * @param  array<int, string>  $secret
     * @param  string  $display  La columna que nombra a un registro en pantalla.
     * @param  bool  $authenticatable  El modelo es un usuario que inicia sesión.
     */
    public static function set(string $model, array $actions, bool $immutable = false, array $secret = [], bool $metas = false, string $display = 'name', bool $authenticatable = false): void
    {
        self::$models[$model] = [
            'actions' => array_values(array_intersect(Actions::ALL, $actions)),
            'immutable' => $immutable,
            'secret' => array_values($secret),
            'metas' => $metas,
            'display' => $display,
            'authenticatable' => $authenticatable,
        ];
    }

    /**
     * `larapack:full-model --metas` sin laraimport: el resto de la forma se
     * queda como estuviera declarada.
     */
    public static function enableMetas(string $model): void
    {
        $declaration = self::of($model);
        $declaration['metas'] = true;

        self::$models[$model] = $declaration;
    }

    /**
     * Desde un modelo del laraimport ya normalizado.
     *
     * @param  array<string, mixed>  $model
     */
    public static function fromModel(array $model): void
    {
        $secret = [];

        foreach ($model['props'] ?? [] as $prop) {
            if (! empty($prop['secret'])) {
                $secret[] = $prop['name'];
            }
        }

        self::set(
            $model['name'],
            $model['actions'] ?? Actions::resolve($model),
            ! empty($model['immutable']),
            $secret,
            ! empty($model['metas']),
            $model['display'] ?? 'name',
            ! empty($model['authenticatable'])
        );
    }

    /**
     * Sin laraimport no se conocen las columnas: se nombra por `name`, como
     * siempre.
     *
     * @return array{actions: array<int, string>, immutable: bool, secret: array<int, string>, metas: bool, display: string, authenticatable: bool}
     */
    public static function of(string $model): array
    {
        return self::$models[$model] ?? [
            'actions' => Actions::ALL,
            'immutable' => false,
            'secret' => [],
            'metas' => false,
            'display' => 'name',
            'authenticatable' => false,
        ];
    }

    public static function has(string $model, string $action): bool
    {
        return in_array($action, self::of($model)['actions'], true);
    }

    /**
     * Si una condición de stub se cumple para el modelo.
     *
     * Una condición desconocida es una errata en un stub, y se trata como
     * error y no como falso: un bloque que se cae en silencio es exactamente
     * el tipo de fallo que sólo aparece en producción.
     */
    public static function holds(string $model, string $condition): bool
    {
        $declaration = self::of($model);

        return match (true) {
            in_array($condition, Actions::ALL, true) => in_array($condition, $declaration['actions'], true),
            $condition === 'immutable' => $declaration['immutable'],
            $condition === 'secret' => $declaration['secret'] !== [],
            $condition === 'metas' => $declaration['metas'],
            $condition === 'authenticatable' => $declaration['authenticatable'],
            default => throw new MakerException("Condición de stub desconocida: '{$condition}'."),
        };
    }

    /**
     * Si el modelo tiene la forma de siempre. Es lo que decide si hace falta
     * anotar algo en el manifiesto.
     */
    public static function isDefault(string $model): bool
    {
        $declaration = self::of($model);

        return Actions::isAll($declaration['actions'])
            && ! $declaration['immutable']
            && $declaration['secret'] === []
            && ! $declaration['authenticatable'];
    }

    /**
     * Lo que hay que anotar en el manifiesto: sólo lo que se aparta de la forma
     * de siempre.
     *
     * Un modelo con todas las acciones no anota nada, así que el manifiesto de
     * un proyecto que no usa las claves nuevas sale idéntico al de antes, y
     * uno antiguo se sigue leyendo bien: la ausencia significa todas.
     *
     * @return array<string, mixed>
     */
    public static function toManifest(string $model): array
    {
        $declaration = self::of($model);
        $entry = [];

        if (! Actions::isAll($declaration['actions'])) {
            $entry['actions'] = $declaration['actions'];
        }

        if ($declaration['immutable']) {
            $entry['immutable'] = true;
        }

        if ($declaration['secret'] !== []) {
            $entry['secret'] = $declaration['secret'];
        }

        if ($declaration['authenticatable']) {
            $entry['authenticatable'] = true;
        }

        return $entry;
    }

    public static function reset(): void
    {
        self::$models = [];
    }
}
