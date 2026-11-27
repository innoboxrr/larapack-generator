<?php

namespace Innoboxrr\LarapackGenerator\Tools;

use LogicException;

/**
 * Del nombre corto de una lista de herramientas —`Model`, `App`— a la
 * herramienta, con su contrato.
 *
 * larapack:full-model, larapack:remove-full-model y larapack:providers
 * recorren listas de nombres. Un nombre que no resuelve a una clase con su
 * interfaz es un error del generador, no del proyecto: saltárselo en silencio
 * dejaba sin generar (o sin borrar) esa pieza y el comando salía en 0.
 */
final class Artifacts
{
    /**
     * `Tools\<Nombre>\<Nombre>Tool`, que tiene que implementar ModelArtifact.
     */
    public static function model(string $name): ModelArtifact
    {
        $class = __NAMESPACE__.'\\'.$name.'\\'.$name.'Tool';

        if (! is_a($class, ModelArtifact::class, true)) {
            throw self::notAnArtifact($name, $class, ModelArtifact::class);
        }

        return new $class;
    }

    /**
     * `Tools\Providers\<Nombre>ServiceProviderTool`, que tiene que implementar
     * PackageArtifact.
     */
    public static function provider(string $name): PackageArtifact
    {
        $class = __NAMESPACE__.'\\Providers\\'.$name.'ServiceProviderTool';

        if (! is_a($class, PackageArtifact::class, true)) {
            throw self::notAnArtifact($name, $class, PackageArtifact::class);
        }

        return new $class;
    }

    private static function notAnArtifact(string $name, string $class, string $interface): LogicException
    {
        $problem = class_exists($class) ? "no implementa {$interface}" : 'no existe';

        return new LogicException("La herramienta «{$name}» ({$class}) {$problem}: no se generaría ni se quitaría nada de ella.");
    }
}
