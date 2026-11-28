<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Commands\Realign\AcceptUntestedAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\Action;
use Innoboxrr\LarapackGenerator\Commands\Realign\BriefAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\CheckAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\DecideAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\DeferAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\DiagnoseAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\DoneAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\EscalateAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\HelpAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\NextAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\OperationAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\PlanAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\PlaybookAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\ShowAction;
use Innoboxrr\LarapackGenerator\Commands\Realign\StatusAction;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
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
 *
 * Aquí sólo está lo común: que la acción exista, que sus opciones y sus
 * argumentos sean los que declara (RealignActions) y a qué clase se entrega
 * (HANDLERS). Cada acción vive en src/Commands/Realign/, y lo que decide
 * —qué paso sigue, si uno está hecho, qué falta— en src/Support/Realign/.
 */
class RealignCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    /**
     * Qué clase hace cada acción. Las operaciones que escriben
     * (Operations::ACTIONS) van todas a OperationAction, que delega en
     * OperationRunner: una operación nueva no se registra aquí.
     * RealignCatalogTest comprueba que cada acción de RealignActions::ALL
     * tenga quien la haga.
     *
     * @var array<string, class-string<Action>>
     */
    public const HANDLERS = [
        'help' => HelpAction::class,
        'diagnose' => DiagnoseAction::class,
        'plan' => PlanAction::class,
        'status' => StatusAction::class,
        'next' => NextAction::class,
        'show' => ShowAction::class,
        'check' => CheckAction::class,
        'decide' => DecideAction::class,
        'defer' => DeferAction::class,
        'undefer' => DeferAction::class,
        'escalate' => EscalateAction::class,
        'accept-untested' => AcceptUntestedAction::class,
        'done' => DoneAction::class,
        'brief' => BriefAction::class,
        'playbook' => PlaybookAction::class,
    ];

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:realign')
            ->setDescription('Realinea con el contrato un proyecto que se desvió: '.implode(', ', self::reads()).' y las operaciones '.implode(', ', Operations::ACTIONS))
            ->setHelp(RealignActions::helpText())
            ->addArgument('action', InputArgument::OPTIONAL, implode(', ', [...self::reads(), ...Operations::ACTIONS]).' o help', 'help')
            ->addArgument('targets', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'next (opcional), show, check, defer, undefer, escalate, accept-untested: el id del paso; decide: el id y la opción; playbook: su nombre; help: una acción; adopt/apply/infer/except: ids de paso (vacío, todos los que sepa hacer)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo este paquete local (directorio, ruta o nombre de Composer), o `app` para el núcleo. Repetible')
            ->addOption('jobs', null, InputOption::VALUE_REQUIRED, 'diagnose y done: cuántas regeneraciones (y, después, cuántas Pint) a la vez', '4')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'diagnose y done: regenera aunque nada haya cambiado')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Operaciones que escriben: dice qué haría sin escribir nada')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Operaciones que escriben: escribe aunque el árbol git tenga cambios sin comitear (no se podrá deshacer con git)')
            ->addOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Operaciones que escriben: clave=valor para la operación. Repetible')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'decide, defer, escalate, accept-untested: por qué (queda en el plan)')
            ->addOption('tested', null, InputOption::VALUE_REQUIRED, 'check: la orden de pruebas del proyecto que se corrió y pasó (queda en el plan); sin ella, un paso que cambia comportamiento queda untested')
            ->addOption('approver', null, InputOption::VALUE_REQUIRED, 'accept-untested sin terminal, sólo dentro de la corrida de aprobación de GitHub: la cuenta que la lanzó, igual que en la variable '.OwnerConfirmation::APPROVER_ENV.' (queda en el plan)')
            ->addOption('deterministic', null, InputOption::VALUE_NONE, 'apply: todos los pasos deterministas pendientes, en el orden del plan (sin ids hay que pedirlo así)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'decide: sólo para este elemento del paso (su archivo); except: sólo este elemento de los pasos, por su objetivo (realign:…) o su archivo. Repetible');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $handler = self::HANDLERS[$action] ?? (in_array($action, Operations::ACTIONS, true) ? OperationAction::class : null);

        if ($handler === null) {
            return $this->reportFailure($input, $output, "Acción desconocida: {$action}.", 'Disponibles: '.implode(', ', [...self::reads(), 'help', ...Operations::ACTIONS]).'.', Action::failureContext($action));
        }

        // help y playbook responden sin proyecto: son lo primero que corre
        // quien todavía no sabe qué es esto, a menudo desde fuera de él.
        $needsProject = ! in_array($action, RealignActions::WITHOUT_PROJECT, true);

        if ($needsProject) {
            $this->applyRootOption($input);
        }

        $targets = array_values(array_map('strval', (array) $input->getArgument('targets')));
        $unexpected = $this->unexpectedOptions($input, $action);

        if ($unexpected !== []) {
            return $this->reportFailure($input, $output, "`{$action}` no usa ".implode(', ', array_map(fn (string $option): string => '--'.$option, $unexpected)).'.',
                $action === 'diagnose' ? 'diagnose no escribe código: no hay nada que simular.' : null, Action::failureContext($action));
        }

        $most = RealignActions::ARGUMENTS[$action][1] ?? null;
        $extra = $most === null ? [] : array_slice($targets, $most);

        if ($extra !== []) {
            // Un argumento que la acción no usa es un error, como una opción:
            // `next <id>` ignoraba el id y daba otro paso.
            return $this->reportFailure($input, $output, "`{$action}` ".($most === 0 ? 'no recibe argumentos' : "recibe a lo sumo {$most}").'; sobra: '.implode(' ', $extra).'.',
                'Uso: '.implode(' · ', RealignActions::ALL[$action]['usage']).'.', Action::failureContext($action));
        }

        try {
            return (new $handler($input, $output, $needsProject ? (string) root_path() : '', $action))->run($targets);
        } catch (RuntimeException $e) {
            return $this->reportFailure($input, $output, $e->getMessage(), extra: Action::failureContext($action));
        }
    }

    /**
     * Las acciones que no son `help` ni una operación que escribe, en el
     * orden en que las lista la ayuda.
     *
     * @return list<string>
     */
    private static function reads(): array
    {
        return array_values(array_diff(array_keys(self::HANDLERS), ['help']));
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
}
