<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * La misma guardia hablada en el idioma de cada agente.
 *
 * `larapack:guard` decide siempre igual —qué se bloquea, qué se recuerda—, y
 * lo decide sobre una entrada con la forma de la de Claude Code:
 * `hook_event_name`, `tool_name`, `tool_input.file_path`, `tool_input.command`,
 * `cwd`, `session_id`, `stop_hook_active`. Lo que cambia de un agente a otro es
 * cómo llega la entrada y cómo hay que contestar para que el agente bloquee o
 * le enseñe el motivo al modelo. Eso, y sólo eso, vive aquí.
 *
 * Cada adaptador sigue el mecanismo que su agente documenta (la tabla de
 * «Qué frena a cada agente» en el README). Donde la documentación no dice algo,
 * se eligió lo que falla del lado de bloquear —una negación que el agente
 * entiende aunque no lea el mensaje— y nunca lo que dejaría pasar en silencio.
 *
 * @phpstan-type Verdict array{kind: 'pass'|'block'|'context'|'notice'|'failure', message: string}
 * @phpstan-type Reply array{code: int, stdout: string, stderr: string}
 */
final class AgentAdapters
{
    public const CLAUDE = 'claude';

    public const CODEX = 'codex';

    public const CURSOR = 'cursor';

    public const GEMINI = 'gemini';

    public const COPILOT = 'copilot';

    public const WINDSURF = 'windsurf';

    public const AGENTS = [self::CLAUDE, self::CODEX, self::CURSOR, self::GEMINI, self::COPILOT, self::WINDSURF];

    /**
     * Los nombres de evento de cada agente, en los de Claude Code.
     */
    private const EVENTS = [
        // Gemini CLI.
        'BeforeTool' => 'PreToolUse',
        'AfterTool' => 'PostToolUse',
        'AfterAgent' => 'Stop',
        // Cursor.
        'preToolUse' => 'PreToolUse',
        'beforeShellExecution' => 'PreToolUse',
        'postToolUse' => 'PostToolUse',
        'afterFileEdit' => 'PostToolUse',
        'stop' => 'Stop',
        'subagentStop' => 'Stop',
        // Windsurf (Cascade).
        'pre_write_code' => 'PreToolUse',
        'pre_run_command' => 'PreToolUse',
        'post_write_code' => 'PostToolUse',
    ];

