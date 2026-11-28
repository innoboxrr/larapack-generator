<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\Relations;

/**
 * Lo que el laraimport declara y el código ya no lleva, aunque no se haya
 * vuelto a generar nada.
 *
 * `verify` no recibe el laraimport, y por eso no veía los desfases que el
 * generador deja atrás sin avisar:
 *
 *  - model-lists-outdated: un modelo editado a mano se conserva con --force,
 *    y antes de 9.3.1 con él sus listas. Una columna añadida al contrato no
 *    llegaba a `$fillable`, `$export_cols` ni `casts()` (30 modelos en
 *    school-management; `ExternalIdentity::casts()` sin `shared`).
 *  - relation-missing: los traits de relaciones se escriben una sola vez y
 *    son del proyecto, así que una relación añadida al contrato después
 *    (`load_relations`) nunca aparecía. Expediente lo documentaba como una
 *    trampa: «borrar el trait para que se regenere».
 *
 * Se lee el `laraimport.json` de la raíz del proyecto (donde lo busca
 * `larapack:import` por omisión). Sin él no se compara nada: no hay contrato.
 *
 * @phpstan-import-type Finding from Verifier
 *
 * @phpstan-type MissingRelation array{model: string, trait: string, name: string, method: string, use: string|null}
 */
final class ContractDrift
{
    public const MODEL_LISTS = 'model-lists-outdated';

    public const RELATION_MISSING = 'relation-missing';

    public const DECLARATION = 'manifest-declaration';

    /**
     * Lo generado corresponde a otro contrato: el laraimport cambió —a mano o
     * al fusionar dos ramas— y no se volvió a importar (ContractFingerprint).
     */
    public const CONTRACT_OUTDATED = 'contract-outdated';

    public const CHECKS = [self::MODEL_LISTS, self::RELATION_MISSING, self::DECLARATION, self::CONTRACT_OUTDATED];

    /**
     * @var array<string, array<string, mixed>>|null por nombre; null si no se ha leído
     */
    private ?array $contract = null;

    private ?string $unreadable = null;

    public function __construct(private Manifest $manifest) {}

    /**
     * @param  array<int, string>  $models  los modelos del manifiesto
     * @return list<Finding>
     */
    public function run(array $models): array
    {
        $contract = $this->contract();

        if ($this->unreadable !== null) {
            return [[
                'level' => Verifier::WARNING,
                'check' => self::MODEL_LISTS,
                'model' => null,
                'file' => 'laraimport.json',
                'message' => "No se compara el código con el laraimport: {$this->unreadable}. Corrígelo con larapack:validate.",
            ]];
        }

        $findings = $this->outdated($models);

        foreach ($models as $model) {
            if (! isset($contract[$model])) {
                continue;
            }

            $findings = [...$findings, ...$this->lists($model, $contract[$model]), ...$this->declaration($model, $contract[$model])];
        }

        // Lo que el contrato declara y el manifiesto no tiene: sin su
        // entrada, ninguna comprobación del manifiesto mira ese modelo.
        foreach (array_diff(array_keys($contract), $models) as $model) {
            $findings[] = [
                'level' => Verifier::WARNING,
                'check' => self::DECLARATION,
                'model' => (string) $model,
                'file' => '.larapack/manifest.json',
                'message' => "laraimport.json declara «{$model}» y el manifiesto no lo tiene: o no se ha generado todavía, o se quitó de .larapack/manifest.json. "
                    .'Mientras falte, verify no comprueba nada de lo que el contrato declara de él (rutas, `secret`, `immutable`, endpoints). '
                    .'Genera con larapack:import; el manifiesto no se edita a mano.',
            ];
        }

        foreach ($this->missingRelations($models) as $missing) {
            $findings[] = [
                'level' => Verifier::WARNING,
                'check' => self::RELATION_MISSING,
                'model' => $missing['model'],
                'file' => $missing['trait'],
                'message' => "El laraimport declara la relación «{$missing['name']}» y ni el modelo ni sus traits la definen: "
                    .'el trait de relaciones se escribe una sola vez y no la recibió. Añádela al trait'
                    .($missing['use'] === null ? '' : " (con {$missing['use']})")
                    .":\n{$missing['method']}\no corre larapack:fix relation-missing, que la añade si el trait no tiene ya ese nombre.",
            ];
        }

        return $findings;
    }

