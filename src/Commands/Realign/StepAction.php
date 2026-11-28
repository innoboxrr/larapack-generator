<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\ProjectTests;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Lo común de `next` y `show`: un paso con todo lo necesario para hacerlo
 * sin contexto.
 */
abstract class StepAction extends Action
{
    /**
     * Un paso con todo lo necesario para hacerlo sin contexto: lo que da
     * `next` y `show`, esté como esté.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>|null  $meanwhile
     */
    protected function describe(array $plan, array $byId, array $step, ?array $meanwhile = null): int
    {
        $status = Planner::effectiveStatus($step, $byId);
        $blockedBy = Planner::blockedBy($step, $byId);
        $how = match ($step['category']) {
            Category::GUIDED => ['playbook' => Playbooks::resolve((string) ($step['playbook'] ?? Playbooks::GENERIC)), 'instructions' => Playbooks::render($step)],
            Category::DECISION => $this->decisionHow($step),
            default => ['command' => $step['command'] ?? null, 'available' => $this->commandAvailable((string) ($step['command'] ?? ''))]
                // Lo que la orden salte se hace a mano: el playbook del tipo.
                + (isset($step['playbook']) ? ['fallback' => ['playbook' => Playbooks::resolve((string) $step['playbook']), 'instructions' => Playbooks::render($step)]] : []),
        };
        $check = 'larapack:realign check '.$step['id'];
        $tests = ['required' => StepTypes::changesBehaviour($step), 'command' => (new ProjectTests($this->root))->command(), 'tested' => $step['tested'] ?? null];
        $meanwhile = $meanwhile === null ? null : ['id' => $meanwhile['id'], 'title' => $meanwhile['title'], 'category' => $meanwhile['category'], 'show' => 'larapack:realign next '.$meanwhile['id']];

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => $this->name, 'done' => false,
                'step' => $step + ['effective_status' => $status], 'how' => $how, 'check' => $check, 'tests' => $tests,
                'done_when' => Playbooks::describeCondition((array) $step['done_when']), 'blocked_by' => $blockedBy, 'meanwhile' => $meanwhile]);

            return Command::SUCCESS;
        }

        $this->output->writeln(sprintf('  Paso %s/%d: <info>%s</info>', $step['order'] ?? '?', count((array) $plan['steps']), $step['id']));
        $this->output->writeln('  '.OutputFormatter::escape((string) $step['title']).' · '.(Category::LABELS[$step['category']] ?? $step['category']).' · riesgo '.$step['risk']['level'].' · '.($step['reversible'] ? 'reversible' : 'no reversible'));
        $this->output->writeln('  '.OutputFormatter::escape((string) $step['description']));
        $this->output->writeln('  Riesgo: '.OutputFormatter::escape((string) $step['risk']['why']));

        if (Planner::finished($step) || $blockedBy !== []) {
            $this->output->writeln('  <comment>Estado: '.$status.($blockedBy === [] ? '' : '; espera a: '.implode(', ', $blockedBy)).'</comment>');
        }

        if (is_array($step['deferred'] ?? null)) {
            $this->output->writeln('  <comment>Aplazado: '.OutputFormatter::escape((string) ($step['deferred']['note'] ?? '')).'</comment> (larapack:realign undefer '.$step['id'].')');
        }

        if (is_array($step['escalated'] ?? null)) {
            $this->output->writeln('  <comment>Pasado al dueño: '.OutputFormatter::escape((string) ($step['escalated']['note'] ?? '')).'</comment>');
        }

        if ($step['category'] === Category::GUIDED) {
            $this->output->writeln("\n".OutputFormatter::escape((string) $how['instructions']));
        } elseif ($step['category'] === Category::DECISION) {
            $this->writeDecision($how);

            if (isset($how['instructions'])) {
                $this->output->writeln("\n".OutputFormatter::escape((string) $how['instructions']));
            }

            $this->writeFiles($step);
        } else {
            $this->output->writeln("\n  Ejecuta: ".OutputFormatter::escape((string) $how['command']).' (primero con --dry-run)'.($how['available'] ? '' : '  <comment>(no disponible en esta versión: hazlo a mano como describe el paso)</comment>'));
            $this->writeFiles($step);

            if (isset($how['fallback'])) {
                $this->output->writeln("\n  Lo que la orden salte (y diga por qué) se hace a mano así:\n\n".OutputFormatter::escape((string) $how['fallback']['instructions']));
            }
        }

        $this->output->writeln("\n  Está hecho cuando ".OutputFormatter::escape(Playbooks::describeCondition((array) $step['done_when'])));
        $this->output->writeln("  Compruébalo con: {$check}");

        if ($tests['required']) {
            $this->output->writeln('  <comment>Cambia comportamiento:</comment> corre las pruebas del proyecto antes y después. '.($tests['command'] === null
                ? 'Con ellas en verde: '.$check.' --tested="<la orden que corriste>"; sin ellas, check lo deja sin probar (untested).'
                : 'check corre la orden del proyecto ('.OutputFormatter::escape($tests['command']).') y sólo lo da por hecho si pasa.'));
        }

        if ($meanwhile !== null) {
            $this->output->writeln("\n  Esta decisión es del dueño: preséntale las opciones y espera a que decida. Mientras, sigue con lo que no depende de ella: "
                .$meanwhile['show'].' — '.OutputFormatter::escape((string) $meanwhile['title']));
        }

        return Command::SUCCESS;
    }

    /**
     * Lo que dice `next` de una decisión ya tomada: la orden de la opción y,
     * si la opción tiene playbook, sus instrucciones rellenadas.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function decided(array $step): array
    {
        $option = Planner::option($step, (string) ($step['decision']['option'] ?? ''));

        return ['decided' => $step['decision'], 'then' => $option['run'] ?? null]
            + $this->instructions($step, is_string($option['playbook'] ?? null) ? $option['playbook'] : null);
    }

    /**
     * Lo que dice `next` de una decisión: sus opciones si falta decidir, lo
     * que pide la opción si ya se decidió y, decidida por elemento, qué se
     * decidió para cada uno y qué falta.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function decisionHow(array $step): array
    {
        $mixed = is_array($step['decisions'] ?? null) && $step['decisions'] !== [];
        $undecided = Navigation::undecided($step);

        if ($undecided === [] && ! $mixed) {
            return $this->decided($step);
        }

        $how = $undecided === []
            ? ['decided' => $step['decision'] ?? null, 'then' => null]
            : ['options' => $step['options'] ?? [], 'decide' => 'larapack:realign decide '.$step['id'].' <opción> --note="por qué"',
                'decide_target' => 'larapack:realign decide '.$step['id'].' <opción> --target=<archivo> --note="por qué"'];

        if ($mixed) {
            $byOption = Navigation::byOption($step);
            ksort($byOption);
            $then = [];

            foreach (array_keys($byOption) as $key) {
                $then[$key] = Planner::option($step, (string) $key)['run'] ?? null;
            }

            $how += ['decided_by_target' => $byOption, 'undecided' => array_values(array_diff($undecided, ['*'])), 'then_by_option' => $then];
        }

        return $how + $this->instructions($step, is_string($step['playbook'] ?? null) ? $step['playbook'] : null);
    }

    /**
     * @param  array<string, mixed>  $how
     */
    private function writeDecision(array $how): void
    {
        foreach ((array) ($how['decided_by_target'] ?? []) as $key => $files) {
            $run = $how['then_by_option'][$key] ?? null;
            $this->output->writeln("\n  Decidido <info>{$key}</info> para ".count((array) $files).': '.OutputFormatter::escape(implode(', ', array_slice((array) $files, 0, 10))).(count((array) $files) > 10 ? '…' : '')
                .(is_string($run) ? "\n    Ahora: ".OutputFormatter::escape($run).' (sólo toca esos)' : ''));
        }

        if (isset($how['options'])) {
            if (($how['undecided'] ?? []) !== []) {
                $this->output->writeln("\n  Faltan por decidir ".count($how['undecided']).': '.OutputFormatter::escape(implode(', ', array_slice($how['undecided'], 0, 10))).(count($how['undecided']) > 10 ? '…' : ''));
            }

            $this->output->writeln("\n  Opciones:");

            foreach ((array) $how['options'] as $option) {
                $this->output->writeln('    <info>'.$option['key'].'</info>: '.OutputFormatter::escape($option['label'].'. '.$option['consequences']));
            }

            $this->output->writeln("\n  Decide con: ".$how['decide']);
            $this->output->writeln('  O por elemento: '.$how['decide_target']);
        } elseif (array_key_exists('decided', $how) && ! isset($how['decided_by_target'])) {
            $this->output->writeln("\n  Decidido: ".OutputFormatter::escape((string) ($how['decided']['option'] ?? '')).($how['then'] === null ? '. Hazlo como dice la opción.' : '. Ahora: '.OutputFormatter::escape((string) $how['then'])));
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{playbook?: string, instructions?: string}
     */
    private function instructions(array $step, ?string $playbook): array
    {
        if ($playbook === null) {
            return [];
        }

        return ['playbook' => Playbooks::resolve($playbook), 'instructions' => Playbooks::render($step, $playbook)];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function writeFiles(array $step): void
    {
        $files = (array) ($step['files'] ?? []);

        if ($files === []) {
            return;
        }

        $this->output->writeln("\n  Archivos (".count($files).'):');

        foreach (array_slice($files, 0, 30) as $file) {
            $this->output->writeln('    '.OutputFormatter::escape((string) $file));
        }

        if (count($files) > 30) {
            $this->output->writeln('    … y '.(count($files) - 30).' más (--format=json los da todos).');
        }
    }

    /**
     * Si la orden de un paso determinista se puede ejecutar ya: una
     * operación sin implementación no.
     */
    private function commandAvailable(string $command): bool
    {
        if (preg_match('/^larapack:realign (\w+)/', $command, $match) === 1) {
            return Operations::available($match[1]);
        }

        return $command !== '';
    }
}
