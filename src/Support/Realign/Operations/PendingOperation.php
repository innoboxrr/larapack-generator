<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use RuntimeException;

/**
 * Una acción del realineamiento declarada y todavía sin implementación en
 * esta versión. Responde lo que es en vez de hacer como que hizo algo: un
 * agente que la ejecuta sabe que ese paso no se puede automatizar aún y que
 * le toca hacerlo como se describe en el paso.
 */
final class PendingOperation implements RealignOperation
{
    public function __construct(private readonly string $action) {}

    public function name(): string
    {
        return $this->action;
    }

    public function description(): string
    {
        return Operations::DESCRIPTIONS[$this->action] ?? '';
    }

    public function handles(): array
    {
        return [];
    }

    public function run(OperationContext $context): OperationResult
    {
        throw new RuntimeException("`larapack:realign {$this->action}` no está disponible en esta versión del generador. Haz el paso como lo describe `larapack:realign next` y compruébalo con `larapack:realign check`.");
    }
}
