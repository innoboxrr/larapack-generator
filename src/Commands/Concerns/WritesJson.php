<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * La salida de --format=json: un único documento y nada más.
 *
 * Quien pide JSON —la CI, un agente que sigue la guía— decodifica la salida
 * entera. Una sola línea de progreso delante del informe la vuelve ilegible, y
 * los estilos de la consola no tienen sitio dentro de un documento.
 */
trait WritesJson
{
    protected function wantsJson(InputInterface $input): bool
    {
        return $input->hasOption('format') && $input->getOption('format') === 'json';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function writeJson(OutputInterface $output, array $document): void
    {
        // En bruto: el formateador de la consola quitaría de los mensajes lo que
        // parezca una etiqueta de estilo y, en una terminal, añadiría colores. Y
        // un byte que no es UTF-8 no puede dejar la salida vacía.
        $output->writeln(
            (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            OutputInterface::OUTPUT_RAW
        );
    }
}
