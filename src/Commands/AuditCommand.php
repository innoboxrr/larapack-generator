<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Support\Ecosystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Comprueba que un paquete cumpla la línea base del ecosistema.
 *
 * `larapack:verify` mira hacia dentro de un paquete —que lo generado siga
 * coherente—. Este mira hacia fuera: que el paquete encaje con los demás.
 * Es lo que impide que la alineación se deshaga en el siguiente push, y por
 * eso está pensado para correr en CI antes de publicar.
 */
class AuditCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('larapack:audit')
            ->setDescription('Comprueba que el paquete cumpla la línea base del ecosistema')
            ->addArgument('path', InputArgument::OPTIONAL, 'Directorio del paquete, o uno que los contenga con --all', '.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Audita todos los subdirectorios de la ruta')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Trata los avisos como errores');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = realpath((string) $input->getArgument('path'));

        if ($path === false) {
            $output->writeln('<fg=red>La ruta indicada no existe.</>');

            return Command::FAILURE;
        }

        $ecosystem = new Ecosystem();
        $findings = [];

        foreach ($this->targets($path, (bool) $input->getOption('all')) as $target) {
            $findings = [...$findings, ...$ecosystem->audit($target)];
        }

        $errors = $this->count($findings, Ecosystem::ERROR);
        $warnings = $this->count($findings, Ecosystem::WARNING);
        $failed = $errors > 0 || ($input->getOption('strict') && $warnings > 0);

        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode([
                'ok' => ! $failed,
                'errors' => $errors,
                'warnings' => $warnings,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $current = null;

        foreach ($findings as $finding) {
            if ($finding['package'] !== $current) {
                $current = $finding['package'];
                $output->writeln("\n  <options=bold>{$current}</>");
            }

            $output->writeln(sprintf('    %s %s', $this->tag($finding['level']), $finding['message']));
        }

        if ($findings === []) {
            $output->writeln('  <info>Todo en la línea base.</info>');
        }

        $output->writeln(sprintf("\n  %d errores, %d avisos", $errors, $warnings));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function targets(string $path, bool $all): array
    {
        if (! $all) {
            return [$path];
        }

        $targets = [];

        foreach (glob("{$path}/*", GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_file("{$directory}/composer.json") || is_file("{$directory}/package.json")) {
                $targets[] = $directory;
            }
        }

        sort($targets);

        return $targets;
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
        return $level === Ecosystem::ERROR ? '<fg=red>error</>' : '<comment>aviso</comment>';
    }
}
