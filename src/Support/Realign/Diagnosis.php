<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;

/**
 * `larapack:realign diagnose`: dónde está el proyecto respecto del contrato.
 *
 * 1. Regenera el contrato de cada ámbito con la versión actual del generador
 *    en un directorio aparte (Regeneration).
 * 2. Compara cada archivo con lo regenerado (ScopeClassifier): idéntico,
 *    sólo formato, salto de versión, editado en sus huecos o fuera, borrado,
 *    renombrado, lo que import no regenera, migraciones.
 * 3. Lista el código HTTP y de modelos que no gestiona nadie, y cuenta el
 *    resto del código que no es de LaraPack.
 * 4. Corre `verify` sobre el proyecto entero (ProjectVerification) y pone a
 *    cada hallazgo su categoría y el tipo de paso que lo resuelve
 *    (StepTypes::forFinding).
 *
 * No escribe código: sólo `.larapack/realign/report.json` y la caché. Con
 * --package se diagnostican esos ámbitos y se conserva lo que el informe
 * decía de los demás.
 *
 * El informe guarda sólo lo que no cumple (más los recuentos de todo): en
 * school-management son 22 000 archivos, y la mayoría idénticos.
 *
 * @phpstan-import-type Scope from Scopes
 * @phpstan-import-type Entry from ScopeClassifier
 * @phpstan-import-type Unmanaged from ScopeClassifier
 */
final class Diagnosis
{
    private RealignState $state;

    public function __construct(private readonly string $root, private readonly int $jobs = 1, private readonly bool $useCache = true)
    {
        $this->state = new RealignState($root);
    }

    /**
     * @param  list<string>  $packages  nombres de --package; vacío, todo el proyecto
     * @return array<string, mixed> el informe, ya guardado
     */
    public function run(array $packages = []): array
    {
        $scopes = new Scopes($this->root);
        $selected = $scopes->all($packages);
        $exceptions = new Exceptions($this->state);
        $outcomes = (new Regeneration($this->state, $this->jobs, $this->useCache))->run($selected);
        $now = gmdate('c');

        // Con --package se rehace lo de esos paquetes (sean o no ámbitos: un
        // paquete sin contrato también tiene hallazgos de verify) y se
        // conserva lo demás.
        $previous = $packages === [] ? null : $this->state->report();
        $root = rtrim($this->root, '/');
        $done = array_map(
            fn (string $directory): string => $directory === $root ? '.' : substr($directory, strlen($root) + 1),
            $packages === [] ? [] : $scopes->resolve($packages)
        );
        $keep = fn (array $item): bool => ! in_array($item['scope'] ?? null, $done, true);

        $report = [
            'generator' => GeneratorVersion::current(),
            'generated_at' => $now,
            'scopes' => array_filter((array) ($previous['scopes'] ?? []), fn (mixed $scope, string|int $path): bool => ! in_array((string) $path, $done, true), ARRAY_FILTER_USE_BOTH),
            'files' => array_values(array_filter((array) ($previous['files'] ?? []), $keep)),
            'unmanaged' => array_values(array_filter((array) ($previous['unmanaged'] ?? []), $keep)),
            'findings' => array_values(array_filter((array) ($previous['findings'] ?? []), $keep)),
            'out_of_scope' => array_filter((array) ($previous['out_of_scope'] ?? []), fn (mixed $scope, string|int $path): bool => ! in_array((string) $path, $done, true), ARRAY_FILTER_USE_BOTH),
        ];

        foreach ($selected as $scope) {
            $outcome = $outcomes[$scope['path']] ?? null;
            $classifier = new ScopeClassifier($scope, $this->root, $outcome !== null && $outcome['ok'] ? $outcome['directory'] : null, $exceptions);
            $entries = $classifier->hasReference() ? $classifier->classifyAll() : [];
            $counts = array_fill_keys(FileClass::ALL, 0);

            foreach ($entries as $entry) {
                $counts[$entry['class']]++;

                if ($entry['type'] !== null || $entry['category'] === Category::OUT_OF_SCOPE) {
                    $report['files'][] = $entry;
                }
            }

            unset($counts[FileClass::ABSENT]);

            $report['scopes'][$scope['path']] = [
                'package' => $scope['package'],
                'path' => $scope['path'],
                'contract' => $scope['contract'],
                'manifest' => $scope['manifest'],
                'manifest_version' => $scope['manifest'] ? (new Manifest($scope['root']))->formatVersion() : null,
                'ui' => Regeneration::uiOf($scope['root']),
                'regeneration' => $outcome === null ? null : array_diff_key($outcome, ['directory' => true]) + ['directory' => $outcome['directory'] === null ? null : substr($outcome['directory'], strlen($this->root) + 1)],
                'fingerprint' => self::fingerprint($scope['root']),
                'diagnosed_at' => $now,
                'counts' => $counts,
                'total' => array_sum($counts),
                'conforming' => count(array_filter($entries, fn (array $entry): bool => $entry['category'] === null)),
            ];

            foreach ($classifier->unmanaged() as $item) {
                $report['unmanaged'][] = $item;
            }

            $report['out_of_scope'][$scope['path']] = [
                'files' => array_count_values(array_column(array_filter($entries, fn (array $entry): bool => $entry['category'] === Category::OUT_OF_SCOPE), 'class')),
                'other_code' => $classifier->otherCode(),
            ];
        }

        $report['findings'] = [...$report['findings'], ...$this->findings($scopes, $selected, $packages, $exceptions)];
        $report['verify'] = [
            'at' => $now,
            'errors' => count(array_filter($report['findings'], fn (array $finding): bool => ($finding['level'] ?? null) === 'error')),
            'warnings' => count(array_filter($report['findings'], fn (array $finding): bool => ($finding['level'] ?? null) === 'warning')),
        ];

        ksort($report['scopes']);
        ksort($report['out_of_scope']);
        $report['totals'] = self::totals($report);

        $this->state->saveReport($report);

        return ['format_version' => RealignState::FORMAT_VERSION] + $report;
    }

