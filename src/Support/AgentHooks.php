<?php

namespace Innoboxrr\LarapackGenerator\Support;

use RuntimeException;
use stdClass;

/**
 * Lo que conecta la guía de LaraPack con las herramientas de los agentes, en
 * los archivos que esas herramientas leen por su cuenta.
 *
 * Los dos archivos son del proyecto, no del paquete: un `.claude/settings.json`
 * suele tener permisos y otros hooks, y un `AGENTS.md`, las reglas de la casa.
 * Por eso aquí nada se sobrescribe: se fusiona lo que falta y se deja el resto
 * como estaba, y reejecutarlo no duplica nada.
 */
final class AgentHooks
{
    public const SETTINGS = '.claude/settings.json';

    public const AGENTS = 'AGENTS.md';

    /**
     * En mayúsculas para que no se confundan con un comando `larapack:…` al
     * buscar los que menciona el texto.
     */
    public const BEGIN = '<!-- LARAPACK:BEGIN -->';

    public const END = '<!-- LARAPACK:END -->';

    private const BLOCK_PATH = '/../../skill/agents-block.md';

    /**
     * Fusiona los hooks de LaraPack en `.claude/settings.json`.
     *
     * - PostToolUse sobre Write|Edit|MultiEdit: `larapack:guard` revisa el
     *   archivo recién editado y devuelve al modelo lo que se sale del contrato.
     * - Stop: `larapack:guard --stop` corre el Verifier entero y no deja dar la
     *   tarea por terminada con errores. Respeta `stop_hook_active`, así que no
     *   retiene al agente dos veces seguidas por lo mismo.
     *
     * Un hook ya presente se reconoce por el comando y se deja como está, aunque
     * se haya cambiado a mano: quien lo cambió sabrá por qué.
     *
     * @return array{target: string, added: array<int, string>, present: array<int, string>}
     */
    public static function installSettings(string $root): array
    {
        $target = rtrim($root, '/').'/'.self::SETTINGS;
        $settings = self::readSettings($target);

        if (! isset($settings->hooks)) {
            $settings->hooks = new stdClass;
        }

        if (! $settings->hooks instanceof stdClass) {
            throw new RuntimeException("{$target}: `hooks` no es un objeto. Corrígelo a mano; no se toca.");
        }

        $added = [];
        $present = [];

        foreach (self::hooks($root) as $event => $group) {
            $groups = $settings->hooks->{$event} ?? [];

            if (! is_array($groups)) {
                throw new RuntimeException("{$target}: `hooks.{$event}` no es una lista. Corrígelo a mano; no se toca.");
            }

            if (self::hasGuard($groups, $event === 'Stop')) {
                $present[] = $event;

                continue;
            }

            $groups[] = $group;
            $settings->hooks->{$event} = $groups;
            $added[] = $event;
        }

        // Sin cambios no se reescribe: reformatear el archivo de otro sólo
        // para dejarlo igual es ruido en su diff.
        if ($added !== []) {
            self::write($target, (string) json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        return ['target' => $target, 'added' => $added, 'present' => $present];
    }

    /**
     * Los grupos de hooks que LaraPack añade, por evento.
     *
     * El comando pasa por `$CLAUDE_PROJECT_DIR` porque el hook se ejecuta en el
     * directorio en el que esté la sesión, que no tiene por qué ser la raíz.
     *
     * @return array<string, stdClass>
     */
    public static function hooks(string $root): array
    {
        $binary = is_file(rtrim($root, '/').'/artisan')
            ? 'php "$CLAUDE_PROJECT_DIR/artisan"'
            : 'php "$CLAUDE_PROJECT_DIR/vendor/bin/builder"';

        return [
            'PostToolUse' => (object) [
                'matcher' => 'Write|Edit|MultiEdit',
                'hooks' => [(object) [
                    'type' => 'command',
                    'command' => "{$binary} larapack:guard",
                    'timeout' => 30,
                    'statusMessage' => 'LaraPack: comprobando el contrato…',
                ]],
            ],
            'Stop' => (object) [
                'hooks' => [(object) [
                    'type' => 'command',
                    'command' => "{$binary} larapack:guard --stop",
                    'timeout' => 120,
                    'statusMessage' => 'LaraPack: verificando el proyecto…',
                ]],
            ],
        ];
    }

    /**
     * Escribe o actualiza el bloque de LaraPack en `AGENTS.md`.
     *
     * Codex y otros agentes no leen `.claude/skills/`; leen `AGENTS.md`. Lo
     * que hay entre los marcadores es de LaraPack y se reescribe; lo de fuera
     * es del proyecto y no se toca. Sin marcadores, el bloque va al final.
     *
     * @return array{target: string, action: string} action: created|updated|appended|unchanged
     */
    public static function installAgentsBlock(string $root, string $skillPath = Skill::DEFAULT_TARGET, string $target = self::AGENTS): array
    {
        $absolute = rtrim($root, '/').'/'.ltrim($target, '/');
        $block = self::BEGIN."\n".self::agentsBlock($skillPath).self::END."\n";

        if (! is_file($absolute)) {
            self::write($absolute, $block);

            return ['target' => $absolute, 'action' => 'created'];
        }

        $current = (string) file_get_contents($absolute);
        $begin = strpos($current, self::BEGIN);
        $end = $begin === false ? false : strpos($current, self::END, $begin);

        if ($begin === false || $end === false) {
            $separator = $current === '' ? '' : (str_ends_with($current, "\n") ? "\n" : "\n\n");
            self::write($absolute, $current.$separator.$block);

            return ['target' => $absolute, 'action' => 'appended'];
        }

        // Lo que sigue al marcador de cierre, sin su salto de línea: el bloque
        // ya trae el suyo.
        $after = substr($current, $end + strlen(self::END));
        $after = str_starts_with($after, "\n") ? substr($after, 1) : $after;
        $updated = substr($current, 0, $begin).$block.$after;

        if ($updated === $current) {
            return ['target' => $absolute, 'action' => 'unchanged'];
        }

        self::write($absolute, $updated);

        return ['target' => $absolute, 'action' => 'updated'];
    }

    /**
     * El texto del bloque, con la ruta en la que se instaló el skill.
     */
    public static function agentsBlock(string $skillPath = Skill::DEFAULT_TARGET): string
    {
        $path = realpath(__DIR__.self::BLOCK_PATH);
        $contents = $path === false ? false : file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('No se encontró skill/agents-block.md en el paquete.');
        }

        return str_replace('{{skill}}', str_replace('\\', '/', $skillPath), str_replace("\r\n", "\n", $contents));
    }

    /**
     * @param  array<int, mixed>  $groups
     */
    private static function hasGuard(array $groups, bool $stop): bool
    {
        foreach ($groups as $group) {
            $handlers = is_object($group) && isset($group->hooks) && is_array($group->hooks) ? $group->hooks : [];

            foreach ($handlers as $handler) {
                $command = is_object($handler) && isset($handler->command) && is_string($handler->command) ? $handler->command : '';

                if (str_contains($command, 'larapack:guard') && str_contains($command, '--stop') === $stop) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Se decodifica a objetos y no a arrays: un `{}` vacío del usuario
     * —`"env": {}`— volvería como `[]` y cambiaría de tipo al guardarlo.
     */
    private static function readSettings(string $target): stdClass
    {
        if (! is_file($target)) {
            return new stdClass;
        }

        $raw = (string) file_get_contents($target);

        if (trim($raw) === '') {
            return new stdClass;
        }

        $decoded = json_decode($raw, false);

        if (! $decoded instanceof stdClass) {
            throw new RuntimeException("{$target} no es un objeto JSON válido. Corrígelo a mano; no se sobrescribe.");
        }

        return $decoded;
    }

    private static function write(string $file, string $contents): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException("No se pudo escribir {$file}.");
        }
    }
}
