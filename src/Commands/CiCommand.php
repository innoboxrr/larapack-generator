<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\CiGate;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\UnifiedDiff;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pone `larapack:verify --strict` como puerta de la rama en un proyecto que ya
 * existe.
 *
 * `larapack:new` ya deja el job en el tests.yml de cada paquete nuevo. Aquí
 * no se edita ningún workflow del proyecto —son suyos, y fusionar YAML es
 * adivinar—: se crea uno propio, `.github/workflows/larapack.yml`, y si ya
 * existe y alguien lo cambió, no se pisa sin --force.
 *
 * Con --owner deja además en CODEOWNERS las rutas que tiene que revisar el
 * dueño. Sin él no escribe nada ahí: un dueño inventado sería peor que
 * ninguno.
 *
 * Lo que no puede hacer —proteger la rama— lo imprime como una orden de `gh`
 * para que la corra el dueño del repositorio. Nunca la ejecuta: cambia quién
 * puede fusionar, y eso no lo decide un comando ni un agente.
 */
class CiCommand extends Command
{
    use TargetsProject;

    protected function configure(): void
    {
        $this->addRootOption();

        $this->setName('larapack:ci')
            ->setDescription('Crea el workflow que exige larapack:verify --strict y, con --owner, las líneas de CODEOWNERS')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que escribiría sin escribir nada')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sobrescribe .github/workflows/larapack.yml aunque difiera')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dueño en CODEOWNERS (repetible): @usuario, @organización/equipo o un correo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $root = str_replace('\\', '/', root_path());
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var list<string> $owners */
        $owners = array_values(array_unique(array_map('trim', (array) $input->getOption('owner'))));

        foreach ($owners as $owner) {
            if (! CiGate::isOwner($owner)) {
                $output->writeln('<error>--owner='.OutputFormatter::escape($owner).' no es un dueño de CODEOWNERS.</error> Usa @usuario, @organización/equipo o un correo.');

                return Command::FAILURE;
            }
        }

        $branch = CiGate::defaultBranch($root);
        $top = CiGate::gitTop($root);

        try {
            $ok = $this->workflow($root, $branch, $dryRun, (bool) $input->getOption('force'), $output);
            $codeowners = $this->codeowners($root, $owners, $dryRun, $output);
        } catch (RuntimeException $e) {
            $output->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return Command::FAILURE;
        }

        if ($top !== null && rtrim($top, '/') !== rtrim($root, '/')) {
            $output->writeln("\n  <comment>Ojo:</comment> la raíz del repositorio git es ".OutputFormatter::escape($top).'. GitHub sólo lee .github/ en esa raíz: lo de aquí sirve si este proyecto se publica como repositorio propio.');
        }

        $this->manualSteps($root, $branch, $codeowners, $output);

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return bool si el workflow quedó (o quedaría) como lo genera LaraPack
     */
    private function workflow(string $root, ?string $branch, bool $dryRun, bool $force, OutputInterface $output): bool
    {
        $file = "{$root}/".CiGate::WORKFLOW;
        $wanted = CiGate::workflow($root, $branch);
        $current = is_file($file) ? (string) file_get_contents($file) : null;

        if ($current === $wanted) {
            $output->writeln('  <info>sin cambios</info>  '.CiGate::WORKFLOW);

            return true;
        }

        if ($current !== null && ! $force) {
            $output->writeln('  <comment>difiere</comment>     '.CiGate::WORKFLOW.': no se pisa sin --force. Lo que escribiría:');
            $output->writeln(UnifiedDiff::of($current, $wanted, CiGate::WORKFLOW), OutputInterface::OUTPUT_RAW);

            return false;
        }

        if ($dryRun) {
            $output->writeln('  <info>'.($current === null ? 'crearía' : 'reescribiría').'</info>     '.CiGate::WORKFLOW);
            $output->writeln(UnifiedDiff::of($current ?? '', $wanted, CiGate::WORKFLOW), OutputInterface::OUTPUT_RAW);

            return true;
        }

        $this->write($file, $wanted);
        $output->writeln('  <info>'.($current === null ? 'creado' : 'reescrito').'</info>      '.CiGate::WORKFLOW);

        return true;
    }

    /**
     * @param  list<string>  $owners
     * @return string el CODEOWNERS que lee GitHub en este proyecto
     */
    private function codeowners(string $root, array $owners, bool $dryRun, OutputInterface $output): string
    {
        $relative = CiGate::codeownersFile($root);
        $paths = CiGate::ownedPaths($relative);

        if ($owners === []) {
            $output->writeln("  <comment>sin dueño</comment>   {$relative}: no se toca. Sin --owner no se inventa quién aprueba: corre de nuevo con --owner=@usuario (o @organización/equipo) para que GitHub pida su revisión en:");

            foreach ($paths as $path) {
                $output->writeln("                {$path}");
            }

            return $relative;
        }

        $file = "{$root}/{$relative}";
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $merged = CiGate::mergeCodeowners($current, $paths, $owners);

        foreach ($merged['present'] as $line) {
            $output->writeln("  <info>ya está</info>     {$relative}: ".OutputFormatter::escape($line));
        }

        foreach ($merged['foreign'] as $line) {
            $output->writeln("  <comment>otro dueño</comment>  {$relative}: ".OutputFormatter::escape($line).' — no se toca; decide tú si cambiarlo.');
        }

        if ($merged['added'] === []) {
            return $relative;
        }

        if (! $dryRun) {
            $this->write($file, $merged['contents']);
        }

        foreach ($merged['added'] as $line) {
            $output->writeln('  <info>'.($dryRun ? 'añadiría' : 'añadido').'</info>     '.$relative.': '.OutputFormatter::escape($line));
        }

        return $relative;
    }

    private function manualSteps(string $root, ?string $branch, string $codeowners, OutputInterface $output): void
    {
        $repository = CiGate::repository($root) ?? 'DUEÑO/REPOSITORIO';
        $branch ??= 'RAMA';

        $output->writeln([
            '',
            '  <options=bold>Lo que este comando no hace: lo hace el dueño del repositorio.</>',
            '',
            '  1. Comitear '.CiGate::WORKFLOW." y {$codeowners}, y dejar que el workflow corra una vez:",
            '     el check «'.CiGate::JOB.'» sólo se puede exigir cuando GitHub ya lo vio.',
            '  2. Si hay excepciones #[HandRolled], aprobarlas una vez, revisar el candado y comitearlo:',
            '     larapack:hand-rolled list, y larapack:hand-rolled approve --all (o una a una).',
            '     '.HandRolledLock::PATH.' es la aprobación: un agente no lo edita.',
            "  3. Proteger la rama {$branch}: exigir el check «".CiGate::JOB.'» y la revisión de los dueños de código',
            '     (Require review from Code Owners). Con la CLI de GitHub, en una línea:',
            '',
        ]);

        $output->writeln('     '.CiGate::protectionCommand($repository, $branch), OutputInterface::OUTPUT_RAW);

        $output->writeln([
            '',
            '     PUT sustituye la protección entera: si la rama ya exige otros checks o revisiones,',
            "     míralos antes (gh api repos/{$repository}/branches/{$branch}/protection) y añádelos a la orden.",
            '     Si los PR del agente salen con la cuenta del dueño, GitHub no deja que se apruebe a sí mismo:',
            '     el administrador fusiona saltándose la revisión, y el aviso queda a la vista en el PR.',
        ]);
    }

    private function write(string $file, string $contents): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException("No se pudo escribir {$file}.");
        }
    }
}
