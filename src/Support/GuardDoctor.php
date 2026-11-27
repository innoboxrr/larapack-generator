<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Qué capas de la guardia tiene un proyecto, y la orden para las que faltan.
 *
 * La guardia es por capas, y cada una frena a quien la ejecuta:
 *
 * - **Hook en vivo** (Claude Code, Cursor, Codex, Gemini CLI, Copilot,
 *   Windsurf): el agente recibe el motivo con el archivo delante. Sólo frena
 *   a ese agente, y sólo si el proyecto tiene sus hooks.
 * - **Git** (`.githooks/` con `core.hooksPath`): frena el commit y el push de
 *   cualquiera, agente o persona. Es configuración local de cada clon.
 * - **CI** (`larapack:ci` y la rama protegida): lo único que no depende de la
 *   máquina de nadie. La protección de la rama no se puede ver desde aquí:
 *   se dice, y se dice cómo comprobarla.
 *
 * El diagnóstico falla (código 1) si falta una de las dos capas que no
 * dependen del agente: la de git o el workflow de la CI. Las de cada agente
 * se informan; cuáles hacen falta depende de con qué agentes se trabaje.
 *
 * @phpstan-type Layer array{id: string, agent: string, layer: string, status: 'ok'|'missing'|'partial'|'outdated'|'unknown'|'warning', detail: string, fix: string|null}
 * @phpstan-type Diagnosis array{ok: bool, root: string, layers: list<Layer>}
 */
final class GuardDoctor
{
    /**
     * @return Diagnosis
     */
    public static function diagnose(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $skill = Guard::commandPrefix($root).' larapack:skill';
        $layers = [self::git($root, $skill), self::ci($root), self::protection($root), self::claude($root, $skill)];

        foreach (AgentIntegrations::AGENTS as $agent) {
            $layers[] = self::agent($root, $agent, $skill);
        }

        $aider = self::aider($root);

        if ($aider !== null) {
            $layers[] = $aider;
        }

        $required = array_filter($layers, fn (array $layer): bool => in_array($layer['id'], ['git', 'ci'], true));

        return [
            'ok' => array_filter($required, fn (array $layer): bool => $layer['status'] !== 'ok') === [],
            'root' => $root,
            'layers' => $layers,
        ];
    }

    /**
     * @param  Diagnosis  $diagnosis
     */
    public static function render(array $diagnosis): string
    {
        $labels = ['ok' => 'ok', 'missing' => 'falta', 'partial' => 'a medias', 'outdated' => 'desfasado', 'unknown' => '¿?', 'warning' => 'ojo'];
        $lines = ['LaraPack: las capas de la guardia en '.Guard::inline($diagnosis['root']), ''];

        foreach ($diagnosis['layers'] as $layer) {
            $lines[] = '  '.self::pad('['.$labels[$layer['status']].']', 11).' '.self::pad($layer['agent'], 16).' '.self::pad($layer['layer'], 14).' '.$layer['detail'];

            if ($layer['fix'] !== null && $layer['status'] !== 'ok') {
                $lines[] = str_repeat(' ', 45).'→ '.$layer['fix'];
            }
        }

        $lines[] = '';
        $lines[] = $diagnosis['ok']
            ? 'Las capas que no dependen del agente (git y CI) están. Las de cada agente sólo hacen falta para los agentes con los que se trabaja.'
            : 'Falta una de las capas que no dependen del agente (git o CI): sin ella, lo que no frene el hook de un agente no lo frena nada antes de la rama.';

        return implode("\n", $lines);
    }

