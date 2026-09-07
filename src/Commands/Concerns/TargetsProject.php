<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Permite decir sobre qué proyecto se trabaja.
 *
 * El descubrimiento por defecto sube directorios desde el propio paquete
 * buscando un `vendor/autoload.php`. Dentro de una aplicación Laravel eso da
 * la raíz correcta, pero con el binario `builder` sobre un clon del generador
 * da el propio generador: el destino dependía de dónde estuviera instalado el
 * paquete, no de sobre qué se quisiera generar.
 *
 * Para un agente eso es inaceptable — necesita poder apuntar al proyecto de
 * forma explícita y que el comando falle si no existe, en vez de escribir en
 * el sitio equivocado.
 */
trait TargetsProject
{
    protected function addRootOption(): void
    {
        $this->addOption(
            'root',
            null,
            InputOption::VALUE_REQUIRED,
            'Raíz del proyecto sobre el que generar (por defecto, la que se descubra)'
        );
    }

    protected function applyRootOption(InputInterface $input): void
    {
        $root = $input->hasOption('root') ? $input->getOption('root') : null;

        if ($root !== null && $root !== '') {
            ProjectRoot::set((string) $root);
        }
    }
}
