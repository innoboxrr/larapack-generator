<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cómo se escribe un documento de --format=json y cómo se cuenta un error,
 * sin ser un comando: lo usan los comandos (WritesJson) y las acciones de
 * `larapack:realign` (Commands\Realign\Action), que no lo son.
 */
trait FormatsJson
{
    protected function jsonRequested(InputInterface $input): bool
    {
        if ($input->hasOption('format') && $input->getOption('format') === 'json') {
            return true;
        }

        // Si falló al leer la entrada, getOption() todavía no sabe nada: se mira
        // lo que se escribió.
        return $input->getParameterOption('--format', null, true) === 'json';
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

    /**
     * El error, como documento o como texto según --format.
     *
     * @param  string|null  $hint  qué hacer, en texto fuera del estilo de error
     * @param  array<string, mixed>  $extra  lo que el comando añade al documento
     */
    protected function reportFailure(InputInterface $input, OutputInterface $output, string $error, ?string $hint = null, array $extra = []): int
    {
        if ($this->jsonRequested($input)) {
            $this->writeJson($output, [
                'ok' => false,
                'error' => $hint === null ? $error : "{$error} {$hint}",
            ] + $extra);
        } else {
            $output->writeln("<error>{$error}</error>".($hint === null ? '' : " {$hint}"));
        }

        return Command::FAILURE;
    }
}