    /**
     * Rellena por caracteres y no por bytes: sprintf() descuadraba «¿?».
     */
    private static function pad(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - mb_strlen($text, 'UTF-8')));
    }

    /**
     * @return Layer
     */
    private static function git(string $root, string $skill): array
    {
        $status = GitHooks::status($root);
        $row = self::gitRow(...);

        if (! $status['repository']) {
            return $row('missing', 'El proyecto no es un repositorio git.', 'git init && '.$skill.' --hooks');
        }

        if ($status['active']) {
            return $row('ok', 'pre-commit y pre-push corren larapack:guard'.($status['hooks_path'] === null ? '' : ' (core.hooksPath='.Guard::inline($status['hooks_path']).')').'.', null);
        }

        $describe = [];

        foreach ($status['hooks'] as $hook => $state) {
            $describe[] = $hook.': '.match ($state) {
                'missing' => 'no existe donde git lo busca',
                'not-executable' => 'no es ejecutable',
                'foreign' => 'es del proyecto y no llama a LaraPack',
                default => 'bien',
            };
        }

        $versioned = is_file(rtrim(GitHooks::top($root) ?? $root, '/').'/'.GitHooks::DIRECTORY.'/pre-commit');
        $fix = $versioned && $status['hooks_path'] === null
            ? 'git config core.hooksPath '.GitHooks::DIRECTORY.'   (cada clon; o '.$skill.' --hooks)'
            : $skill.' --hooks';

        return $row(in_array('ours', $status['hooks'], true) || in_array('chained', $status['hooks'], true) ? 'partial' : 'missing', implode('; ', $describe).'.', $fix);
    }

    /**
     * @param  'ok'|'missing'|'partial'  $state
     * @return Layer
     */
    private static function gitRow(string $state, string $detail, ?string $fix): array
    {
        return ['id' => 'git', 'agent' => 'Todos (git)', 'layer' => 'hook de git', 'status' => $state, 'detail' => $detail, 'fix' => $fix];
    }

    /**
     * El workflow que corre `verify --strict`: el de `larapack:ci` u otro
     * que haga lo mismo.
     *
     * @return Layer
     */
    private static function ci(string $root): array
    {
        $base = GitHooks::top($root) ?? $root;
        $workflows = array_merge(glob($base.'/.github/workflows/*.yml') ?: [], glob($base.'/.github/workflows/*.yaml') ?: []);
        $found = null;

        foreach ($workflows as $workflow) {
            $contents = (string) file_get_contents($workflow);

            if (str_contains($contents, 'larapack:verify') && str_contains($contents, '--strict')) {
                $found = $workflow;

                break;
            }
        }

        return [
            'id' => 'ci',
            'agent' => 'Todos (CI)',
            'layer' => 'workflow',
            'status' => $found === null ? 'missing' : 'ok',
            'detail' => $found === null
                ? 'Ningún workflow corre larapack:verify --strict.'
                : Guard::inline((string) Guard::relative($base, $found)).' corre larapack:verify --strict.',
            'fix' => $found === null ? Guard::commandPrefix($root).' larapack:ci --owner=@dueño' : null,
        ];
    }

    /**
     * La rama protegida: desde aquí no se ve. Se dice cómo comprobarla.
     *
     * @return Layer
     */
    private static function protection(string $root): array
    {
        return [
            'id' => 'branch-protection',
            'agent' => 'Todos (CI)',
            'layer' => 'rama protegida',
            'status' => 'unknown',
            'detail' => 'No se puede ver desde el disco: sin la protección que exige el check «'.CiGate::JOB.'», la CI avisa pero no impide.',
            'fix' => Guard::commandPrefix($root).' larapack:ci   (imprime la orden que la protege, y cómo comprobarla)',
        ];
    }

    /**
     * @return Layer
     */
    private static function claude(string $root, string $skill): array
    {
        $status = AgentHooks::settingsStatus($root);
        $missing = array_keys(array_filter($status, fn (string $state): bool => $state === 'missing'));
        $outdated = array_keys(array_filter($status, fn (string $state): bool => $state === 'outdated'));

        return [
            'id' => AgentAdapters::CLAUDE,
            'agent' => AgentIntegrations::NAMES[AgentAdapters::CLAUDE],
            'layer' => 'hook en vivo',
            'status' => match (true) {
                count($missing) === count($status) => 'missing',
                $missing !== [] => 'partial',
                $outdated !== [] => 'outdated',
                default => 'ok',
            },
            'detail' => match (true) {
                count($missing) === count($status) => 'Sin hooks de LaraPack en '.AgentHooks::SETTINGS.'.',
                $missing !== [] => 'Faltan '.implode(', ', $missing).' en '.AgentHooks::SETTINGS.'.',
                $outdated !== [] => 'El filtro de '.implode(', ', $outdated).' es de una versión anterior: no ve `git commit --no-verify`.',
                default => 'PostToolUse, Stop y PreToolUse en '.AgentHooks::SETTINGS.'.',
            },
            'fix' => $skill.' --hooks',
        ];
    }

    /**
     * @return Layer
     */
    private static function agent(string $root, string $agent, string $skill): array
    {
        $status = AgentIntegrations::status($root, $agent);
        $hooks = AgentIntegrations::HOOK_FILES[$agent];
        $rules = AgentIntegrations::RULE_FILES[$agent];

        return [
            'id' => $agent,
            'agent' => AgentIntegrations::NAMES[$agent],
            'layer' => 'hook en vivo',
            'status' => match (true) {
                $status['hooks'] === 'installed' && $status['rules'] === 'installed' => 'ok',
                $status['hooks'] === 'outdated' => 'outdated',
                $status['hooks'] === 'missing' && $status['rules'] === 'missing' => 'missing',
                default => 'partial',
            },
            'detail' => sprintf(
                'hooks en %s: %s; reglas en %s: %s.',
                $hooks,
                ['installed' => 'sí', 'outdated' => 'de otra versión', 'missing' => 'no'][$status['hooks']],
                $rules,
                $status['rules'] === 'installed' ? 'sí' : 'no'
            ),
            'fix' => $skill.' --agents='.$agent,
        ];
    }

    /**
     * Aider comitea solo, y por defecto con `--no-verify`: el hook de git no
     * lo ve salvo que el proyecto lo pida.
     *
     * @return Layer|null
     */
    private static function aider(string $root): ?array
    {
        $config = rtrim($root, '/').'/.aider.conf.yml';

        if (! is_file($config)) {
            return null;
        }

        $verifies = preg_match('/^\s*git-commit-verify\s*:\s*true\b/mi', (string) file_get_contents($config)) === 1;

        return [
            'id' => 'aider',
            'agent' => 'Aider',
            'layer' => 'hook de git',
            'status' => $verifies ? 'ok' : 'warning',
            'detail' => $verifies
                ? '.aider.conf.yml pide git-commit-verify: el hook de git corre en sus commits.'
                : 'Aider comitea con --no-verify salvo que .aider.conf.yml diga git-commit-verify: true; sin eso, sólo lo frena la CI.',
            'fix' => $verifies ? null : 'añade «git-commit-verify: true» a .aider.conf.yml',
        ];
    }
}
