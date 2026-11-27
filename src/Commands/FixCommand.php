<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\Formatter;
use Innoboxrr\LarapackGenerator\Support\LegacyFix;
use Innoboxrr\LarapackGenerator\Support\LegacySecurity;
use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Corrige lo que `verify` marca como deuda de seguridad en código generado
 * con versiones anteriores (index-managed, resource-appends), igual en cada
 * archivo y a partir de la plantilla actual.
 *
 * Existe para que un proyecto con cientos de archivos así no dependa de que
 * un agente los edite uno a uno. Lo que no tiene una forma reconocida se
 * salta y se dice por qué: eso sí queda para una persona o un agente.
 *
 * Sólo toca lo que viene del generador: reescribe prepareForValidation() y
 * el bloque setAppends() cuando tienen la forma generada; si no, skipped. La
 * lógica propia que haya alrededor no se pierde.
 *
 * `route-closure` corrige un solo caso: el fallback de la SPA que escribía
 * laravel-setup <= 7.2.0 en routes/web.php, que pasa a SpaController y
 * SpaRequest como en laravel-setup 7.2.1 (SpaFallback). Cualquier otro
 * closure de las rutas se salta con el mensaje de `verify`.
 *
 * --package (repetible) acota a un paquete local —por su directorio en
 * packages/ o su nombre de Composer— o a `app`, el núcleo: lo que no cuelga de
 * ningún paquete. Se combina con --path.
 *
 * Además reporta, sin arreglar nada: en index-managed, los ManagedFilter cuyo
 * canView() no tiene camino para el administrador (`admin_path_missing`); en
 * resource-appends, la propuesta de `$appendable` que se lee del front
 * (`appendable_proposals`). Las dos son heurísticas de lectura, no pruebas: se
 * revisan a mano y no cambian el código de salida.
 *
 * Termina con código distinto de cero si algo se saltó, también con
 * --dry-run: quedó deuda que el comando no puede pagar.
 *
 * @phpstan-import-type CheckReport from LegacyFix
 */
class FixCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:fix')
            ->setDescription('Corrige de forma determinista la deuda que verify marca en lo generado con versiones anteriores (y el fallback de la SPA de laravel-setup <= 7.2.0)')
            ->addArgument('checks', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Qué corregir: '.implode(', ', LegacyFix::CHECKS))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra el diff de cada archivo sin escribir nada')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Sólo lo que cuelga de este subdirectorio de la raíz (p. ej. packages/school-library)')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo este paquete local (directorio en packages/ o nombre de Composer), o `app` para el núcleo. Repetible');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $checks = array_values(array_unique((array) $input->getArgument('checks')));
        $unknown = array_diff($checks, LegacyFix::CHECKS);

        if ($unknown !== []) {
            return $this->reportFailure($input, $output,
                'Corrección desconocida: '.implode(', ', $unknown).'.',
                'Disponibles: '.implode(', ', LegacyFix::CHECKS).'.');
        }

        $path = $input->getOption('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        if ($path !== null && ! is_dir(root_path().'/'.$path)) {
            return $this->reportFailure($input, $output, "No existe el directorio {$path} en la raíz del proyecto.");
        }

        $sources = new ProjectSources(root_path());
        $packages = [];

        foreach ((array) $input->getOption('package') as $package) {
            $package = trim((string) $package);
            $root = strtolower($package) === 'app' ? $sources->root() : $sources->packageRoot($package);

            if ($root === null) {
                $known = array_map(fn (string $root): string => basename($root), array_values(array_diff($sources->roots(), [$sources->root()])));

                return $this->reportFailure($input, $output,
                    "No hay un paquete local {$package}.",
                    'Usa el nombre de su directorio en packages/, su nombre de Composer o `app` para el núcleo.'.($known === [] ? '' : ' Hay: '.implode(', ', $known).'.'));
            }

            $packages[] = $root;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $fix = new LegacyFix(root_path(), $checks, $path, array_values(array_unique($packages)));
        $fix->plan();

        if ($dryRun) {
            $report = $fix->withDiffs();
            $formatted = false;
        } else {
            ['report' => $report, 'formatted' => $formatted] = $fix->apply();
        }

        $skipped = array_sum(array_map(fn (array $entry): int => count($entry['skipped']), $report));

        if ($this->wantsJson($input)) {
            $this->writeJson($output, [
                'ok' => $skipped === 0,
                'dry_run' => $dryRun,
                'formatted' => $formatted,
                'checks' => $report,
            ]);

            return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($report as $check => $entry) {
            $output->writeln("<info>{$check}</info>");

            foreach ($entry['fixed'] as $fixed) {
                $output->writeln('  '.($dryRun ? 'arreglaría' : 'arreglado').'  '.OutputFormatter::escape($fixed['file']));

                if (isset($fixed['diff'])) {
                    // En bruto: es código, y el formateador se comería lo que
                    // parezca una etiqueta de estilo.
                    $output->writeln($fixed['diff'], OutputInterface::OUTPUT_RAW);
                }
            }

            foreach ($entry['skipped'] as $skip) {
                $output->writeln('  <comment>saltado</comment>    '.OutputFormatter::escape($skip['file']).': '.OutputFormatter::escape($skip['reason']));
            }

            $output->writeln(sprintf('  %d %s, %d saltados, %d ya estaban bien', count($entry['fixed']), $dryRun ? 'por arreglar' : 'arreglados', count($entry['skipped']), $entry['unchanged']));

            $this->writeAdminPaths($output, $entry);
            $this->writeProposals($output, $entry);
        }

        if (isset($report[LegacySecurity::RESOURCE_APPENDS]) && $report[LegacySecurity::RESOURCE_APPENDS]['fixed'] !== []) {
            $output->writeln("\n  Los Resources arreglados ignoran `appends[]` hasta que declaras en \$appendable los accessors que pide la interfaz.");
        }

        if (! $dryRun && $formatted) {
            $output->writeln('  '.Formatter::resolve()['message']);
        }

        return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  CheckReport  $entry
     */
    private function writeAdminPaths(OutputInterface $output, array $entry): void
    {
        $missing = $entry['admin_path_missing'] ?? [];
        $unresolved = $entry['managed_filter_unresolved'] ?? [];

        if ($missing === [] && $unresolved === []) {
            return;
        }

        $output->writeln(sprintf('  <comment>%d ManagedFilter sin camino para el administrador</comment> (heurística de lectura: revísalos a mano)', count($missing)));

        foreach ($missing as $filter) {
            $output->writeln('    '.OutputFormatter::escape($filter['file']).' ('.OutputFormatter::escape($filter['model']).'): '.OutputFormatter::escape($filter['reason']));
        }

        foreach ($unresolved as $request) {
            $output->writeln('    sin ManagedFilter localizado  '.OutputFormatter::escape($request['file']).': '.OutputFormatter::escape($request['reason']));
        }
    }

    /**
     * @param  CheckReport  $entry
     */
    private function writeProposals(OutputInterface $output, array $entry): void
    {
        $proposals = $entry['appendable_proposals'] ?? null;

        if ($proposals === null || ($proposals['resources'] === [] && $proposals['unassociated'] === [])) {
            return;
        }

        $output->writeln('  <comment>Propuestas para $appendable</comment>: '.OutputFormatter::escape($proposals['warning']));

        foreach ($proposals['resources'] as $resource) {
            $output->writeln('    '.OutputFormatter::escape($resource['file']));

            foreach ($resource['accessors'] as $accessor) {
                $output->writeln(sprintf('      %s%s  (%s)',
                    OutputFormatter::escape($accessor['name']),
                    $accessor['exists'] ? '' : '  <comment>no existe en el modelo</comment>',
                    OutputFormatter::escape(implode(', ', $accessor['sources']))));
            }
        }

        foreach ($proposals['unassociated'] as $loose) {
            $output->writeln('    sin asociar  '.OutputFormatter::escape($loose['name']).'  '.OutputFormatter::escape($loose['source']).': '.OutputFormatter::escape($loose['reason']));
        }
    }
}
