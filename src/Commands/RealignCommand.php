<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Completion;
use Innoboxrr\LarapackGenerator\Support\Realign\Diagnosis;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationRunner;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\OwnerBrief;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\ProjectTests;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
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
 *     next [<id>] el siguiente paso (o ese) con todo lo necesario para hacerlo
 *     show <id>  cualquier paso, como lo da next
 *     check <id> comprueba su done_when y lo marca hecho, o dice qué falta
 *     decide <id> <opción> [--target=…]  registra una decisión, entera o por elemento
 *     defer/undefer <id>  aplaza un paso o lo devuelve al orden
 *     escalate <id>  pasa un guiado al dueño como decisión
 *     accept-untested <id>  el dueño acepta sin probar lo que quedó untested
 *     done       el criterio de terminado: falla y dice qué falta (Completion)
 *     brief      lo que espera al dueño, en un documento (OwnerBrief)
 *     playbook [<nombre>]   los playbooks del paquete, o uno sin rellenar
 *
 * Las que escriben (adopt, apply, infer, except) tienen --dry-run, exigen el
 * árbol git limpio salvo --allow-dirty e informan fixed/skipped/unchanged
 * (OperationRunner).
 */
class RealignCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    private const READS = ['diagnose', 'plan', 'status', 'next', 'show', 'check', 'decide', 'defer', 'undefer', 'escalate', 'accept-untested', 'done', 'brief', 'playbook', 'help'];

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:realign')
            ->setDescription('Realinea con el contrato un proyecto que se desvió: diagnose, plan, status, next, show, check, decide, defer, undefer, escalate, accept-untested, done, brief, playbook y las operaciones adopt, apply, infer y except')
            ->setHelp(RealignActions::helpText())
            ->addArgument('action', InputArgument::OPTIONAL, 'diagnose, plan, status, next, show, check, decide, defer, undefer, escalate, accept-untested, done, brief, playbook, adopt, apply, infer, except o help', 'help')
            ->addArgument('targets', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'next (opcional), show, check, defer, undefer, escalate, accept-untested: el id del paso; decide: el id y la opción; playbook: su nombre; help: una acción; adopt/apply/infer/except: ids de paso (vacío, todos los que sepa hacer)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo este paquete local (directorio, ruta o nombre de Composer), o `app` para el núcleo. Repetible')
            ->addOption('jobs', null, InputOption::VALUE_REQUIRED, 'diagnose y done: cuántas regeneraciones a la vez', '4')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'diagnose y done: regenera aunque nada haya cambiado')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Operaciones que escriben: dice qué haría sin escribir nada')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Operaciones que escriben: escribe aunque el árbol git tenga cambios sin comitear (no se podrá deshacer con git)')
            ->addOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Operaciones que escriben: clave=valor para la operación. Repetible')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'decide, defer, escalate, accept-untested: por qué (queda en el plan)')
            ->addOption('tested', null, InputOption::VALUE_REQUIRED, 'check: la orden de pruebas del proyecto que se corrió y pasó (queda en el plan); sin ella, un paso que cambia comportamiento queda untested')
            ->addOption('approver', null, InputOption::VALUE_REQUIRED, 'accept-untested sin terminal: el nombre de la persona que acepta, igual que en la variable '.OwnerConfirmation::APPROVER_ENV.' (queda en el plan)')
            ->addOption('deterministic', null, InputOption::VALUE_NONE, 'apply: todos los pasos deterministas pendientes, en el orden del plan (sin ids hay que pedirlo así)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'decide: sólo para este elemento del paso (su archivo); except: sólo este elemento de los pasos, por su objetivo (realign:…) o su archivo. Repetible');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        // help y playbook responden sin proyecto: son lo primero que corre
        // quien todavía no sabe qué es esto, a menudo desde fuera de él.
        $needsProject = ! in_array($action, RealignActions::WITHOUT_PROJECT, true);

        if ($needsProject) {
            $this->applyRootOption($input);
        }

        $targets = array_values(array_map('strval', (array) $input->getArgument('targets')));
        $writes = in_array($action, Operations::ACTIONS, true);

        if (! $writes && ! in_array($action, self::READS, true)) {
            return $this->reportFailure($input, $output, "Acción desconocida: {$action}.", 'Disponibles: '.implode(', ', [...self::READS, ...Operations::ACTIONS]).'.');
        }

        $unexpected = $this->unexpectedOptions($input, $action);

        if ($unexpected !== []) {
            return $this->reportFailure($input, $output, "`{$action}` no usa ".implode(', ', array_map(fn (string $option): string => '--'.$option, $unexpected)).'.',
                $writes || $action === 'help' ? null : ($action === 'diagnose' ? 'diagnose no escribe código: no hay nada que simular.' : null));
        }

        $extra = array_slice($targets, RealignActions::ARGUMENTS[$action][1] ?? count($targets));

        if ($extra !== []) {
            // Un argumento que la acción no usa es un error, como una opción:
            // `next <id>` ignoraba el id y daba otro paso.
            $most = RealignActions::ARGUMENTS[$action][1] ?? 0;

            return $this->reportFailure($input, $output, "`{$action}` ".($most === 0 ? 'no recibe argumentos' : "recibe a lo sumo {$most}").'; sobra: '.implode(' ', $extra).'.',
                'Uso: '.implode(' · ', RealignActions::ALL[$action]['usage']).'.', ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action]);
        }

        $root = $needsProject ? root_path() : '';

        try {
            return match ($action) {
                'help' => $this->help($input, $output, $targets),
                'diagnose' => $this->diagnose($input, $output, $root),
                'plan' => $this->plan($input, $output, $root),
                'status' => $this->status($input, $output, $root),
                'next' => $this->next($input, $output, $root, $targets),
                'show' => $this->show($input, $output, $root, $targets),
                'defer', 'undefer' => $this->defer($input, $output, $root, $targets, $action === 'defer'),
                'escalate' => $this->escalate($input, $output, $root, $targets),
                'accept-untested' => $this->acceptUntested($input, $output, $root, $targets),
                'check' => $this->check($input, $output, $root, $targets),
                'decide' => $this->decide($input, $output, $root, $targets),
                'done' => $this->done($input, $output, $root),
                'brief' => $this->brief($input, $output, $root, $targets),
                'playbook' => $this->playbook($input, $output, $targets),
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
        // Lo que acepta cada acción vive en RealignActions: una opción que la acción
        // no usa es un error, porque ignorarla haría creer que hizo algo (un
        // `diagnose --dry-run` que en verdad no simula nada).
        $accepted = RealignActions::options($action);
        $unexpected = [];

        foreach (['package', 'no-cache', 'dry-run', 'allow-dirty', 'option', 'note', 'deterministic', 'target', 'tested', 'approver'] as $option) {
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

    /**
     * @param  list<string>  $targets
     */
    private function help(InputInterface $input, OutputInterface $output, array $targets = []): int
    {
        if ($targets !== []) {
            return $this->helpFor($input, $output, $targets);
        }

        $actions = [];

        foreach (array_keys(RealignActions::ALL) as $action) {
            if ($action !== 'help') {
                $actions[$action] = RealignActions::summary($action);
            }
        }

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'help', 'actions' => $actions,
                'categories' => Category::ALL, 'classes' => FileClass::ALL, 'guide' => RealignActions::GUIDE,
                'guides' => array_map(fn (array $definition): string => $definition['guide'], RealignActions::ALL),
                'entry_point' => 'larapack:realign status']);

            return Command::SUCCESS;
        }

        $output->writeln('  <info>larapack:realign</info> <acción>   (guía: '.RealignActions::GUIDE.' del paquete)');
        $output->writeln('');

        foreach ($actions as $name => $description) {
            $output->writeln(sprintf('  %-9s %s', $name, OutputFormatter::escape($description)));
        }

        $output->writeln('
  El ciclo: diagnose → plan → next → (hacer el paso) → check → next …');
        $output->writeln('  Si llegas sin contexto: larapack:realign status. Una acción en detalle: larapack:realign help <acción>.');

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function helpFor(InputInterface $input, OutputInterface $output, array $targets): int
    {
        $action = strtolower($targets[0]);

        if (count($targets) > 1 || ! isset(RealignActions::ALL[$action])) {
            return $this->reportFailure($input, $output, 'help recibe a lo sumo una acción conocida: '.implode(', ', array_keys(RealignActions::ALL)).'.',
                extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => 'help']);
        }

        $definition = RealignActions::ALL[$action];
        $summary = RealignActions::summary($action);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'help', 'topic' => $action,
                'summary' => $summary, 'usage' => $definition['usage'], 'writes' => $definition['writes'],
                'options' => $definition['options'], 'keys' => (object) $definition['keys'], 'guide' => $definition['guide'],
                'available' => ! in_array($action, Operations::ACTIONS, true) || Operations::available($action)]);

            return Command::SUCCESS;
        }

        $output->writeln('  <info>larapack:realign '.$action.'</info>');
        $output->writeln('  '.OutputFormatter::escape($summary));
        $output->writeln('');

        foreach ($definition['usage'] as $usage) {
            $output->writeln('    '.OutputFormatter::escape($usage));
        }

        foreach ($definition['keys'] as $key => $meaning) {
            $output->writeln('    --option '.$key.'=…  '.OutputFormatter::escape($meaning));
        }

        $output->writeln('');
        $output->writeln('  Escribe: '.OutputFormatter::escape($definition['writes']));
        $output->writeln('  Opciones: '.($definition['options'] === [] ? '—' : implode(' ', array_map(fn (string $option): string => '--'.$option, $definition['options']))).' (y --root, --format en todas)');
        $output->writeln('  Guía: '.$definition['guide'].' del paquete');

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
        $scopes = array_values(array_map(fn (array $scope): array => array_intersect_key($scope, array_flip(['package', 'path', 'contract', 'manifest', 'manifest_version', 'regeneration', 'counts', 'total', 'conforming', 'breakdown'])), (array) $report['scopes']));

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'diagnose',
                'generator' => $report['generator'], 'generated_at' => $report['generated_at'], 'totals' => $report['totals'],
                'verify' => $report['verify'], 'scopes' => $scopes, 'report' => $this->relative($state->reportPath(), $root)]);

            return Command::SUCCESS;
        }

        $totals = $report['totals'];

        foreach ($scopes as $scope) {
            $regeneration = is_array($scope['regeneration']) ? $scope['regeneration'] : null;
            $breakdown = is_array($scope['breakdown'] ?? null) ? $scope['breakdown'] : null;
            $output->writeln(sprintf('  %s: %d archivos, %d cumplen%s%s',
                $scope['package'] === null ? 'app (núcleo)' : OutputFormatter::escape((string) $scope['package']),
                $scope['total'],
                $scope['conforming'],
                $breakdown === null || $scope['total'] === 0 ? '' : sprintf(', %d por adoptar, %d con otro paso, %d fuera de alcance',
                    $breakdown['to_adopt'], $breakdown['other_steps'], $breakdown['out_of_scope']),
                match (true) {
                    ! $scope['contract'] => ' <comment>(sin contrato: no hay con qué comparar)</comment>',
                    $regeneration !== null && ! $regeneration['ok'] => ' <fg=red>(no se pudo regenerar: '.OutputFormatter::escape((string) $regeneration['error']).')</>',
                    default => '',
                }));
        }

        $output->writeln('  <comment>«cumplen»: sin paso pendiente —idénticos o iguales salvo formato que el manifiesto ya registra con su hash, editados sólo en sus huecos o con excepción—. Un archivo identical o format_only que el manifiesto no registra con su hash actual no cumple todavía: cuenta en «por adoptar» (el paso adopt, que sólo anota el manifiesto). Por eso «cumplen» puede ser menos que identical.</comment>');
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
        $byStatus = array_fill_keys([Planner::PENDING, Planner::AWAITING_DECISION, Planner::DECIDED, Planner::BLOCKED, Planner::UNTESTED, Planner::DONE], 0);
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
            'awaiting_owner' => $this->awaitingOwner($steps, $byId),
            'deferred' => array_values(array_map(fn (array $step): array => ['id' => $step['id'], 'title' => $step['title'], 'note' => $step['deferred']['note'] ?? null, 'at' => $step['deferred']['at'] ?? null],
                array_filter($steps, fn (array $step): bool => is_array($step['deferred'] ?? null) && ! Planner::finished($step)))),
            'untested' => $this->untested($steps),
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
            $output->writeln(sprintf("\n  %d pasos: %d hechos, %d sin probar, %d pendientes, %d esperan decisión, %d decididos, %d bloqueados.",
                count($steps), $byStatus[Planner::DONE], $byStatus[Planner::UNTESTED], $byStatus[Planner::PENDING], $byStatus[Planner::AWAITING_DECISION], $byStatus[Planner::DECIDED], $byStatus[Planner::BLOCKED]));

            foreach ($byCategory as $category => $counts) {
                $output->writeln(sprintf('    %-17s %s', Category::LABELS[$category] ?? $category, implode(', ', array_map(fn (string $status, int $count): string => "{$status}: {$count}", array_keys($counts), $counts))));
            }

            if ($document['last_check'] !== null) {
                $output->writeln("\n  Última comprobación: ".$document['last_check']['id'].' ('.($document['last_check']['done'] ? 'hecho' : 'falta').', '.$document['last_check']['at'].')');
            }

            foreach ($document['awaiting_owner'] as $waiting) {
                $count = count($waiting['decisions']);
                $output->writeln(sprintf("\n  <comment>Falta%s %d %s del dueño antes de seguir con lo guiado de %s</comment> (%d %s): %s.",
                    $count === 1 ? '' : 'n', $count, $count === 1 ? 'decisión' : 'decisiones', OutputFormatter::escape($waiting['scope']),
                    count($waiting['guided']), count($waiting['guided']) === 1 ? 'paso espera' : 'pasos esperan', implode(', ', $waiting['decisions'])));
                $output->writeln('  Preséntaselas (larapack:realign show <id>); mientras, sigue con lo que no depende de ellas (larapack:realign next).');
            }

            if ($document['deferred'] !== []) {
                $output->writeln("\n  Aplazados (".count($document['deferred']).'), siguen pendientes; undefer los devuelve:');

                foreach ($document['deferred'] as $deferred) {
                    $output->writeln('    '.$deferred['id'].' — '.OutputFormatter::escape((string) $deferred['note']));
                }
            }

            if ($document['untested'] !== []) {
                $output->writeln("\n  <comment>Sin probar (".count($document['untested']).')</comment>: hechos a falta de su prueba; done no termina mientras sigan así. Con las pruebas en verde, check --tested; si no se pueden correr, lo acepta el dueño:');

                foreach ($document['untested'] as $untested) {
                    $output->writeln('    '.$untested['id'].' — '.$untested['check'].'  ·  '.$untested['accept']);
                }
            }

            $output->writeln($next === null
                ? "\n  <info>No queda nada pendiente.</info> Confírmalo con: larapack:realign diagnose"
                : "\n  Siguiente: <info>{$next['id']}</info> — ".OutputFormatter::escape((string) $next['title']).'  (larapack:realign next)');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function next(InputInterface $input, OutputInterface $output, string $root, array $targets = []): int
    {
        $plan = $this->requirePlan($root);
        $byId = $this->byId($plan);

        if ($targets !== []) {
            return $this->describe($input, $output, $plan, $byId, $plan['steps'][$this->indexOf($plan, $targets[0])], 'next');
        }

        $steps = $this->stepsIn($plan, $this->scopePaths($input, $root));
        $step = $this->nextStep($steps, $byId);

        if ($step === null) {
            $left = array_values(array_filter($steps, fn (array $step): bool => ! Planner::finished($step)));

            if ($left !== []) {
                return $this->waiting($input, $output, $left, $byId);
            }

            if ($this->wantsJson($input)) {
                $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'next', 'done' => true, 'step' => null, 'untested' => $this->untested($steps)]);
            } else {
                $output->writeln('  <info>No queda nada pendiente.</info> Confírmalo con un diagnóstico nuevo: larapack:realign diagnose && larapack:realign plan');

                if ($this->untested($steps) !== []) {
                    $output->writeln('  <comment>Pero hay '.count($this->untested($steps)).' sin probar</comment>: larapack:realign status los lista.');
                }
            }

            return Command::SUCCESS;
        }

        // Una decisión sin decidir es del dueño: se ofrece primero, y se
        // dice con qué seguir mientras tanto para no parar todo.
        $meanwhile = Planner::effectiveStatus($step, $byId) === Planner::AWAITING_DECISION ? $this->meanwhile($steps, $byId) : null;

        return $this->describe($input, $output, $plan, $byId, $step, 'next', $meanwhile);
    }

    /**
     * @param  list<string>  $targets
     */
    private function show(InputInterface $input, OutputInterface $output, string $root, array $targets): int
    {
        if (count($targets) !== 1) {
            return $this->reportFailure($input, $output, 'show necesita el id de un paso: larapack:realign show <id>.', extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => 'show']);
        }

        $plan = $this->requirePlan($root);

        return $this->describe($input, $output, $plan, $this->byId($plan), $plan['steps'][$this->indexOf($plan, $targets[0])], 'show');
    }

    /**
     * Un paso con todo lo necesario para hacerlo sin contexto: lo que da
     * `next` y `show`, esté como esté.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>|null  $meanwhile
     */
    private function describe(InputInterface $input, OutputInterface $output, array $plan, array $byId, array $step, string $action, ?array $meanwhile = null): int
    {
        $status = Planner::effectiveStatus($step, $byId);
        $blockedBy = $this->blockedBy($step, $byId);
        $how = match ($step['category']) {
            Category::GUIDED => ['playbook' => Playbooks::resolve((string) ($step['playbook'] ?? Playbooks::GENERIC)), 'instructions' => Playbooks::render($step)],
            Category::DECISION => $this->decisionHow($step),
            default => ['command' => $step['command'] ?? null, 'available' => $this->commandAvailable((string) ($step['command'] ?? ''))]
                // Lo que la orden salte se hace a mano: el playbook del tipo.
                + (isset($step['playbook']) ? ['fallback' => ['playbook' => Playbooks::resolve((string) $step['playbook']), 'instructions' => Playbooks::render($step)]] : []),
        };
        $check = 'larapack:realign check '.$step['id'];
        $tests = ['required' => StepTypes::changesBehaviour($step), 'command' => (new ProjectTests((string) root_path()))->command(), 'tested' => $step['tested'] ?? null];
        $meanwhile = $meanwhile === null ? null : ['id' => $meanwhile['id'], 'title' => $meanwhile['title'], 'category' => $meanwhile['category'], 'show' => 'larapack:realign next '.$meanwhile['id']];

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => $action, 'done' => false,
                'step' => $step + ['effective_status' => $status], 'how' => $how, 'check' => $check, 'tests' => $tests,
                'done_when' => Playbooks::describeCondition((array) $step['done_when']), 'blocked_by' => $blockedBy, 'meanwhile' => $meanwhile]);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('  Paso %s/%d: <info>%s</info>', $step['order'] ?? '?', count((array) $plan['steps']), $step['id']));
        $output->writeln('  '.OutputFormatter::escape((string) $step['title']).' · '.(Category::LABELS[$step['category']] ?? $step['category']).' · riesgo '.$step['risk']['level'].' · '.($step['reversible'] ? 'reversible' : 'no reversible'));
        $output->writeln('  '.OutputFormatter::escape((string) $step['description']));
        $output->writeln('  Riesgo: '.OutputFormatter::escape((string) $step['risk']['why']));

        if (Planner::finished($step) || $blockedBy !== []) {
            $output->writeln('  <comment>Estado: '.$status.($blockedBy === [] ? '' : '; espera a: '.implode(', ', $blockedBy)).'</comment>');
        }

        if (is_array($step['deferred'] ?? null)) {
            $output->writeln('  <comment>Aplazado: '.OutputFormatter::escape((string) ($step['deferred']['note'] ?? '')).'</comment> (larapack:realign undefer '.$step['id'].')');
        }

        if (is_array($step['escalated'] ?? null)) {
            $output->writeln('  <comment>Pasado al dueño: '.OutputFormatter::escape((string) ($step['escalated']['note'] ?? '')).'</comment>');
        }

        if ($step['category'] === Category::GUIDED) {
            $output->writeln("\n".OutputFormatter::escape((string) $how['instructions']));
        } elseif ($step['category'] === Category::DECISION) {
            $this->writeDecision($output, $how);

            if (isset($how['instructions'])) {
                $output->writeln("\n".OutputFormatter::escape((string) $how['instructions']));
            }

            $this->writeFiles($output, $step);
        } else {
            $output->writeln("\n  Ejecuta: ".OutputFormatter::escape((string) $how['command']).' (primero con --dry-run)'.($how['available'] ? '' : '  <comment>(no disponible en esta versión: hazlo a mano como describe el paso)</comment>'));
            $this->writeFiles($output, $step);

            if (isset($how['fallback'])) {
                $output->writeln("\n  Lo que la orden salte (y diga por qué) se hace a mano así:\n\n".OutputFormatter::escape((string) $how['fallback']['instructions']));
            }
        }

        $output->writeln("\n  Está hecho cuando ".OutputFormatter::escape(Playbooks::describeCondition((array) $step['done_when'])));
        $output->writeln("  Compruébalo con: {$check}");

        if ($tests['required']) {
            $output->writeln('  <comment>Cambia comportamiento:</comment> corre las pruebas del proyecto antes y después. '.($tests['command'] === null
                ? 'Con ellas en verde: '.$check.' --tested="<la orden que corriste>"; sin ellas, check lo deja sin probar (untested).'
                : 'check corre la orden del proyecto ('.OutputFormatter::escape($tests['command']).') y sólo lo da por hecho si pasa.'));
        }

        if ($meanwhile !== null) {
            $output->writeln("\n  Esta decisión es del dueño: preséntale las opciones y espera a que decida. Mientras, sigue con lo que no depende de ella: "
                .$meanwhile['show'].' — '.OutputFormatter::escape((string) $meanwhile['title']));
        }

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

        $declared = $input->getOption('tested');

        if (is_string($declared) && trim($declared) === '') {
            return $this->reportFailure($input, $output, '--tested necesita la orden de pruebas que se corrió y pasó: --tested="php artisan test --filter=…".',
                extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => 'check']);
        }

        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $index = $this->indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];
        $result = (new DoneWhen($root))->check($step);
        $now = gmdate('c');
        // Lo que cambia comportamiento no se da por hecho sin su prueba: la
        // que declara quien la corrió, o la orden del proyecto, que se corre
        // aquí (ProjectTests). Sin ninguna, queda sin probar.
        $needsTests = StepTypes::changesBehaviour($step);
        $tests = null;
        $tested = null;

        if ($result['done'] && $needsTests && is_string($declared)) {
            $tested = ['via' => 'declared', 'command' => trim($declared), 'at' => $now];
        } elseif ($result['done'] && $needsTests && ($configured = (new ProjectTests($root))->command()) !== null) {
            $tests = (new ProjectTests($root))->run($configured);

            if ($tests['exit'] === 0) {
                $tested = ['via' => 'config', 'command' => $configured, 'at' => $now];
            } else {
                $result['done'] = false;
                $result['remaining'][] = "Las pruebas del proyecto fallan ({$configured}, sale con {$tests['exit']}): el paso cambia comportamiento y no se da por hecho. El final de la salida está en `tests.output`.";
            }
        }

        $untested = $result['done'] && $needsTests && $tested === null;
        $was = $step['status'] ?? Planner::PENDING;
        $status = $result['done'] ? ($untested ? Planner::UNTESTED : Planner::DONE) : Planner::PENDING;
        $plan['steps'][$index]['last_check'] = ['id' => $step['id'], 'at' => $now, 'done' => $result['done'] && ! $untested, 'remaining' => count($result['remaining'])]
            + ($untested ? ['untested' => true] : []);

        if ($tested !== null) {
            $plan['steps'][$index]['tested'] = $tested;
            unset($plan['steps'][$index]['untested_accepted']);
        }

        if ($status === Planner::DONE && ($was !== Planner::DONE || $tested !== null)) {
            $plan['steps'][$index]['status'] = Planner::DONE;
            $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'done', 'detail' => 'Comprobado: '.Playbooks::describeCondition((array) $step['done_when'])
                .($tested === null ? '' : ' Probado ('.($tested['via'] === 'config' ? 'la orden del proyecto' : 'lo declara quien lo hizo').'): '.$tested['command'].'.')];
        } elseif ($status === Planner::UNTESTED && $was === Planner::PENDING) {
            // Uno ya hecho y probado no pierde su prueba por comprobarlo otra vez.
            $plan['steps'][$index]['status'] = Planner::UNTESTED;
            $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'untested', 'detail' => 'Comprobado sin probar: '.Playbooks::describeCondition((array) $step['done_when'])];
        } elseif ($status === Planner::PENDING && Planner::finished($step)) {
            $plan['steps'][$index]['status'] = Planner::PENDING;
            unset($plan['steps'][$index]['tested'], $plan['steps'][$index]['untested_accepted']);
            $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'reopened', 'detail' => 'La comprobación ya no pasa.'];
        }

        $state->savePlan($plan);
        $final = (string) $plan['steps'][$index]['status'];
        $warning = $final === Planner::UNTESTED
            ? "SIN PROBAR: {$step['id']} cambia comportamiento y nadie registró su prueba. "
                .'Queda `untested`: hecho a falta de la prueba, y `larapack:realign done` no termina mientras quede así. '
                .'Corre las pruebas del proyecto y, si pasan: larapack:realign check '.$step['id'].' --tested="<la orden que corriste>" '
                .'(o registra la orden en '.RealignState::DIRECTORY.'/config.json y check la corre). Si no se pueden correr, dilo en tu informe: '
                .'aceptarlo sin probar lo decide el dueño (larapack:realign accept-untested '.$step['id'].' --note="…").'
            : null;

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => $result['done'], 'action' => 'check', 'id' => $step['id'],
                'done' => $final === Planner::DONE, 'status' => $final] + $result + ['tests' => $tests] + ($warning === null ? [] : ['warning' => $warning]));

            return $result['done'] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($result['done']) {
            $output->writeln($warning === null
                ? "  <info>Hecho:</info> {$step['id']} · siguiente: larapack:realign next"
                : '  <comment>'.OutputFormatter::escape($warning).'</comment>');

            return Command::SUCCESS;
        }

        $output->writeln("  <comment>Falta</comment> en {$step['id']} (".count($result['remaining']).'):');

        foreach (array_slice($result['remaining'], 0, 50) as $line) {
            $output->writeln('    - '.OutputFormatter::escape($line));
        }

        if (count($result['remaining']) > 50) {
            $output->writeln('    … y '.(count($result['remaining']) - 50).' más (--format=json los da todos).');
        }

        if ($tests !== null && $tests['exit'] !== 0) {
            $output->writeln('
  Final de la salida de las pruebas:
'.OutputFormatter::escape($tests['output']));
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
        $files = array_values(array_unique(array_map('strval', (array) $input->getOption('target'))));

        if ($files === []) {
            // Entera: sustituye también lo decidido elemento por elemento.
            $plan['steps'][$index]['decision'] = $decision;
            unset($plan['steps'][$index]['decisions']);
        } else {
            $known = Navigation::files($step);
            $unknown = array_values(array_diff($files, $known));

            if ($unknown !== []) {
                return $this->reportFailure($input, $output, "El paso {$id} no tiene ".implode(', ', $unknown).'.',
                    'Sus elementos: '.implode(', ', array_slice($known, 0, 20)).(count($known) > 20 ? '… (larapack:realign show '.$id.' --format=json los da todos)' : '').'.',
                    ['format_version' => RealignState::FORMAT_VERSION, 'action' => 'decide']);
            }

            foreach ($files as $file) {
                $plan['steps'][$index]['decisions'][$file] = $decision;
            }
        }

        $plan['steps'][$index]['history'][] = ['at' => $decision['at'], 'event' => 'decided', 'detail' => $option['key']
            .($files === [] ? '' : ' en '.implode(', ', $files)).($decision['note'] === null ? '' : ': '.$decision['note'])];
        $state->savePlan($plan);

        $then = $option['run'] ?? null;
        $undecided = Navigation::undecided($plan['steps'][$index]);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'decide', 'id' => $id, 'decision' => $decision,
                'targets' => $files, 'undecided' => $undecided, 'then' => $then, 'check' => 'larapack:realign check '.$id]);

            return Command::SUCCESS;
        }

        $output->writeln("  Decidido en {$id}".($files === [] ? '' : ' para '.count($files).' de '.count(Navigation::files($step))).": <info>{$option['key']}</info> — ".OutputFormatter::escape((string) $option['consequences']));

        if ($undecided !== []) {
            $output->writeln('  Faltan por decidir '.count($undecided).': '.implode(', ', array_slice($undecided, 0, 10)).(count($undecided) > 10 ? '…' : '').'.');
        }

        $output->writeln($then === null ? '  Hazlo como dice la opción, y después: larapack:realign check '.$id : "  Ahora: {$then}\n  Y después: larapack:realign check {$id}");

        return Command::SUCCESS;
    }

    /**
     * El dueño acepta sin probar un paso que quedó `untested`: lo confirma
     * él, como una aprobación (OwnerConfirmation), en su terminal o
     * nombrándose sin ella, y queda en el plan con su nota, su nombre si lo
     * dio y la hora, a la vista en el diff y en lo que lista `done`.
     *
     * @param  list<string>  $targets
     */
    private function acceptUntested(InputInterface $input, OutputInterface $output, string $root, array $targets): int
    {
        $extra = ['format_version' => RealignState::FORMAT_VERSION, 'action' => 'accept-untested'];

        if (count($targets) !== 1) {
            return $this->reportFailure($input, $output, 'accept-untested necesita el id de un paso: larapack:realign accept-untested <id> --note="por qué".', extra: $extra);
        }

        $note = Exceptions::sanitise((string) $input->getOption('note'));

        if ($note === '') {
            return $this->reportFailure($input, $output, 'accept-untested necesita --note: por qué se acepta sin probar y cuándo se probará. Queda en el plan.', extra: $extra);
        }

        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $index = $this->indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];

        if (($step['status'] ?? null) !== Planner::UNTESTED) {
            return $this->reportFailure($input, $output, "El paso {$step['id']} no está sin probar (está ".($step['status'] ?? Planner::PENDING).'): sólo se acepta lo que check o plan dejaron `untested`.', extra: $extra);
        }

        $option = $input->getOption('approver');
        $named = OwnerConfirmation::namedApprover(is_string($option) ? $option : null);

        if ($named['error'] !== null) {
            return $this->reportFailure($input, $output, $named['error'], extra: $extra);
        }

        $summary = [
            '  Aceptar SIN PROBAR '.$step['id'].' — '.Exceptions::sanitise((string) $step['title']),
            '  Cambia comportamiento: '.Exceptions::sanitise((string) $step['risk']['why']),
            '  Archivos: '.Exceptions::sanitise(implode(', ', array_slice(Navigation::files($step), 0, 10))).(count(Navigation::files($step)) > 10 ? '…' : ''),
            '  Nota: «'.$note.'»',
            '  Nadie vio pasar sus pruebas; queda hecho con tu aceptación en '.RealignState::DIRECTORY.'/plan.json, y done deja de pedirlas.',
        ];
        $via = 'approver';

        if ($named['approver'] === null) {
            $terminal = OwnerConfirmation::openTerminal();

            if ($terminal === null) {
                return $this->reportFailure($input, $output, 'Aceptar un paso sin probar lo confirma el dueño del proyecto en su terminal, y este proceso no tiene ninguna (/dev/tty): no se cambió nada. '
                    .'Si eres un agente, no lo intentes por otra vía: déjalo en tu informe (larapack:realign brief lo junta para el dueño). '
                    .'Una persona sin terminal se nombra: '.OwnerConfirmation::APPROVER_ENV.'="Su nombre" y --approver="Su nombre", y el nombre queda en el plan.', extra: $extra);
            }

            $phrase = 'acepto sin probar';
            $terminal->write(implode("\n", $summary)."\n\n  Si eres el dueño del proyecto y lo aceptas tú, escribe «{$phrase}»: ");
            $answer = $terminal->readLine();
            $terminal->write("\n");

            if ($answer === null || trim($answer) !== $phrase) {
                return $this->reportFailure($input, $output, "No se confirmó (había que escribir «{$phrase}»): no se cambió nada.", extra: $extra);
            }

            $via = 'terminal';
        } elseif (! $this->wantsJson($input)) {
            $output->writeln(array_map(fn (string $line): string => OutputFormatter::escape($line), $summary));
        }

        $now = gmdate('c');
        $accepted = ['note' => $note, 'at' => $now, 'via' => $via, 'by' => $named['approver'] === null ? null : Exceptions::sanitise($named['approver'])];
        $plan['steps'][$index]['status'] = Planner::DONE;
        $plan['steps'][$index]['untested_accepted'] = $accepted;
        $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'accepted-untested', 'detail' => 'Aceptado sin probar'
            .($accepted['by'] === null ? ' (en la terminal del dueño)' : ' por '.$accepted['by']).': '.$note];
        $state->savePlan($plan);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, $extra + ['ok' => true, 'id' => $step['id'], 'status' => Planner::DONE, 'untested_accepted' => $accepted]);

            return Command::SUCCESS;
        }

        $output->writeln("  <info>Aceptado sin probar:</info> {$step['id']}. Revisa el diff de ".RealignState::DIRECTORY.'/plan.json y comitéalo.');

        return Command::SUCCESS;
    }

    /**
     * Lo que espera al dueño, en un documento: lo escribe en
     * .larapack/realign/brief.{md,json} y lo imprime (OwnerBrief).
     *
     * @param  list<string>  $targets
     */
    private function brief(InputInterface $input, OutputInterface $output, string $root, array $targets): int
    {
        if ($targets !== []) {
            return $this->reportFailure($input, $output, 'brief no recibe ids: junta todo lo que espera al dueño (acótalo con --package).');
        }

        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $report = $state->report();
        $paths = $this->scopePaths($input, $root);
        $brief = new OwnerBrief($root);
        $document = $brief->build($plan, $this->stepsIn($plan, $paths), $this->byId($plan), $paths, $this->stale($report, $root), $report['generated_at'] ?? null);
        $brief->save($document);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, $document);

            return Command::SUCCESS;
        }

        $output->writeln(OutputFormatter::escape(OwnerBrief::markdown($document)));
        $output->writeln('  Escrito en '.$document['files']['markdown'].' (para el dueño) y '.$document['files']['json'].' (para un agente). Se retoma con: larapack:realign status');

        return Command::SUCCESS;
    }

    /**
     * El criterio de terminado (Completion): sale con 0 sólo si no falta
     * nada en ningún ámbito; si no, con 1 y lo que falta, por fuente.
     */
    private function done(InputInterface $input, OutputInterface $output, string $root): int
    {
        $jobs = (int) $input->getOption('jobs');

        if ($jobs < 1) {
            return $this->reportFailure($input, $output, '--jobs tiene que ser un entero mayor que cero.');
        }

        $packages = $this->packages($input);
        $roots = $packages === [] ? [] : (new Scopes($root))->resolve($packages);
        $result = (new Completion($root, $jobs, ! $input->getOption('no-cache')))->run($packages, $roots, $this->scopePaths($input, $root));

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => $result['done'], 'action' => 'done']
                + ($result['done'] ? [] : ['error' => 'El realineamiento no ha terminado: '.implode('; ', $result['reasons']).'.'])
                + $result);

            return $result['done'] ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($result['declared'] as $declared) {
            $output->writeln('  <fg=gray>fuera, por decisión ('.OutputFormatter::escape((string) $declared['step']).'):</> '.OutputFormatter::escape((string) $declared['message']));
        }

        foreach ($result['accepted_untested'] as $accepted) {
            $output->writeln('  <comment>aceptado sin probar ('.OutputFormatter::escape((string) $accepted['id']).($accepted['by'] === null ? '' : ', '.OutputFormatter::escape((string) $accepted['by'])).'):</comment> '.OutputFormatter::escape((string) $accepted['note']));
        }

        if ($result['done']) {
            $output->writeln('  <info>Terminado.</info> Nada pendiente en el plan, ninguna decisión ni excepción por aprobar, y validate y verify --strict en verde en cada ámbito.');
            $output->writeln('  Las pruebas del proyecto no las corre done: las registró check en cada paso que cambia comportamiento (lo aceptado sin probar sale arriba).');

            return Command::SUCCESS;
        }

        $output->writeln('  <comment>No ha terminado.</comment> Falta:');

        foreach ($result['reasons'] as $reason) {
            $output->writeln('    - '.OutputFormatter::escape($reason));
        }

        $lines = [
            ...array_map(fn (array $row): string => 'paso '.$row['id'].' ('.$row['status'].'): '.$row['check'], $result['missing']['steps']),
            ...array_map(fn (array $row): string => 'decisión '.$row['id'].': '.$row['decide'], $result['missing']['decisions']),
            ...array_map(fn (array $row): string => 'sin probar '.$row['id'].': '.$row['check'].'  ·  el dueño: '.$row['accept'], $result['missing']['untested']),
            ...array_map(fn (array $row): string => 'excepción '.$row['target'].' ('.$row['status'].')', $result['missing']['exceptions']),
            ...array_map(fn (array $row): string => 'validate '.$row['file'].' '.$row['path'].': '.$row['message'], $result['missing']['validate']),
            ...array_map(fn (array $row): string => 'verify '.$row['check'].' '.($row['file'] ?? $row['model'] ?? '').': '.$row['message'], $result['missing']['verify']),
        ];

        $output->writeln('');

        foreach (array_slice($lines, 0, 50) as $line) {
            $output->writeln('    '.OutputFormatter::escape($line));
        }

        if (count($lines) > 50) {
            $output->writeln('    … y '.(count($lines) - 50).' más (--format=json los da todos).');
        }

        return Command::FAILURE;
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
        $option = $this->optionOf($step, (string) ($step['decision']['option'] ?? ''));

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
                $then[$key] = $this->optionOf($step, (string) $key)['run'] ?? null;
            }

            $how += ['decided_by_target' => $byOption, 'undecided' => array_values(array_diff($undecided, ['*'])), 'then_by_option' => $then];
        }

        return $how + $this->instructions($step, is_string($step['playbook'] ?? null) ? $step['playbook'] : null);
    }

    /**
     * @param  array<string, mixed>  $how
     */
    private function writeDecision(OutputInterface $output, array $how): void
    {
        foreach ((array) ($how['decided_by_target'] ?? []) as $key => $files) {
            $run = $how['then_by_option'][$key] ?? null;
            $output->writeln("\n  Decidido <info>{$key}</info> para ".count((array) $files).': '.OutputFormatter::escape(implode(', ', array_slice((array) $files, 0, 10))).(count((array) $files) > 10 ? '…' : '')
                .(is_string($run) ? "\n    Ahora: ".OutputFormatter::escape($run).' (sólo toca esos)' : ''));
        }

        if (isset($how['options'])) {
            if (($how['undecided'] ?? []) !== []) {
                $output->writeln("\n  Faltan por decidir ".count($how['undecided']).': '.OutputFormatter::escape(implode(', ', array_slice($how['undecided'], 0, 10))).(count($how['undecided']) > 10 ? '…' : ''));
            }

            $output->writeln("\n  Opciones:");

            foreach ((array) $how['options'] as $option) {
                $output->writeln('    <info>'.$option['key'].'</info>: '.OutputFormatter::escape($option['label'].'. '.$option['consequences']));
            }

            $output->writeln("\n  Decide con: ".$how['decide']);
            $output->writeln('  O por elemento: '.$how['decide_target']);
        } elseif (array_key_exists('decided', $how) && ! isset($how['decided_by_target'])) {
            $output->writeln("\n  Decidido: ".OutputFormatter::escape((string) ($how['decided']['option'] ?? '')).($how['then'] === null ? '. Hazlo como dice la opción.' : '. Ahora: '.OutputFormatter::escape((string) $how['then'])));
        }
    }

    /**
     * Nada que hacer ahora, pero queda: lo aplazado y lo que espera.
     *
     * @param  list<array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $byId
     */
    private function waiting(InputInterface $input, OutputInterface $output, array $left, array $byId): int
    {
        $ids = fn (callable $filter): array => array_values(array_map(fn (array $step): string => (string) $step['id'], array_filter($left, $filter)));
        $deferred = $ids(fn (array $step): bool => isset($step['deferred']));
        $owner = $ids(fn (array $step): bool => ! isset($step['deferred']) && Navigation::handedOver($step));
        $blocked = $ids(fn (array $step): bool => ! isset($step['deferred']) && ! Navigation::handedOver($step)
            && (Planner::effectiveStatus($step, $byId) === Planner::BLOCKED || Navigation::waitsForGuided($step, $byId)));

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'next', 'done' => false, 'step' => null,
                'waiting' => ['deferred' => $deferred, 'owner' => $owner, 'blocked' => $blocked]]);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('  Nada que hacer ahora: quedan %d aplazados, %d en manos del dueño (lo pedido espera su aprobación: check dice qué falta) y %d bloqueados.', count($deferred), count($owner), count($blocked)));
        $output->writeln('  larapack:realign status los lista; larapack:realign undefer <id> devuelve uno aplazado.');

        return Command::SUCCESS;
    }

    /**
     * Lo primero que se puede hacer sin esperar al dueño.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, array<string, mixed>>  $byId
     * @return array<string, mixed>|null
     */
    private function meanwhile(array $steps, array $byId): ?array
    {
        foreach ($steps as $step) {
            if (! isset($step['deferred']) && ! Navigation::handedOver($step) && ! Navigation::waitsForGuided($step, $byId)
                && ! in_array(Planner::effectiveStatus($step, $byId), [Planner::DONE, Planner::UNTESTED, Planner::BLOCKED, Planner::AWAITING_DECISION], true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Lo que está sin probar: cada paso con la orden para darlo por probado y
     * la del dueño para aceptarlo así.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{id: string, title: string, scope: string, at: string|null, check: string, accept: string}>
     */
    private function untested(array $steps): array
    {
        $rows = [];

        foreach ($steps as $step) {
            if (($step['status'] ?? null) === Planner::UNTESTED) {
                $history = array_values(array_filter((array) ($step['history'] ?? []), fn (mixed $event): bool => is_array($event) && in_array($event['event'] ?? null, ['untested', 'resolved'], true)));
                $rows[] = ['id' => (string) $step['id'], 'title' => (string) $step['title'], 'scope' => (string) ($step['scope']['path'] ?? '.'),
                    'at' => $history === [] ? null : (string) ($history[count($history) - 1]['at'] ?? ''),
                    'check' => 'larapack:realign check '.$step['id'].' --tested="<orden>"', 'accept' => 'larapack:realign accept-untested '.$step['id'].' --note="por qué"'];
            }
        }

        return $rows;
    }

    /**
     * Los pasos de `depends_on` que todavía lo detienen.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, array<string, mixed>>  $byId
     * @return list<string>
     */
    private function blockedBy(array $step, array $byId): array
    {
        if (Planner::finished($step)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', (array) ($step['depends_on'] ?? [])), fn (string $id): bool => isset($byId[$id])
            && ! Planner::finished($byId[$id])
            && ! (Navigation::conditions((string) ($byId[$id]['type'] ?? '')) && Navigation::allows($step, $byId[$id]))));
    }

    /**
     * Por ámbito, las decisiones del dueño sin tomar que detienen lo guiado,
     * y lo guiado que detienen.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, array<string, mixed>>  $byId
     * @return list<array{scope: string, package: string|null, decisions: list<string>, guided: list<string>}>
     */
    private function awaitingOwner(array $steps, array $byId): array
    {
        $by = [];

        foreach ($steps as $step) {
            if ($step['category'] !== Category::GUIDED || isset($step['deferred'])) {
                continue;
            }

            foreach ($this->blockedBy($step, $byId) as $id) {
                $decision = $byId[$id];
                $shared = array_intersect(Navigation::files($step), Navigation::files($decision));
                $pending = array_filter($shared, fn (string $file): bool => Navigation::optionFor($decision, $file) === null);

                if (! Navigation::conditions((string) $decision['type']) || $pending === []) {
                    continue;
                }

                $package = is_string($step['scope']['package'] ?? null) ? $step['scope']['package'] : null;
                $key = $package ?? 'app';
                $by[$key] ??= ['scope' => $key, 'package' => $package, 'decisions' => [], 'guided' => []];
                $by[$key]['decisions'][$id] = $id;
                $by[$key]['guided'][(string) $step['id']] = (string) $step['id'];
            }
        }

        return array_values(array_map(function (array $entry): array {
            $decisions = array_values($entry['decisions']);
            $guided = array_values($entry['guided']);
            sort($decisions);
            sort($guided);

            return ['scope' => $entry['scope'], 'package' => $entry['package'], 'decisions' => $decisions, 'guided' => $guided];
        }, $by));
    }

    /**
     * `defer <id> --note=…` aplaza un paso: sigue pendiente, pero `next` lo
     * salta y `status` lo lista. `undefer <id>` lo devuelve al orden.
     *
     * @param  list<string>  $targets
     */
    private function defer(InputInterface $input, OutputInterface $output, string $root, array $targets, bool $defer): int
    {
        $action = $defer ? 'defer' : 'undefer';
        $extra = ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action];

        if (count($targets) !== 1) {
            return $this->reportFailure($input, $output, "{$action} necesita el id de un paso: ".RealignActions::ALL[$action]['usage'][0].'.', extra: $extra);
        }

        $note = trim((string) $input->getOption('note'));

        if ($defer && $note === '') {
            return $this->reportFailure($input, $output, 'defer necesita la razón: --note="por qué se aplaza".', 'Es lo que lee quien retome el plan.', $extra);
        }

        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $index = $this->indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];
        $now = gmdate('c');

        if ($defer && Planner::finished($step)) {
            return $this->reportFailure($input, $output, "El paso {$step['id']} ya está hecho: no hay nada que aplazar.", extra: $extra);
        }

        if (! $defer && ! isset($step['deferred'])) {
            return $this->reportFailure($input, $output, "El paso {$step['id']} no está aplazado.", extra: $extra);
        }

        if ($defer) {
            $plan['steps'][$index]['deferred'] = ['note' => $note, 'at' => $now];
        } else {
            unset($plan['steps'][$index]['deferred']);
        }

        $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => $defer ? 'deferred' : 'undeferred', 'detail' => $defer ? $note : 'Vuelve al orden del plan.'];
        $state->savePlan($plan);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => $action, 'id' => $step['id'],
                'deferred' => $plan['steps'][$index]['deferred'] ?? null, 'next' => 'larapack:realign next']);

            return Command::SUCCESS;
        }

        $output->writeln($defer
            ? "  Aplazado {$step['id']}: sigue pendiente, next pasa al siguiente y status lo lista. Para retomarlo: larapack:realign undefer {$step['id']}"
            : "  {$step['id']} vuelve al orden del plan. Siguiente: larapack:realign next");

        return Command::SUCCESS;
    }

    /**
     * `escalate <id> --note=…`: un paso guiado que no se debe hacer sin el
     * dueño (el controlador de un protocolo que el playbook dice dejar) pasa
     * a ser una decisión suya, con su nota.
     *
     * @param  list<string>  $targets
     */
    private function escalate(InputInterface $input, OutputInterface $output, string $root, array $targets): int
    {
        $extra = ['format_version' => RealignState::FORMAT_VERSION, 'action' => 'escalate'];

        if (count($targets) !== 1) {
            return $this->reportFailure($input, $output, 'escalate necesita el id de un paso: '.RealignActions::ALL['escalate']['usage'][0].'.', extra: $extra);
        }

        $note = trim((string) $input->getOption('note'));

        if ($note === '') {
            return $this->reportFailure($input, $output, 'escalate necesita la nota: --note="qué hay que decidir y por qué".', 'Es lo que lee el dueño.', $extra);
        }

        $state = new RealignState($root);
        $plan = $this->requirePlan($root);
        $index = $this->indexOf($plan, $targets[0]);
        $step = $plan['steps'][$index];

        if ($step['category'] !== Category::GUIDED) {
            return $this->reportFailure($input, $output, "El paso {$step['id']} no es un paso guiado: es ".(Category::LABELS[$step['category']] ?? $step['category']).'.',
                $step['category'] === Category::DECISION ? 'Ya lo decide el dueño: larapack:realign show '.$step['id'].'.' : null, $extra);
        }

        if (Planner::finished($step)) {
            return $this->reportFailure($input, $output, "El paso {$step['id']} ya está hecho.", extra: $extra);
        }

        $now = gmdate('c');
        $plan['steps'][$index] = Navigation::escalate($step, ['note' => $note, 'at' => $now]);
        $plan['steps'][$index]['history'][] = ['at' => $now, 'event' => 'escalated', 'detail' => $note];
        $state->savePlan($plan);
        $escalated = $plan['steps'][$index];
        $decide = 'larapack:realign decide '.$step['id'].' <opción> --note="por qué"';

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'escalate', 'id' => $step['id'],
                'escalated' => $escalated['escalated'], 'options' => $escalated['options'], 'decide' => $decide]);

            return Command::SUCCESS;
        }

        $output->writeln("  {$step['id']} es ahora una decisión del dueño. Opciones:");

        foreach ((array) $escalated['options'] as $option) {
            $output->writeln('    <info>'.$option['key'].'</info>: '.OutputFormatter::escape($option['label'].'. '.$option['consequences']));
        }

        $output->writeln("  La toma el dueño: {$decide}. Mientras, sigue con: larapack:realign next");

        return Command::SUCCESS;
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
     * @param  list<string>  $targets
     */
    private function playbook(InputInterface $input, OutputInterface $output, array $targets): int
    {
        if (count($targets) > 1) {
            return $this->reportFailure($input, $output, 'playbook recibe a lo sumo un nombre: larapack:realign playbook [<nombre>].');
        }

        if ($targets === []) {
            $list = [];

            foreach (Playbooks::names() as $name) {
                $first = strtok(Playbooks::describe($name), "\n");
                $title = trim(ltrim($first === false ? '' : $first, '# '));
                // Un playbook titulado con el del paso (`# {{title}}`) se
                // nombra en la lista por su tipo, no por el marcador.
                $list[$name] = str_starts_with($title, '<') ? ($name === Playbooks::GENERIC
                    ? 'Cualquier paso sin playbook propio: leer el hallazgo, el contrato antes que el código'
                    : (StepTypes::exists($name) ? (string) StepTypes::get($name)['title'] : $title)) : $title;
            }

            if ($this->wantsJson($input)) {
                $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'playbook', 'playbooks' => $list]);

                return Command::SUCCESS;
            }

            foreach ($list as $name => $title) {
                $output->writeln(sprintf('  %-32s %s', $name, OutputFormatter::escape($title)));
            }

            $output->writeln("\n  Uno entero: larapack:realign playbook <nombre>. Rellenado con lo de un paso, lo da larapack:realign next.");

            return Command::SUCCESS;
        }

        if (! Playbooks::exists($targets[0])) {
            return $this->reportFailure($input, $output, "No hay un playbook {$targets[0]}.", 'Hay: '.implode(', ', Playbooks::names()).'.');
        }

        $text = Playbooks::describe($targets[0]);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['format_version' => RealignState::FORMAT_VERSION, 'ok' => true, 'action' => 'playbook', 'name' => $targets[0], 'instructions' => $text]);

            return Command::SUCCESS;
        }

        $output->writeln(OutputFormatter::escape($text));

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function write(InputInterface $input, OutputInterface $output, string $root, string $action, array $targets): int
    {
        // apply reescribe código: sin ids, sólo si se pide en voz alta.
        if ($action === 'apply' && $targets === [] && ! $input->getOption('deterministic')) {
            return $this->reportFailure($input, $output, 'apply reescribe código: nombra los pasos (larapack:realign apply <id>…) o pide todos los deterministas con --deterministic.',
                extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action]);
        }

        if ($action === 'apply' && $targets !== [] && $input->getOption('deterministic')) {
            return $this->reportFailure($input, $output, '--deterministic es para todos los pasos: no se combina con ids.',
                extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action]);
        }

        $options = [];

        foreach ((array) $input->getOption('option') as $pair) {
            $pair = (string) $pair;

            if (! str_contains($pair, '=')) {
                return $this->reportFailure($input, $output, "--option tiene que ser clave=valor: {$pair}.");
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);

            // Una clave que la operación no lee es un error, como una opción:
            // ignorarla haría creer que hizo algo.
            $known = RealignActions::ALL[$action]['keys'];

            if (! Operations::overridden($action) && ! isset($known[$key])) {
                return $this->reportFailure($input, $output, "`{$action}` no usa --option {$key}.",
                    $known === [] ? "{$action} no lee ninguna clave." : 'Claves de '.$action.': '.implode(', ', array_keys($known)).' (larapack:realign help '.$action.').',
                    extra: ['format_version' => RealignState::FORMAT_VERSION, 'action' => $action]);
            }

            $options[$key] = $value;
        }

        $report = (new OperationRunner($root))->run($action, $targets, $this->scopePaths($input, $root), (bool) $input->getOption('dry-run'), (bool) $input->getOption('allow-dirty'), $options,
            array_values(array_map('strval', (array) $input->getOption('target'))));
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
            // Lo aplazado y lo que ya sólo espera al dueño no se vuelve a dar.
            if (! isset($step['deferred']) && ! Navigation::handedOver($step) && ! Navigation::waitsForGuided($step, $byId)
                && ! in_array(Planner::effectiveStatus($step, $byId), [Planner::DONE, Planner::UNTESTED, Planner::BLOCKED], true)) {
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
