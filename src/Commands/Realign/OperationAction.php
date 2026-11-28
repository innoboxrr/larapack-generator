<?php

namespace Innoboxrr\LarapackGenerator\Commands\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationRunner;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Las operaciones que escriben (`adopt`, `apply`, `infer`, `except`): valida
 * lo que pide la línea de órdenes y delega en OperationRunner.
 */
final class OperationAction extends Action
{
    /**
     * @param  list<string>  $targets
     */
    public function run(array $targets): int
    {
        $action = $this->name;

        // apply reescribe código: sin ids, sólo si se pide en voz alta.
        if ($action === 'apply' && $targets === [] && ! $this->input->getOption('deterministic')) {
            return $this->fail('apply reescribe código: nombra los pasos (larapack:realign apply <id>…) o pide todos los deterministas con --deterministic.');
        }

        if ($action === 'apply' && $targets !== [] && $this->input->getOption('deterministic')) {
            return $this->fail('--deterministic es para todos los pasos: no se combina con ids.');
        }

        $options = [];

        foreach ((array) $this->input->getOption('option') as $pair) {
            $pair = (string) $pair;

            if (! str_contains($pair, '=')) {
                return $this->fail("--option tiene que ser clave=valor: {$pair}.");
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);

            // Una clave que la operación no lee es un error, como una opción:
            // ignorarla haría creer que hizo algo.
            $known = RealignActions::ALL[$action]['keys'];

            if (! Operations::overridden($action) && ! isset($known[$key])) {
                return $this->fail("`{$action}` no usa --option {$key}.",
                    $known === [] ? "{$action} no lee ninguna clave." : 'Claves de '.$action.': '.implode(', ', array_keys($known)).' (larapack:realign help '.$action.').',
                );
            }

            $options[$key] = $value;
        }

        $report = (new OperationRunner($this->root))->run($action, $targets, $this->scopePaths(), (bool) $this->input->getOption('dry-run'), (bool) $this->input->getOption('allow-dirty'), $options,
            array_values(array_map('strval', (array) $this->input->getOption('target'))));
        $ok = $report['result']['skipped'] === [];

        if ($this->wantsJson()) {
            $this->json(['format_version' => RealignState::FORMAT_VERSION, 'ok' => $ok, 'action' => $action, 'dry_run' => $report['dry_run'], 'steps' => $report['steps']] + $report['result'] + ['commit' => $report['commit']]);

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        foreach (['fixed' => $report['dry_run'] ? 'cambiaría' : 'cambiado', 'skipped' => 'saltado', 'unchanged' => 'sin cambios'] as $key => $label) {
            foreach ($report['result'][$key] as $item) {
                $this->output->writeln(sprintf('  %-11s %s: %s', $label, OutputFormatter::escape((string) ($item['file'] ?? '-')), OutputFormatter::escape((string) $item['reason'])));
            }
        }

        foreach ($report['result']['notes'] as $note) {
            $this->output->writeln('  <comment>'.OutputFormatter::escape($note).'</comment>');
        }

        $this->output->writeln(sprintf("\n  %d %s, %d saltados, %d sin cambios. Comprueba cada paso con larapack:realign check <id>.",
            count($report['result']['fixed']), $report['dry_run'] ? 'cambiarían' : 'cambiados', count($report['result']['skipped']), count($report['result']['unchanged'])));

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
