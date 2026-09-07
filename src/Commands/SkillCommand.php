<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\Skill;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Instala en el proyecto las instrucciones de uso para un agente.
 *
 * Un paquete dentro de vendor/ no lo descubre nadie: ni Claude Code ni Codex
 * miran ahí. Este comando lo copia donde sí se lee, y al vivir el original en
 * el paquete, actualizar el generador actualiza las instrucciones.
 */
class SkillCommand extends Command
{
    use TargetsProject;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:skill')
            ->setDescription('Instala en el proyecto las instrucciones de LaraPack para un agente')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Destino, relativo a la raíz', Skill::DEFAULT_TARGET)
            ->addOption('print', null, InputOption::VALUE_NONE, 'Emite el texto por salida estándar en vez de instalarlo')
            ->addOption('source', null, InputOption::VALUE_NONE, 'Muestra sólo la ruta del original en el paquete')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sobrescribe el destino aunque ya exista');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('source')) {
            $output->writeln(Skill::path());

            return Command::SUCCESS;
        }

        if ($input->getOption('print')) {
            $output->write(Skill::contents());

            return Command::SUCCESS;
        }

        $this->applyRootOption($input);

        $result = Skill::install(
            (string) $input->getOption('path'),
            (bool) $input->getOption('force')
        );

        if ($result['reason'] === 'ya existe') {
            // Si el contenido coincide no hay nada que hacer y no es un error:
            // el caso normal es reejecutarlo tras actualizar el paquete.
            $output->writeln($result['written']
                ? "  <info>Ya instalado y al día:</info> {$result['target']}"
                : "  <comment>Ya existe y difiere del paquete:</comment> {$result['target']}\n"
                    . '  Usa --force para sobrescribirlo, o --print para compararlo.');

            return Command::SUCCESS;
        }

        $output->writeln("  <info>Instalado:</info> {$result['target']}");

        return Command::SUCCESS;
    }
}
