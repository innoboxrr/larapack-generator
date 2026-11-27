<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

/**
 * Una operación del realineamiento que escribe: `adopt`, `apply`, `infer`,
 * `except`. Es el contrato para enchufar una implementación sin tocar nada
 * más del núcleo:
 *
 *  1. Una clase `Innoboxrr\LarapackGenerator\Support\Realign\Operations\<Acción>Operation`
 *     (`AdoptOperation`, `ApplyOperation`…) que implemente esta interfaz.
 *     Operations la descubre por el nombre; mientras no exista, la acción
 *     responde que no está disponible (PendingOperation).
 *  2. Nada más. OperationRunner ya hace lo común a todas: comprueba que el
 *     árbol git esté limpio (salvo `--allow-dirty`), resuelve los pasos que
 *     se nombran, pasa `--dry-run`, pinta el informe y lo anota en la
 *     historia de cada paso.
 *
 * Lo que se espera de una implementación (docs/realign/README.md):
 *
 *  - Con `dryRun()` no escribe nada y devuelve lo que haría (con diff si
 *    puede).
 *  - Sólo toca lo que reconoce con certeza; lo demás va a `skipped` con su
 *    razón. Lo que ya estaba bien va a `unchanged`.
 *  - Deja el árbol en un estado que `verify` y `larapack:realign check`
 *    pueden juzgar: no deja nada a medias.
 *  - Nunca reescribe una migración que ya existe ni cambia un nombre de ruta
 *    o una URI que el contrato no declare.
 */
interface RealignOperation
{
    /**
     * El nombre de la acción: `adopt`, `apply`, `infer` o `except`.
     */
    public function name(): string;

    /**
     * Qué hace, en una línea, para `larapack:realign` sin argumentos.
     */
    public function description(): string;

    /**
     * Los tipos de paso que sabe ejecutar (StepTypes). Un paso de otro tipo
     * se rechaza antes de llamar a run().
     *
     * @return list<string>
     */
    public function handles(): array;

    public function run(OperationContext $context): OperationResult;
}
