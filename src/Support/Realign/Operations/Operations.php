<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;

/**
 * El registro de las operaciones que escriben.
 *
 * Una acción se resuelve por convención: `adopt` busca
 * `Operations\AdoptOperation`. Así, enchufar una implementación es añadir
 * un archivo, sin tocar una lista compartida. Mientras no exista, la acción
 * es una PendingOperation, que lo dice. Las pruebas (o un proyecto que quiera
 * la suya) pueden registrar otra con register().
 */
final class Operations
{
    /**
     * Las acciones que escriben, y qué hace cada una (de RealignActions, que es
     * donde se escribe una vez para --help, help y la documentación).
     */
    public const DESCRIPTIONS = [
        'adopt' => RealignActions::ALL['adopt']['summary'],
        'apply' => RealignActions::ALL['apply']['summary'],
        'infer' => RealignActions::ALL['infer']['summary'],
        'except' => RealignActions::ALL['except']['summary'],
    ];

    public const ACTIONS = ['adopt', 'apply', 'infer', 'except'];

    /**
     * @var array<string, \Closure(): RealignOperation>
     */
    private static array $registered = [];

    public static function for(string $action): RealignOperation
    {
        if (isset(self::$registered[$action])) {
            return (self::$registered[$action])();
        }

        $class = __NAMESPACE__.'\\'.ucfirst($action).'Operation';

        if (in_array($action, self::ACTIONS, true) && class_exists($class) && is_subclass_of($class, RealignOperation::class)) {
            return new $class;
        }

        return new PendingOperation($action);
    }

    /**
     * Si la acción tiene una implementación de verdad.
     */
    public static function available(string $action): bool
    {
        return ! self::for($action) instanceof PendingOperation;
    }

    /**
     * Si la acción tiene una implementación registrada por encima de la
     * convención (las pruebas, o un proyecto que quiera la suya): sus
     * claves de `--option` son suyas y no las de RealignActions.
     */
    public static function overridden(string $action): bool
    {
        return isset(self::$registered[$action]);
    }

    /**
     * Registra una implementación para una acción, por encima de la
     * convención. `null` la quita.
     *
     * @param  (\Closure(): RealignOperation)|null  $factory
     */
    public static function register(string $action, ?\Closure $factory): void
    {
        if ($factory === null) {
            unset(self::$registered[$action]);

            return;
        }

        self::$registered[$action] = $factory;
    }

    public static function reset(): void
    {
        self::$registered = [];
    }
}
