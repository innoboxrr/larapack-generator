<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\ProjectSources;
use Innoboxrr\LarapackGenerator\Support\Realign\Infer\ContractComparison;
use Innoboxrr\LarapackGenerator\Support\Realign\Infer\ContractInferrer;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;

/**
 * `larapack:realign infer`: escribe un laraimport BORRADOR desde el código
 * (ContractInferrer) para los pasos `no-contract`, `unmanaged-models` y
 * `unmanaged-http`.
 *
 * ────────────────────────────────────────────────────────────────────────
 * LO QUE NUNCA HACE
 *
 *  - Sobrescribir `laraimport.json`. El borrador va a
 *    `laraimport.inferred.json`, junto al contrato. Sólo con
 *    `--option write=contract` y SIN un `laraimport.json` previo se escribe
 *    directamente como contrato.
 *  - Tocar una migración, un modelo, una ruta o un controlador: infer sólo
 *    LEE el código. Adoptar el borrador (renombrarlo, completarlo, importar)
 *    es otro paso, y lo decide una persona con el informe delante.
 * ────────────────────────────────────────────────────────────────────────
 *
 * Con contrato, el borrador es el contrato que ya existe MÁS lo inferido que
 * le falta (los modelos de `unmanaged-models`, los controladores y endpoints
 * de `unmanaged-http`), y el informe compara lo inferido con lo declarado
 * (ContractComparison): lo que difiere puede ser el código o el contrato el
 * que se quedó atrás.
 *
 * Sin contrato en la raíz, infiere también cada paquete local que tampoco lo
 * tiene (`--option packages=0` lo evita): en un proyecto como brandai cada
 * paquete es una zona con su propio borrador.
 *
 * El informe de cada ámbito queda en `.larapack/realign/infer/<ámbito>.json`
 * (formato en docs/realign/README.md): de dónde salió cada prop y con qué
 * confianza, lo que no se infirió, lo que el laraimport no sabe expresar,
 * las decisiones y si el borrador valida.
 */
final class InferOperation implements RealignOperation
{
    public const DRAFT = 'laraimport.inferred.json';

    public const FORMAT_VERSION = 1;

    public function name(): string
    {
        return 'infer';
    }

    public function description(): string
    {
        return Operations::DESCRIPTIONS['infer'];
    }

    public function handles(): array
    {
        return ['no-contract', 'unmanaged-models', 'unmanaged-http'];
    }

