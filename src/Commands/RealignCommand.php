<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Diagnosis;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationRunner;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `larapack:realign`: llevar de vuelta al contrato un proyecto que se
 * desvió, sin reconstruirlo. La guía completa está en docs/realign/README.md.
 *
 * Un solo comando con la acción como argumento, como `larapack:hand-rolled`
 * (list, approve, revoke), y no un comando por acción: todas comparten la
 * raíz, el estado (`.larapack/realign/`), --package y --format, y así las
 * operaciones que escriben se enchufan sin registrar un comando nuevo
 * (Operations). `larapack:realign` sin acción, o con `help`, lista lo que
 * hay.
 *
 * Las que no escriben código —sólo su estado— son seguras siempre:
 *
 *     diagnose   regenera el contrato aparte y clasifica cada archivo
 *     plan       del diagnóstico, los pasos ordenados y reanudables
 *     status     dónde se va
 *     next       el siguiente paso con todo lo necesario para hacerlo
 *     check <id> comprueba su done_when y lo marca hecho, o dice qué falta
 *     decide <id> <opción>  registra una decisión
 *
 * Las que escriben (adopt, apply, infer, except) tienen --dry-run, exigen el
 * árbol git limpio salvo --allow-dirty e informan fixed/skipped/unchanged
 * (OperationRunner).
 */
class RealignCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    private const READS = ['diagnose', 'plan', 'status', 'next', 'check', 'decide', 'help'];

    /**
     * Las opciones que acepta cada acción además de --root y --format. Una
     * opción que la acción no usa es un error: ignorarla haría creer que
     * hizo algo (un `diagnose --dry-run` que en verdad no simula nada).
     */
    private const ACCEPTS = [
        'diagnose' => ['package', 'jobs', 'no-cache'],
        'plan' => [],
        'status' => ['package'],
        'next' => ['package'],
        'check' => [],
        'decide' => ['note'],
        'help' => [],
        'write' => ['package', 'dry-run', 'allow-dirty', 'option'],
    ];

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:realign')
            ->setDescription('Realinea con el contrato un proyecto que se desvió: diagnose, plan, status, next, check, decide y las operaciones adopt, apply, infer y except')
            ->addArgument('action', InputArgument::OPTIONAL, 'diagnose, plan, status, next, check, decide, adopt, apply, infer, except o help', 'help')
            ->addArgument('targets', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'check: el id del paso; decide: el id y la opción; adopt/apply/infer/except: ids de paso (vacío, todos los que sepa hacer)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo este paquete local (directorio, ruta o nombre de Composer), o `app` para el núcleo. Repetible')
            ->addOption('jobs', null, InputOption::VALUE_REQUIRED, 'diagnose: cuántas regeneraciones a la vez', '4')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'diagnose: regenera aunque nada haya cambiado')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Operaciones que escriben: dice qué haría sin escribir nada')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Operaciones que escriben: escribe aunque el árbol git tenga cambios sin comitear (no se podrá deshacer con git)')
            ->addOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Operaciones que escriben: clave=valor para la operación. Repetible')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'decide: por qué se decide así (queda en el plan)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $action = strtolower((string) $input->getArgument('action'));
        $targets = array_values(array_map('strval', (array) $input->getArgument('targets')));
        $writes = in_array($action, Operations::ACTIONS, true);

        if (! $writes && ! in_array($action, self::READS, true)) {
            return $this->reportFailure($input, $output, "Acción desconocida: {$action}.", 'Disponibles: '.implode(', ', [...self::READS, ...Operations::ACTIONS]).'.');
        }

        $unexpected = $this->unexpectedOptions($input, $writes ? 'write' : $action);

        if ($unexpected !== []) {
            return $this->reportFailure($input, $output, "`{$action}` no usa ".implode(', ', array_map(fn (string $option): string => '--'.$option, $unexpected)).'.',
                $writes || $action === 'help' ? null : ($action === 'diagnose' ? 'diagnose no escribe código: no hay nada que simular.' : null));
        }

        $root = root_path();

        try {
            return match ($action) {
                'help' => $this->help($input, $output),
                'diagnose' => $this->diagnose($input, $output, $root),
                'plan' => $this->plan($input, $output, $root),
                'status' => $this->status($input, $output, $root),
                'next' => $this->next($input, $output, $root),
                'check' => $this->check($input, $output, $root, $targets),
                'decide' => $this->decide($input, $output, $root, $targets),
                default => $this->write($input, $output, $root, $action, $targets),
            };
        } catch (RuntimeException $e) {
            return $this->reportFailure($input, $output, $e->getMessage(), extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action]);
        }
    }

    /**
     * @return list<string>
     */
    private function unexpectedOptions(InputInterface $input, string $action): array
    {
        $accepted = self::ACCEPTS[$action] ?? [];
        $unexpected = [];

        foreach (['package', 'no-cache', 'dry-run', 'allow-dirty', 'option', 'note'] as $option) {
            $value = $input->getOption($option);

            if ($value !== false && $value !== null && $value !== [] && ! in_array($option, $accepted, true)) {
                $unexpected[] = $option;
            }
        }

        if ($input->getParameterOption('--jobs', null, true) !== null && ! in_array('jobs', $accepted, true)) {
            $unexpected[] = 'jobs';
        }

        return $unexpected;
    }

    private function help(InputInterface $input, OutputInterface $output): int
    {
        $actions = [
            'diagnose' => 'Regenera el contrato aparte con la versión actual y clasifica cada archivo y cada hallazgo de verify. No escribe código.',
            'plan' => 'Convierte el diagnóstico en pasos ordenados y reanudables (.larapack/realign/plan.json).',
            'status' => 'Dónde se va: pasos hechos, pendientes y bloqueados, y si el diagnóstico está viejo.',
            'next' => 'El siguiente paso, con todo lo necesario para hacerlo sin contexto.',
            'check' => 'Comprueba el done_when de un paso y lo marca hecho, o dice qué falta.',
            'decide' => 'Registra la opción elegida en un paso de decisión.',
        ];

        foreach (Operations::ACTIONS as $operation) {
            $actions[$operation] = Operations::DESCRIPTIONS[$operation].(Operations::available($operation) ? '' : ' (no disponible en esta versión)');
        }

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'help', 'actions' => $actions,
                'categories' => Category::ALL, 'classes' => FileClass::ALL, 'guide' => 'docs/realign/README.md']);

            return Command::SUCCESS;
        }

        $output->writeln('  <info>larapack:realign</info> <acción>   (guía: docs/realign/README.md del paquete)');
        $output->writeln('');

        foreach ($actions as $name => $description) {
            $output->writeln(sprintf('  %-9s %s', $name, $description));
        }

        $output->writeln("\n  El ciclo: diagnose → plan → next → (hacer el paso) → check → next …");

        return Command::SUCCESS;
    }

    private function diagnose(InputInterface $input, OutputInterface $output, string $root): int
    {
        $jobs = (int) $input->getOption('jobs');

        if ($jobs < 1) {
            return $this->reportFailure($input, $output, '--jobs tiene que ser un entero mayor que cero.');
        }

        $report = (new Diagnosis($root, $jobs, ! $input->getOption('no-cache')))->run($this->packages($input));
        $state = new RealignState($root);
        $scopes = array_values(array_map(fn (array $scope): array => array_intersect_key($scope, array_flip(['package', 'path', 'contract', 'manifest', 'manifest_version', 'regeneration', 'counts', 'total', 'conforming'])), (array) $report['scopes']));

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'diagnose',
                'generator' => $report['generator'], 'generated_at' => $report['generated_at'], 'totals' => $report['totals'],
                'verify' => $report['verify'], 'scopes' => $scopes, 'report' => $this->relative($state->reportPath(), $root)]);

            return Command::SUCCESS;
        }

        $totals = $report['totals'];

        foreach ($scopes as $scope) {
            $regeneration = is_array($scope['regeneration']) ? $scope['regeneration'] : null;
            $output->writeln(sprintf('  %s: %d archivos, %d cumplen%s',
                $scope['package'] === null ? 'app (núcleo)' : OutputFormatter::escape((string) $scope['package']),
                $scope['total'],
                $scope['conforming'],
                match (true) {
                    ! $scope['contract'] => ' <comment>(sin contrato: no hay con qué comparar)</comment>',
                    $regeneration !== null && ! $regeneration['ok'] => ' <fg=red>(no se pudo regenerar: '.OutputFormatter::escape((string) $regeneration['error']).')</>',
                    default => '',
                }));
        }

        $output->writeln("\n  Archivos por clase:");

        foreach ($totals['classes'] as $class => $count) {
            if ($count > 0) {
                $output->writeln(sprintf('    %-17s %6d  %s', $class, $count, FileClass::DESCRIPTIONS[$class] ?? ''));
            }
        }

        $output->writeln("\n  Por categoría (archivos, código sin gestionar y hallazgos de verify):");

        foreach ($totals['categories'] as $category => $count) {
            $output->writeln(sprintf('    %-17s %6d', Category::LABELS[$category] ?? $category, $count));
        }

        $output->writeln("\n  Informe: ".$this->relative($state->reportPath(), $root).'. Siguiente: larapack:realign plan');

        return Command::SUCCESS;
    }

    private function plan(InputInterface $input, OutputInterface $output, string $root): int
    {
        $state = new RealignState($root);
        $report = $state->report();

        if ($report === null) {
            throw new RuntimeException('No hay diagnóstico: corre `larapack:realign diagnose` primero.');
        }

        $plan = Planner::build($report, $state->plan());
        $state->savePlan($plan);

        return $this->status($input, $output, $root, 'plan');
    }

    private function status(InputInterface $input, OutputInterface $output, string $root, string $action = 'status'): int
    {
        $state = new RealignState($root);
        $report = $state->report();
        $plan = $state->plan();
        $steps = $this->stepsIn($plan, $this->scopePaths($input, $root));
        $byId = $this->byId($plan);
        $byStatus = array_fill_keys([Planner::PENDING, Planner::AWAITING_DECISION, Planner::DECIDED, Planner::BLOCKED, Planner::DONE], 0);
        $byCategory = [];

        foreach ($steps as $step) {
            $status = Planner::effectiveStatus($step, $byId);
            $byStatus[$status]++;
            $byCategory[(string) $step['category']][$status] = ($byCategory[(string) $step['category']][$status] ?? 0) + 1;
        }

        $next = $this->nextStep($steps, $byId);
        $stale = $this->stale($report, $root);
        $checks = array_values(array_filter(array_map(fn (array $step): mixed => $step['last_check'] ?? null, $steps), 'is_array'));
        usort($checks, fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        $document = [
            'format_version' => RealignState::FORMAT_VERSION,
            'ok' => true,
            'action' => $action,
            'diagnosed_at' => $report['generated_at'] ?? null,
            'planned_at' => $plan['planned_at'] ?? null,
            'stale' => $stale,
            'totals' => $report['totals'] ?? null,
            'verify' => $report['verify'] ?? null,
            'steps' => ['total' => count($steps), 'by_status' => $byStatus, 'by_category' => $byCategory],
            'next' => $next === null ? null : ['id' => $next['id'], 'title' => $next['title'], 'category' => $next['category'], 'status' => Planner::effectiveStatus($next, $byId)],
            'last_check' => $checks[0] ?? null,
            'plan' => $plan === null ? null : $this->relative($state->planPath(), $root),
        ];

        if ($this->wantsJson($input)) {
            $this->writeJson($output, $document);

            return Command::SUCCESS;
        }

        if ($report === null) {
            $output->writeln('  Sin diagnóstico todavía. Empieza con: larapack:realign diagnose');

            return Command::SUCCESS;
        }

        $output->writeln('  Diagnóstico: '.$document['diagnosed_at'].($plan === null ? '  (sin plan: larapack:realign plan)' : '  ·  plan: '.$document['planned_at']));

        foreach ($stale as $path) {
            $output->writeln("  <comment>El contrato o el manifiesto de {$path} cambiaron después del diagnóstico: vuelve a correr diagnose y plan.</comment>");
        }

        if ($plan !== null) {
            $output->writeln(sprintf("\n  %d pasos: %d hechos, %d pendientes, %d esperan decisión, %d decididos, %d bloqueados.",
                count($steps), $byStatus[Planner::DONE], $byStatus[Planner::PENDING], $byStatus[Planner::AWAITING_DECISION], $byStatus[Planner::DECIDED], $byStatus[Planner::BLOCKED]));

            foreach ($byCategory as $category => $counts) {
                $output->writeln(sprintf('    %-17s %s', Category::LABELS[$category] ?? $category, implode(', ', array_map(fn (string $status, int $count): string => "{$status}: {$count}", array_keys($counts), $counts))));
            }

            if ($document['last_check'] !== null) {
                $output->writeln("\n  Última comprobación: ".$document['last_check']['id'].' ('.($document['last_check']['done'] ? 'hecho' : 'falta').', '.$document['last_check']['at'].')');
            }

            $output->writeln($next === null
                ? "\n  <info>No queda nada pendiente.</info> Confírmalo con: larapack:realign diagnose"
                : "\n  Siguiente: <info>{$next['id']}</info> — ".OutputFormatter::escape((string) $next['title']).'  (larapack:realign next)');
        }

        return Command::SUCCESS;
    }

    private function next(InputInterface $input, OutputInterface $output, string $root): int
    {
        $plan = $this->requirePlan($root);
        $byId = $this->byId($plan);
        $step = $this->nextStep($this->stepsIn($plan, $this->scopePaths($input, $root)), $byId);

        if ($step === null) {
            if ($this->wantsJson($input)) {
                $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'next', 'done' => true, 'step' => null]);
            } else {
                $output->writeln('  <info>No queda nada pendiente.</info> Confírmalo con un diagnóstico nuevo: larapack:realign diagnose && larapack:realign plan');
            }

            return Command::SUCCESS;
        }

        $status = Planner::effectiveStatus($step, $byId);
        $how = match ($step['category']) {
            Category::GUIDED => ['playbook' => Playbooks::resolve((string) ($step['playbook'] ?? Playbooks::GENERIC)), 'instructions' => Playbooks::render($step)],
            Category::DECISION => is_array($step['decision'] ?? null)
                ? ['decided' => $step['decision'], 'then' => $this->optionOf($step, (string) ($step['decision']['option'] ?? ''))['run'] ?? null]
                : ['options' => $step['options'] ?? [], 'decide' => 'larapack:realign decide '.$step['id'].' <opción> --note="por qué"'],
            default => ['command' => $step['command'] ?? null, 'available' => $this->commandAvailable((string) ($step['command'] ?? ''))],
        };
        $check = 'larapack:realign check '.$step['id'];

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'next', 'done' => false,
                'step' => $step + ['effective_status' => $status], 'how' => $how, 'check' => $check,
                'done_when' => Playbooks::describeCondition((array) $step['done_when'])]);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('  Paso %s/%d: <info>%s</info>', $step['order'] ?? '?', count((array) $plan['steps']), $step['id']));
        $output->writeln('  '.OutputFormatter::escape((string) $step['title']).' · '.(Category::LABELS[$step['category']] ?? $step['category']).' · riesgo '.$step['risk']['level'].' · '.($step['reversible'] ? 'reversible' : 'no reversible'));
        $output->writeln('  '.OutputFormatter::escape((string) $step['description']));
        $output->writeln('  Riesgo: '.OutputFormatter::escape((string) $step['risk']['why']));

        if ($step['category'] === Category::GUIDED) {
            $output->writeln("\n".OutputFormatter::escape((string) $how['instructions']));
        } elseif ($step['category'] === Category::DECISION) {
            if (isset($how['decided'])) {
                $output->writeln("\n  Decidido: ".OutputFormatter::escape((string) ($how['decided']['option'] ?? '')).($how['then'] === null ? '. Hazlo como dice la opción.' : '. Ahora: '.OutputFormatter::escape((string) $how['then'])));
            } else {
                $output->writeln("\n  Opciones:");

                foreach ((array) $how['options'] as $option) {
                    $output->writeln('    <info>'.$option['key'].'</info>: '.OutputFormatter::escape($option['label'].'. '.$option['consequences']));
                }

                $output->writeln("\n  Decide con: ".$how['decide']);
            }

            $this->writeFiles($output, $step);
        } else {
            $output->writeln("\n  Ejecuta: ".OutputFormatter::escape((string) $how['command']).($how['available'] ? '' : '  <comment>(no disponible en esta versión: hazlo a mano como describe el paso)</comment>'));
            $this->writeFiles($output, $step);
        }

        $output->writeln("\n  Está hecho cuando ".OutputFormatter::escape(Playbooks::describeCondition((array) $step['done_when'])));
        $output->writeln("  Compruébalo con: {$check}");

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function check(InputInterface $input, OutputInterface $output, string $root, array $targets): int
    {
        if (count($targets) !== 1) {
            return $this->reportFailure($input, $output, 'check necesita el id de un paso: larapack:realign check <id>.');
        }

        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $index = $this->indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];
        $result = (new DoneWhen($root))->check($step);
        $now = gmdate('c');

        $plan['steps'][$index]['last_check'] = ['id' => $step['id'], 'at' => $now, 'done' => $result['done'], 'remaining' => count($result['remaining'])];

        if ($result['done'] && ($step['status'] ?? null) !== Planner::DONE) {
            $plan['steps'][$index]['status'] = Planner::DONE;
            $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'done', 'detail' => 'Comprobado: '.Playbooks::describeCondition((array) $step['done_when'])];
        } elseif (! $result['done'] && ($step['status'] ?? null) === Planner::DONE) {
            $plan['steps'][$index]['status'] = Planner::PENDING;
            $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'reopened', 'detail' => 'La comprobación ya no pasa.'];
        }

        $state->savePlan($plan);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => $result['done'], 'action' => 'check', 'id' => $step['id']] + $result);

            return $result['done'] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($result['done']) {
            $output->writeln("  <info>Hecho:</info> {$step['id']} · siguiente: larapack:realign next");

            return Command::SUCCESS;
        }

        $output->writeln("  <comment>Falta</comment> en {$step['id']} (".count($result['remaining']).'):');

        foreach (array_slice($result['remaining'], 0, 50) as $line) {
            $output->writeln('    - '.OutputFormatter::escape($line));
        }

        if (count($result['remaining']) > 50) {
            $output->writeln('    … y '.(count($result['remaining']) - 50).' más (--format=json los da todos).');
        }

        return Command::FAILURE;
    }

    /**
     * @param  list<string>  $targets
     */
    private function decide(InputInterface $input, OutputInterface $output, string $root, array $targets): int
    {
        if (count($targets) !== 2) {
            return $this->reportFailure($input, $output, 'decide necesita el id del paso y la opción: larapack:realign decide <id> <opción> --note="por qué".');
        }

        [$id, $choice] = $targets;
        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $index = $this->indexOf($plan, $id);
        $step = $plan['steps'][$index];

        if ($step['category'] !== Category::DECISION) {
            return $this->reportFailure($input, $output, "El paso {$id} no es una decisión: es ".(Category::LABELS[$step['category']] ?? $step['category']).'.', 'Sigue lo que dice `larapack:realign next`.');
        }

        $option = $this->optionOf($step, $choice);

        if ($option === null) {
            return $this->reportFailure($input, $output, "El paso {$id} no tiene la opción {$choice}.",
                'Tiene: '.implode(', ', array_map(fn (array $option): string => (string) $option['key'], (array) ($step['options'] ?? []))).'.');
        }

        $note = $input->getOption('note');
        $decision = ['option' => $option['key'], 'note' => is_string($note) && $note !== '' ? $note : null, 'at' => gmdate('c')];
        $plan['steps'][$index]['decision'] = $decision;
        $plan['steps'][$index]['history'][] = ['at' => $decision['at'], 'event' => 'decided', 'detail' => $option['key'].($decision['note'] === null ? '' : ': '.$decision['note'])];
        $state->savePlan($plan);

        $then = $option['run'] ?? null;

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'decide', 'id' => $id, 'decision' => $decision,
                'then' => $then, 'check' => 'larapack:realign check '.$id]);

            return Command::SUCCESS;
        }

        $output->writeln("  Decidido en {$id}: <info>{$option['key']}</info> — ".OutputFormatter::escape((string) $option['consequences']));
        $output->writeln($then === null ? '  Hazlo como dice la opción, y después: larapack:realign check '.$id : "  Ahora: {$then}\n  Y después: larapack:realign check {$id}");

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function write(InputInterface $input, OutputInterface $output, string $root, string $action, array $targets): int
    {
        $options = [];

        foreach ((array) $input->getOption('option') as $pair) {
            $pair = (string) $pair;

            if (! str_contains($pair, '=')) {
                return $this->reportFailure($input, $output, "--option tiene que ser clave=valor: {$pair}.");
            }

            [$key, $value] = explode('=', $pair, 2);
            $options[trim($key)] = $value;
        }

        $report = (new OperationRunner($root))->run($action, $targets, $this->scopePaths($input, $root), (bool) $input->getOption('dry-run'), (bool) $input->getOption('allow-dirty'), $options);
        $ok = $report['result']['skipped'] === [];

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => $ok, 'action' => $action, 'dry_run' => $report['dry_run'], 'steps' => $report['steps']] + $report['result']);

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        foreach (['fixed' => $report['dry_run'] ? 'cambiaría' : 'cambiado', 'skipped' => 'saltado', 'unchanged' => 'sin cambios'] as $key => $label) {
            foreach ($report['result'][$key] as $item) {
                $output->writeln(sprintf('  %-11s %s: %s', $label, OutputFormatter::escape((string) ($item['file'] ?? '-')), OutputFormatter::escape((string) $item['reason'])));
            }
        }

        foreach ($report['result']['notes'] as $note) {
            $output->writeln('  <comment>'.OutputFormatter::escape($note).'</comment>');
        }

        $output->writeln(sprintf("\n  %d %s, %d saltados, %d sin cambios. Comprueba cada paso con larapack:realign check <id>.",
            count($report['result']['fixed']), $report['dry_run'] ? 'cambiarían' : 'cambiados', count($report['result']['skipped']), count($report['result']['unchanged'])));

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function writeFiles(OutputInterface $output, array $step): void
    {
        $files = (array) ($step['files'] ?? []);

        if ($files === []) {
            return;
        }

        $output->writeln("\n  Archivos (".count($files).'):');

        foreach (array_slice($files, 0, 30) as $file) {
            $output->writeln('    '.OutputFormatter::escape((string) $file));
        }

        if (count($files) > 30) {
            $output->writeln('    … y '.(count($files) - 30).' más (--format=json los da todos).');
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

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>|null
     */
    private function optionOf(array $step, string $key): ?array
    {
        foreach ((array) ($step['options'] ?? []) as $option) {
            if (is_array($option) && ($option['key'] ?? null) === $key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, mixed>|null
     */
    private function nextStep(array $steps, array $byId): ?array
    {
        foreach ($steps as $step) {
            if (! in_array(Planner::effectiveStatus($step, $byId), [Planner::DONE, Planner::BLOCKED], true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Los ámbitos cuyo contrato o manifiesto cambió después del diagnóstico.
     *
     * @param  array<string, mixed>|null  $report
     * @return list<string>
     */
    private function stale(?array $report, string $root): array
    {
        $stale = [];

        foreach ((array) ($report['scopes'] ?? []) as $path => $scope) {
            $directory = $path === '.' ? $root : $root.'/'.$path;

            if (is_array($scope) && is_dir($directory) && ($scope['fingerprint'] ?? null) !== Diagnosis::fingerprint($directory)) {
                $stale[] = (string) $path;
            }
        }

        return $stale;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePlan(string $root): array
    {
        $plan = (new RealignState($root))->plan();

        if ($plan === null || ! is_array($plan['steps'] ?? null)) {
            throw new RuntimeException('No hay plan: corre `larapack:realign diagnose` y después `larapack:realign plan`.');
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function indexOf(array $plan, string $id): int
    {
        foreach ((array) $plan['steps'] as $index => $step) {
            if (is_array($step) && ($step['id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        throw new RuntimeException("No hay un paso {$id} en el plan. `larapack:realign status --format=json` y plan.json los listan.");
    }

    /**
     * @param  array<string, mixed>|null  $plan
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function stepsIn(?array $plan, array $paths): array
    {
        return array_values(array_filter(
            (array) ($plan['steps'] ?? []),
            fn (mixed $step): bool => is_array($step) && ($paths === [] || in_array($step['scope']['path'] ?? null, $paths, true))
        ));
    }

    /**
     * @param  array<string, mixed>|null  $plan
     * @return array<string, array<string, mixed>>
     */
    private function byId(?array $plan): array
    {
        $byId = [];

        foreach ((array) ($plan['steps'] ?? []) as $step) {
            if (is_array($step) && is_string($step['id'] ?? null)) {
                $byId[$step['id']] = $step;
            }
        }

        return $byId;
    }

    /**
     * @return list<string>
     */
    private function packages(InputInterface $input): array
    {
        return array_values(array_map('strval', (array) $input->getOption('package')));
    }

    /**
     * Las rutas de ámbito de --package (`.` para el núcleo).
     *
     * @return list<string>
     */
    private function scopePaths(InputInterface $input, string $root): array
    {
        $packages = $this->packages($input);

        if ($packages === []) {
            return [];
        }

        $root = rtrim($root, '/');

        return array_map(
            fn (string $directory): string => $directory === $root ? '.' : substr($directory, strlen($root) + 1),
            (new Scopes($root))->resolve($packages)
        );
    }

    private function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
    }
}