    /**
     * Lo que el manifiesto anota de la forma de un modelo —sus acciones,
     * `immutable`, sus columnas `secret`, sus endpoints— tiene que ser lo que
     * declara el contrato.
     *
     * `verify` no recibe el laraimport: `route-not-declared`,
     * `endpoint-missing`, `immutable-write` y `secret-exposed` comprueban el
     * código contra esa anotación. El manifiesto es un archivo más del
     * repositorio, y bastaba borrarle `"secret": ["api_token"]` para que una
     * columna secreta en el Resource dejara de ser un error, con el contrato
     * intacto. Es error: mientras difieran, lo que verify da por bueno no es
     * lo que el contrato dice. También lo es, de buena fe, un contrato que
     * cambió y no se regeneró.
     *
     * @param  array<string, mixed>  $definition  el modelo del contrato, ya resuelto
     * @return list<Finding>
     */
    private function declaration(string $model, array $definition): array
    {
        $annotated = $this->manifest->declarationOf($model);
        $secret = [];

        foreach ((array) ($definition['props'] ?? []) as $prop) {
            if (is_array($prop) && ! empty($prop['secret'])) {
                $secret[] = (string) $prop['name'];
            }
        }

        $declared = [
            'actions' => array_values(array_intersect(Actions::ALL, (array) ($definition['actions'] ?? Actions::resolve($definition)))),
            'immutable' => ! empty($definition['immutable']),
            'secret' => $secret,
            'endpoints' => array_values((array) ($definition['endpoints'] ?? [])),
        ];
        $labels = ['actions' => 'las acciones', 'immutable' => '`immutable`', 'secret' => 'las columnas `secret`', 'endpoints' => 'los endpoints'];
        $differs = [];

        foreach ($labels as $key => $label) {
            if (self::canonical($declared[$key]) !== self::canonical($annotated[$key])) {
                $differs[] = $label;
            }
        }

        if ($differs === []) {
            return [];
        }

        return [[
            'level' => Verifier::ERROR,
            'check' => self::DECLARATION,
            'model' => $model,
            'file' => '.larapack/manifest.json',
            'message' => "El manifiesto no anota de «{$model}» lo mismo que declara laraimport.json: difieren ".implode(', ', $differs)
                .'. verify comprueba el código contra lo que anota el manifiesto, así que mientras difieran no comprueba lo que el contrato declara. '
                .'Regenera con larapack:import, que lo vuelve a anotar; el manifiesto no se edita a mano.',
        ]];
    }

