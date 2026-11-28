<?php

namespace Innoboxrr\LarapackGenerator\Commands\Concerns;

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
    use FormatsJson;

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
        return $this->jsonRequested($input);
    }
}
