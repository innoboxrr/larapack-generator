<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Support\Files;
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
 * Cada ámbito, el suyo: `infer no-contract@.` infiere sólo la raíz, y cada
 * paquete local tiene su propio paso (`no-contract@packages/x`; en un
 * proyecto sin LaraPack cada paquete es un ámbito, Scopes). Antes la raíz
 * inferia también todos los paquetes sin contrato, y el plan seguía
 * pidiendo la decisión de cada uno como si no se hubiera hecho nada.
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
        // Todos los pasos de un mismo ámbito van a UN borrador: dos pasos que
        // escribieran cada uno el suyo se pisarían.
        $scopes = [];

        foreach ($context->steps as $step) {
            $path = (string) ($step['scope']['path'] ?? '.');
            $type = (string) $step['type'];
            $scopes[$path] ??= ['directory' => $path === '.' ? $base : $base.'/'.$path, 'types' => [], 'steps' => [], 'models' => []];
            $scopes[$path]['types'][] = $type;
            $scopes[$path]['steps'][] = (string) ($step['id'] ?? '');

            if ($type === 'unmanaged-models') {
                foreach ((array) ($step['files'] ?? []) as $file) {
                    $scopes[$path]['models'][] = $path === '.' ? (string) $file : (string) preg_replace('#^'.preg_quote($path, '#').'/#', '', (string) $file);
                }
            }
        }

        foreach ($scopes as $path => $scope) {
            $this->inferScope($context, $result, (string) $path, $scope, $shared);
        }

        return $result;
    }

    /**
     * @param  array{directory: string, types: list<string>, steps: list<string>, models: list<string>}  $scope
     * @param  list<string>  $shared
     */
    private function inferScope(OperationContext $context, OperationResult $result, string $path, array $scope, array $shared): void
    {
        $directory = $scope['directory'];
        $types = array_values(array_unique($scope['types']));
        $stepIds = array_values(array_unique($scope['steps']));
        $http = $context->option('http', '1') !== '0' && array_intersect($types, ['no-contract', 'unmanaged-http']) !== [];

        // Siempre se infiere el ámbito entero: con contrato, para compararlo
        // con todo lo declarado; lo que entra al borrador lo deciden los pasos.
        $inferred = (new ContractInferrer($directory, $shared))->infer(null, $http);
        $contractFile = $directory.'/laraimport.json';

        $existing = is_file($contractFile) ? json_decode((string) file_get_contents($contractFile), true) : null;
        $existing = is_array($existing) ? $existing : null;
        $added = [];
        $draft = $existing === null ? $inferred['contract'] : $this->merge($existing, $inferred, $types, $scope['models'], $added);
        $step = ['id' => implode(', ', $stepIds)];
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
            'steps' => $stepIds,
            'step_types' => $types,
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

            Files::write($target, json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            Files::write($state.'/'.$slug.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        $verb = $context->dryRun() ? 'escribiría' : 'escrito';
        $summary = $existing === null ? $summary : count($added).' entradas añadidas al contrato que ya existe ('.$summary.')';
        // En una simulación no se escribe el informe: va entero en el
        // resultado (`infer`), y `report` es null. Antes remitía a un archivo
        // que el dry-run no escribe, y el brief recomienda justo el dry-run
        // para saber si las rutas se conservan (route-not-preservable).
        $result->fixed($relative, "borrador {$verb} desde el código: {$summary}.", $context->dryRun()
            ? ['step' => $step['id'], 'report' => null, 'infer' => $report]
            : ['step' => $step['id'], 'report' => $reportPath]);
        $where = $context->dryRun() ? 'el resultado (--format=json, `infer`; sin --dry-run, en '.$reportPath.')' : $reportPath;

        if (! $validation['ok']) {
            $result->skipped($relative, "el borrador no pasa larapack:validate ({$validation['errors']} errores): corrígelo antes de adoptarlo. Detalle en {$where}.", ['step' => $step['id']]);
        }

        if ($inferred['decisions'] !== []) {
            $result->note("{$relative}: ".count($inferred['decisions']).' decisiones que no se tomaron por ti, en '.$where.'. Por tipo: '.self::decisionSummary($inferred['decisions']).'.');
        }

        if ($existing === null && ! $writeContract) {
            $result->note("{$relative}: revísalo con el informe y, cuando lo des por bueno, renómbralo a laraimport.json. Nada del código se tocó.");
        }
    }

    /**
     * Las decisiones por tipo, con cuántas son y hasta tres ejemplos de cada
     * una: `route-not-preservable 913 (api.v1.posts.index, …)`. Es lo que se
     * lee en texto; el detalle entero va en el informe.
     *
     * @param  list<array<string, mixed>>  $decisions
     */
    public static function decisionSummary(array $decisions): string
    {
        $by = [];

        foreach ($decisions as $decision) {
            $by[(string) ($decision['type'] ?? '?')][] = (string) ($decision['subject'] ?? '?');
        }

        ksort($by);
        $parts = [];

        foreach ($by as $type => $subjects) {
            $examples = array_slice(array_values(array_unique($subjects)), 0, 3);
            $parts[] = $type.' '.count($subjects).' ('.implode(', ', $examples).(count($subjects) > count($examples) ? ', …' : '').')';
        }

        return implode('; ', $parts);
    }

    /**
     * El contrato que ya existe más lo inferido que le falta: nunca se cambia
     * algo que ya está declarado.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $result  Lo que devuelve ContractInferrer::infer().
     * @param  list<string>  $types  Los tipos de paso que se hacen.
     * @param  list<string>  $models  Los archivos de modelo de `unmanaged-models`.
     * @param  list<string>  $added
     * @return array<string, mixed>
     */
    private function merge(array $existing, array $result, array $types, array $models, array &$added): array
    {
        $inferred = $result['contract'];
        $draft = $existing;
        $declared = array_column((array) ($existing['models'] ?? []), 'name');

        foreach ((array) ($inferred['models'] ?? []) as $model) {
            $wanted = in_array('no-contract', $types, true)
                || (in_array('unmanaged-models', $types, true) && in_array($result['files'][$model['name']] ?? null, $models, true));

            if ($wanted && ! in_array($model['name'], $declared, true)) {
                $draft['models'][] = $model;
                $added[] = 'models/'.$model['name'];
            }
        }

        if (array_intersect($types, ['unmanaged-http', 'no-contract']) !== []) {
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
