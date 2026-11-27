<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\TargetsProject;
use Innoboxrr\LarapackGenerator\Support\BranchProtection;
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
 * Lo que no puede hacer —proteger la rama— se lo deja al dueño a un paso:
 * escribe `.github/rulesets/larapack-verify.json`, un ruleset de GitHub para
 * importar desde la web o mandar con `gh api`, e imprime las tres vías (la
 * web, `gh`, y la protección clásica de ramas). Nunca las ejecuta: cambian
 * quién puede fusionar, y eso no lo decide un comando ni un agente. De
 * fábrica, nadie se salta la regla (--no-enforce-admins deja pasar a los
 * administradores, con aviso).
 *
 * Con --check no escribe nada: pregunta a GitHub si la rama por defecto
 * exige el check y lo dice —exige, protegida sin exigirlo, sin protección o
 * no se pudo saber—. Es lo mismo que corre el workflow en su último paso.
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
            ->setDescription('Crea el workflow que exige larapack:verify --strict, el ruleset para proteger la rama y, con --owner, las líneas de CODEOWNERS; con --check, dice si la rama exige el check')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que escribiría sin escribir nada')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sobrescribe .github/workflows/larapack.yml y '.BranchProtection::RULESET_FILE.' aunque difieran')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dueño en CODEOWNERS (repetible): @usuario, @organización/equipo o un correo')
            ->addOption('runs-on', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Etiqueta del runner (repetible) o una lista JSON: --runs-on=self-hosted --runs-on=linux, o --runs-on=\'["self-hosted","linux"]\'. Sin ella, '.CiGate::DEFAULT_RUNNER)
            ->addOption('enforce-admins', null, InputOption::VALUE_NEGATABLE, 'En el ruleset y en la orden de protección: que tampoco un administrador se salte la puerta (de fábrica; --no-enforce-admins lo quita)', true)
            ->addOption('check', null, InputOption::VALUE_NONE, 'No escribe nada: pregunta a GitHub si la rama por defecto exige el check «'.CiGate::JOB.'»')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Con --check: dueño/repositorio (de fábrica, el remoto origin o GITHUB_REPOSITORY)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Con --check: la rama (de fábrica, la rama por defecto según GitHub)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Con --check: txt, json o github (anotaciones y resumen del job de Actions)', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRootOption($input);

        $root = str_replace('\\', '/', root_path());

        if ($input->getOption('check')) {
            return $this->check($input, $root, $output);
        }

        foreach (['repo', 'branch'] as $option) {
            if ($input->getOption($option) !== null) {
                $output->writeln("<error>--{$option} sólo va con --check.</error>");

                return Command::FAILURE;
            }
        }

        if ($input->getOption('format') !== 'txt') {
            $output->writeln('<error>--format sólo va con --check.</error>');

            return Command::FAILURE;
        }

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

        $enforceAdmins = (bool) $input->getOption('enforce-admins');

        try {
            $ok = $this->files($root, [
                CiGate::WORKFLOW => CiGate::workflow($root, $branch, $runsOn),
                BranchProtection::RULESET_FILE => BranchProtection::rulesetJson($enforceAdmins),
            ], $dryRun, (bool) $input->getOption('force'), $output);
            // Si el workflow no se escribe, CODEOWNERS tampoco: se enseña lo
            // que se añadiría y nada más.
            $codeowners = $this->codeowners($root, $owners, $dryRun || ! $ok, $output);
        } catch (RuntimeException $e) {
            $output->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return Command::FAILURE;
        }

        if (! $ok) {
            $output->writeln("\n  <error>No se escribió nada.</error> Mira el diff y corre de nuevo con --force si lo quieres así.");
        }

        if ($runsOn === []) {
            $this->suggestRunners($root, $output);
        }

        if ($top !== null && rtrim($top, '/') !== rtrim($root, '/')) {
            $output->writeln("\n  <comment>Ojo:</comment> la raíz del repositorio git es ".OutputFormatter::escape($top).'. GitHub sólo lee .github/ en esa raíz: lo de aquí sirve si este proyecto se publica como repositorio propio.');
        }

        $this->manualSteps($root, $branch, $codeowners, $enforceAdmins, $output);

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
     * Escribe los archivos de la puerta —el workflow y el ruleset— o
     * ninguno: si uno que ya existe difiere y no hay --force, no se escribe
     * nada, tampoco el que faltaba.
     *
     * @param  array<string, string>  $files  ruta relativa => contenido
     * @return bool si quedaron (o quedarían) como los genera LaraPack
     */
    private function files(string $root, array $files, bool $dryRun, bool $force, OutputInterface $output): bool
    {
        $plan = [];

        foreach ($files as $relative => $wanted) {
            $file = "{$root}/{$relative}";
            $plan[$relative] = [$file, is_file($file) ? (string) file_get_contents($file) : null, $wanted];
        }

        $blocked = ! $force && array_filter($plan, fn (array $entry): bool => $entry[1] !== null && $entry[1] !== $entry[2]) !== [];

        foreach ($plan as $relative => [$file, $current, $wanted]) {
            if ($current === $wanted) {
                $output->writeln('  <info>sin cambios</info>  '.$relative);

                continue;
            }

            if ($current !== null && ! $force) {
                $output->writeln('  <comment>difiere</comment>     '.$relative.': no se pisa sin --force. Lo que escribiría:');
                $output->writeln(UnifiedDiff::of($current, $wanted, $relative), OutputInterface::OUTPUT_RAW);

                continue;
            }

            if ($blocked) {
                $output->writeln('  <comment>no se crea</comment>  '.$relative.': otro archivo difiere, y no se deja la puerta a medias.');

                continue;
            }

            if ($dryRun) {
                $output->writeln('  <info>'.($current === null ? 'crearía' : 'reescribiría').'</info>     '.$relative);
                $output->writeln(UnifiedDiff::of($current ?? '', $wanted, $relative), OutputInterface::OUTPUT_RAW);

                continue;
            }

            $this->write($file, $wanted);
            $output->writeln('  <info>'.($current === null ? 'creado' : 'reescrito').'</info>      '.$relative);
        }

        return ! $blocked;
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

        $output->writeln([
            '',
            '  <options=bold>Lo que este comando no hace: lo hace el dueño del repositorio.</>',
            '',
            '  1. Comitear '.CiGate::WORKFLOW.', '.BranchProtection::RULESET_FILE." y {$codeowners}, y dejar que",
            '     el workflow corra una vez: en la web, el check «'.CiGate::JOB.'» sólo se ofrece cuando GitHub ya lo vio.',
            '  2. Si hay excepciones #[HandRolled], aprobarlas una vez, revisar el candado y comitearlo:',
            '     larapack:hand-rolled list, y larapack:hand-rolled approve --all (o una a una).',
            '     '.HandRolledLock::PATH.' es la aprobación: un agente no lo edita.',
        ]);

        $this->protectionGuide($root, $repository, $branch ?? 'RAMA', $enforceAdmins, '  3. ', $output);
    }

    /**
     * Cómo hacer que la rama exija el check: las tres vías, cómo comprobarlo
     * y lo que cuesta. Lo imprime `larapack:ci` al escribir y `--check`
     * cuando la rama no lo exige.
     */
    private function protectionGuide(string $root, string $repository, string $branch, bool $enforceAdmins, string $bullet, OutputInterface $output): void
    {
        $binary = CiGate::isApplication($root) ? 'php artisan' : 'php vendor/bin/builder';
        $ruleset = BranchProtection::RULESET_FILE;

        $output->writeln([
            $bullet."Proteger la rama {$branch}: que exija el check «".CiGate::JOB.'» y la revisión de los dueños de código',
            '     (Require review from Code Owners), y que nadie la borre ni la reescriba. Una de estas tres vías:',
            '',
            '     a) En la web, importando el ruleset (la recomendada). Fija el check a GitHub Actions: un estado',
            '        con ese nombre publicado con otro token no cuenta.',
            "        https://github.com/{$repository}/settings/rules -> New ruleset -> Import a ruleset",
            "        -> {$ruleset} -> revisar -> Create.",
            '',
            '     b) Con la CLI de GitHub, el mismo ruleset:',
            '',
        ]);

        $output->writeln('        gh api --method POST '.CiGate::shellQuote("repos/{$repository}/rulesets").' --input '.CiGate::shellQuote($ruleset), OutputInterface::OUTPUT_RAW);

        $output->writeln([
            '',
            '        Si ya lo importaste y cambió, se actualiza en vez de crear otro (el ID lo da --check):',
            '        gh api --method PUT '.OutputFormatter::escape(CiGate::shellQuote("repos/{$repository}/rulesets/ID")).' --input '.OutputFormatter::escape(CiGate::shellQuote($ruleset)),
            '',
            '     c) Con la protección clásica de ramas, si prefieres no usar rulesets. En una línea:',
            '',
        ]);

        $output->writeln('        '.CiGate::protectionCommand($repository, $branch, $enforceAdmins), OutputInterface::OUTPUT_RAW);

        $output->writeln([
            '',
            '        PUT sustituye la protección entera: si la rama ya exige otros checks o revisiones,',
            '        míralos antes (gh api '.OutputFormatter::escape(CiGate::shellQuote("repos/{$repository}/branches/{$branch}/protection")).') y añádelos a la orden.',
            '        La protección clásica no fija de qué app llega el check: por eso el ruleset va primero.',
            '',
        ]);

        if ($enforceAdmins) {
            $output->writeln([
                '     Sin bypass en el ruleset, y enforce_admins=true en la clásica: tampoco un administrador se salta el check ni la revisión.',
                '     La puerta sólo vale si nadie entra por fuera de ella: si no, un push directo a '.$branch.' con una cuenta de',
                '     administrador —un orquestador que sube el SHA ya verificado con la cuenta del dueño— no pasa por',
                '     verify --strict ni por la revisión del candado.',
                '     El precio: GitHub no deja aprobar un PR propio. Los PR del agente tienen que salir de una cuenta',
                '     que no sea la del dueño (un bot sin administración), o hace falta un segundo dueño de código.',
                '     Si aun así lo quieres sin ella: --no-enforce-admins.',
            ]);
        } else {
            $output->writeln([
                '     <comment>Ojo: enforce_admins=false</comment>, y el papel de administrador como bypass del ruleset. La puerta sólo',
                '     vale mientras todo entre por PR y nadie empuje como administrador: un push directo a '.$branch.' con una cuenta',
                '     de administrador —un orquestador que sube el SHA verificado con la cuenta del dueño— se salta verify --strict y',
                '     la revisión del candado, y GitHub sólo lo deja anotado. Quita --no-enforce-admins para cerrarla.',
            ]);
        }

        $output->writeln([
            '',
            '     En un repositorio privado de una cuenta Free, GitHub no aplica ni rulesets ni protección de ramas:',
            '     hace falta Pro, Team o Enterprise (o que el repositorio sea público).',
            '',
            '     Para comprobar que quedó (sólo lee; usa GH_TOKEN, GITHUB_TOKEN o la sesión de gh):',
            '',
            "        {$binary} larapack:ci --check",
            '',
            '     Tiene que decir «Exige «'.CiGate::JOB.'»». El workflow lo vuelve a mirar al final de cada corrida y,',
            '     mientras no lo exija, lo avisa (::warning y el resumen del job). No falla por eso: sería circular.',
        ]);
    }

    /**
     * `--check`: si la rama exige el check, preguntándole a GitHub. Nunca
     * escribe nada, ni en el proyecto ni en GitHub.
     *
     * Sale con 0 si lo exige, 1 si no (protegida sin exigirlo, o sin
     * protección) y 2 si no se pudo saber: lo que no se pudo leer nunca se
     * da por protegido.
     */
    private function check(InputInterface $input, string $root, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');

        if (! in_array($format, ['txt', 'json', 'github'], true)) {
            $output->writeln('<error>--format='.OutputFormatter::escape($format).' no existe: txt, json o github.</error>');

            return Command::FAILURE;
        }

        foreach (['dry-run', 'force', 'owner', 'runs-on'] as $option) {
            $value = $input->getOption($option);

            if ($value === true || (is_array($value) && $value !== [])) {
                $output->writeln("<error>--check sólo lee: no va con --{$option}.</error>");

                return Command::FAILURE;
            }
        }

        $repository = $input->getOption('repo');
        $repository = is_string($repository) ? trim($repository) : null;

        if ($repository === null) {
            $environment = getenv('GITHUB_REPOSITORY');
            $repository = CiGate::repository($root) ?? (is_string($environment) && $environment !== '' ? $environment : null);
        }

        if ($repository !== null && ! BranchProtection::isRepository($repository)) {
            $output->writeln('<error>«'.OutputFormatter::escape($repository).'» no es dueño/repositorio.</error>');

            return Command::FAILURE;
        }

        $branch = $input->getOption('branch');
        $branch = is_string($branch) && trim($branch) !== '' ? trim($branch) : null;

        $report = BranchProtection::check(BranchProtection::api(), $repository, $branch);

        if ($format === 'json') {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            if ($format === 'github') {
                $this->annotate($report, $root, $output);
            }

            $this->report($report, $root, (bool) $input->getOption('enforce-admins'), $output);
        }

        return match ($report['status']) {
            BranchProtection::REQUIRED => Command::SUCCESS,
            BranchProtection::UNKNOWN => Command::INVALID,
            default => Command::FAILURE,
        };
    }

    /**
     * El informe de --check en texto.
     *
     * @param  array{repository: string|null, branch: string|null, status: string, via: string|null, rulesets: list<array{id: int|null, name: string|null, source: string|null, requires_check: bool, app_id: int|null, code_owner_review: bool, bypass: string, you_can_bypass: bool|null}>, classic: array{requires_check: bool, app_id: int|null, admins_included: bool|null, code_owner_review: bool|null}|null, holes: list<string>, unverified: list<string>, problems: list<string>}  $report
     */
    private function report(array $report, string $root, bool $enforceAdmins, OutputInterface $output): void
    {
        $repository = $report['repository'];
        $branch = $report['branch'];
        $check = '«'.CiGate::JOB.'»';

        $output->writeln('  '.OutputFormatter::escape(($repository ?? 'repositorio sin saber').', rama '.($branch === null ? 'sin saber' : Guard::inline($branch))
            .($report['via'] === null ? '' : ' (por '.$report['via'].')')).':');
        $output->writeln('');
        $output->writeln('  '.match ($report['status']) {
            BranchProtection::REQUIRED => "<info>Exige {$check}.</info>",
            BranchProtection::WITHOUT_CHECK => "<error>Protegida, pero NO exige {$check}:</error> un PR en rojo se fusiona igual.",
            BranchProtection::UNPROTECTED => '<error>Sin protección ni reglas:</error> un PR en rojo se fusiona igual, y se puede empujar directo.',
            default => "<comment>No se pudo saber si exige {$check}.</comment> No se da por protegida.",
        });

        foreach ($report['rulesets'] as $ruleset) {
            $parts = [$ruleset['requires_check'] ? 'exige el check'.match ($ruleset['app_id']) {
                null => ' (de cualquier fuente)',
                BranchProtection::ACTIONS_APP_ID => ' (de GitHub Actions)',
                default => " (de la app {$ruleset['app_id']})",
            } : 'no exige el check'];

            $parts[] = $ruleset['code_owner_review'] ? 'revisión de dueños de código' : 'sin revisión de dueños de código';
            $parts[] = 'bypass: '.match ($ruleset['bypass']) {
                'none' => 'nadie',
                'some' => 'alguien',
                default => 'sin saber',
            };

            $output->writeln('    '.OutputFormatter::escape(BranchProtection::label($ruleset).': '.implode(', ', $parts)).'.');
        }

        $classic = $report['classic'];

        $output->writeln('    protección clásica: '.($classic === null ? 'no tiene.' : ($classic['requires_check'] ? 'exige el check.' : 'no exige el check.')));

        foreach (['holes' => '<comment>Abierto:</comment>', 'unverified' => 'Sin comprobar con estas credenciales:', 'problems' => 'No se pudo leer:'] as $key => $title) {
            if ($report[$key] === []) {
                continue;
            }

            $output->writeln(['', '  '.$title]);

            foreach ($report[$key] as $item) {
                $output->writeln('    - '.OutputFormatter::escape($item));
            }
        }

        if ($report['status'] === BranchProtection::REQUIRED) {
            return;
        }

        $output->writeln('');

        $this->protectionGuide(
            $root,
            $repository ?? 'DUEÑO/REPOSITORIO',
            $branch !== null && CiGate::isSafeBranch($branch) ? $branch : 'RAMA',
            $enforceAdmins,
            '  ',
            $output
        );
    }

    /**
     * `--format=github`: anotaciones del job y el resumen. El `::warning`
     * sale en la página de la corrida y en el PR; el resumen, con los pasos.
     *
     * @param  array{repository: string|null, branch: string|null, status: string, holes: list<string>, unverified: list<string>, problems: list<string>}  $report
     */
    private function annotate(array $report, string $root, OutputInterface $output): void
    {
        $where = 'La rama '.($report['branch'] ?? 'por defecto').($report['repository'] === null ? '' : ' de '.$report['repository']);
        $check = '«'.CiGate::JOB.'»';

        $warning = match ($report['status']) {
            BranchProtection::REQUIRED => null,
            BranchProtection::WITHOUT_CHECK => "{$where} está protegida pero no exige {$check}: un PR en rojo se fusiona igual. Los pasos, en el resumen del job.",
            BranchProtection::UNPROTECTED => "{$where} no está protegida: un PR en rojo se fusiona igual. Los pasos, en el resumen del job.",
            default => 'No se pudo saber si '.lcfirst($where)." exige {$check}; no se da por protegida. ".implode(' ', $report['problems']),
        };

        if ($warning !== null) {
            $output->writeln(BranchProtection::annotation('warning', 'La puerta de LaraPack no está cerrada', trim($warning)), OutputInterface::OUTPUT_RAW);
        }

        foreach ($report['holes'] as $hole) {
            $output->writeln(BranchProtection::annotation('warning', 'La puerta de LaraPack tiene un hueco', $hole), OutputInterface::OUTPUT_RAW);
        }

        $summary = getenv('GITHUB_STEP_SUMMARY');

        if (is_string($summary) && $summary !== '') {
            $server = getenv('GITHUB_SERVER_URL');

            file_put_contents($summary, BranchProtection::summary(
                $report,
                CiGate::isApplication($root) ? 'php artisan' : 'php vendor/bin/builder',
                is_string($server) && $server !== '' ? $server : 'https://github.com'
            ), FILE_APPEND);
        }
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
