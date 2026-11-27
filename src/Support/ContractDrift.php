<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;

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
 *
 * Se lee el `laraimport.json` de la raíz del proyecto (donde lo busca
 * `larapack:import` por omisión). Sin él no se compara nada: no hay contrato.
 *
 * @phpstan-import-type Finding from Verifier
 */
final class ContractDrift
{
    public const MODEL_LISTS = 'model-lists-outdated';

    public const CHECKS = [self::MODEL_LISTS];

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

        $findings = [];

        foreach ($models as $model) {
            if (! isset($contract[$model])) {
                continue;
            }

            $findings = [...$findings, ...$this->lists($model, $contract[$model])];
        }

        return $findings;
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
