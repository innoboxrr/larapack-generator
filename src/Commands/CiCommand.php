<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\CiGate;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\UnifiedDiff;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

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
 * puede fusionar, y eso no lo decide un comando ni un agente. La orden pide
 * `enforce_admins=true` (--no-enforce-admins lo quita, con aviso): sin él,
 * quien empuja como administrador se salta la puerta.
 *
 * Con --runs-on el job corre en los runners del proyecto; sin él, en el
 * alojado de GitHub, y si otros workflows corren en otro sitio, lo dice.
 *
 * Si el workflow no se puede escribir (difiere y no hay --force), no se
 * escribe nada: tampoco CODEOWNERS. Un intento fallido no deja el
 * repositorio a medias.
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
            ->addOption('owner', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dueño en CODEOWNERS (repetible): @usuario, @organización/equipo o un correo')
            ->addOption('runs-on', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Etiqueta del runner (repetible) o una lista JSON: --runs-on=self-hosted --runs-on=linux, o --runs-on=\'["self-hosted","linux"]\'. Sin ella, '.CiGate::DEFAULT_RUNNER)
            ->addOption('enforce-admins', null, InputOption::VALUE_NEGATABLE, 'En la orden de protección: que tampoco un administrador se salte la puerta (de fábrica; --no-enforce-admins lo quita)', true);
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

        try {
            $runsOn = CiGate::runsOn(array_values(array_map('strval', (array) $input->getOption('runs-on'))));
        } catch (UnexpectedValueException $e) {
            $output->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return Command::FAILURE;
        }

        $branch = CiGate::defaultBranch($root);
        $top = CiGate::gitTop($root);

        try {
            $ok = $this->workflow($root, $branch, $runsOn, $dryRun, (bool) $input->getOption('force'), $output);
            // Si el workflow no se escribe, CODEOWNERS tampoco: se enseña lo
            // que se añadiría y nada más.
            $codeowners = $this->codeowners($root, $owners, $dryRun || ! $ok, $output);
        } catch (RuntimeException $e) {
            $output->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return Command::FAILURE;
        }

        if (! $ok) {
            $output->writeln("\n  <error>No se escribió nada.</error> Mira el diff de ".CiGate::WORKFLOW.' y corre de nuevo con --force si lo quieres así.');
        }

        if ($runsOn === []) {
            $this->suggestRunners($root, $output);
        }

        if ($top !== null && rtrim($top, '/') !== rtrim($root, '/')) {
            $output->writeln("\n  <comment>Ojo:</comment> la raíz del repositorio git es ".OutputFormatter::escape($top).'. GitHub sólo lee .github/ en esa raíz: lo de aquí sirve si este proyecto se publica como repositorio propio.');
        }

        $this->manualSteps($root, $branch, $codeowners, (bool) $input->getOption('enforce-admins'), $output);

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Sin --runs-on el job va al runner alojado. Si los demás workflows del
     * proyecto corren en otro sitio —los suyos, porque se acabaron los
     * minutos—, se dice y se propone la opción.
     */
    private function suggestRunners(string $root, OutputInterface $output): void
    {
        $others = array_filter(
            CiGate::otherRunners($root),
            fn (string $value): bool => $value !== CiGate::DEFAULT_RUNNER,
            ARRAY_FILTER_USE_KEY
        );

        if ($others === []) {
            return;
        }

        $output->writeln("\n  <comment>Runner:</comment> el job va a ".CiGate::DEFAULT_RUNNER.', y otros workflows del proyecto corren en otro sitio:');

        foreach ($others as $value => $files) {
            $labels = CiGate::labelsOf((string) $value);
            $output->writeln('    runs-on: '.OutputFormatter::escape((string) $value).' ('.OutputFormatter::escape(implode(', ', $files)).')'
                .($labels === null ? '' : ' -> --runs-on='.CiGate::shellQuote((string) json_encode($labels, JSON_UNESCAPED_SLASHES))));
        }

        $output->writeln([
            '  Si los runners alojados no te sirven —sin minutos, o verify tarda en un monorepo—, corre de nuevo con',
            '  --runs-on: un job que no arranca deja el check exigido sin llegar, y ningún PR se fusiona.',
        ]);
    }

    /**
     * @param  list<string>  $runsOn
     * @return bool si el workflow quedó (o quedaría) como lo genera LaraPack
     */
    private function workflow(string $root, ?string $branch, array $runsOn, bool $dryRun, bool $force, OutputInterface $output): bool
    {
        $file = "{$root}/".CiGate::WORKFLOW;
        $wanted = CiGate::workflow($root, $branch, $runsOn);
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

    private function manualSteps(string $root, ?string $branch, string $codeowners, bool $enforceAdmins, OutputInterface $output): void
    {
        $repository = CiGate::repository($root) ?? 'DUEÑO/REPOSITORIO';

        if ($branch !== null && ! CiGate::isSafeBranch($branch)) {
            $output->writeln("\n  <comment>Ojo:</comment> la rama por defecto del remoto tiene caracteres que no se escriben en una orden de shell («"
                .OutputFormatter::escape(Guard::inline($branch)).'»): la orden lleva RAMA en su lugar. Escríbela tú.');
            $branch = null;
        }

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

        $output->writeln('     '.CiGate::protectionCommand($repository, $branch, $enforceAdmins), OutputInterface::OUTPUT_RAW);

        $output->writeln([
            '',
            '     PUT sustituye la protección entera: si la rama ya exige otros checks o revisiones,',
            '     míralos antes (gh api '.CiGate::shellQuote("repos/{$repository}/branches/{$branch}/protection").') y añádelos a la orden.',
            '',
        ]);

        if ($enforceAdmins) {
            $output->writeln([
                '     enforce_admins=true: tampoco un administrador se salta el check ni la revisión. La puerta sólo',
                '     vale si nadie entra por fuera de ella: con false, un push directo a '.$branch.' con una cuenta de',
                '     administrador —un orquestador que sube el SHA ya verificado con la cuenta del dueño— no pasa por',
                '     verify --strict ni por la revisión del candado.',
                '     El precio: GitHub no deja aprobar un PR propio. Los PR del agente tienen que salir de una cuenta',
                '     que no sea la del dueño (un bot sin administración), o hace falta un segundo dueño de código.',
                '     Si aun así lo quieres sin ella: --no-enforce-admins.',
            ]);

            return;
        }

        $output->writeln([
            '     <comment>Ojo: enforce_admins=false.</comment> La puerta sólo vale mientras todo entre por PR y nadie',
            '     empuje como administrador: un push directo a '.$branch.' con una cuenta de administrador —un',
            '     orquestador que sube el SHA verificado con la cuenta del dueño— se salta verify --strict y la',
            '     revisión del candado, y GitHub sólo lo deja anotado. Quita --no-enforce-admins para cerrarla.',
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
