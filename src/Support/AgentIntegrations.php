<?php

namespace Innoboxrr\LarapackGenerator\Support;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * La guardia en los agentes que no son Claude Code: sus hooks, donde el
 * agente los tiene, y sus reglas, donde las lee.
 *
 * Cada agente tiene su archivo y su formato, pero todos llaman al mismo
 * `larapack:guard --agent=<agente>` (AgentAdapters traduce la entrada y la
 * respuesta). Sólo se escriben los hooks que el agente documenta con un
 * mecanismo determinista —un comando que corre antes o después de la
 * herramienta y cuyo resultado el agente respeta—:
 *
 * | Agente | Hooks | Reglas |
 * |---|---|---|
 * | Cursor | `.cursor/hooks.json` (preToolUse, postToolUse, stop) | `.cursor/rules/larapack.mdc` |
 * | Codex CLI | `.codex/hooks.json` (PreToolUse, PostToolUse, Stop) | `AGENTS.md` |
 * | Gemini CLI | `.gemini/settings.json` (BeforeTool, AfterTool, AfterAgent) | `GEMINI.md` |
 * | GitHub Copilot (CLI y agente en la nube) | `.github/hooks/larapack.json` (preToolUse, postToolUse, agentStop) | `.github/copilot-instructions.md` |
 * | Windsurf | `.windsurf/hooks.json` (pre_write_code, pre_run_command, post_write_code) | `.windsurf/rules/larapack.md` |
 *
 * Los comandos son de shell POSIX, como los de Claude Code. Los archivos que
 * comparte el proyecto (`.gemini/settings.json`, `GEMINI.md`,
 * `.github/copilot-instructions.md`) se fusionan, como `.claude/settings.json`
 * y `AGENTS.md`: se añade lo que falta y lo demás se queda como estaba.
 */
final class AgentIntegrations
{
    /**
     * Los agentes con integración propia: Claude Code tiene la suya
     * (AgentHooks), y Codex lee además AGENTS.md.
     */
    public const AGENTS = [AgentAdapters::CURSOR, AgentAdapters::CODEX, AgentAdapters::GEMINI, AgentAdapters::COPILOT, AgentAdapters::WINDSURF];

    public const NAMES = [
        AgentAdapters::CLAUDE => 'Claude Code',
        AgentAdapters::CURSOR => 'Cursor',
        AgentAdapters::CODEX => 'Codex CLI',
        AgentAdapters::GEMINI => 'Gemini CLI',
        AgentAdapters::COPILOT => 'GitHub Copilot',
        AgentAdapters::WINDSURF => 'Windsurf',
    ];

    /**
     * Dónde lee sus hooks cada agente.
     */
    public const HOOK_FILES = [
        AgentAdapters::CURSOR => '.cursor/hooks.json',
        AgentAdapters::CODEX => '.codex/hooks.json',
        AgentAdapters::GEMINI => '.gemini/settings.json',
        AgentAdapters::COPILOT => '.github/hooks/larapack.json',
        AgentAdapters::WINDSURF => '.windsurf/hooks.json',
    ];

    /**
     * Dónde lee sus reglas cada agente. Los que son sólo de LaraPack se
     * escriben enteros; los compartidos llevan el bloque entre marcadores.
     */
    public const RULE_FILES = [
        AgentAdapters::CURSOR => '.cursor/rules/larapack.mdc',
        AgentAdapters::CODEX => AgentHooks::AGENTS,
        AgentAdapters::GEMINI => 'GEMINI.md',
        AgentAdapters::COPILOT => '.github/copilot-instructions.md',
        AgentAdapters::WINDSURF => '.windsurf/rules/larapack.md',
    ];

    /**
     * La cabecera de los archivos de reglas que son sólo de LaraPack: lo que
     * cada agente necesita para aplicarlas siempre.
     */
    private const FRONTMATTER = [
        AgentAdapters::CURSOR => "---\ndescription: Contrato de LaraPack: la arquitectura se declara en laraimport.json y el controlador sólo hace return \$request->handle();\nalwaysApply: true\n---\n\n",
        AgentAdapters::WINDSURF => "---\ntrigger: always_on\n---\n\n",
    ];

