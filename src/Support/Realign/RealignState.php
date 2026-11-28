<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use RuntimeException;

/**
 * El estado del realineamiento de un proyecto, en `.larapack/realign/`.
 *
 *  - `report.json`: el último diagnóstico (Diagnosis), por ámbito.
 *  - `plan.json`: los pasos, su estado y su historia (Planner). Es lo que
 *    hace el proceso reanudable: un agente que llega sin contexto corre
 *    `larapack:realign status` y sabe dónde se va.
 *  - `config.json`: lo que configura una persona (la orden de pruebas del
 *    proyecto, ProjectTests). Opcional; no lo escribe ninguna acción.
 *  - `cache/`: lo regenerado para comparar (Regeneration). No se versiona:
 *    se ignora con el `.gitignore` de este directorio.
 *
 * Los dos JSON llevan `format_version` (FORMAT_VERSION) y se escriben de
 * forma atómica. Están pensados para versionarse con el proyecto: el plan es
 * el registro de qué se decidió y qué se hizo.
 *
 * Nada de lo que hay aquí es código del proyecto: las operaciones que exigen
 * el árbol git limpio no cuentan este directorio (OperationRunner).
 */
final class RealignState
{
    /**
     * Versión del formato de report.json, plan.json y de toda salida
     * `--format=json` de `larapack:realign`. Se sube si cambia algo que un
     * agente lee; añadir una clave no la sube.
     */
    public const FORMAT_VERSION = 1;

    public const DIRECTORY = '.larapack/realign';

    private const GITIGNORE = "# Lo regenerado para comparar: se rehace solo y no se versiona.\ncache/\n";

    public function __construct(private readonly string $root) {}

    public function root(): string
    {
        return $this->root;
    }

    public function directory(): string
    {
        return $this->root.'/'.self::DIRECTORY;
    }

    public function cacheDirectory(): string
    {
        return $this->directory().'/cache';
    }

    public function reportPath(): string
    {
        return $this->directory().'/report.json';
    }

    public function planPath(): string
    {
        return $this->directory().'/plan.json';
    }

    /**
     * La configuración del realineamiento del proyecto, que escribe una
     * persona y se versiona: hoy, la orden de pruebas del proyecto (`tests`)
     * que `check` corre en los pasos que cambian comportamiento
     * (ProjectTests).
     */
    public function configPath(): string
    {
        return $this->directory().'/config.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        return $this->load($this->configPath());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        return $this->load($this->reportPath());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(): ?array
    {
        return $this->load($this->planPath());
    }

    /**
     * El plan, para las acciones que no tienen nada que hacer sin él.
     *
     * @return array<string, mixed>
     */
    public function requirePlan(): array
    {
        $plan = $this->plan();

        if ($plan === null || ! is_array($plan['steps'] ?? null)) {
            throw new RuntimeException('No hay plan: corre `larapack:realign diagnose` y después `larapack:realign plan`.');
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function saveReport(array $report): void
    {
        $this->save($this->reportPath(), $report);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function savePlan(array $plan): void
    {
        $this->save($this->planPath(), $plan);
    }

    /**
     * Crea el directorio y su `.gitignore`. Se llama antes de escribir
     * nada, también la caché.
     */
    public function prepare(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear {$directory}.");
        }

        if (! is_file($directory.'/.gitignore')) {
            file_put_contents($directory.'/.gitignore', self::GITIGNORE);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode(Files::read($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$path} no es JSON válido. Bórralo y vuelve a correr `larapack:realign diagnose`.");
        }

        $version = $decoded['format_version'] ?? null;

        if (! is_int($version) || $version > self::FORMAT_VERSION) {
            throw new RuntimeException("{$path} está en un formato que esta versión de LaraPack no entiende (format_version ".json_encode($version).'). Actualiza innoboxrr/larapack-generator.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function save(string $path, array $document): void
    {
        $this->prepare();

        $document = ['format_version' => self::FORMAT_VERSION] + $document;
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $temporary = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json) !== strlen($json) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("No se pudo escribir {$path}.");
        }
    }
}
