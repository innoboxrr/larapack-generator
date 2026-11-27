<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Formatter;
use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use RuntimeException;

/**
 * La referencia del diagnóstico: lo que generaría hoy el contrato de cada
 * ámbito, con la versión del generador que corre, en un directorio aparte.
 *
 * Por ámbito se copian el `composer.json`, el `laraimport.json` y el
 * `pint.json` (el del paquete; si no tiene, el de la raíz, que es con el que
 * se formatea un monorepo) y se corre `larapack:import` en un proceso hijo
 * con `--root` en ese directorio. El hijo formatea con la misma Pint que
 * resolvería el proyecto (Formatter::resolve), así que lo regenerado está
 * en el formato en el que el proyecto lo habría dejado. Con
 * `--react`/`--vue` según lo que el manifiesto dice que se generó.
 *
 * Proceso hijo y no en este proceso por dos razones: el estado de una
 * generación es estático (Declaration, TablePrefix, el manifiesto en
 * memoria) y no se puede tener a medias de dos ámbitos, y así se corren
 * varios a la vez (--jobs). En school-management, 82 ámbitos con 6 procesos
 * tardaban unos 2 minutos.
 *
 * Se guarda en `.larapack/realign/cache/<ámbito>/<clave>/`, y la clave es el
 * hash de todo lo que decide el resultado —versión del generador, las
 * plantillas, el contrato, el composer.json, la configuración de Pint, la
 * interfaz y LARAPACK_PINT—: si nada cambió, no se regenera.
 *
 * Lo regenerado nunca se mezcla con el proyecto: el directorio está fuera de
 * todo lo que recorre el generador (es oculto) y el `.gitignore` del estado
 * lo deja fuera de git.
 *
 * @phpstan-import-type Scope from Scopes
 *
 * @phpstan-type Outcome array{ok: bool, directory: string|null, cached: bool, key: string, ui: list<string>, seconds: float, error: string|null}
 */
final class Regeneration
{
    /**
     * Se sube si cambia la forma de regenerar: invalida toda caché.
     */
    private const RECIPE = 'realign-regeneration-1';

    private static ?string $stubsHash = null;

    public function __construct(
        private readonly RealignState $state,
        private readonly int $jobs = 1,
        private readonly bool $useCache = true,
    ) {}

    /**
     * Regenera los ámbitos que tienen contrato. Los que no lo tienen no
     * aparecen: sin contrato no hay referencia (el diagnóstico lo dice).
     *
     * @param  list<Scope>  $scopes
     * @return array<string, Outcome> ruta del ámbito => resultado
     */
    public function run(array $scopes): array
    {
        $this->state->prepare();

        $outcomes = [];
        $pending = [];

        foreach ($scopes as $scope) {
            if (! $scope['contract']) {
                continue;
            }

            $ui = self::uiOf($scope['root']);
            $key = $this->key($scope, $ui);
            $directory = $this->directoryFor($scope['path'], $key);

            if ($this->useCache && is_file($directory.'.done')) {
                $outcomes[$scope['path']] = ['ok' => true, 'directory' => $directory, 'cached' => true, 'key' => $key, 'ui' => $ui, 'seconds' => 0.0, 'error' => null];

                continue;
            }

            $pending[] = ['scope' => $scope, 'key' => $key, 'ui' => $ui, 'directory' => $directory];
        }

        foreach ($this->runAll($pending) as $path => $outcome) {
            $outcomes[$path] = $outcome;
        }

        return $outcomes;
    }

    /**
     * La interfaz que se generó en un ámbito, por lo que dice el manifiesto
     * (los stubs ReactView/ y ModelView/); sin manifiesto, por los módulos
     * que hay en disco.
     *
     * @return list<string>
     */
    public static function uiOf(string $root): array
    {
        $ui = [];

        if (is_file($root.'/.larapack/manifest.json')) {
            $manifest = (new Manifest($root))->snapshot();
            $found = [];

            // Por la ruta y no por el stub: el contrato del modelo
            // (`model.js`) sale del mismo stub en Vue y en React.
            foreach (['', ...$manifest->models()] as $model) {
                foreach (array_keys($manifest->filesFor($model)) as $relative) {
                    if (preg_match('#^resources/(react|vue)/#', (string) $relative, $match) === 1) {
                        $found[$match[1]] = true;
                    }
                }
            }

            foreach (['react', 'vue'] as $framework) {
                if (isset($found[$framework])) {
                    $ui[] = $framework;
                }
            }

            return $ui;
        }

        foreach (['react', 'vue'] as $framework) {
            if (is_dir($root.'/resources/'.$framework.'/src/models')) {
                $ui[] = $framework;
            }
        }

        return $ui;
    }

    /**
     * @param  Scope  $scope
     * @param  list<string>  $ui
     */
    private function key(array $scope, array $ui): string
    {
        return hash('sha256', (string) json_encode([
            self::RECIPE,
            GeneratorVersion::current(),
            self::stubsHash(),
            Files::read($scope['root'].'/laraimport.json'),
            is_file($scope['root'].'/composer.json') ? Files::read($scope['root'].'/composer.json') : null,
            ($pint = self::pintConfigFor($scope['root'], $this->state->root())) === null ? null : Files::read($pint),
            $ui,
            getenv('LARAPACK_PINT') ?: null,
            ProjectRoot::using($scope['root'], fn (): ?string => Formatter::pint()),
        ]));
    }

