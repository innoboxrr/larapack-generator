<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Verifier;

/**
 * Comprueba el `done_when` de un paso: `larapack:realign check <id>`.
 *
 * Los tipos de comprobación (`kind`), todos deterministas:
 *
 *  - `verify`: `verify` acotado a esas comprobaciones (`checks`), a ese
 *    paquete (`package`) y, si los trae, a esos archivos (`files`), no
 *    encuentra ni un error ni un aviso. Una excepción aprobada no cuenta.
 *  - `classification`: cada archivo de `files`, comparado otra vez con lo
 *    regenerado (la misma ScopeClassifier del diagnóstico), queda en una de
 *    las clases de `accept` (o en ninguna de `reject`); con `tracked` y
 *    `hash_current`, además registrado y con el hash del manifiesto; con
 *    `resolved`, sin paso pendiente (cumple o tiene excepción).
 *  - `unmanaged`: ninguno de los archivos sigue siendo código sin gestionar
 *    (se declaró en el contrato o se aprobó como excepción).
 *  - `contract`: el laraimport del ámbito valida.
 *  - `decision`: hay una decisión registrada (`larapack:realign decide`).
 *
 * Una decisión, además de su comprobación, necesita estar decidida: hasta
 * entonces no se da por hecha aunque el problema hubiera desaparecido.
 *
 * Un tipo nuevo se añade aquí y en la documentación (docs/realign/README.md);
 * uno que no se conoce falla: no se da por hecho lo que no se sabe comprobar.
 *
 * @phpstan-type Result array{done: bool, remaining: list<string>, kind: string}
 */
final class DoneWhen
{
    public const KINDS = ['verify', 'classification', 'unmanaged', 'contract', 'decision'];

    private RealignState $state;

    /**
     * @var array<string, ScopeClassifier>
     */
    private array $classifiers = [];

