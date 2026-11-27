<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;

/**
 * Lo que recibe una operación: sobre qué proyecto, qué pasos, si es una
 * simulación y las opciones de la línea de órdenes que no son del núcleo.
 *
 * Los pasos llegan enteros, como están en plan.json (Planner): con sus
 * archivos, sus detalles —la clase y la razón de cada archivo, los
 * hallazgos de verify—, su `done_when` y, en una decisión, la opción
 * elegida en `decision`.
 */
final class OperationContext
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, string>  $options  `--option clave=valor`, repetible
     */
    public function __construct(
        public readonly string $root,
        public readonly RealignState $state,
        public readonly array $steps,
        public readonly bool $dryRun,
        public readonly array $options = [],
    ) {}

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }
}
