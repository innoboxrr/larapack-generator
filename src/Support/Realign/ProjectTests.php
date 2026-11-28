<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use RuntimeException;

/**
 * Las pruebas del proyecto, cuando `check` las corre él mismo.
 *
 * Un paso que cambia comportamiento (StepTypes::changesBehaviour()) no se da
 * por hecho sin su prueba. Si el proyecto registra su orden de pruebas en
 * `.larapack/realign/config.json`:
 *
 *     {"format_version": 1, "tests": "php artisan test"}
 *
 * `check` la corre en la raíz del proyecto después de que el `done_when`
 * pase, y el paso queda `done` sólo si sale con 0; si no, sigue sin hacer y
 * `check` da el final de la salida. Sin la orden, la prueba la declara quien
 * la corrió (`check --tested="<orden>"`) o el paso queda `untested`.
 *
 * La orden la escribe una persona y es del proyecto, como un script de
 * Composer: se corre con la shell, tal cual. Corre la suite entera (o la
 * parte que la persona haya querido): el paso no sabe qué pruebas cubren sus
 * archivos.
 */
final class ProjectTests
{
    /**
     * Cuántas líneas del final de la salida se guardan y se enseñan.
     */
    public const TAIL = 40;

    public function __construct(private readonly string $root) {}

    /**
     * La orden configurada, o null si no hay.
     */
    public function command(): ?string
    {
        $config = (new RealignState($this->root))->config();
        $tests = $config['tests'] ?? null;

        if ($tests !== null && ! is_string($tests)) {
            throw new RuntimeException(RealignState::DIRECTORY.'/config.json: `tests` tiene que ser la orden de pruebas del proyecto, como texto.');
        }

        $tests = is_string($tests) ? trim($tests) : '';

        return $tests === '' ? null : $tests;
    }

    /**
     * Corre la orden y devuelve su código de salida y el final de su salida
     * (la estándar y la de errores, juntas).
     *
     * @return array{command: string, exit: int, output: string}
     */
    public function run(string $command): array
    {
        $process = proc_open($command.' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $this->root);

        if (! is_resource($process)) {
            throw new RuntimeException("No se pudo ejecutar la orden de pruebas del proyecto: {$command}");
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        $lines = preg_split('/\R/', rtrim($output)) ?: [];

        return ['command' => $command, 'exit' => $exit, 'output' => implode("\n", array_slice($lines, -self::TAIL))];
    }
}