    /**
     * El agente que manda una entrada, por su forma: cada uno trae un campo
     * que los otros no.
     *
     * Hace falta sin `--agent` porque Cursor importa los hooks de
     * `.claude/settings.json` y los ejecuta con SU entrada: sin reconocerla, el
     * hook de antes de la herramienta no veía la orden y dejaba pasar todo.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function detect(array $payload): string
    {
        return match (true) {
            array_key_exists('cursor_version', $payload) || array_key_exists('workspace_roots', $payload) => self::CURSOR,
            array_key_exists('agent_action_name', $payload) => self::WINDSURF,
            array_key_exists('toolName', $payload) || array_key_exists('sessionId', $payload) => self::COPILOT,
            in_array($payload['hook_event_name'] ?? null, ['BeforeTool', 'AfterTool', 'AfterAgent', 'BeforeAgent'], true) => self::GEMINI,
            array_key_exists('turn_id', $payload) => self::CODEX,
            default => self::CLAUDE,
        };
    }

    /**
     * La entrada del agente con la forma de la de Claude Code.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalise(string $agent, array $payload): array
    {
        $normalised = match ($agent) {
            self::CURSOR => self::fromCursor($payload),
            self::COPILOT => self::fromCopilot($payload),
            self::WINDSURF => self::fromWindsurf($payload),
            default => $payload,
        };

        $event = $normalised['hook_event_name'] ?? null;

        if (is_string($event) && isset(self::EVENTS[$event])) {
            $normalised['hook_event_name'] = self::EVENTS[$event];
        }

        return self::withPatchedFiles($normalised);
    }

    /**
     * Cursor: la orden de `beforeShellExecution` y el archivo de
     * `afterFileEdit` llegan sueltos; el directorio, como la primera raíz del
     * espacio de trabajo; y la segunda vuelta del hook de fin de turno, como
     * `loop_count`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function fromCursor(array $payload): array
    {
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];

        foreach (['command', 'file_path'] as $field) {
            if (! isset($input[$field]) && is_string($payload[$field] ?? null)) {
                $input[$field] = $payload[$field];
            }
        }

        $roots = is_array($payload['workspace_roots'] ?? null) ? array_values($payload['workspace_roots']) : [];
        $cwd = $payload['cwd'] ?? ($input['cwd'] ?? ($roots[0] ?? getenv('CURSOR_PROJECT_DIR')));

        return [
            'hook_event_name' => $payload['hook_event_name'] ?? null,
            'tool_name' => $payload['tool_name'] ?? null,
            'tool_input' => $input,
            'cwd' => is_string($cwd) ? $cwd : null,
            'session_id' => $payload['conversation_id'] ?? ($payload['session_id'] ?? null),
            'stop_hook_active' => is_numeric($payload['loop_count'] ?? null) && (int) $payload['loop_count'] > 0,
        ];
    }

    /**
     * GitHub Copilot (CLI y agente en la nube), en su formato propio: el
     * evento no viene en la entrada —lo dice la opción del comando, que es
     * una por evento— y los argumentos de la herramienta pueden venir como
     * texto JSON. `bash` trae `command`; `edit` y `create`, la ruta.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function fromCopilot(array $payload): array
    {
        $arguments = $payload['toolArgs'] ?? [];

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : ['command' => $arguments];
        }

        $arguments = is_array($arguments) ? $arguments : [];
        $tool = is_string($payload['toolName'] ?? null) ? $payload['toolName'] : null;
        $input = [];

        foreach (['path', 'file_path', 'filePath', 'filename'] as $field) {
            if (is_string($arguments[$field] ?? null)) {
                $input['file_path'] = $arguments[$field];

                break;
            }
        }

        if (is_string($arguments['command'] ?? null)) {
            $input['command'] = $arguments['command'];
        }

        return [
            'hook_event_name' => null,
            'tool_name' => match ($tool) {
                'bash' => 'Bash',
                'edit' => 'Edit',
                'create' => 'Write',
                default => $tool,
            },
            'tool_input' => $input,
            'cwd' => $payload['cwd'] ?? null,
            'session_id' => $payload['sessionId'] ?? null,
            'stop_hook_active' => ($payload['stop_hook_active'] ?? false) === true,
        ];
    }

    /**
     * Windsurf: el evento es `agent_action_name` y lo de la herramienta
     * viene en `tool_info` —`file_path` al escribir, `command_line` y `cwd`
     * al correr una orden—.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function fromWindsurf(array $payload): array
    {
        $info = is_array($payload['tool_info'] ?? null) ? $payload['tool_info'] : [];
        $input = [];

        if (is_string($info['file_path'] ?? null)) {
            $input['file_path'] = $info['file_path'];
        }

        if (is_string($info['command_line'] ?? null)) {
            $input['command'] = $info['command_line'];
        }

        return [
            'hook_event_name' => $payload['agent_action_name'] ?? null,
            'tool_name' => isset($input['command']) ? 'Bash' : 'Write',
            'tool_input' => $input,
            'cwd' => $info['cwd'] ?? null,
            'session_id' => $payload['trajectory_id'] ?? null,
        ];
    }

    /**
     * Los archivos de un parche de Codex (`apply_patch`), como archivos
     * editados.
     *
     * Codex edita con un parche: llega como `tool_input.command` con
     * `*** Begin Patch`, o dentro de una orden de shell (`apply_patch <<'EOF'`).
     * Sin esto, la guardia lo leía como una orden y no veía qué archivos
     * tocaba —ni el candado de las excepciones—. Un parche suelto deja de ser
     * una orden; uno dentro de una orden de shell se queda también como orden.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withPatchedFiles(array $payload): array
    {
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
        $patch = null;

        foreach (['command', 'patch', 'input'] as $field) {
            if (is_string($input[$field] ?? null) && str_contains($input[$field], '*** Begin Patch')) {
                $patch = $field;

                break;
            }
        }

        if ($patch === null) {
            return $payload;
        }

        $files = self::patchedFiles((string) $input[$patch]);
        $standalone = str_starts_with(ltrim((string) $input[$patch]), '*** Begin Patch');

        if ($standalone) {
            unset($input[$patch]);
        }

        $edits = is_array($input['edits'] ?? null) ? $input['edits'] : [];

        foreach ($files as $file) {
            $edits[] = ['file_path' => $file];
        }

        $input['edits'] = $edits;
        $payload['tool_input'] = $input;

        return $payload;
    }

    /**
     * Las rutas de las cabeceras de un parche de Codex: las que añade,
     * actualiza o borra, y el destino de un `*** Move to:`.
     *
     * @return list<string>
     */
    public static function patchedFiles(string $patch): array
    {
        preg_match_all('/^\*\*\* (?:Add File|Update File|Delete File|Move to): *(.+?)\s*$/m', $patch, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * La respuesta que entiende el agente para un veredicto.
     *
     * - pass: nada que decir.
     * - block: frenar —o, si el evento ya ocurrió, devolverle el motivo al
     *   modelo— con el mensaje.
     * - context: un recordatorio que llega al modelo sin bloquear.
     * - notice: un aviso para la persona; el turno termina.
     * - failure: la guardia no pudo comprobar; no bloquea, salvo donde el
     *   agente trata cualquier fallo como negación (Copilot antes de la
     *   herramienta, que por eso sale con 0).
     *
     * @param  'pre'|'edit'|'stop'  $event
     * @param  Verdict  $verdict
     * @return Reply
     */
    public static function reply(string $agent, string $event, array $verdict): array
    {
        $message = $verdict['message'];

        if ($verdict['kind'] === 'pass') {
            return self::out(0);
        }

        if ($verdict['kind'] === 'failure') {
            return self::out($agent === self::COPILOT && $event === 'pre' ? 0 : 1, '', $message);
        }

        return match ($agent) {
            self::CURSOR => self::cursor($event, $verdict['kind'], $message),
            self::COPILOT => self::copilot($event, $verdict['kind'], $message),
            self::WINDSURF => self::windsurf($event, $verdict['kind'], $message),
            self::GEMINI => self::claudeLike('AfterTool', $verdict['kind'], $message, false),
            self::CODEX => self::claudeLike('PostToolUse', $verdict['kind'], $message, $event === 'edit'),
            default => self::claudeLike('PostToolUse', $verdict['kind'], $message, false),
        };
    }

    /**
     * Claude Code, Codex y Gemini CLI: código 2 y el motivo por la salida de
     * error para bloquear (antes de la herramienta la impide, después le da
     * el motivo al modelo, al terminar el turno lo hace seguir); con 0, un
     * JSON con `additionalContext` o `systemMessage`.
     *
     * Codex documenta para PostToolUse `{"decision":"block","reason":…}` y no
     * dice qué hace con el código 2 ahí: después de editar se le contesta con
     * el JSON.
     *
     * @return Reply
     */
    private static function claudeLike(string $postEvent, string $kind, string $message, bool $jsonBlock): array
    {
        return match ($kind) {
            'block' => $jsonBlock
                ? self::out(0, self::json(['decision' => 'block', 'reason' => $message]))
                : self::out(2, '', $message),
            'context' => self::out(0, self::json(['hookSpecificOutput' => ['hookEventName' => $postEvent, 'additionalContext' => $message]])),
            default => self::out(0, self::json(['systemMessage' => $message])),
        };
    }

    /**
     * Cursor: antes de la herramienta, `permission: deny` con el mensaje para
     * el modelo y código 2 (que Cursor también lee como negación, por si no
     * lee el JSON); después de editar, `additional_context`, que es lo único
     * de postToolUse que llega al modelo; al terminar el turno,
     * `followup_message`, que Cursor envía como el siguiente mensaje.
     *
     * @return Reply
     */
    private static function cursor(string $event, string $kind, string $message): array
    {
        if ($event === 'pre' && $kind === 'block') {
            return self::out(2, self::json([
                'permission' => 'deny',
                'user_message' => 'LaraPack lo impidió: '.strtok($message, "\n"),
                'agent_message' => $message,
            ]));
        }

        if ($event === 'stop') {
            return $kind === 'block'
                ? self::out(0, self::json(['followup_message' => $message]))
                : self::out(0, '{}', $message);
        }

        return self::out(0, self::json(['additional_context' => $message]));
    }

    /**
     * Copilot, en su formato propio: `permissionDecision: deny` antes de la
     * herramienta; `additionalContext` después; `decision: block` al
     * terminar, que fuerza otra vuelta con el motivo como instrucción.
     *
     * @return Reply
     */
    private static function copilot(string $event, string $kind, string $message): array
    {
        return match (true) {
            $event === 'pre' => self::out(0, self::json(['permissionDecision' => 'deny', 'permissionDecisionReason' => $message])),
            $event === 'stop' && $kind === 'block' => self::out(0, self::json(['decision' => 'block', 'reason' => $message])),
            $event === 'stop' => self::out(0, '', $message),
            default => self::out(0, self::json(['additionalContext' => $message])),
        };
    }

    /**
     * Windsurf no tiene canal JSON: un hook de antes bloquea con 2 y el
     * motivo por la salida de error. Uno de después ya no puede bloquear, y
     * lo que escribe sólo lo ve la persona (con `show_output`).
     *
     * @return Reply
     */
    private static function windsurf(string $event, string $kind, string $message): array
    {
        return $event === 'pre' && $kind === 'block'
            ? self::out(2, '', $message)
            : self::out(0, $message);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function json(array $document): string
    {
        return (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return Reply
     */
    private static function out(int $code, string $stdout = '', string $stderr = ''): array
    {
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
