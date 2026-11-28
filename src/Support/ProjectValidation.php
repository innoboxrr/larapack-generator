<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;

/**
 * `validate` sobre un proyecto entero: el contrato de la raíz y el de cada
 * paquete local, como `verify` (ProjectVerification) desde 9.3.1.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ: `larapack:validate` sólo leía el `laraimport.json` de la raíz. En
 * un monorepo como school-management cada paquete tiene el suyo, y el
 * criterio de terminado del realineamiento («validate pasa en cada ámbito»)
 * obligaba a correrlo una vez por paquete con su --root. Ahora recorre los
 * mismos ámbitos que `verify`, con los mismos nombres en --package.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Qué ámbitos: los de ProjectVerification::scopes(). Un paquete sin
 * `laraimport.json` no es un error —no todos los paquetes tienen contrato—:
 * sale en el resumen con `contract: false`. La raíz sin contrato tampoco lo es
 * si algún paquete lo tiene (una raíz que sólo agrupa paquetes); sin ninguno
 * en todo el proyecto, sí, como siempre. Un ámbito nombrado en --package sin
 * contrato es un error: se pidió validar algo que no existe.
 *
 * Cada hallazgo lleva `package` (el nombre de Composer, o null en el núcleo)
 * y `file` (el contrato, relativo a la raíz del proyecto).
 *
 * @phpstan-type Finding array{level: string, path: string, message: string, package: string|null, file: string}
 * @phpstan-type Scope array{package: string|null, path: string, file: string, contract: bool, ok: bool, errors: int, warnings: int, models: list<string>}
 */
final class ProjectValidation
{
    public const CONTRACT = 'laraimport.json';

    /**
     * @param  list<string>  $packages  raíces absolutas a las que acotar; vacío, todas
     */
    public function __construct(
        private readonly string $root,
        private readonly array $packages = [],
        private readonly bool $withPackages = true,
        private readonly bool $interface = false,
    ) {}

    /**
     * @return array{findings: list<Finding>, summary: list<Scope>, errors: int, warnings: int}
     */
    public function run(bool $strict = false): array
    {
        $scopes = (new ProjectVerification($this->root, $this->packages, $this->withPackages))->scopes();
        $explicit = $this->packages !== [];
        $withContract = array_filter($scopes, fn (array $scope): bool => is_file($scope['root'].'/'.self::CONTRACT));
        $findings = [];
        $summary = [];

        foreach ($scopes as $scope) {
            $file = ($scope['path'] === '.' ? '' : $scope['path'].'/').self::CONTRACT;
            $exists = is_file($scope['root'].'/'.self::CONTRACT);

            // Sin contrato: un paquete que no lo tiene, o una raíz que sólo
            // agrupa paquetes que sí, no es un fallo. Lo pedido por nombre, o
            // un proyecto sin ningún contrato, sí.
            if (! $exists && ! $explicit && ($scope['package'] !== null || $withContract !== [])) {
                $summary[] = ['package' => $scope['package'], 'path' => $scope['path'], 'file' => $file, 'contract' => false, 'ok' => true, 'errors' => 0, 'warnings' => 0, 'models' => []];

                continue;
            }

            $own = array_map(
                fn (array $finding): array => $finding + ['package' => $scope['package'], 'file' => $file],
                $this->findings($scope['root'])
            );
            $errors = self::count($own, SemanticValidator::ERROR);
            $warnings = self::count($own, SemanticValidator::WARNING);
            $findings = [...$findings, ...$own];
            $summary[] = [
                'package' => $scope['package'],
                'path' => $scope['path'],
                'file' => $file,
                'contract' => $exists,
                'ok' => $errors === 0 && (! $strict || $warnings === 0),
                'errors' => $errors,
                'warnings' => $warnings,
                'models' => $this->models($scope['root']),
            ];
        }

        return [
            'findings' => $findings,
            'summary' => $summary,
            'errors' => self::count($findings, SemanticValidator::ERROR),
            'warnings' => self::count($findings, SemanticValidator::WARNING),
        ];
    }

    /**
     * Lo que dice `validate` de un archivo: la forma, la coherencia, lo que
     * comprobará `import` contra lo ya generado en ese ámbito y, si se pide,
     * lo que necesitan las vistas.
     *
     * @return list<array{level: string, path: string, message: string}>
     */
    public static function file(string $path, string $scopeRoot, bool $interface = false): array
    {
        ['document' => $document, 'errors' => $findings] = ImportDocument::fromFile($path);

        if ($document !== null) {
            $findings = [...$findings, ...ProjectRoot::using($scopeRoot, fn (): array => SemanticValidator::againstManifest($document->toArray(), new Manifest($scopeRoot)))];
        }

        if ($document !== null && $interface) {
            $findings = [...$findings, ...SemanticValidator::interface($document->toArray())];
        }

        return array_values($findings);
    }

    /**
     * @return list<array{level: string, path: string, message: string}>
     */
    private function findings(string $scopeRoot): array
    {
        return self::file($scopeRoot.'/'.self::CONTRACT, $scopeRoot, $this->interface);
    }

    /**
     * Los modelos del contrato en orden de migración; vacío si no se lee.
     *
     * @return list<string>
     */
    private function models(string $scopeRoot): array
    {
        $document = ImportDocument::fromFile($scopeRoot.'/'.self::CONTRACT)['document'];

        return $document === null ? [] : array_map('strval', array_column($document->models(), 'name'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private static function count(array $findings, string $level): int
    {
        return count(array_filter($findings, fn (array $finding): bool => $finding['level'] === $level));
    }
}
