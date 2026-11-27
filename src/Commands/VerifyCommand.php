<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Comprueba que lo generado siga siendo coherente.
 *
 * Está pensado para ejecutarse en CI y para que un agente lo use como
 * comprobación después de implementar: la salida en JSON le dice exactamente
 * qué corregir, y el código de salida distinto de cero le dice que aún no ha
 * terminado.
 *
 * --check acota a unas comprobaciones (repetible), para que un agente que
 * acaba de escribir un controlador pueda preguntar sólo por eso sin esperar
 * a todo lo demás.
 *
 * En la raíz de un proyecto con paquetes locales lee también el manifiesto de
 * cada uno (ver ProjectVerification): cada hallazgo dice a qué paquete
 * pertenece, y el resumen va por paquete. --package (repetible) acota a uno
 * —por su directorio, su ruta o su nombre de Composer— o a `app`, el núcleo;
 * --no-packages deja fuera sus manifiestos, como antes de 9.3.1.
 */
class VerifyCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:verify')
            ->setDescription('Comprueba que lo generado siga coherente con el manifiesto y que todo controlador delegue en su request')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Trata los avisos como errores')
            ->addOption('check', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo esta comprobación (repetible): '.implode(', ', Verifier::CHECKS))
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo este paquete local (directorio en packages/, ruta o nombre de Composer), o `app` para el núcleo. Repetible')
            ->addOption('no-packages', null, InputOption::VALUE_NONE, 'No lee los manifiestos de los paquetes locales: sólo el de la raíz');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        // Un nombre mal escrito no puede acabar en "sin desviaciones": filtrar
        // por algo que no existe no encuentra nada, y eso parecería un verde.
        $only = array_values(array_unique((array) $input->getOption('check')));
        $unknown = array_diff($only, Verifier::CHECKS);

        if ($unknown !== []) {
            return $this->reportFailure($input, $output,
                'Comprobación desconocida: '.implode(', ', $unknown).'.',
                'Disponibles: '.implode(', ', Verifier::CHECKS).'.');
        }

        $verification = new ProjectVerification(root_path(), withPackages: ! $input->getOption('no-packages'));
        $packages = [];

        foreach ((array) $input->getOption('package') as $package) {
            $resolved = $verification->resolve((string) $package);

            if ($resolved === null) {
                return $this->reportFailure($input, $output,
                    "No hay un paquete local {$package}.",
                    'Usa el nombre de su directorio en packages/, su ruta, su nombre de Composer o `app` para el núcleo. Hay: '.implode(', ', $verification->known()).'.');
            }

            $packages[] = $resolved;
        }

        if ($packages !== []) {
            $verification = new ProjectVerification(root_path(), array_values(array_unique($packages)), ! $input->getOption('no-packages'));
        }

        ['findings' => $findings, 'summary' => $summary] = $verification->run($only);

        $errors = $this->count($findings, Verifier::ERROR);
        $warnings = $this->count($findings, Verifier::WARNING);

        $failed = $errors > 0 || ($input->getOption('strict') && $warnings > 0);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, [
                'ok' => ! $failed,
                'errors' => $errors,
                'warnings' => $warnings,
                'findings' => $findings,
                'summary' => $summary,
            ] + ($only === [] ? [] : ['checks' => $only]));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        // El mensaje y la ruta pueden llevar texto del proyecto auditado (la
        // razón de un #[HandRolled], un nombre de archivo): se escapan para
        // que una etiqueta de la consola en ellos se lea tal cual.
        foreach ($findings as $finding) {
            $output->writeln(sprintf(
                '  %s %s%s%s%s',
                $this->tag($finding['level']),
                $finding['package'] !== null ? '<fg=cyan>['.OutputFormatter::escape($finding['package']).']</> ' : '',
                $finding['model'] ? '['.OutputFormatter::escape($finding['model']).'] ' : '',
                $finding['file'] ? OutputFormatter::escape($finding['file']).': ' : '',
                OutputFormatter::escape($finding['message'])
            ));
        }

        if ($findings === []) {
            $output->writeln('  <info>Sin desviaciones.</info>');
        }

        // Con paquetes locales, una línea por cada uno. Sin ellos la salida
        // queda como estaba.
        if (count($summary) > 1 || ($summary !== [] && $summary[0]['path'] !== '.')) {
            $output->writeln("\n  Por paquete:");

            foreach ($summary as $scope) {
                $output->writeln(sprintf(
                    '    %s: %d errores, %d avisos%s',
                    $scope['package'] === null ? 'app (núcleo)' : OutputFormatter::escape($scope['package']).' ('.OutputFormatter::escape($scope['path']).')',
                    $scope['errors'],
                    $scope['warnings'],
                    $scope['manifest'] ? '' : ' (sin manifiesto: sólo el código)'
                ));
            }
        }

        $output->writeln(sprintf("\n  %d errores, %d avisos", $errors, $warnings));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function count(array $findings, string $level): int
    {
        return count(array_filter($findings, fn (array $f): bool => $f['level'] === $level));
    }

    private function tag(string $level): string
    {
        return match ($level) {
            Verifier::ERROR => '<fg=red>error  </>',
            Verifier::WARNING => '<comment>aviso  </comment>',
            default => '<fg=gray>info   </>',
        };
    }
}
