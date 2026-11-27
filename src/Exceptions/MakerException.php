<?php

namespace Innoboxrr\LarapackGenerator\Exceptions;

use Exception;

class MakerException extends Exception
{
    public static function stubNotFound(string $stub): self
    {
        return new self("No se encontró la plantilla {$stub}.");
    }

    public static function directoryNotCreated(string $directory): self
    {
        return new self("No se pudo crear el directorio {$directory}.");
    }

    public static function invalidModelName(string $name): self
    {
        return new self("«{$name}» no es un nombre de modelo válido: usa un identificador en PascalCase, como Post o BlogPost.");
    }

    public static function copyFailed(string $stub, string $destination): self
    {
        return new self("No se pudo copiar la plantilla {$stub} a {$destination}.");
    }

    public static function notDeclared(string $what, string $name): self
    {
        return new self("El laraimport no declara {$what} «{$name}».");
    }

    /**
     * Una expresión regular que falla devuelve null; escrito tal cual, ese null
     * dejaba el archivo vacío.
     */
    public static function rewriteFailed(string $file): self
    {
        return new self("No se pudo reescribir {$file}: ".preg_last_error_msg().'.');
    }

    public static function readFailed(string $file): self
    {
        return new self("No se pudo leer {$file}.");
    }

    /**
     * El manifiesto no se pudo escribir ni reintentándolo. Si hay temporal, es
     * lo registrado en la corrida: moverlo encima del manifiesto lo recupera.
     */
    public static function manifestNotWritten(string $manifest, string $reason, ?string $pending): self
    {
        return new self($pending === null
            ? "No se pudo escribir {$manifest} ({$reason})."
            : "No se pudo escribir {$manifest} ({$reason}), ni reintentándolo: otro proceso lo tiene bloqueado. Lo registrado en esta corrida está en {$pending}; cuando se libere, muévelo encima de {$manifest}. Sin eso, lo que se generó queda sin registrar y --force lo tomará por editado a mano.");
    }

    public static function invalidComposerJson(string $file, string $reason): self
    {
        return new self("{$file} no es un objeto JSON válido ({$reason}): no se modifica un composer.json que no se entiende.");
    }

    /**
     * Sin el namespace no hay nada correcto que generar: el modelo saldría en
     * el namespace global y el PSR-4 de las factorías, sin el del paquete.
     *
     * Con `$onlyGlobal`, la única entrada que apunta al directorio es la del
     * prefijo vacío (""): Composer la acepta, pero no da namespace.
     */
    public static function missingNamespace(string $file, string $directory, bool $onlyGlobal = false): self
    {
        $example = "(por ejemplo, \"Acme\\\\Blog\\\\\": \"{$directory}/\")";

        if ($onlyGlobal) {
            return new self("{$file} sólo apunta a {$directory}/ desde el prefijo vacío (\"\") de `autoload.psr-4`, que es el namespace global, y lo generado necesita uno propio. Añádelo {$example} y vuelve a ejecutar.");
        }

        return new self("{$file} no declara en `autoload.psr-4` ningún namespace que apunte a {$directory}/ (ni como cadena ni en una lista de rutas), y es el namespace de todo lo que se genera. Añádelo {$example} y vuelve a ejecutar.");
    }
}
