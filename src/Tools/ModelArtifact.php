<?php

namespace Innoboxrr\LarapackGenerator\Tools;

/**
 * Una herramienta que genera, y retira, lo que le toca de un modelo.
 *
 * larapack:full-model y larapack:remove-full-model recorren una lista de
 * herramientas por nombre. Antes las instanciaban por reflexión y llamaban a
 * create()/remove() sobre un `object`: nada garantizaba que existieran, ni con
 * qué firma. Una herramienta de la lista que no cumple esto hace fallar el
 * comando (`Artifacts::model`), en vez de quedarse sin ejecutar en silencio.
 */
interface ModelArtifact
{
    /**
     * @return bool true si escribió algo (o lo escribiría, en una simulación).
     */
    public function create(string $ModelName): bool;

    /**
     * @return bool true si quitó algo.
     */
    public function remove(string $ModelName): bool;
}
