<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\ProjectValidation;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Valida un laraimport antes de generar nada.
 *
 * Hasta ahora un archivo incompleto no daba un mensaje sino un TypeError a
 * mitad de la generación, con parte de los archivos ya escritos. Aquí falla
 * antes de tocar el disco y dice exactamente qué dato está mal.
 *
 * Sin ruta, valida el contrato de la raíz y el de cada paquete local que lo
 * tenga, como `verify` lee el manifiesto de cada uno (ProjectValidation):
 * cada hallazgo dice a qué paquete pertenece y el resumen va por paquete.
 * --package (repetible) acota a uno —su directorio, su ruta, su nombre de
 * Composer o `app` para el núcleo— y --no-packages deja sólo la raíz, como
 * antes de 9.4.0. Con una ruta, valida ese archivo y nada más.
 */
class ValidateCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:validate')
            ->setDescription('Valida un laraimport.json contra el esquema y comprueba sus referencias; sin ruta, el de la raíz y el de cada paquete local')
            ->addArgument('jsonPath', InputArgument::OPTIONAL, 'Ruta del archivo; por omisión laraimport.json en la raíz y en cada paquete local')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Trata los avisos como errores')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Comprueba también lo que necesita el módulo Vue')
            ->addOption('react', null, InputOption::VALUE_NONE, 'Comprueba también lo que necesita el módulo React')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sólo este paquete local (directorio en packages/, ruta o nombre de Composer), o `app` para el núcleo. Repetible')
            ->addOption('no-packages', null, InputOption::VALUE_NONE, 'No valida los contratos de los paquetes locales: sólo el de la raíz');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $interface = $input->getOption('vue') || $input->getOption('react');
        $strict = (bool) $input->getOption('strict');
        $jsonPath = $input->getArgument('jsonPath');
        $named = array_values(array_map('strval', (array) $input->getOption('package')));

        if ($jsonPath !== null && ($named !== [] || $input->getOption('no-packages'))) {
            return $this->reportFailure($input, $output, 'Con una ruta se valida ese archivo: --package y --no-packages no se combinan con ella.');
        }

        if ($jsonPath !== null) {
            $findings = array_map(fn (array $finding): array => $finding + ['package' => null, 'file' => (string) $jsonPath], ProjectValidation::file((string) $jsonPath, root_path(), $interface));
            $document = ImportDocument::fromFile((string) $jsonPath)['document'];
            $models = $document ? array_map('strval', array_column($document->models(), 'name')) : [];
            $result = ['findings' => $findings, 'summary' => [], 'errors' => $this->count($findings, SemanticValidator::ERROR), 'warnings' => $this->count($findings, SemanticValidator::WARNING)];

            return $this->report($input, $output, (string) $jsonPath, $models, $result, $strict);
        }

        $verification = new ProjectVerification(root_path(), withPackages: ! $input->getOption('no-packages'));
        $packages = [];

        foreach ($named as $package) {
            $resolved = $verification->resolve($package);

            if ($resolved === null) {
                return $this->reportFailure($input, $output,
                    "No hay un paquete local {$package}.",
                    'Usa el nombre de su directorio en packages/, su ruta, su nombre de Composer o `app` para el núcleo. Hay: '.implode(', ', $verification->known()).'.');
            }

            $packages[] = $resolved;
        }

        $result = (new ProjectValidation(root_path(), array_values(array_unique($packages)), ! $input->getOption('no-packages'), $interface))->run($strict);
        $core = array_values(array_filter($result['summary'], fn (array $scope): bool => $scope['path'] === '.'));

        return $this->report($input, $output, root_path().'/'.ProjectValidation::CONTRACT, $core[0]['models'] ?? [], $result, $strict);
    }

    /**
     * @param  list<string>  $models  los del contrato de la raíz (o del archivo pedido)
     * @param  array{findings: list<array<string, mixed>>, summary: list<array<string, mixed>>, errors: int, warnings: int}  $result
     */
    private function report(InputInterface $input, OutputInterface $output, string $file, array $models, array $result, bool $strict): int
    {
        ['findings' => $findings, 'summary' => $summary, 'errors' => $errors, 'warnings' => $warnings] = $result;
        $failed = $errors > 0 || ($strict && $warnings > 0);

        if ($this->wantsJson($input)) {
            $this->writeJson($output, [
                'ok' => ! $failed,
                'file' => $file,
                'errors' => $errors,
                'warnings' => $warnings,
                'models' => $models,
                'findings' => $findings,
            ] + ($summary === [] ? [] : ['summary' => $summary]));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $byPackage = count($summary) > 1 || ($summary !== [] && $summary[0]['path'] !== '.');

        foreach ($findings as $finding) {
            $output->writeln(sprintf(
                '  %s %s<fg=cyan>%s</> %s',
                $finding['level'] === SemanticValidator::ERROR ? '<fg=red>error</>' : '<comment>aviso</comment>',
                $byPackage ? OutputFormatter::escape((string) $finding['file']).' ' : '',
                OutputFormatter::escape((string) $finding['path']),
                OutputFormatter::escape((string) $finding['message'])
            ));
        }

        if ($findings === []) {
            $output->writeln($byPackage ? '  <info>Los contratos son válidos.</info>' : '  <info>El archivo es válido.</info>');
        }

        if ($models !== [] || (! $byPackage && $findings === [])) {
            // El orden es el que seguirán las migraciones.
            $output->writeln(sprintf("\n  Modelos (en orden de migración): %s", implode(', ', $models)));
        }

        if ($byPackage) {
            $output->writeln("\n  Por paquete:");

            foreach ($summary as $scope) {
                $output->writeln(sprintf(
                    '    %s: %s',
                    $scope['package'] === null ? 'app (núcleo)' : OutputFormatter::escape((string) $scope['package']).' ('.OutputFormatter::escape((string) $scope['path']).')',
                    $scope['contract'] || $scope['errors'] > 0
                        ? sprintf('%d errores, %d avisos, %d modelos', $scope['errors'], $scope['warnings'], count($scope['models']))
                        : 'sin laraimport.json'
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
}
