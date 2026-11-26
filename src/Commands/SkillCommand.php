<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\AgentHooks;
use Innoboxrr\LarapackGenerator\Support\Skill;
use RuntimeException;
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
 *
 * Además deja la regla en `AGENTS.md`, que es lo que leen Codex y compañía, y
 * con `--hooks` la hace ejecutable en Claude Code: un texto se olvida a mitad
 * de una sesión larga; un hook que devuelve el error en cada edición, no.
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sobrescribe el destino aunque ya exista')
            ->addOption('hooks', null, InputOption::VALUE_NONE, 'Fusiona en .claude/settings.json los hooks que ejecutan larapack:guard')
            ->addOption('no-agents', null, InputOption::VALUE_NONE, 'No escribe el bloque de LaraPack en AGENTS.md');
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

        $path = (string) $input->getOption('path');
        $result = Skill::install($path, (bool) $input->getOption('force'));

        if ($result['reason'] === 'ya existe') {
            // Si el contenido coincide no hay nada que hacer y no es un error:
            // el caso normal es reejecutarlo tras actualizar el paquete.
            $output->writeln($result['written']
                ? "  <info>Ya instalado y al día:</info> {$result['target']}"
                : "  <comment>Ya existe y difiere del paquete:</comment> {$result['target']}\n"
                    .'  Usa --force para sobrescribirlo, o --print para compararlo.');
        } else {
            $output->writeln("  <info>Instalado:</info> {$result['target']}");
        }

        try {
            // El bloque se reescribe siempre, sin --force: lo de fuera de los
            // marcadores es del proyecto y no se toca, y lo de dentro es del
            // paquete y tiene que seguir al paquete.
            if (! $input->getOption('no-agents')) {
                $agents = AgentHooks::installAgentsBlock(root_path(), $path);

                $output->writeln(match ($agents['action']) {
                    'created' => "  <info>Creado:</info> {$agents['target']}",
                    'appended' => "  <info>Bloque de LaraPack añadido al final:</info> {$agents['target']}",
                    'updated' => "  <info>Bloque de LaraPack actualizado:</info> {$agents['target']}",
                    default => "  <info>Bloque de LaraPack al día:</info> {$agents['target']}",
                });
            }

            if ($input->getOption('hooks')) {
                $hooks = AgentHooks::installSettings(root_path());

                $output->writeln($hooks['added'] === []
                    ? "  <info>Hooks de LaraPack ya presentes:</info> {$hooks['target']}"
                    : '  <info>Hooks añadidos ('.implode(', ', $hooks['added'])."):</info> {$hooks['target']}");
            }
        } catch (RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
