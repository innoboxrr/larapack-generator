<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * `verify` sobre un proyecto entero: la raíz y sus paquetes locales.
 *
 * Un proyecto como school-management tiene un manifiesto en la raíz y otro en
 * cada paquete (`packages/<p>/.larapack/manifest.json`). El Verifier sólo lee
 * uno, el de su raíz, así que desde la raíz lo que faltaba en un paquete no
 * salía —aunque el código de todos sí se recorría, porque ControllerShape mira
 * `packages/*`—. Había que correrlo una vez por paquete con su --root: 81
 * veces.
 *
 * Aquí se corre el Verifier de la raíz, como siempre, y además las
 * comprobaciones del manifiesto de cada paquete local que lo tenga, contra su
 * propia raíz. Las del código no se repiten: ya las hizo la raíz para todos.
 * Cada hallazgo lleva `package` —el nombre de Composer del paquete al que
 * pertenece, o null si es del núcleo— y su ruta, relativa a la raíz del
 * proyecto.
 *
 * Qué es un paquete local es lo mismo que para el resto de la guardia
 * (ProjectSources): los repositorios `path` del composer.json y
 * `packages/*`. Pero sólo se lee el manifiesto de los que están dentro de la
 * raíz: un repositorio `path` que apunta fuera (`../school-core-contracts`,
 * desde un paquete) es una dependencia, no parte de lo que se mide. Su
 * código se sigue recorriendo, como antes, y sale en el resumen sin
 * manifiesto.
 *
 * @phpstan-import-type Finding from Verifier
 *
 * @phpstan-type ScopedFinding array{level: string, check: string, model: string|null, file: string|null, message: string, package: string|null}
 * @phpstan-type Scope array{package: string|null, path: string, manifest: bool, errors: int, warnings: int, info: int}
 */
final class ProjectVerification
{
    /**
     * Lo que acepta --package para nombrar el núcleo, como en `larapack:fix`.
     */
    public const CORE = 'app';

    private ProjectSources $sources;

    /**
     * @param  list<string>  $packages  raíces absolutas a las que acotar; vacío, todas
     */
    public function __construct(
        private readonly string $root,
        private readonly array $packages = [],
        private readonly bool $withPackages = true,
    ) {
        $this->sources = new ProjectSources($root);
    }

    public function sources(): ProjectSources
    {
        return $this->sources;
    }

    /**
     * La raíz de lo que se nombra en --package (directorio, ruta relativa,
     * nombre de Composer o `app`), o null si no hay ninguno.
     */
    public function resolve(string $name): ?string
    {
        $name = trim($name);

        return strtolower($name) === self::CORE ? $this->sources->root() : $this->sources->packageRoot($name);
    }

    /**
     * Los nombres que acepta --package, para decirlos cuando uno no existe.
     *
     * @return list<string>
     */
    public function known(): array
    {
        return [self::CORE, ...array_map(fn (string $root): string => $this->nameOf($root), $this->packageRoots())];
    }

