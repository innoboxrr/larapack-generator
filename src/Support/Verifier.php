<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Comprueba que lo generado siga en su sitio y que las entidades equivalentes
 * tengan la misma forma.
 *
 * La idea no es adivinar si una clase "tiene demasiada lógica" —eso produce
 * falsos positivos y acaba desactivándose—, sino verificar lo que es
 * determinista: que los archivos existan, que las entidades comparables
 * tengan los mismos componentes, y que los contratos entre el backend
 * generado y el módulo JS sigan cuadrando.
 */
final class Verifier
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public function __construct(private ?Manifest $manifest = null)
    {
        $this->manifest ??= new Manifest();
    }

    /**
     * @return array<int, array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    public function run(): array
    {
        $models = $this->manifest->models();

        if ($models === []) {
            return [[
                'level' => self::WARNING,
                'check' => 'empty-manifest',
                'model' => null,
                'file' => null,
                'message' => 'No hay nada registrado. Genera algo o comprueba que .larapack/manifest.json esté en el proyecto correcto.',
            ]];
        }

        // Los artefactos del paquete (proveedores, config, andamiaje del
        // modulo npm) se comprueban igual, pero quedan fuera de la
        // consistencia entre entidades: no son una entidad.
        $withPackage = ['', ...$models];

        return [
            ...$this->checkFilesExist($withPackage),
            ...$this->checkCustomisations($withPackage),
            ...$this->checkComponentConsistency($models),
            ...$this->checkRoutePrefixContract($models),
        ];
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkFilesExist(array $models): array
    {
        $findings = [];

        foreach ($models as $model) {
            foreach ($this->manifest->filesFor($model) as $relative => $meta) {
                if (is_file($this->manifest->absolute($relative))) {
                    continue;
                }

                $findings[] = [
                    'level' => self::ERROR,
                    'check' => 'missing-file',
                    'model' => $model ?: null,
                    'file' => $relative,
                    'message' => "Se generó pero ya no existe. Regenéralo con --force o retíralo del manifiesto.",
                ];
            }
        }

        return $findings;
    }

    /**
     * Editar lo generado es lo normal: aquí sólo se informa, para que quede
     * claro qué no se puede regenerar sin perder trabajo.
     *
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkCustomisations(array $models): array
    {
        $findings = [];

        foreach ($models as $model) {
            foreach ($this->manifest->filesFor($model) as $relative => $meta) {
                $absolute = $this->manifest->absolute($relative);

                if (! is_file($absolute) || $this->manifest->hash($absolute) === $meta['hash']) {
                    continue;
                }

                $findings[] = [
                    'level' => self::INFO,
                    'check' => 'customised',
                    'model' => $model ?: null,
                    'file' => $relative,
                    'message' => 'Editado a mano desde que se generó; --force no lo tocará.',
                ];
            }
        }

        return $findings;
    }

    /**
     * Si todas las demás entidades tienen un componente y ésta no, es drift.
     *
     * Se compara sólo contra el consenso unánime del resto para no generar
     * ruido: una entidad con un componente extra no delata a las demás.
     *
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkComponentConsistency(array $models): array
    {
        if (count($models) < 2) {
            return [];
        }

        $components = [];

        foreach ($models as $model) {
            $components[$model] = $this->componentsOf($model);
        }

        $findings = [];

        foreach ($models as $model) {
            $others = array_diff($models, [$model]);

            $sharedByAllOthers = null;

            foreach ($others as $other) {
                $sharedByAllOthers = $sharedByAllOthers === null
                    ? $components[$other]
                    : array_intersect($sharedByAllOthers, $components[$other]);
            }

            foreach (array_diff($sharedByAllOthers ?? [], $components[$model]) as $missing) {
                $findings[] = [
                    'level' => self::WARNING,
                    'check' => 'inconsistent-entity',
                    'model' => $model,
                    'file' => null,
                    'message' => "No tiene componente {$missing} y todas las demás entidades sí.",
                ];
            }
        }

        return $findings;
    }

    /**
     * El módulo JS resuelve cada URL por nombre de ruta, componiendo
     * API_ROUTE_PREFIX. Ese prefijo tiene que reconstruir exactamente el
     * ->as('api.<dotNamespace><archivo>.') del RouteServiceProvider: si uno de
     * los dos cambia, el front deja de encontrar el backend en silencio.
     *
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkRoutePrefixContract(array $models): array
    {
        $findings = [];
        $manifest = $this->manifest->read();

        foreach ($models as $model) {
            foreach ($this->manifest->filesFor($model) as $relative => $meta) {
                if ($meta['stub'] !== 'ModelView/model.js') {
                    continue;
                }

                $absolute = $this->manifest->absolute($relative);

                if (! is_file($absolute)) {
                    continue;
                }

                $expected = $this->expectedRoutePrefix(
                    $manifest['models'][$model]['namespace'] ?? '',
                    basename(dirname($relative))
                );

                if (! preg_match("/API_ROUTE_PREFIX\s*=\s*'([^']+)'/", file_get_contents($absolute), $matches)) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'check' => 'route-prefix',
                        'model' => $model,
                        'file' => $relative,
                        'message' => 'No declara API_ROUTE_PREFIX.',
                    ];

                    continue;
                }

                if ($matches[1] !== $expected) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'check' => 'route-prefix',
                        'model' => $model,
                        'file' => $relative,
                        'message' => "API_ROUTE_PREFIX es '{$matches[1]}' y el RouteServiceProvider registra '{$expected}'.",
                    ];
                }
            }
        }

        return $findings;
    }

    private function expectedRoutePrefix(string $namespace, string $kebabModel): string
    {
        $dotNamespace = mb_strtolower(str_replace('\\', '.', $namespace));

        return 'api.' . $dotNamespace . str_replace('-', '_', $kebabModel) . '.';
    }

    /**
     * El primer segmento del stub identifica el componente: Model/…,
     * Policy/…, Requests/… Es el propio generador quien lo dice, así que no
     * puede desincronizarse de lo que genera.
     *
     * @return array<int, string>
     */
    private function componentsOf(string $model): array
    {
        $components = [];

        foreach ($this->manifest->filesFor($model) as $meta) {
            $components[] = explode('/', $meta['stub'])[0];
        }

        return array_values(array_unique($components));
    }
}
