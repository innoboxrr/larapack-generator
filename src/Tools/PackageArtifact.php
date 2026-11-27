<?php

namespace Innoboxrr\LarapackGenerator\Tools;

/**
 * Una herramienta que genera algo del paquete y no de un modelo: un
 * proveedor de servicios, la configuración.
 */
interface PackageArtifact
{
    /**
     * @return bool true si escribió algo (o lo escribiría, en una simulación).
     */
    public function create(): bool;
}
