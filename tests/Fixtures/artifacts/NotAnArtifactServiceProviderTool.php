<?php

namespace Innoboxrr\LarapackGenerator\Tools\Providers;

/**
 * Un proveedor que existe donde Artifacts::provider() lo busca pero no
 * implementa PackageArtifact. Ninguno de los de src/ sirve para eso —los
 * cuatro lo implementan—, así que ArtifactsTest carga éste a mano: el
 * namespace es el de producción y el autoload de tests no lo encontraría.
 */
final class NotAnArtifactServiceProviderTool {}