    /**
     * @param  array<int, string>  $only  sólo estas comprobaciones; vacío, todas
     * @return array{findings: list<ScopedFinding>, summary: list<Scope>}
     */
    public function run(array $only = []): array
    {
        $root = $this->sources->root();
        $verified = [];
        $findings = [];

        foreach ($this->rootFindings($only) as $finding) {
            $findings[] = $finding + ['package' => $this->packageOf($this->ownerOf($finding['file']))];
        }

        foreach ($this->withPackages ? $this->packageRoots() : [] as $package) {
            if (! $this->selected($package) || ! $this->inside($package) || ! is_file($package.'/.larapack/manifest.json')) {
                continue;
            }

            $verified[] = $package;

            foreach ($this->packageFindings($package, $only) as $finding) {
                $findings[] = $finding + ['package' => $this->nameOf($package)];
            }
        }

        // Una raíz que sólo agrupa paquetes —sin laraimport propio— no tiene
        // nada que registrar: si se midió algún paquete, «no hay nada
        // registrado» en ella no dice nada. Con un laraimport sí: lo
        // declarado no se generó.
        if ($verified !== [] && ! is_file($root.'/laraimport.json')) {
            $findings = array_values(array_filter(
                $findings,
                fn (array $finding): bool => ! ($finding['check'] === 'empty-manifest' && $finding['package'] === null)
            ));
        }

        $findings = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $this->selected($this->rootOfPackage($finding['package']))
        ));

        return ['findings' => $findings, 'summary' => $this->summary($findings, $verified)];
    }

    /**
     * @param  array<int, string>  $only
     * @return list<Finding>
     */
    private function rootFindings(array $only): array
    {
        $root = $this->sources->root();

        return ProjectRoot::using($root, fn (): array => (new Verifier(new Manifest($root)))->run($only));
    }

    /**
     * Las comprobaciones del manifiesto del paquete, contra su raíz. Las del
     * código ya las hizo la raíz para todo el proyecto.
     *
     * @param  array<int, string>  $only
     * @return list<Finding>
     */
    private function packageFindings(string $package, array $only): array
    {
        $checks = $only === [] ? Verifier::MANIFEST_CHECKS : array_values(array_intersect($only, Verifier::MANIFEST_CHECKS));

        if ($checks === []) {
            return [];
        }

        $prefix = $this->pathOf($package).'/';

        return array_map(
            fn (array $finding): array => array_merge($finding, ['file' => $finding['file'] === null ? null : $prefix.$finding['file']]),
            ProjectRoot::using($package, fn (): array => (new Verifier(new Manifest($package)))->run($checks))
        );
    }

    /**
     * @return list<string>
     */
    private function packageRoots(): array
    {
        return array_values(array_filter($this->sources->roots(), fn (string $root): bool => $root !== $this->sources->root()));
    }

    private function selected(string $root): bool
    {
        return $this->packages === [] || in_array($root, $this->packages, true);
    }

    /**
     * La raíz a la que pertenece un archivo relativo a la del proyecto.
     */
    private function ownerOf(?string $file): string
    {
        if ($file === null) {
            return $this->sources->root();
        }

        // Lo de un repositorio `path` de fuera llega con su ruta absoluta.
        $absolute = preg_match('#^(/|[A-Za-z]:/)#', $file) === 1 ? $file : $this->sources->root().'/'.$file;

        return $this->sources->rootOf($absolute);
    }

    private function packageOf(string $root): ?string
    {
        return $root === $this->sources->root() ? null : $this->nameOf($root);
    }

    private function rootOfPackage(?string $package): string
    {
        foreach ($package === null ? [] : $this->packageRoots() as $root) {
            if ($this->nameOf($root) === $package) {
                return $root;
            }
        }

        return $this->sources->root();
    }

    /**
     * El nombre de Composer del paquete; su ruta relativa si no lo declara.
     */
    private function nameOf(string $root): string
    {
        $name = ProjectSources::composer($root)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $this->pathOf($root);
    }

    private function inside(string $directory): bool
    {
        return str_starts_with($directory, $this->sources->root().'/');
    }

    /**
     * La ruta de una raíz respecto de la del proyecto: `.`, `packages/x`, o
     * `../x` para un repositorio `path` que apunta fuera.
     */
    private function pathOf(string $directory): string
    {
        $root = $this->sources->root();

        if ($directory === $root) {
            return '.';
        }

        if ($this->inside($directory)) {
            return substr($directory, strlen($root) + 1);
        }

        $from = explode('/', trim($root, '/'));
        $to = explode('/', trim($directory, '/'));
        $common = 0;

        while (isset($from[$common], $to[$common]) && $from[$common] === $to[$common]) {
            $common++;
        }

        return implode('/', [...array_fill(0, count($from) - $common, '..'), ...array_slice($to, $common)]);
    }

    /**
     * Una fila por raíz medida —la del proyecto y cada paquete local—, con lo
     * que se encontró en ella. Los totales del informe son su suma.
     *
     * @param  list<ScopedFinding>  $findings
     * @param  list<string>  $verified  los paquetes cuyo manifiesto se leyó
     * @return list<Scope>
     */
    private function summary(array $findings, array $verified): array
    {
        $root = $this->sources->root();
        $rows = [];

        foreach ([$root, ...$this->packageRoots()] as $scope) {
            if (! $this->selected($scope)) {
                continue;
            }

            $package = $this->packageOf($scope);
            $own = array_filter($findings, fn (array $finding): bool => $finding['package'] === $package);
            $count = fn (string $level): int => count(array_filter($own, fn (array $finding): bool => $finding['level'] === $level));

            $rows[] = [
                'package' => $package,
                'path' => $this->pathOf($scope),
                'manifest' => $scope === $root ? is_file($root.'/.larapack/manifest.json') : in_array($scope, $verified, true),
                'errors' => $count(Verifier::ERROR),
                'warnings' => $count(Verifier::WARNING),
                'info' => $count(Verifier::INFO),
            ];
        }

        return $rows;
    }
}
