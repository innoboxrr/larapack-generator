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

    public static function copyFailed(string $stub, string $destination): self
    {
        return new self("No se pudo copiar la plantilla {$stub} a {$destination}.");
    }
}
