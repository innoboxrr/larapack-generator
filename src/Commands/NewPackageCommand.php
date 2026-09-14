<?php

namespace Innoboxrr\LarapackGenerator\Commands;

use Innoboxrr\LarapackGenerator\Commands\Concerns\ReportsGeneration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Support\Skill;
use Innoboxrr\LarapackGenerator\Tools\Config\ConfigTool;
use Innoboxrr\LarapackGenerator\Tools\Package\PackageTool;
use Innoboxrr\LarapackGenerator\Tools\Test\TestTool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Crea un paquete nuevo, listo para generar, probar y publicar.
 *
 * Hasta ahora LaraPack generaba dentro de un proyecto que alguien ya había
 * montado a mano, y lo que ese montaje decidía —versiones, tests, publicación,
 * cómo descubre Laravel el paquete— era justo lo que cada paquete tenía
 * distinto. Con esto el primer commit ya pasa larapack:audit, la CI tiene un
 * test que correr, y un agente encuentra la guía en el propio repositorio.
 */
class NewPackageCommand extends Command
{
    use ReportsGeneration;

    /**
     * El formato de nombre que acepta Composer.
     */
    private const NAME = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    private const NAMESPACE = '/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)+$/';

    private const PROVIDERS = ['App', 'Auth', 'Event', 'Route'];

    protected function configure(): void
    {
        $this->setName('larapack:new')
            ->setDescription('Crea un paquete nuevo listo para generar, probar y publicar')
            ->addArgument('name', InputArgument::REQUIRED, 'Nombre Composer del paquete: vendor/paquete')
            ->addArgument('directory', InputArgument::OPTIONAL, 'Dónde crearlo; por omisión ./<paquete>')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace raíz; por omisión sale del nombre (acme/shop-catalog: Acme\\ShopCatalog)')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Descripción para composer.json y el README')
            ->addOption('license', null, InputOption::VALUE_REQUIRED, 'Licencia de composer.json', 'MIT')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra lo que crearía sin escribir nada')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Formato de salida: txt o json', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');

        if (! preg_match(self::NAME, $name)) {
            return $this->reportFailure($input, $output, "«{$name}» no es un nombre de paquete válido para Composer.", 'Usa vendor/paquete, en minúsculas.');
        }

        $namespace = trim(str_replace('/', '\\', (string) ($input->getOption('namespace') ?? self::namespaceFor($name))), '\\');

        if (! preg_match(self::NAMESPACE, $namespace)) {
            return $this->reportFailure($input, $output, "«{$namespace}» no es un namespace válido.", 'Usa Vendor\\Paquete, con cada parte en PascalCase.');
        }

        $package = explode('/', $name)[1];
        $directory = (string) ($input->getArgument('directory') ?? getcwd().'/'.$package);

        // Un composer.json quiere decir que ahí ya hay un proyecto, con sus
        // decisiones. Rehacerlo por encima es exactamente lo que no se puede
        // deshacer; para generar dentro de él están los demás comandos.
        if (is_file($directory.'/composer.json')) {
            return $this->reportFailure($input, $output, "{$directory} ya tiene composer.json.", 'Para generar dentro de un proyecto existente usa larapack:import o larapack:providers con --root.');
        }

        $description = (string) ($input->getOption('description') ?? "{$package}: paquete Laravel generado con LaraPack.");
        $license = (string) $input->getOption('license');
        $dryRun = (bool) $input->getOption('dry-run');

        // La simulación construye el paquete de verdad en un directorio
        // temporal y lo borra: así dice exactamente lo que crearía, en vez de
        // una lista mantenida aparte que acabaría divergiendo.
        $target = $dryRun ? sys_get_temp_dir().'/larapack-new-'.bin2hex(random_bytes(6)) : $directory;

        if (! is_dir($target) && ! mkdir($target, 0777, true) && ! is_dir($target)) {
            return $this->reportFailure($input, $output, "No se pudo crear {$target}.");
        }

        Generation::reset();

        try {
            ProjectRoot::set($target);

            $this->build($name, $namespace, $description, $license);

            Generation::dryRun($dryRun);

            $this->reportGeneration($input, $output);
        } finally {
            if ($dryRun) {
                ProjectRoot::set(null);
                self::remove($target);
            }
        }

        if (! $dryRun && ! $this->wantsJson($input)) {
            $output->writeln('');
            $output->writeln('  <info>Siguiente:</info>');
            $output->writeln("    cd {$directory}");
            $output->writeln('    composer install');
            $output->writeln('    escribe laraimport.json            (php vendor/bin/builder larapack:schema)');
            $output->writeln('    php vendor/bin/builder larapack:import --vue');
        }

        return Command::SUCCESS;
    }

    private function build(string $name, string $namespace, string $description, string $license): void
    {
        $composerFile = root_path().'/composer.json';

        file_put_contents($composerFile, json_encode(PackageTool::composerJson($name, $namespace, $description, $license), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        Generation::record('create', $composerFile, 'ecosystem.json');

        (new PackageTool)->create($name, $description, $license);

        foreach (self::PROVIDERS as $provider) {
            $tool = 'Innoboxrr\\LarapackGenerator\\Tools\\Providers\\'.$provider.'ServiceProviderTool';

            (new $tool)->create();
        }

        (new ConfigTool)->create();

        (new TestTool)->createScaffold();

        $skill = Skill::install(Skill::DEFAULT_TARGET, false);

        Generation::record($skill['reason'] === '' ? 'create' : 'skipped', $skill['target'], Skill::path(), $skill['reason'] ?: null);

        // Las herramientas reescriben composer.json con json_encode y sin salto
        // final; se deja como lo dejaría Composer para que el primer diff no sea
        // ruido.
        $composer = json_decode((string) file_get_contents($composerFile), true);

        file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * acme/shop-catalog: Acme\ShopCatalog
     */
    public static function namespaceFor(string $name): string
    {
        return implode('\\', array_map(
            fn (string $part): string => str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $part))),
            explode('/', $name)
        ));
    }

    private static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            is_dir($path) && ! is_link($path) ? self::remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