    /**
     * Lo que se pasa como `agents`: una lista separada por comas, o `all`.
     *
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        $agents = array_values(array_unique(array_filter(array_map(
            fn (string $agent): string => strtolower(trim($agent)),
            explode(',', $value)
        ), fn (string $agent): bool => $agent !== '')));

        if (in_array('all', $agents, true)) {
            return self::AGENTS;
        }

        $unknown = array_diff($agents, self::AGENTS);

        if ($unknown !== []) {
            throw new RuntimeException('Agente desconocido: '.implode(', ', $unknown).'. Disponibles: '.implode(', ', self::AGENTS).' o all.');
        }

        return $agents;
    }

    /**
     * Instala los hooks y las reglas de un agente.
     *
     * @return list<array{target: string, action: string}> action: created|updated|unchanged|appended|kept
     */
    public static function install(string $root, string $agent, string $skillPath = Skill::DEFAULT_TARGET): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return [self::installHooks($root, $agent), self::installRules($root, $agent, $skillPath)];
    }

    /**
     * Si el proyecto tiene los hooks de LaraPack de ese agente.
     */
    public static function hasNative(?string $root, string $agent): bool
    {
        if ($root === null || ! isset(self::HOOK_FILES[$agent])) {
            return false;
        }

        $file = rtrim($root, '/').'/'.self::HOOK_FILES[$agent];
        $contents = is_file($file) ? (string) file_get_contents($file) : '';

        return str_contains($contents, 'larapack:guard') && str_contains($contents, '--agent='.$agent);
    }

    /**
     * El estado de un agente para el diagnóstico.
     *
     * @return array{hooks: 'installed'|'outdated'|'missing', rules: 'installed'|'missing'}
     */
    public static function status(string $root, string $agent): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $hooks = 'missing';

        if (self::hasNative($root, $agent)) {
            $config = self::readJson($root.'/'.self::HOOK_FILES[$agent]);
            $hooks = 'installed';

            foreach (self::hooks($root, $agent)['events'] as $event => $entry) {
                if (! self::eventHasCurrent($config, $agent, $event, $entry)) {
                    $hooks = 'outdated';
                }
            }
        }

        $rules = $root.'/'.self::RULE_FILES[$agent];
        $contents = is_file($rules) ? (string) file_get_contents($rules) : '';

        return ['hooks' => $hooks, 'rules' => str_contains($contents, AgentHooks::BEGIN) ? 'installed' : 'missing'];
    }

    /**
     * La configuración de hooks del agente: las claves de arriba que pide su
     * formato y una entrada por evento.
     *
     * @return array{top: array<string, mixed>, events: array<string, stdClass>}
     */
    public static function hooks(string $root, string $agent): array
    {
        $command = fn (string $arguments): string => self::command($root, $agent, $arguments);

        return match ($agent) {
            AgentAdapters::CURSOR => [
                'top' => ['version' => 1],
                'events' => [
                    'preToolUse' => (object) ['command' => $command('--pre'), 'matcher' => 'Shell|Write|Delete', 'timeout' => 30],
                    'postToolUse' => (object) ['command' => $command(''), 'matcher' => '^Write$', 'timeout' => 30],
                    'stop' => (object) ['command' => $command('--stop'), 'timeout' => 120, 'loop_limit' => 1],
                ],
            ],
            AgentAdapters::CODEX => [
                'top' => [],
                'events' => [
                    'PreToolUse' => self::group('Bash|apply_patch|Edit|Write', $command('--pre')),
                    'PostToolUse' => self::group('apply_patch|Edit|Write', $command('')),
                    'Stop' => self::group(null, $command('--stop')),
                ],
            ],
            AgentAdapters::GEMINI => [
                'top' => [],
                'events' => [
                    'BeforeTool' => self::group('write_file|replace|run_shell_command', $command('--pre'), 30000),
                    'AfterTool' => self::group('write_file|replace', $command(''), 30000),
                    'AfterAgent' => self::group(null, $command('--stop'), 120000),
                ],
            ],
            AgentAdapters::COPILOT => [
                'top' => ['version' => 1],
                'events' => [
                    'preToolUse' => (object) ['type' => 'command', 'matcher' => 'bash|edit|create', 'bash' => $command('--pre'), 'cwd' => '.', 'timeoutSec' => 30],
                    'postToolUse' => (object) ['type' => 'command', 'matcher' => 'edit|create', 'bash' => $command(''), 'cwd' => '.', 'timeoutSec' => 30],
                    'agentStop' => (object) ['type' => 'command', 'bash' => $command('--stop'), 'cwd' => '.', 'timeoutSec' => 120],
                ],
            ],
            AgentAdapters::WINDSURF => [
                'top' => [],
                'events' => [
                    'pre_write_code' => (object) ['command' => $command('--pre'), 'show_output' => true],
                    'pre_run_command' => (object) ['command' => $command('--pre'), 'show_output' => true],
                    'post_write_code' => (object) ['command' => $command(''), 'show_output' => true],
                ],
            ],
            default => throw new RuntimeException("{$agent} no tiene integración propia."),
        };
    }

    /**
     * El comando de un hook: encuentra la raíz del proyecto como la da cada
     * agente, comprueba que haya algo que ejecutar (sin `vendor/` no hace
     * nada) y llama a la guardia. El de antes de la herramienta filtra en la
     * shell, como el de Claude Code (AgentHooks::preCommand()).
     *
     * - Cursor y Gemini CLI exportan la raíz (`CURSOR_PROJECT_DIR`,
     *   `GEMINI_PROJECT_DIR`).
     * - Copilot ejecuta el hook en el `cwd` que se le da, la raíz del
     *   repositorio.
     * - Codex y Windsurf no documentan una variable: la raíz del repositorio
     *   git, o el directorio de trabajo.
     */
    public static function command(string $root, string $agent, string $arguments): string
    {
        $base = match ($agent) {
            AgentAdapters::CURSOR => '${CURSOR_PROJECT_DIR:-.}',
            AgentAdapters::GEMINI => '${GEMINI_PROJECT_DIR:-.}',
            AgentAdapters::COPILOT => '.',
            default => '$(git rev-parse --show-toplevel 2>/dev/null || pwd)',
        };

        [$required, $php] = is_file(rtrim($root, '/').'/artisan')
            ? ['"$R/vendor/autoload.php"', 'php "$R/artisan"']
            : ['"$R/vendor/bin/builder"', 'php "$R/vendor/bin/builder"'];
        $guard = trim("--agent={$agent} {$arguments}");

        return "R=\"{$base}\"; ".($arguments === '--pre'
            ? AgentHooks::preCommand($required, $php, $guard)
            : "[ ! -f {$required} ] || {$php} larapack:guard {$guard}");
    }

    /**
     * Un grupo con el formato de Claude Code, que usan Codex y Gemini CLI.
     * El tiempo, sólo donde el formato lo documenta (Gemini, en milisegundos).
     */
    private static function group(?string $matcher, string $command, ?int $timeout = null): stdClass
    {
        $handler = (object) ['type' => 'command', 'command' => $command];

        if ($timeout !== null) {
            $handler->timeout = $timeout;
        }

        $group = $matcher === null ? new stdClass : (object) ['matcher' => $matcher];
        $group->hooks = [$handler];

        return $group;
    }

    /**
     * Fusiona los hooks del agente en su archivo.
     *
     * Por evento: si ya hay un comando de LaraPack para este agente y este
     * evento, se pone al día (es suyo: lleva `--agent=`); si no, se añade.
     * Lo del proyecto no se toca, y sin cambios el archivo no se reescribe.
     *
     * @return array{target: string, action: string}
     */
    private static function installHooks(string $root, string $agent): array
    {
        $target = $root.'/'.self::HOOK_FILES[$agent];
        $existed = is_file($target);
        $config = self::readJson($target);
        $definition = self::hooks($root, $agent);
        $changed = false;

        foreach ($definition['top'] as $key => $value) {
            if (! isset($config->{$key})) {
                $config->{$key} = $value;
                $changed = true;
            }
        }

        if (! isset($config->hooks)) {
            $config->hooks = new stdClass;
        }

        if (! $config->hooks instanceof stdClass) {
            throw new RuntimeException("{$target}: `hooks` no es un objeto. Corrígelo a mano; no se toca.");
        }

        foreach ($definition['events'] as $event => $entry) {
            $entries = $config->hooks->{$event} ?? [];

            if (! is_array($entries)) {
                throw new RuntimeException("{$target}: `hooks.{$event}` no es una lista. Corrígelo a mano; no se toca.");
            }

            if (self::eventHasCurrent($config, $agent, $event, $entry)) {
                continue;
            }

            $replaced = false;

            foreach ($entries as $index => $existing) {
                if (self::ownsEntry($existing, $agent, $event)) {
                    $entries[$index] = $entry;
                    $replaced = true;

                    break;
                }
            }

            if (! $replaced) {
                $entries[] = $entry;
            }

            $config->hooks->{$event} = $entries;
            $changed = true;
        }

        if (! $changed) {
            return ['target' => $target, 'action' => 'unchanged'];
        }

        try {
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$target}: no se puede volver a escribir sin perder datos ({$e->getMessage()}). Añade los hooks a mano; no se toca.");
        }

        AgentHooks::writeInside($root, $target, $json."\n");

        return ['target' => $target, 'action' => $existed ? 'updated' : 'created'];
    }

    /**
     * Si el evento ya tiene exactamente la entrada de ahora.
     */
    private static function eventHasCurrent(stdClass $config, string $agent, string $event, stdClass $entry): bool
    {
        $hooks = isset($config->hooks) && $config->hooks instanceof stdClass ? $config->hooks : new stdClass;
        $entries = is_array($hooks->{$event} ?? null) ? $hooks->{$event} : [];

        foreach ($entries as $existing) {
            if (self::ownsEntry($existing, $agent, $event) && json_encode($existing) === json_encode($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si una entrada es la de LaraPack para ese agente y ese evento: algún
     * comando suyo llama a la guardia con `--agent=` y con la opción del
     * evento.
     */
    private static function ownsEntry(mixed $entry, string $agent, string $event): bool
    {
        $flag = self::flagOf($agent, $event);

        foreach (self::strings($entry) as $string) {
            if (! str_contains($string, 'larapack:guard --agent='.$agent)) {
                continue;
            }

            $flags = array_filter(['--pre', '--stop'], fn (string $known): bool => str_contains($string, $known));

            if ($flag === null ? $flags === [] : in_array($flag, $flags, true)) {
                return true;
            }
        }

        return false;
    }

    private static function flagOf(string $agent, string $event): ?string
    {
        foreach (self::hooks('', $agent)['events'] as $name => $entry) {
            if ($name !== $event) {
                continue;
            }

            foreach (self::strings($entry) as $string) {
                if (str_contains($string, '--pre')) {
                    return '--pre';
                }

                if (str_contains($string, '--stop')) {
                    return '--stop';
                }
            }
        }

        return null;
    }

    /**
     * Todas las cadenas de una entrada, a cualquier profundidad.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value) && ! is_object($value)) {
            return [];
        }

        $strings = [];

        foreach ((array) $value as $item) {
            $strings = [...$strings, ...self::strings($item)];
        }

        return $strings;
    }

    /**
     * Las reglas del agente: el bloque de AGENTS.md, en su archivo.
     *
     * @return array{target: string, action: string}
     */
    private static function installRules(string $root, string $agent, string $skillPath): array
    {
        $relative = self::RULE_FILES[$agent];

        if (! isset(self::FRONTMATTER[$agent])) {
            return AgentHooks::installAgentsBlock($root, $skillPath, $relative);
        }

        // Un archivo que es sólo de LaraPack: entero. Si existe sin los
        // marcadores, lo escribió otro y se deja.
        $target = $root.'/'.$relative;
        $contents = self::FRONTMATTER[$agent].AgentHooks::BEGIN."\n".AgentHooks::agentsBlock($skillPath).AgentHooks::END."\n";

        if (is_file($target)) {
            $current = (string) file_get_contents($target);

            if (! str_contains($current, AgentHooks::BEGIN)) {
                return ['target' => $target, 'action' => 'kept'];
            }

            if ($current === $contents) {
                return ['target' => $target, 'action' => 'unchanged'];
            }

            AgentHooks::writeInside($root, $target, $contents);

            return ['target' => $target, 'action' => 'updated'];
        }

        AgentHooks::writeInside($root, $target, $contents);

        return ['target' => $target, 'action' => 'created'];
    }

    /**
     * Se decodifica a objetos, como `.claude/settings.json`: un `{}` del
     * proyecto no puede volver como `[]`.
     */
    private static function readJson(string $target): stdClass
    {
        if (! is_file($target) || trim((string) file_get_contents($target)) === '') {
            return new stdClass;
        }

        $decoded = json_decode((string) file_get_contents($target), false);

        if (! $decoded instanceof stdClass) {
            throw new RuntimeException("{$target} no es un objeto JSON válido. Corrígelo a mano; no se sobrescribe.");
        }

        return $decoded;
    }
}