    public function run(OperationContext $context): OperationResult
    {
        $result = new OperationResult;
        // Las rutas, todas desde la raíz real (la de ProjectSources): las
        // migraciones compartidas y las de cada ámbito tienen que nombrarse
        // igual para saber qué tabla crea cada ámbito.
        $sources = new ProjectSources($context->root);
        $base = $sources->root();
        $shared = $this->sharedMigrations($sources);
        $done = [];

        foreach ($context->steps as $step) {
            $path = (string) ($step['scope']['path'] ?? '.');
            $type = (string) $step['type'];
            $directory = $path === '.' ? $base : $base.'/'.$path;
            $targets = [[$path, $directory]];

            if ($type === 'no-contract' && $path === '.' && $context->option('packages', '1') !== '0') {
                foreach ($sources->roots() as $root) {
                    if ($root !== $base && str_starts_with($root, $base.'/') && ! is_file($root.'/laraimport.json') && ! is_file($root.'/.larapack/manifest.json')) {
                        $targets[] = [substr($root, strlen($base) + 1), $root];
                    }
                }
            }

            foreach ($targets as [$scopePath, $scopeDirectory]) {
                $key = $scopePath.'|'.$type;

                if (isset($done[$key])) {
                    continue;
                }

                $done[$key] = true;
                $this->inferScope($context, $result, $step, $type, $scopePath, $scopeDirectory, $shared);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  list<string>  $shared
     */
    private function inferScope(OperationContext $context, OperationResult $result, array $step, string $type, string $path, string $directory, array $shared): void
    {
        $only = null;

        if ($type === 'unmanaged-models') {
            $only = array_values(array_map(
                fn (string $file): string => $path === '.' ? $file : (string) preg_replace('#^'.preg_quote($path, '#').'/#', '', $file),
                array_filter((array) ($step['files'] ?? []), 'is_string')
            ));
        }

        $inferred = (new ContractInferrer($directory, $shared))->infer($only, $context->option('http', '1') !== '0');
        $contractFile = $directory.'/laraimport.json';
        $existing = is_file($contractFile) ? json_decode((string) file_get_contents($contractFile), true) : null;
        $existing = is_array($existing) ? $existing : null;
        $added = [];
        $draft = $existing === null ? $inferred['contract'] : $this->merge($existing, $inferred['contract'], $type, $added);
        $validation = $existing === null ? $inferred['validation'] : $this->validate($draft);
        $writeContract = $existing === null && $context->option('write') === 'contract';
        $target = $writeContract ? $contractFile : $directory.'/'.self::DRAFT;
        $relative = ($path === '.' ? '' : $path.'/').basename($target);
        $slug = $path === '.' ? 'app' : str_replace('/', '-', $path);
        $reportPath = RealignState::DIRECTORY.'/infer/'.$slug.'.json';

        $report = [
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => gmdate('c'),
            'scope' => $path,
            'step' => $step['id'] ?? null,
            'step_type' => $type,
            'draft' => $relative,
            'contract_existed' => $existing !== null,
            'added' => $added,
            'stats' => $inferred['stats'],
            'validation' => $validation,
            'decisions' => $inferred['decisions'],
            'not_inferred' => $inferred['not_inferred'],
            'lost' => $inferred['lost'],
            'out_of_scope' => $inferred['out_of_scope'],
            'provenance' => $inferred['provenance'],
            'comparison' => $existing !== null ? ContractComparison::compare($existing, $inferred['contract']) : null,
        ];

        $summary = sprintf('%d modelos, %d props, %d relaciones, %d índices, %d pivotes, %d controladores con %d endpoints; %d decisiones, %d cosas que el laraimport no expresa',
            $inferred['stats']['models'], $inferred['stats']['props'], $inferred['stats']['relations'], $inferred['stats']['indexes'],
            $inferred['stats']['pivots'], $inferred['stats']['controllers'], $inferred['stats']['endpoints'], count($inferred['decisions']), count($inferred['lost']));

        if (! $context->dryRun()) {
            $state = $context->state->directory().'/infer';

            if (! is_dir($state)) {
                mkdir($state, 0755, true);
            }

            file_put_contents($target, json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            file_put_contents($state.'/'.$slug.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        $verb = $context->dryRun() ? 'escribiría' : 'escrito';
        $result->fixed($relative, "borrador {$verb} desde el código: {$summary}.", ['step' => $step['id'] ?? null, 'report' => $reportPath]);

        if (! $validation['ok']) {
            $result->skipped($relative, "el borrador no pasa larapack:validate ({$validation['errors']} errores): corrígelo antes de adoptarlo. Detalle en {$reportPath}.", ['step' => $step['id'] ?? null]);
        }

        if ($inferred['decisions'] !== []) {
            $result->note("{$relative}: ".count($inferred['decisions']).' decisiones que no se tomaron por ti (nombres repetidos, ciclos, tablas sin modelo, rutas que no se pueden conservar): están en '.$reportPath.'.');
        }

        if ($existing === null && ! $writeContract) {
            $result->note("{$relative}: revísalo con el informe y, cuando lo des por bueno, renómbralo a laraimport.json. Nada del código se tocó.");
        }
    }

    /**
     * El contrato que ya existe más lo inferido que le falta: nunca se cambia
     * algo que ya está declarado.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $inferred
     * @param  list<string>  $added
     * @return array<string, mixed>
     */
    private function merge(array $existing, array $inferred, string $type, array &$added): array
    {
        $draft = $existing;
        $declared = array_column((array) ($existing['models'] ?? []), 'name');

        if (in_array($type, ['unmanaged-models', 'no-contract'], true)) {
            foreach ((array) ($inferred['models'] ?? []) as $model) {
                if (! in_array($model['name'], $declared, true)) {
                    $draft['models'][] = $model;
                    $added[] = 'models/'.$model['name'];
                }
            }
        }

        if (in_array($type, ['unmanaged-http', 'no-contract'], true)) {
            $controllers = array_column((array) ($existing['controllers'] ?? []), 'name');

            foreach ((array) ($inferred['controllers'] ?? []) as $controller) {
                if (! in_array($controller['name'], $controllers, true) && ! in_array($controller['name'], $declared, true)) {
                    $draft['controllers'][] = $controller;
                    $added[] = 'controllers/'.$controller['name'];
                }
            }

            foreach ((array) ($inferred['models'] ?? []) as $model) {
                $index = array_search($model['name'], $declared, true);

                if ($index === false || empty($model['endpoints'])) {
                    continue;
                }

                $known = [];

                foreach ((array) ($draft['models'][$index]['endpoints'] ?? []) as $endpoint) {
                    $known[] = $endpoint['name'];
                    $known[] = $endpoint['method'].' '.($endpoint['uri'] ?? '');
                }

                foreach ($model['endpoints'] as $endpoint) {
                    if (in_array($endpoint['name'], $known, true) || in_array($endpoint['method'].' '.$endpoint['uri'], $known, true)) {
                        continue;
                    }

                    $draft['models'][$index]['endpoints'][] = $endpoint;
                    $added[] = 'models/'.$model['name'].'/endpoints/'.$endpoint['name'];
                }
            }
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{ok: bool, errors: int, warnings: int, findings: list<array<string, mixed>>}
     */
    private function validate(array $draft): array
    {
        $findings = ImportDocument::fromArray($draft)['errors'];
        $errors = count(array_filter($findings, fn (array $f): bool => $f['level'] === SemanticValidator::ERROR));

        return ['ok' => $errors === 0, 'errors' => $errors, 'warnings' => count($findings) - $errors, 'findings' => array_values($findings)];
    }

    /**
     * Las migraciones de todos los ámbitos del proyecto: corren contra la
     * misma base, y la tabla de un modelo puede crearla otro paquete.
     *
     * @return list<string>
     */
    private function sharedMigrations(ProjectSources $sources): array
    {
        $directories = [];

        foreach ($sources->roots() as $directory) {
            $directories = [...$directories, ...ContractInferrer::migrationDirectories($directory)];
        }

        return array_values(array_unique($directories));
    }
}