    public function __construct(private readonly string $root)
    {
        $this->state = new RealignState($root);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return Result
     */
    public function check(array $step): array
    {
        $condition = is_array($step['done_when'] ?? null) ? $step['done_when'] : [];
        $kind = (string) ($condition['kind'] ?? '');
        $files = array_values(array_filter((array) ($condition['files'] ?? []), 'is_string'));

        $remaining = match ($kind) {
            'verify' => $this->verify($condition, $files),
            'classification' => $this->classification($condition, $files),
            'unmanaged' => $this->unmanaged($files),
            'contract' => $this->contract((string) ($condition['scope'] ?? '.')),
            'decision' => [],
            default => ["No se sabe comprobar `{$kind}`: actualiza innoboxrr/larapack-generator."],
        };

        if (($step['category'] ?? null) === Category::DECISION && ! is_array($step['decision'] ?? null)) {
            array_unshift($remaining, 'Falta decidir: larapack:realign decide '.($step['id'] ?? '<id>').' <opción>.');
        }

        return ['done' => $remaining === [], 'remaining' => $remaining, 'kind' => $kind];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<string>  $files
     * @return list<string>
     */
    private function verify(array $condition, array $files): array
    {
        $checks = array_values(array_intersect((array) ($condition['checks'] ?? []), Verifier::CHECKS));
        $package = is_string($condition['package'] ?? null) ? $condition['package'] : null;
        $verification = new ProjectVerification($this->root);

        if ($package !== null) {
            $resolved = $verification->resolve($package);

            if ($resolved === null) {
                return ["El paquete {$package} ya no existe."];
            }

            $verification = new ProjectVerification($this->root, [$resolved]);
        }

        // Sin comprobaciones conocidas no se da nada por bueno.
        if ($checks === []) {
            return ['El paso no dice qué comprobaciones de verify mirar.'];
        }

        $exceptions = new Exceptions($this->state);
        $remaining = [];

        foreach ($verification->run($checks)['findings'] as $finding) {
            if ($finding['level'] === Verifier::INFO || ($files !== [] && ! in_array($finding['file'], $files, true))) {
                continue;
            }

            if ($exceptions->covers(['type' => StepTypes::forFinding($finding), 'file' => $finding['file'], 'check' => $finding['check']])) {
                continue;
            }

            $remaining[] = "{$finding['check']}: ".($finding['file'] ?? '-').": {$finding['message']}";
        }

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<string>  $files
     * @return list<string>
     */
    private function classification(array $condition, array $files): array
    {
        $accept = array_values(array_filter((array) ($condition['accept'] ?? []), 'is_string'));
        $reject = array_values(array_filter((array) ($condition['reject'] ?? []), 'is_string'));
        $remaining = [];

        foreach ($files as $file) {
            $classifier = $this->classifierFor($file);

            if ($classifier === null) {
                $remaining[] = "{$file}: no se pudo regenerar el contrato de su ámbito para compararlo.";

                continue;
            }

            $entry = $classifier->classify($file);
            $class = $entry['class'];
            $problem = match (true) {
                $accept !== [] && ! in_array($class, $accept, true) => "es {$class} ({$entry['reason']}); se espera ".implode(' o ', $accept),
                in_array($class, $reject, true) => "sigue siendo {$class} ({$entry['reason']})",
                ($condition['tracked'] ?? false) === true && ! $entry['tracked'] => 'el manifiesto todavía no lo registra',
                ($condition['hash_current'] ?? false) === true && $entry['hash_current'] !== true => 'el hash del manifiesto todavía no es el de lo que hay en disco',
                ($condition['resolved'] ?? false) === true && $entry['type'] !== null => "sigue pendiente: {$entry['reason']}",
                default => null,
            };

            if ($problem !== null) {
                $remaining[] = "{$file}: {$problem}.";
            }
        }

        return $remaining;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function unmanaged(array $files): array
    {
        $still = [];

        foreach ($files as $file) {
            $classifier = $this->classifierFor($file, false);

            if ($classifier === null) {
                continue;
            }

            foreach ($classifier->unmanaged() as $item) {
                $still[$item['file']] = true;
            }
        }

        return array_values(array_map(
            fn (string $file): string => "{$file}: sigue escrito a mano, sin declarar ni aprobar.",
            array_filter($files, fn (string $file): bool => isset($still[$file]))
        ));
    }

    /**
     * @return list<string>
     */
    private function contract(string $path): array
    {
        $scopeRoot = $path === '.' ? $this->root : $this->root.'/'.$path;
        $errors = array_filter(
            ImportDocument::fromFile($scopeRoot.'/laraimport.json')['errors'],
            fn (array $error): bool => $error['level'] === SemanticValidator::ERROR
        );

        return array_values(array_map(fn (array $error): string => "{$path}/laraimport.json {$error['path']}: {$error['message']}", $errors));
    }

    /**
     * El clasificador del ámbito al que pertenece un archivo, con lo
     * regenerado (de la caché si no cambió nada). null si el ámbito no se
     * puede regenerar.
     */
    private function classifierFor(string $file, bool $needsReference = true): ?ScopeClassifier
    {
        $best = null;

        foreach ((new Scopes($this->root))->all() as $scope) {
            if (($scope['path'] === '.' || str_starts_with($file, $scope['path'].'/')) && ($best === null || strlen($scope['path']) > strlen($best['path']))) {
                $best = $scope;
            }
        }

        if ($best === null) {
            return null;
        }

        if (isset($this->classifiers[$best['path']])) {
            return $this->classifiers[$best['path']];
        }

        $directory = null;

        if ($best['contract']) {
            $outcome = (new Regeneration($this->state))->run([$best])[$best['path']] ?? null;
            $directory = $outcome !== null && $outcome['ok'] ? $outcome['directory'] : null;
        }

        if ($needsReference && $directory === null) {
            return null;
        }

        return $this->classifiers[$best['path']] = new ScopeClassifier($best, $this->root, $directory, new Exceptions($this->state));
    }
}
