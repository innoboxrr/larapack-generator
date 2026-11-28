<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use RuntimeException;

/**
 * Lo común a toda operación que escribe, para que cada implementación sólo
 * se ocupe de lo suyo:
 *
 *  1. Resuelve los pasos: los nombrados, o todos los pendientes de los tipos
 *     que la operación sabe hacer (acotados a --package). Un paso de otro
 *     tipo, uno ya hecho o uno bloqueado por otro se rechaza con su razón.
 *  2. Sin `--dry-run`, exige el árbol git limpio —fuera de
 *     `.larapack/realign/`, que es estado y no código— para que todo lo que
 *     haga se pueda deshacer con git. `--allow-dirty` lo salta, dicho.
 *  3. Llama a la operación y anota en la historia de cada paso lo que hizo.
 *     No lo da por hecho: eso lo decide `check`, con su `done_when`.
 *
 * Con `--dry-run`, `apply` y `adopt` corren de verdad sobre una copia
 * temporal del proyecto (Simulation): un paso escribe lo que lee el
 * siguiente, y la simulación tiene que encadenarse igual que la corrida.
 */
final class OperationRunner
{
    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $targets  ids de paso; vacío, todos los que la operación sabe hacer
     * @param  list<string>  $packages  rutas de ámbito a las que acotar (`.` es el núcleo); vacío, todos
     * @param  array<string, string>  $options
     * @param  list<string>  $only  `--target`: sólo estos elementos de los pasos (lo entiende `except`)
     * @return array{action: string, dry_run: bool, steps: list<string>, result: array{fixed: list<array<string, mixed>>, skipped: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, notes: list<string>}}
     */
    public function run(string $action, array $targets, array $packages, bool $dryRun, bool $allowDirty, array $options = [], array $only = []): array
    {
        $operation = Operations::for($action);
        $state = new RealignState($this->root);
        // Cada corrida compara con lo que hay ahora, no con lo de otra.
        ScopeReference::forget();
        $plan = $state->plan();

        if ($operation instanceof PendingOperation) {
            $operation->run(new OperationContext($this->root, $state, [], $dryRun, $options));
        }

        if ($plan === null) {
            throw new RuntimeException('No hay plan: corre `larapack:realign diagnose` y `larapack:realign plan` primero.');
        }

        $steps = $this->steps($operation, $plan, $targets, $packages);

        if (! $dryRun && ! $allowDirty) {
            $this->ensureClean();
        }

        $result = $dryRun && in_array($action, Simulation::ACTIONS, true) && ! Operations::overridden($action)
            ? Simulation::run($this->root, $operation, $steps, $options, $only)
            : $operation->run(new OperationContext($this->root, $state, $steps, $dryRun, $options, $only))->toArray();
        $ids = array_map(fn (array $step): string => (string) $step['id'], $steps);

        if (! $dryRun) {
            $now = gmdate('c');

            foreach ($plan['steps'] as $index => $step) {
                if (in_array($step['id'] ?? null, $ids, true)) {
                    $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'applied', 'detail' => sprintf(
                        '%s: %d cambiados, %d saltados, %d sin cambios.',
                        $action,
                        count($result['fixed']),
                        count($result['skipped']),
                        count($result['unchanged'])
                    )];
                }
            }

            $state->savePlan($plan);
        }

        return ['action' => $action, 'dry_run' => $dryRun, 'steps' => $ids, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $targets
     * @param  list<string>  $packages
     * @return list<array<string, mixed>>
     */
    private function steps(RealignOperation $operation, array $plan, array $targets, array $packages): array
    {
        $byId = [];

        foreach ((array) ($plan['steps'] ?? []) as $step) {
            if (is_array($step) && is_string($step['id'] ?? null)) {
                $byId[$step['id']] = $step;
            }
        }

        $handles = $operation->handles();
        $selected = [];

        if ($targets === []) {
            foreach ($byId as $step) {
                if (in_array($step['type'] ?? null, $handles, true)
                    && ($packages === [] || in_array($step['scope']['path'] ?? null, $packages, true))
                    && Planner::effectiveStatus($step, $byId) !== Planner::DONE
                    && Planner::effectiveStatus($step, $byId) !== Planner::BLOCKED
                    && self::decidedFor($step, $operation->name())) {
                    $selected[] = $step;
                }
            }

            if ($selected === []) {
                throw new RuntimeException('No hay pasos pendientes que `'.$operation->name().'` sepa hacer'.($packages === [] ? '' : ' en esos paquetes').'.');
            }

            return $selected;
        }

        foreach ($targets as $id) {
            $step = $byId[$id] ?? null;

            if ($step === null) {
                throw new RuntimeException("No hay un paso {$id} en el plan. `larapack:realign status` los lista.");
            }

            if (! in_array($step['type'] ?? null, $handles, true)) {
                throw new RuntimeException("El paso {$id} es de tipo ".($step['type'] ?? '?').', y `'.$operation->name().'` sólo hace: '.implode(', ', $handles).'.');
            }

            $status = Planner::effectiveStatus($step, $byId);

            if ($status === Planner::DONE) {
                throw new RuntimeException("El paso {$id} ya está hecho.");
            }

            if ($status === Planner::BLOCKED) {
                throw new RuntimeException("El paso {$id} espera a: ".implode(', ', array_map('strval', (array) ($step['depends_on'] ?? []))).'.');
            }

            $selected[] = $step;
        }

        return $selected;
    }

    /**
     * Sin ids, una decisión sólo entra si se decidió una opción que ejecuta
     * esta operación (`adopt` en `untracked-edited`, `restore` en
     * `missing`): lo que falta decidir no se hace por omisión, y tampoco lo
     * que se decidió hacer de otra forma. Lo que no es una decisión, siempre.
     *
     * @param  array<string, mixed>  $step
     */
    private static function decidedFor(array $step, string $action): bool
    {
        if (($step['category'] ?? null) !== Category::DECISION) {
            return true;
        }

        $chosen = $step['decision']['option'] ?? null;

        foreach ((array) ($step['options'] ?? []) as $option) {
            if (is_array($option) && ($option['key'] ?? null) === $chosen && is_string($option['run'] ?? null)) {
                return str_starts_with($option['run'], 'larapack:realign '.$action.' ');
            }
        }

        return false;
    }

    /**
     * El árbol tiene que estar limpio para que lo que se escriba se pueda
     * deshacer con git. Fuera de un repositorio git no hay cómo deshacerlo,
     * y también se exige --allow-dirty.
     */
    private function ensureClean(): void
    {
        $command = ['git', '-C', $this->root, 'status', '--porcelain', '--untracked-files=all', '--', '.', ':(exclude).larapack/realign'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo ejecutar git para comprobar que el árbol esté limpio. Con --allow-dirty se escribe igual.');
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException("{$this->root} no es un repositorio git: lo que se escriba no se podrá deshacer con git. Con --allow-dirty se escribe igual.");
        }

        $lines = array_values(array_filter(explode("\n", $output), fn (string $line): bool => trim($line) !== ''));

        if ($lines !== []) {
            $shown = array_slice($lines, 0, 10);

            throw new RuntimeException('El árbol git tiene cambios sin comitear ('.count($lines).'): '.implode('; ', array_map('trim', $shown))
                .(count($lines) > 10 ? '…' : '').'. Comítalos o guárdalos antes, para poder deshacer lo que escriba el realineamiento; --allow-dirty lo salta.');
        }
    }
}