    /**
     * Lo que cambia el diagnóstico de un ámbito: su contrato y su
     * manifiesto. Si cualquiera cambia después, el informe está viejo y
     * `status` lo dice.
     *
     * @return array{laraimport: string|null, manifest: string|null}
     */
    public static function fingerprint(string $scopeRoot): array
    {
        $hash = fn (string $file): ?string => is_file($file) ? hash('sha256', str_replace("\r\n", "\n", Files::read($file))) : null;

        return [
            'laraimport' => $hash($scopeRoot.'/laraimport.json'),
            'manifest' => $hash($scopeRoot.'/.larapack/manifest.json'),
        ];
    }

    /**
     * Los recuentos del informe: por clase de archivo y por categoría de
     * todo lo encontrado.
     *
     * @param  array<string, mixed>  $report
     * @return array{classes: array<string, int>, categories: array<string, int>, conforming: int, files: int}
     */
    public static function totals(array $report): array
    {
        $classes = array_fill_keys(array_diff(FileClass::ALL, [FileClass::ABSENT]), 0);
        $conforming = 0;
        $files = 0;

        foreach ((array) ($report['scopes'] ?? []) as $scope) {
            foreach ((array) ($scope['counts'] ?? []) as $class => $count) {
                $classes[(string) $class] = ($classes[(string) $class] ?? 0) + (int) $count;
            }

            $conforming += (int) ($scope['conforming'] ?? 0);
            $files += (int) ($scope['total'] ?? 0);
        }

        $categories = array_fill_keys(Category::ALL, 0);

        foreach (['files', 'unmanaged', 'findings'] as $section) {
            foreach ((array) ($report[$section] ?? []) as $item) {
                $category = is_array($item) ? ($item['category'] ?? null) : null;

                if (is_string($category) && isset($categories[$category])) {
                    $categories[$category]++;
                }
            }
        }

        return ['classes' => $classes, 'categories' => $categories, 'conforming' => $conforming, 'files' => $files];
    }

    /**
     * Los hallazgos de verify con su categoría y su paso. `customised` y
     * `missing-file` no: la clasificación de archivos los dice mejor.
     *
     * @param  list<Scope>  $selected
     * @param  list<string>  $packages
     * @return list<array<string, mixed>>
     */
    private function findings(Scopes $scopes, array $selected, array $packages, Exceptions $exceptions): array
    {
        $verification = $packages === []
            ? new ProjectVerification($this->root)
            : new ProjectVerification($this->root, $scopes->resolve($packages));

        // El nombre de un paquete local => la ruta de su ámbito; un paquete
        // que no es ámbito (sin contrato ni manifiesto) se cuenta con la suya.
        $paths = [];

        foreach ((new ProjectSources($this->root))->roots() as $directory) {
            if (str_starts_with($directory, rtrim($this->root, '/').'/')) {
                $paths[$scopes->nameOf($directory)] = substr($directory, strlen(rtrim($this->root, '/')) + 1);
            }
        }

        $findings = [];

        foreach ($verification->run()['findings'] as $finding) {
            $type = StepTypes::forFinding($finding);

            if ($type === null) {
                continue;
            }

            $scope = $finding['package'] === null ? '.' : ($paths[$finding['package']] ?? $finding['package']);

            if ($exceptions->covers(['type' => $type, 'scope' => $scope, 'file' => $finding['file'], 'check' => $finding['check']])) {
                continue;
            }

            $findings[] = $finding + ['scope' => $scope, 'category' => StepTypes::get($type)['category'], 'type' => $type];
        }

        return $findings;
    }
}
