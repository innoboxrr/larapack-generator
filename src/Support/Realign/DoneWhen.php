<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
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
 *  - `foreign-keys`: el laraimport del ámbito declara `on_delete` /
 *    `on_update` en cada una de esas columnas (`columns`); el valor que
 *    declare es suyo: callar era lo que había que resolver.
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
    public const KINDS = ['verify', 'classification', 'unmanaged', 'contract', 'foreign-keys', 'decision'];

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
            'verify' => $this->verify($condition, $files, (string) ($step['type'] ?? '')),
            'classification' => $this->classification($condition, $files),
            'unmanaged' => $this->unmanaged($files),
            'contract' => $this->contract((string) ($condition['scope'] ?? '.')),
            'foreign-keys' => $this->foreignKeys((string) ($condition['scope'] ?? '.'), array_values(array_filter((array) ($condition['columns'] ?? []), 'is_array'))),
            'decision' => [],
            default => ["No se sabe comprobar `{$kind}`: actualiza innoboxrr/larapack-generator."],
        };

        $undecided = ($step['category'] ?? null) === Category::DECISION ? Navigation::undecided($step) : [];

        if ($undecided === ['*'] || ($undecided !== [] && ! is_array($step['decisions'] ?? null))) {
            array_unshift($remaining, 'Falta decidir: larapack:realign decide '.($step['id'] ?? '<id>').' <opción>.');
        } elseif ($undecided !== []) {
            // Decidido por elemento, a medias: cuáles faltan.
            array_unshift($remaining, ...array_map(fn (string $file): string => "{$file}: falta decidir: larapack:realign decide ".($step['id'] ?? '<id>')." <opción> --target={$file}.", $undecided));
        }

        $remaining = [...$remaining, ...$this->exceptionsOf($step, $remaining !== [])];

        return ['done' => $remaining === [], 'remaining' => $remaining, 'kind' => $kind];
    }

    /**
     * Lo que falta de las excepciones del paso.
     *
     * Si se decidió dejarlo como está (una opción cuya orden es
     * `larapack:realign except`), el paso no está hecho hasta que el dueño
     * del proyecto apruebe cada excepción que se pidió: decidir y pedir lo
     * puede cualquiera; aprobar, no. Sin ninguna pedida, falta pedirla.
     *
     * En cualquier otro paso que todavía no pasa, las que esperan aprobación
     * se nombran, para que se sepa a quién le toca.
     *
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function exceptionsOf(array $step, bool $failing): array
    {
        $id = (string) ($step['id'] ?? '');
        $exceptions = new Exceptions($this->state);
        $requests = array_filter($exceptions->requests(), fn (array $request): bool => $request['step'] === $id && Navigation::stillRequested($step, $request));
        // Decidido por elemento, basta con que uno se deje como está.
        $excepting = Navigation::decidedFor($step, 'except');

        if (! $excepting && ! $failing) {
            return [];
        }

        if ($excepting && $requests === []) {
            return ["Se decidió dejarlo como está y falta pedir la excepción: larapack:realign except {$id} --option reason=\"por qué\"."];
        }

        $remaining = [];

        foreach ($requests as $target => $request) {
            $status = $exceptions->status($target);

            if ($status !== Exceptions::APPROVED) {
                $remaining[] = match ($status) {
                    Exceptions::UNAPPROVED => "{$target}: excepción solicitada y sin aprobar. La aprueba el dueño del proyecto (larapack:hand-rolled approve), no un agente.",
                    Exceptions::REASON_CHANGED => "{$target}: se aprobó con otra razón que la que se pide ahora; el dueño la vuelve a aprobar.",
                    Exceptions::CODE_CHANGED => "{$target}: se aprobó sobre otro código; el dueño la vuelve a aprobar si sigue valiendo.",
                    default => "{$target}: lo aprobado ya no existe; el dueño la retira (larapack:hand-rolled revoke).",
                };
            }
        }

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<string>  $files
     * @return list<string>
     */
    private function verify(array $condition, array $files, string $type = ''): array
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
            if ($finding['level'] === Verifier::INFO || ($files !== [] && ! $this->concerns($finding, $files))) {
                continue;
            }

            // Un hallazgo que pide otro tipo de paso es de ese paso, aunque
            // esté en el mismo archivo: el `fix` del fallback de la SPA está
            // hecho aunque en routes/web.php quede otro closure (una decisión).
            if ($type !== '' && StepTypes::exists($type) && StepTypes::forFinding($finding) !== $type) {
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
     * Si un hallazgo es de estos archivos. El de `request-handle` está en la
     * request, que el plan no conoce (el paso es del controlador): es de
     * ellos si su mensaje nombra el controlador. Sin esto, un controlador que
     * pasa a delegar en una request sin handle() daría el paso por hecho.
     *
     * @param  array{check: string, file: string|null, message: string}  $finding
     * @param  list<string>  $files
     */
    private function concerns(array $finding, array $files): bool
    {
        if (in_array($finding['file'], $files, true)) {
            return true;
        }

        if ($finding['check'] !== 'request-handle') {
            return false;
        }

        foreach ($files as $file) {
            if (str_contains($finding['message'], ' en '.$file.' ')) {
                return true;
            }
        }

        return false;
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
     * @param  list<array<string, mixed>>  $columns
     * @return list<string>
     */
    private function foreignKeys(string $path, array $columns): array
    {
        $file = ($path === '.' ? $this->root : $this->root.'/'.$path).'/laraimport.json';
        $raw = is_file($file) ? json_decode(Files::read($file), true) : null;

        if (! is_array($raw)) {
            return ["{$path}/laraimport.json no se puede leer."];
        }

        $declared = [];

        foreach (is_array($raw['models'] ?? null) ? $raw['models'] : [] as $model) {
            foreach (is_array($model) && is_array($model['props'] ?? null) ? $model['props'] : [] as $prop) {
                if (is_array($prop) && is_string($prop['name'] ?? null)) {
                    $declared[($model['name'] ?? '').'.'.$prop['name']] = $prop;
                }
            }
        }

        $remaining = [];

        foreach ($columns as $column) {
            $key = ($column['model'] ?? '').'.'.($column['column'] ?? '');
            $prop = $declared[$key] ?? null;

            foreach (['on_delete', 'on_update'] as $action) {
                if (! isset($column[$action])) {
                    continue;
                }

                if ($prop === null) {
                    $remaining[] = "{$key}: el contrato ya no la declara.";

                    break;
                }

                if (! array_key_exists($action, $prop)) {
                    $remaining[] = "{$key}: no declara {$action} (la migración hace {$column[$action]}).";
                }
            }
        }

        return $remaining;
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