    /**
     * El hash de todas las plantillas del generador: cambiar una cambia lo
     * regenerado aunque la versión no haya cambiado (un clon de trabajo).
     */
    private static function stubsHash(): string
    {
        if (self::$stubsHash !== null) {
            return self::$stubsHash;
        }

        $directory = dirname(__DIR__, 2).'/Stubs';
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }

        sort($files);

        $context = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($context, substr($file, strlen($directory))."\0".str_replace("\r\n", "\n", Files::read($file))."\0");
        }

        return self::$stubsHash = hash_final($context);
    }

    /**
     * La configuración de Pint de un ámbito: la suya, o la de la raíz del
     * proyecto (en un monorepo se formatea todo desde la raíz).
     */
    public static function pintConfigFor(string $scopeRoot, string $projectRoot): ?string
    {
        foreach ([$scopeRoot, $projectRoot] as $candidate) {
            if (is_file($candidate.'/pint.json')) {
                return $candidate.'/pint.json';
            }
        }

        return null;
    }

    private function directoryFor(string $path, string $key): string
    {
        $slug = $path === '.' ? 'root' : trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $path), '-');

        return $this->state->cacheDirectory().'/'.$slug.'/'.substr($key, 0, 16);
    }

    /**
     * @param  list<array{scope: Scope, key: string, ui: list<string>, directory: string}>  $pending
     * @return array<string, Outcome>
     */
    private function runAll(array $pending): array
    {
        $outcomes = [];
        $running = [];
        $jobs = max(1, $this->jobs);

        while ($pending !== [] || $running !== []) {
            while ($pending !== [] && count($running) < $jobs) {
                $job = array_shift($pending);
                $running[] = $this->start($job);
            }

            foreach ($running as $index => $process) {
                $status = proc_get_status($process['handle']);

                if ($status['running']) {
                    continue;
                }

                $outcomes[$process['job']['scope']['path']] = $this->finish($process, $status['exitcode']);
                unset($running[$index]);
            }

            if ($running !== []) {
                usleep(20_000);
            }
        }

        return $outcomes;
    }

    /**
     * @param  array{scope: Scope, key: string, ui: list<string>, directory: string}  $job
     * @return array{job: array{scope: Scope, key: string, ui: list<string>, directory: string}, handle: resource, started: float}
     */
    private function start(array $job): array
    {
        $directory = $job['directory'];

        // Lo de una clave anterior de este ámbito ya no sirve: se borra para
        // que la caché no crezca con cada cambio del contrato.
        self::remove(dirname($directory));

        if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear {$directory}.");
        }

        $root = $job['scope']['root'];

        foreach (['composer.json', 'laraimport.json'] as $file) {
            if (is_file($root.'/'.$file)) {
                copy($root.'/'.$file, $directory.'/'.$file);
            }
        }

        $pint = self::pintConfigFor($root, $this->state->root());

        if ($pint !== null) {
            copy($pint, $directory.'/pint.json');
        }

        $command = [PHP_BINARY, dirname(__DIR__, 3).'/builder', 'larapack:import', $directory.'/laraimport.json',
            '--root='.$directory, '--format=json', ...array_map(fn (string $ui): string => '--'.$ui, $job['ui'])];

        $handle = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $directory.'.import.json', 'w'],
            2 => ['file', $directory.'.stderr', 'w'],
        ], $pipes, $directory);

        if (! is_resource($handle)) {
            throw new RuntimeException('No se pudo arrancar la regeneración de '.$job['scope']['path'].'.');
        }

        // Nada que leer: un hijo que preguntara algo no se quedaría esperando.
        fclose($pipes[0]);

        return ['job' => $job, 'handle' => $handle, 'started' => microtime(true)];
    }

    /**
     * @param  array{job: array{scope: Scope, key: string, ui: list<string>, directory: string}, handle: resource, started: float}  $process
     * @return Outcome
     */
    private function finish(array $process, int $exitCode): array
    {
        proc_close($process['handle']);

        $job = $process['job'];
        $directory = $job['directory'];
        $seconds = round(microtime(true) - $process['started'], 2);
        $report = is_file($directory.'.import.json') ? json_decode((string) file_get_contents($directory.'.import.json'), true) : null;

        if ($exitCode !== 0 || ! is_array($report) || ($report['ok'] ?? false) !== true) {
            $error = is_array($report) && is_string($report['error'] ?? null) ? $report['error'] : null;
            $error ??= trim((string) @file_get_contents($directory.'.stderr')) ?: "larapack:import terminó con código {$exitCode}.";

            return ['ok' => false, 'directory' => null, 'cached' => false, 'key' => $job['key'], 'ui' => $job['ui'], 'seconds' => $seconds, 'error' => $error];
        }

        file_put_contents($directory.'.done', (string) json_encode(['seconds' => $seconds]));

        return ['ok' => true, 'directory' => $directory, 'cached' => false, 'key' => $job['key'], 'ui' => $job['ui'], 'seconds' => $seconds, 'error' => null];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
