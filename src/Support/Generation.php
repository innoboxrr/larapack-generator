<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Modo de ejecución de los generadores y registro de lo que hicieron.
 *
 * Hasta ahora las herramientas abortaban con `file_exists` y devolvían `false`
 * en silencio, sin que nadie leyera ese retorno: editar el laraimport.json y
 * volver a importar no propagaba nada, y no había forma de saber qué se iba a
 * tocar antes de tocarlo.
 */
final class Generation
{
    private static bool $force = false;

    private static bool $dryRun = false;

    /**
     * @var array<int, array{action: string, file: string, stub: string, reason: string|null}>
     */
    private static array $log = [];

    public static function force(bool $value = true): void
    {
        self::$force = $value;
    }

    public static function dryRun(bool $value = true): void
    {
        self::$dryRun = $value;
    }

    public static function isForced(): bool
    {
        return self::$force;
    }

    public static function isDryRun(): bool
    {
        return self::$dryRun;
    }

    /**
     * @param  string  $action  create|overwrite|skipped|preserved
     */
    public static function record(string $action, string $file, string $stub, ?string $reason = null): void
    {
        self::$log[] = [
            'action' => $action,
            'file' => $file,
            'stub' => self::stubName($stub),
            'reason' => $reason,
        ];
    }

    /**
     * El stub se reporta relativo a Stubs/: la ruta absoluta depende de dónde
     * esté instalado el paquete y haría inestable la salida en JSON.
     */
    private static function stubName(string $stub): string
    {
        $stub = str_replace('\\', '/', $stub);
        $marker = '/Stubs/';
        $position = strpos($stub, $marker);

        return $position === false ? basename($stub) : substr($stub, $position + strlen($marker));
    }

    /**
     * @return array<int, array{action: string, file: string, stub: string, reason: string|null}>
     */
    public static function log(): array
    {
        return self::$log;
    }

    /**
     * @return array<string, int>
     */
    public static function summary(): array
    {
        $summary = ['create' => 0, 'overwrite' => 0, 'skipped' => 0, 'preserved' => 0];

        foreach (self::$log as $entry) {
            $summary[$entry['action']] = ($summary[$entry['action']] ?? 0) + 1;
        }

        return $summary;
    }

    public static function reset(): void
    {
        self::$force = false;
        self::$dryRun = false;
        self::$log = [];
    }
}
