<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Commands\Concerns\WritesJson;
use Innoboxrr\LarapackGenerator\Support\Merge\Reconciler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `larapack:reconcile`: deja el proyecto coherente después de fusionar ramas.
 *
 * Es el paso del integrador cuando varias consolas trabajan a la vez: se
 * corre justo después de `git merge` —con conflictos o sin ellos— y antes de
 * comitear la fusión. Fusiona el laraimport por nombres, recompone el
 * manifiesto de las dos ramas, retira las migraciones que se repiten y
 * vuelve a importar con el contrato fusionado (Reconciler). Lo que no puede
 * decidir solo lo deja nombrado y sale con error.
 *
 * No comitea. Del índice de git sólo toca lo que estaba en conflicto y
 * resolvió, para marcarlo como resuelto.
 */
class ReconcileCommand extends Command
{
    use TargetsProject;
    use WritesJson;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:reconcile')
            ->setDescription('Tras un git merge: fusiona el laraimport por nombres, recompone el manifiesto de las dos ramas, retira las migraciones repetidas y vuelve a importar')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice lo que haría sin escribir nada')
            ->addOption('keep-migrations', null, InputOption::VALUE_NONE, 'No retira las migraciones repetidas: las deja para que decida una persona')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $dryRun = (bool) $input->getOption('dry-run');
        $result = (new Reconciler(root_path(), $dryRun, (bool) $input->getOption('keep-migrations')))->run();

        if ($this->wantsJson($input)) {
            $this->writeJson($output, ['ok' => $result['ok'], 'dryRun' => $dryRun, 'merge' => $result['merge'], 'scopes' => $result['scopes']]);

            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln($result['merge'] === null
            ? '  No hay una fusión en curso ni el último commit lo es: sólo se vuelve a importar.'
            : '  Fusión: '.$result['merge'].'.');

        foreach ($result['scopes'] as $scope) {
            $output->writeln('');
            $output->writeln('  <fg=cyan>'.OutputFormatter::escape($scope['scope'] === '.' ? 'raíz' : $scope['scope']).'</>');

            foreach ($scope['actions'] as $action) {
                $output->writeln('    <info>hecho</info>     '.OutputFormatter::escape($action));
            }

            foreach ($scope['review'] as $review) {
                $output->writeln('    <comment>revisa</comment>    '.OutputFormatter::escape($review));
            }

            foreach ($scope['pending'] as $pending) {
                $output->writeln('    <fg=red>pendiente</> '.OutputFormatter::escape($pending));
            }

            if ($scope['actions'] === [] && $scope['review'] === [] && $scope['pending'] === []) {
                $output->writeln('    nada que reconciliar');
            }
        }

        $output->writeln('');
        $output->writeln($result['ok']
            ? '  Reconciliado'.($dryRun ? ' (ensayo: no se ha escrito nada)' : '').'. Ahora: `larapack:verify --strict`, las pruebas del proyecto y comitear la fusión (`git add -A`).'
            : '  <fg=red>Queda trabajo pendiente</>: resuélvelo y vuelve a correr `larapack:reconcile`.');

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
