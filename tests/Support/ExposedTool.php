<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use Innoboxrr\LarapackGenerator\Tools\Tool;

/**
 * Tool expone init(), replaceTokens() y generate() como protegidos porque son
 * detalle interno de las herramientas. Aquí se abren para poder probarlos
 * aislados, sin pasar por un comando completo.
 */
final class ExposedTool extends Tool
{
    public function prepare(string $modelName): void
    {
        $this->init($modelName);
    }

    public function replace(string $content): string
    {
        return $this->replaceTokens($content);
    }

    public function generateFrom(string $stub, string $destination): bool
    {
        return $this->generate($stub, $destination);
    }
}
