<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\Import\Actions;

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

    /**
     * Los componentes de interfaz que sólo tienen sentido con cada acción.
     */
    private const INTERFACE_TRACES = [
        'index' => ['views/AdminView', 'widgets/DataTable'],
        'show' => ['views/ShowView'],
        'create' => ['forms/CreateForm', 'views/CreateView'],
        'update' => ['forms/EditForm', 'views/EditView'],
    ];

    /**
     * Las claves que el propio Resource usa en sus acciones por fila. Que
     * aparezcan como cadena no significa que una columna se esté exponiendo.
     */
    private const RESOURCE_KEYS = ['id', 'name', 'callback', 'icon', 'route', 'policy', 'params', 'to'];

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
            ...$this->checkDeclaredActions($models),
            ...$this->checkImmutable($models),
            ...$this->checkSecrets($models),
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
     * Y sólo contra las que declararon la misma forma. Un modelo de sólo
     * lectura no tiene formulario de edición por diseño; compararlo con los
     * normales lo marcaría una vez por cada componente que no debe tener, y en
     * un sistema donde dos de cada tres tablas no tienen la forma por defecto
     * el aviso se convertiría en ruido que nadie lee.
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
            $others = array_filter(
                array_diff($models, [$model]),
                fn (string $other): bool => $this->shapeOf($other) === $this->shapeOf($model)
            );

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

    /**
     * Rastros de acciones que el modelo no declara.
     *
     * Existen porque alguien los escribió a mano: el generador no los produce.
     * Es la deriva que LaraPack existe para impedir, en el sitio donde hasta
     * ahora no tenía nada que decir.
     *
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkDeclaredActions(array $models): array
    {
        $findings = [];

        foreach ($models as $model) {
            $declaration = $this->manifest->declarationOf($model);
            $undeclared = array_diff(Actions::ALL, $declaration['actions']);

            // Lo que un modelo inmutable no puede tener lo cuenta
            // immutable-write, con un mensaje que dice por qué importa.
            if ($declaration['immutable']) {
                $undeclared = array_diff($undeclared, Actions::MODIFY);
            }

            foreach ($this->traces($model, $undeclared) as [$action, $file, $what]) {
                $findings[] = $this->finding(
                    self::ERROR,
                    'route-not-declared',
                    $model,
                    $file,
                    "{$what} {$action}, que {$model} no declara. Alguien lo escribió a mano: declara la acción en el laraimport o retíralo."
                );
            }
        }

        return $findings;
    }

    /**
     * Un modelo inmutable con un camino de escritura.
     *
     * Quitar la ruta impide entrar por HTTP, pero no que un servicio del propio
     * paquete llame a save(). Por eso se comprueba también que la guarda del
     * modelo siga en su sitio: es lo que sostiene la invariante en ejecución.
     *
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkImmutable(array $models): array
    {
        $findings = [];

        foreach ($models as $model) {
            if (! $this->manifest->declarationOf($model)['immutable']) {
                continue;
            }

            foreach ($this->traces($model, Actions::MODIFY) as [$action, $file, $what]) {
                $findings[] = $this->finding(
                    self::ERROR,
                    'immutable-write',
                    $model,
                    $file,
                    "{$what} {$action}, y {$model} es inmutable: sus filas no se modifican ni se borran."
                );
            }

            $modelFile = $this->fileFromStub($model, 'Model/ModelTemplate.txt');
            $content = $modelFile === null ? null : $this->contentsOf($modelFile);

            if ($content !== null && (! str_contains($content, 'static::updating(') || ! str_contains($content, 'static::deleting('))) {
                $findings[] = $this->finding(
                    self::ERROR,
                    'immutable-write',
                    $model,
                    $modelFile,
                    'El modelo ya no rechaza modificaciones. Sin la guarda de booted(), cualquier save() o delete() del propio paquete modifica una fila que no debería cambiar.'
                );
            }
        }

        return $findings;
    }

    /**
     * Una columna secreta que vuelve a salir.
     *
     * @param  array<int, string>  $models
     * @return array<int, array<string, mixed>>
     */
    private function checkSecrets(array $models): array
    {
        $findings = [];

        foreach ($models as $model) {
            $secret = $this->manifest->declarationOf($model)['secret'];

            if ($secret === []) {
                continue;
            }

            $modelFile = $this->fileFromStub($model, 'Model/ModelTemplate.txt');
            $modelContent = $modelFile === null ? null : $this->contentsOf($modelFile);

            $resource = $this->fileFromStub($model, 'Resource/ResourceTemplate.txt');
            $resourceContent = $resource === null ? null : $this->contentsOf($resource);

            foreach ($secret as $name) {
                $quoted = preg_quote($name, '/');

                if ($modelContent !== null && ! $this->arrayPropertyContains($modelContent, 'hidden', $name)) {
                    $findings[] = $this->finding(self::ERROR, 'secret-exposed', $model, $modelFile,
                        "'{$name}' es secreta y no está en \$hidden: toArray() la devuelve, y con él el Resource.");
                }

                if ($modelContent !== null && $this->arrayPropertyContains($modelContent, 'export_cols', $name)) {
                    $findings[] = $this->finding(self::ERROR, 'secret-exposed', $model, $modelFile,
                        "'{$name}' es secreta y está en \$export_cols: sale en la exportación.");
                }

                $namedInResource = $resourceContent !== null && (
                    preg_match("/->\\s*{$quoted}\\b|makeVisible\\([^)]*['\"]{$quoted}['\"]/", $resourceContent)
                    || (! in_array($name, self::RESOURCE_KEYS, true) && preg_match("/['\"]{$quoted}['\"]\\s*=>/", $resourceContent))
                );

                if ($namedInResource) {
                    $findings[] = $this->finding(self::ERROR, 'secret-exposed', $model, $resource,
                        "'{$name}' es secreta y el Resource la nombra: sale por la API aunque esté en \$hidden.");
                }

                foreach ($this->filesFromStub($model, 'ModelView/model.js') as $contract) {
                    if (preg_match("/id:\\s*['\"]{$quoted}['\"]/", (string) $this->contentsOf($contract))) {
                        $findings[] = $this->finding(self::ERROR, 'secret-exposed', $model, $contract,
                            "'{$name}' es secreta y es columna de la tabla.");
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Dónde aparecen unas acciones en el código del modelo: su ruta, su método
     * del controlador, su request y, en la interfaz, sus formularios y vistas.
     *
     * @param  array<int, string>  $actions
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function traces(string $model, array $actions): array
    {
        $traces = [];

        $routes = $this->fileFromStub($model, 'Route/RouteTemplate.txt');
        $routesContent = $routes === null ? '' : (string) $this->contentsOf($routes);

        $controller = $this->fileFromStub($model, 'Controller/ControllerTemplate.txt');
        $controllerContent = $controller === null ? '' : (string) $this->contentsOf($controller);

        $app = $this->appDirectory($model);

        foreach ($actions as $action) {
            if (str_contains($routesContent, "->name('" . Actions::routeName($action) . "')")) {
                $traces[] = [$action, $routes, 'Hay una ruta para'];
            }

            if (preg_match('/public function ' . $action . '\s*\(/', $controllerContent)) {
                $traces[] = [$action, $controller, 'El controlador tiene un método para'];
            }

            if ($app !== null) {
                $request = "{$app}/Http/Requests/{$model}/" . Actions::requestClass($action) . '.php';

                if (is_file($this->manifest->absolute($request))) {
                    $traces[] = [$action, $request, 'Existe el request de'];
                }
            }

            foreach ($this->filesFromStub($model, 'ModelView/model.js') as $contract) {
                $module = dirname($contract);
                $extension = str_contains($module, 'resources/react/') ? 'jsx' : 'vue';

                foreach (self::INTERFACE_TRACES[$action] ?? [] as $component) {
                    $file = "{$module}/{$component}.{$extension}";

                    if (is_file($this->manifest->absolute($file))) {
                        $traces[] = [$action, $file, 'Existe el componente de interfaz de'];
                    }
                }
            }
        }

        return $traces;
    }

    /**
     * La forma declarada que decide con quién es comparable un modelo. Los
     * secretos no cuentan: no cambian qué componentes tiene.
     */
    private function shapeOf(string $model): string
    {
        $declaration = $this->manifest->declarationOf($model);

        return json_encode([$declaration['actions'], $declaration['immutable']]);
    }

    private function fileFromStub(string $model, string $stub): ?string
    {
        return $this->filesFromStub($model, $stub)[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function filesFromStub(string $model, string $stub): array
    {
        $files = [];

        foreach ($this->manifest->filesFor($model) as $relative => $meta) {
            if ($meta['stub'] === $stub) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    private function contentsOf(string $relative): ?string
    {
        $absolute = $this->manifest->absolute($relative);

        return is_file($absolute) ? (string) file_get_contents($absolute) : null;
    }

    /**
     * `src` o `app`, leído de dónde se generó el modelo.
     */
    private function appDirectory(string $model): ?string
    {
        $modelFile = $this->fileFromStub($model, 'Model/ModelTemplate.txt')
            ?? $this->fileFromStub($model, 'Controller/ControllerTemplate.txt');

        if ($modelFile === null) {
            return null;
        }

        return str_contains($modelFile, '/Http/Controllers/')
            ? dirname($modelFile, 3)
            : dirname($modelFile, 2);
    }

    private function arrayPropertyContains(string $php, string $property, string $value): bool
    {
        if (! preg_match('/\$' . $property . '\s*=\s*\[(.*?)\];/s', $php, $matches)) {
            return false;
        }

        return (bool) preg_match("/['\"]" . preg_quote($value, '/') . "['\"]/", $matches[1]);
    }

    /**
     * @return array{level: string, check: string, model: string|null, file: string|null, message: string}
     */
    private function finding(string $level, string $check, ?string $model, ?string $file, string $message): array
    {
        return compact('level', 'check', 'model', 'file', 'message');
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