    /**
     * Un valor sin el orden de sus claves ni el de una lista de nombres: lo
     * que el contrato declara y lo que el manifiesto guardó pasan por JSON
     * por caminos distintos.
     */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $value = array_map($sort, $value);

            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            } elseif (array_filter($value, 'is_string') === $value) {
                sort($value, SORT_STRING);
            }

            return $value;
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Las piezas cuyo contrato ya no es el que había al generarlas.
     *
     * Es un error y no un aviso: no es deuda que se pueda ir pagando, es que
     * el código y el contrato dicen cosas distintas ahora mismo. Lo arregla
     * `larapack:import --force` (o, tras un merge, `larapack:reconcile`), que
     * regenera lo no editado y vuelve a anotar la huella.
     *
     * Un manifiesto que no dice ninguna huella —de antes de 9.4.0— no se
     * compara: no hay con qué. La primera importación con --force las anota.
     *
     * @param  array<int, string>  $models  los del manifiesto
     * @return list<Finding>
     */
    private function outdated(array $models): array
    {
        $declared = ContractFingerprint::ofFile($this->manifest->root().'/laraimport.json');

        if ($declared === null || ! $this->manifest->tracksContract()) {
            return [];
        }

        $finding = fn (?string $model, string $message): array => [
            'level' => Verifier::ERROR,
            'check' => self::CONTRACT_OUTDATED,
            'model' => $model,
            'file' => 'laraimport.json',
            'message' => $message,
        ];

        $fix = 'Corre `larapack:import --force` (tras un merge, `larapack:reconcile`) y revisa lo que conserve por estar editado a mano.';
        $findings = [];

        foreach ($declared['models'] as $model => $fingerprint) {
            $recorded = $this->manifest->contractOf($model);

            if (! in_array($model, $models, true)) {
                $findings[] = $finding($model, "El laraimport declara {$model} y no hay nada generado de él. {$fix}");
            } elseif ($recorded !== null && ! ContractFingerprint::matches($recorded, $fingerprint)) {
                $findings[] = $finding($model, "Lo generado de {$model} corresponde a otro contrato: su declaración en el laraimport (o algo de lo que depende: el prefijo de tablas, el modelo al que apunta una clave ajena) cambió desde la última importación. {$fix}");
            }
        }

        foreach ($models as $model) {
            if (! isset($declared['models'][$model]) && $this->manifest->contractOf($model) !== null) {
                $findings[] = $finding($model, "El manifiesto registra {$model}, generado desde el laraimport, y el laraimport ya no lo declara. Si se retiró, `larapack:remove-full-model {$model}` borra lo generado; si se perdió en un merge, devuélvelo al laraimport.");
            }
        }

        $pivots = $this->manifest->pivotContracts();

        foreach ($declared['pivots'] as $pivot => $fingerprint) {
            if (! ContractFingerprint::matches($pivots[$pivot] ?? null, $fingerprint)) {
                $findings[] = $finding(null, isset($pivots[$pivot])
                    ? "El pivote {$pivot} cambió en el laraimport desde la última importación. {$fix}"
                    : "El laraimport declara el pivote {$pivot} y no se ha importado. {$fix}");
            }
        }

        return $findings;
    }

    /**
     * Las relaciones del contrato que no define nadie, con el código exacto.
     *
     * @param  array<int, string>|null  $models  los del manifiesto; null, todos
     * @return list<MissingRelation>
     */
    public function missingRelations(?array $models = null): array
    {
        $contract = $this->contract();
        $missing = [];

        foreach ($models ?? $this->manifest->models() as $model) {
            $relations = (array) ($contract[$model]['load_relations'] ?? []);
            $modelFile = $this->modelFile($model);

            if ($relations === [] || $modelFile === null) {
                continue;
            }

            $defined = $this->methodsOf($model, $modelFile);
            $namespace = rtrim((string) ($this->manifest->read()['models'][$model]['namespace'] ?? ''), '\\');
            $trait = dirname($modelFile).'/Traits/Relations/'.$model.'Relations.php';

            foreach ($relations as $relation) {
                $name = (string) $relation['name'];

                if (in_array(strtolower($name), $defined, true)) {
                    continue;
                }

                $related = Relations::hasRelated($relation) ? (string) $relation['related'] : null;
                $relatedNamespace = rtrim((string) ($relation['namespace'] ?? $namespace.'\\Models'), '\\');

                $missing[] = [
                    'model' => $model,
                    'trait' => $trait,
                    'name' => $name,
                    'method' => "    public function {$name}()\n    {\n        return ".Relations::call($relation).";\n    }",
                    'use' => $related === null || $related === $model ? null : "use {$relatedNamespace}\\{$related};",
                ];
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $declared
     * @return list<Finding>
     */
    private function lists(string $model, array $declared): array
    {
        $file = $this->modelFile($model);

        if ($file === null || ! is_file($this->manifest->absolute($file))) {
            return [];
        }

        $result = ModelRegions::sync(Files::read($this->manifest->absolute($file)), ModelLists::expected($declared), $file);
        $findings = [];
        $missing = [];

        foreach ($result['added'] as $region => $entries) {
            $missing[] = ($region === 'casts' ? 'casts()' : '$'.$region).': '.implode(', ', $entries);
        }

        foreach ($result['skipped'] as $skip) {
            $missing[] = "{$skip['manual']} ({$skip['reason']})";
        }

        if ($missing !== []) {
            $findings[] = [
                'level' => Verifier::WARNING,
                'check' => self::MODEL_LISTS,
                'model' => $model,
                'file' => $file,
                'message' => 'Las listas del modelo no llevan lo que el laraimport declara. Falta '.implode('; ', $missing)
                    .'. larapack:import --force lo completa sin tocar el resto del modelo, aunque esté editado a mano'
                    .($result['skipped'] === [] ? '.' : ', salvo lo que no reconoce con certeza: eso, a mano.'),
            ];
        }

        $notes = [];

        foreach ($result['differs'] as $name => $cast) {
            $notes[] = "casts() da a '{$name}' {$cast['actual']} y el laraimport {$cast['expected']}";
        }

        foreach ($result['extra'] as $region => $names) {
            $notes[] = ($region === 'casts' ? 'casts()' : '$'.$region).' lleva '.implode(', ', array_map(fn (string $name): string => "'{$name}'", $names)).', que el laraimport no declara';
        }

        if ($notes !== []) {
            $findings[] = [
                'level' => Verifier::INFO,
                'check' => self::MODEL_LISTS,
                'model' => $model,
                'file' => $file,
                'message' => ucfirst(implode('; ', $notes)).'. No se cambia solo: pudo decidirlo una persona. Si sobra, llévalo al laraimport o quítalo.',
            ];
        }

        return $findings;
    }

    /**
     * Los métodos del modelo y de sus traits (Models/Traits/<Tipo>/<Modelo>*.php),
     * en minúsculas: PHP no distingue mayúsculas en los nombres de método.
     *
     * @return list<string>
     */
    private function methodsOf(string $model, string $modelFile): array
    {
        $files = [$this->manifest->absolute($modelFile), ...(glob($this->manifest->absolute(dirname($modelFile)).'/Traits/*/'.$model.'*.php') ?: [])];
        $methods = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            foreach (PhpOutline::ofFile($file)->classes() as $class) {
                foreach ($class['methods'] as $method) {
                    $methods[] = strtolower($method['name']);
                }
            }
        }

        return $methods;
    }

    private function modelFile(string $model): ?string
    {
        foreach ($this->manifest->filesFor($model) as $relative => $meta) {
            if ($meta['stub'] === 'Model/ModelTemplate.txt') {
                return $relative;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contract(): array
    {
        if ($this->contract !== null) {
            return $this->contract;
        }

        $this->contract = [];
        $path = $this->manifest->root().'/laraimport.json';

        if (! is_file($path)) {
            return $this->contract;
        }

        ['document' => $document, 'errors' => $errors] = ImportDocument::fromFile($path);

        if ($document === null) {
            $this->unreadable = 'laraimport.json no es válido ('.($errors[0]['message'] ?? 'sin detalle').')';

            return $this->contract;
        }

        foreach ($document->models() as $model) {
            $this->contract[(string) $model['name']] = $model;
        }

        return $this->contract;
    }
}
