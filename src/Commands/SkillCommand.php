<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\AgentHooks;
use Innoboxrr\LarapackGenerator\Support\AgentIntegrations;
use Innoboxrr\LarapackGenerator\Support\GitHooks;
use Innoboxrr\LarapackGenerator\Support\Skill;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
 * con `--hooks` la hace ejecutable: en Claude Code, y en git, que frena el
 * commit de cualquier agente o persona. Un texto se olvida a mitad de una
 * sesión larga; un hook que devuelve el error en cada edición, no. Con
 * `--agents` instala también los hooks y las reglas de otros agentes
 * (AgentIntegrations).
 *
 * Junto al skill instala la guía del realineamiento (`larapack-realign`), y el
 * bloque de AGENTS.md —que es también el de las reglas de Cursor, Codex,
 * Gemini y Copilot— manda a `larapack:realign status` cuando hay uno en curso.
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
            ->addOption('hooks', null, InputOption::VALUE_NONE, 'Fusiona en .claude/settings.json los hooks que ejecutan larapack:guard, y pone los hooks de git (.githooks/, core.hooksPath)')
            ->addOption('agents', null, InputOption::VALUE_REQUIRED, 'Hooks y reglas de otros agentes, separados por comas: '.implode(', ', AgentIntegrations::AGENTS).' o all')
            ->addOption('no-agents', null, InputOption::VALUE_NONE, 'No escribe el bloque de LaraPack en AGENTS.md')
            ->addOption('no-realign', null, InputOption::VALUE_NONE, 'No instala la guía del realineamiento (larapack-realign/SKILL.md, junto al skill)');
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

        try {
            $integrations = is_string($input->getOption('agents')) ? AgentIntegrations::parse($input->getOption('agents')) : [];
        } catch (RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }

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

        $this->realignGuide($input, $output, $path);

        try {
            // El bloque se reescribe siempre, sin --force: lo de fuera de los
            // marcadores es del proyecto y no se toca, y lo de dentro es del
            // paquete y tiene que seguir al paquete.
            if (! $input->getOption('no-agents') && AgentHooks::isAgentsFile(root_path(), $path)) {
                // Con --path=AGENTS.md el skill entero ya es ese archivo: el
                // bloque lo duplicaría, y la siguiente ejecución vería que
                // difiere del paquete para siempre.
                $output->writeln('  <info>El skill ya está en AGENTS.md:</info> no se añade el bloque de LaraPack.');
            } elseif (! $input->getOption('no-agents')) {
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

                $this->gitHooks($output);
            }

            foreach ($integrations as $agent) {
                foreach (AgentIntegrations::install(root_path(), $agent, $path) as $written) {
                    $output->writeln(sprintf('  <info>%s, %s:</info> %s', AgentIntegrations::NAMES[$agent], match ($written['action']) {
                        'created' => 'creado',
                        'updated' => 'actualizado',
                        'appended' => 'bloque añadido al final',
                        'kept' => 'existe y no es de LaraPack, no se toca',
                        default => 'al día',
                    }, $written['target']));
                }
            }

            if ($input->getOption('hooks') || $integrations !== []) {
                $output->writeln('  Qué capas tiene el proyecto y cuáles faltan: larapack:guard --doctor');
            }
        } catch (RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * La guía del realineamiento, como un skill hermano del de LaraPack:
     * cuando un agente llega a un proyecto que se desvió, la encuentra donde
     * busca los skills y empieza por `larapack:realign status`. Con un
     * destino que no es un directorio de skills (`--path=AGENTS.md`) no se
     * adivina otro: el bloque de AGENTS.md apunta a la guía del paquete.
     */
    private function realignGuide(InputInterface $input, OutputInterface $output, string $path): void
    {
        if ($input->getOption('no-realign')) {
            return;
        }

        $target = Skill::realignTargetFor($path);

        if ($target === null) {
            $output->writeln('  La guía del realineamiento sigue en el paquete: docs/realign/README.md (punto de entrada: larapack:realign status).');

            return;
        }

        $result = Skill::installRealign($target, (bool) $input->getOption('force'));

        if ($result['reason'] === 'ya existe') {
            $output->writeln($result['written']
                ? "  <info>Guía del realineamiento al día:</info> {$result['target']}"
                : "  <comment>La guía del realineamiento existe y difiere del paquete:</comment> {$result['target']}\n"
                    .'  Usa --force para sobrescribirla.');

            return;
        }

        $output->writeln("  <info>Guía del realineamiento instalada:</info> {$result['target']}");
    }

    /**
     * La capa de git: los scripts en `.githooks/` y `core.hooksPath`.
     */
    private function gitHooks(OutputInterface $output): void
    {
        $git = GitHooks::install(root_path());

        foreach ($git['written'] as $file) {
            $output->writeln("  <info>Hook de git escrito:</info> {$file}");
        }

        foreach ($git['current'] as $file) {
            $output->writeln("  <info>Hook de git al día:</info> {$file}");
        }

        $output->writeln(match ($git['hooks_path']) {
            'set' => '  <info>git usa los hooks de '.GitHooks::DIRECTORY.'</info> (core.hooksPath). Cada clon lo activa con: git config core.hooksPath '.GitHooks::DIRECTORY,
            'present' => '  <info>git ya usa los hooks de '.GitHooks::DIRECTORY.'.</info>',
            default => '  <comment>git todavía no usa los hooks de '.GitHooks::DIRECTORY.':</comment>',
        });

        foreach ($git['instructions'] as $instruction) {
            $output->writeln('  - '.OutputFormatter::escape($instruction));
        }
    }
}
