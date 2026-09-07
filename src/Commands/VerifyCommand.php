<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use Symfony\Component\Console\Command\Command;
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
 */
class VerifyCommand extends Command
{
    use TargetsProject;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:verify')
            ->setDescription('Comprueba que lo generado siga coherente con el manifiesto')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Trata los avisos como errores');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $findings = (new Verifier())->run();

        $errors = $this->count($findings, Verifier::ERROR);
        $warnings = $this->count($findings, Verifier::WARNING);

        $failed = $errors > 0 || ($input->getOption('strict') && $warnings > 0);

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode([
                'ok' => ! $failed,
                'errors' => $errors,
                'warnings' => $warnings,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $output->writeln(sprintf(
                '  %s %s%s%s',
                $this->tag($finding['level']),
                $finding['model'] ? "[{$finding['model']}] " : '',
                $finding['file'] ? "{$finding['file']}: " : '',
                $finding['message']
            ));
        }

        if ($findings === []) {
            $output->writeln('  <info>Sin desviaciones.</info>');
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
