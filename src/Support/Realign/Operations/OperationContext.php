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
     * @param  list<string>  $only  `--target`, repetible: sólo estos elementos (objetivos o archivos) de los pasos; vacío, todos
     * @param  bool  $simulation  la corrida de un `--dry-run` sobre la copia temporal (Simulation): escribe en la copia y anota el diff de cada cambio
     */
    public function __construct(
        public readonly string $root,
        public readonly RealignState $state,
        public readonly array $steps,
        public readonly bool $dryRun,
        public readonly array $options = [],
        public readonly array $only = [],
        public readonly bool $simulation = false,
    ) {}

    /**
     * Si no hay que escribir. En la copia de una simulación sí se escribe:
     * es la copia la que se tira.
     */
    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Si lo que se informa es lo que se haría y no lo que se hizo: un
     * `--dry-run`, en el disco o en la copia. Es para el tiempo verbal y
     * para anotar diffs; lo que se escribe lo decide dryRun().
     */
    public function simulated(): bool
    {
        return $this->dryRun || $this->simulation;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }
}
