<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * La salida de --format=json: un único documento y nada más.
 *
 * Quien pide JSON —la CI, un agente que sigue la guía— decodifica la salida
 * entera. Una sola línea de progreso delante del informe la vuelve ilegible, y
 * los estilos de la consola no tienen sitio dentro de un documento. Un error
 * tampoco: llega como `{"ok": false, "error": "..."}`, con el mismo código de
 * salida distinto de cero.
 */
trait WritesJson
{
    /**
     * Lo que falla antes o durante execute() —un argumento que falta, una raíz
     * que no existe— Symfony lo pinta como texto en stderr y deja la salida
     * vacía: quien espera un documento no tiene nada que leer.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (Throwable $e) {
            if (! $this->wantsJson($input)) {
                throw $e;
            }

            return $this->reportFailure($input, $output, $e->getMessage(), extra: ['exception' => $e::class]);
        }
    }

    protected function wantsJson(InputInterface $input): bool
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
        if ($this->wantsJson($input)) {
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
