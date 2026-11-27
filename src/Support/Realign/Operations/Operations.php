<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

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
     * Las acciones que escriben, y qué hace cada una.
     */
    public const DESCRIPTIONS = [
        'adopt' => 'Pone el manifiesto al día con lo que hay: adopta lo que ya es lo que generaría el contrato, vuelve a enlazar lo renombrado y olvida lo que ya no existe.',
        'apply' => 'Ejecuta un paso determinista que reescribe código: regenera lo que nadie editó y quedó atrás, o lo que falta.',
        'infer' => 'Infiere el contrato (laraimport.json) desde el código: modelos, columnas, endpoints y controladores escritos a mano.',
        'except' => 'Registra como excepción aprobada lo que se decide dejar como está, con su razón.',
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
