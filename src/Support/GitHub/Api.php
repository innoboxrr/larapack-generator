<?php

namespace Innoboxrr\LarapackGenerator\Support\GitHub;

/**
 * Lo único que LaraPack le pregunta a GitHub: lecturas, nunca escrituras.
 *
 * Proteger la rama cambia quién puede fusionar, y eso lo decide el dueño:
 * ninguna implementación de esta interfaz tiene con qué escribir.
 */
interface Api
{
    /**
     * Un GET a la API REST, con la ruta relativa a la raíz de la API
     * (`repos/acme/notes/rules/branches/main`). Nunca lanza: lo que no se pudo
     * saber —sin red, sin credenciales, un error de GitHub— va en la
     * respuesta.
     */
    public function get(string $path): Response;

    /**
     * Por dónde se pregunta, para decirlo en el informe: `gh`, `GH_TOKEN`…
     */
    public function describe(): string;
}
