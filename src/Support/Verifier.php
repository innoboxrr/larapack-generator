<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;

/**
 * Comprueba que lo generado siga en su sitio y que las entidades equivalentes
 * tengan la misma forma.
 *
 * La idea no es adivinar si una clase "tiene demasiada lógica" —eso produce
 * falsos positivos y acaba desactivándose—, sino verificar lo que es
 * determinista: que los archivos existan, que las entidades comparables
 * tengan los mismos componentes, y que los contratos entre el backend
 * generado y el módulo JS sigan cuadrando.
 *
 * La forma de los controladores también es determinista, y no depende del
 * manifiesto: ControllerShape la comprueba en todos, generados o no.
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

    /**
     * Las comprobaciones que dependen del manifiesto. Sin modelos registrados
     * no tienen nada que mirar.
     */
    private const MANIFEST_CHECKS = [
        'empty-manifest', 'missing-file', 'customised', 'inconsistent-entity', 'route-prefix',
        'route-not-declared', 'endpoint-missing', 'immutable-write', 'secret-exposed',
    ];

    /**
     * Todo lo que `verify` sabe comprobar, por el nombre que lleva cada
     * hallazgo en `check`. Es lo que acepta --check.
     */
    public const CHECKS = [...self::MANIFEST_CHECKS, ...ControllerShape::CHECKS, ...LegacySecurity::CHECKS];

    public function __construct(private ?Manifest $manifest = null)
    {
        $this->manifest ??= new Manifest;
    }

    /**
     * @param  array<int, string>  $only  sólo estas comprobaciones; vacío, todas
     * @return array<int, array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    public function run(array $only = []): array
    {
        $wants = fn (array $checks): bool => $only === [] || array_intersect($only, $checks) !== [];

        $findings = [
            ...($wants(self::MANIFEST_CHECKS) ? $this->manifestFindings() : []),
            // La forma de los controladores no depende del manifiesto: la
            // deriva está justo en los que el generador no escribió, y un
            // proyecto sin nada generado también tiene controladores.
            ...($wants(ControllerShape::CHECKS) ? (new ControllerShape($this->manifest->root()))->run() : []),
            ...($wants(LegacySecurity::CHECKS) ? (new LegacySecurity($this->manifest->root()))->run() : []),
        ];

        if ($only === []) {
            return $findings;
        }

        return array_values(array_filter($findings, fn (array $finding): bool => in_array($finding['check'], $only, true)));
    }

    /**
     * @return array<int, array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    private function manifestFindings(): array
    {
        // Se lee el manifiesto una vez para toda la verificación: cada check
        // lo consulta por modelo y por archivo, y releerlo del disco en cada
        // consulta hacía que un proyecto grande tardara minutos.
        $live = $this->manifest;
        $this->manifest = $live->snapshot();

        try {
            return $this->checks();
        } finally {
            $this->manifest = $live;
        }
    }

    /**
     * @return array<int, array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    private function checks(): array
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
                    'message' => 'Se generó pero ya no existe. Regenéralo con --force o retíralo del manifiesto.',
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
            // Las metas se declaran por modelo: que una tabla las tenga y otra
            // no es una decisión, no deriva. Contarlas avisaba en cada paquete
            // con un solo modelo con metas. Con los endpoints pasa lo mismo:
            // cada modelo declara los suyos, y uno sin ninguno no está roto.
            $components[$model] = array_values(array_diff($this->componentsOf($model), ['ModelMetas', 'MigrationMetas', 'Endpoint']));
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
     * Existen porque alguien los escribió a mano —el generador no los produce—
     * o porque se declararon, se generaron y luego se retiraron del laraimport
     * sin regenerar. Lo primero es la deriva que LaraPack existe para impedir;
     * lo segundo, una limpieza pendiente. El mensaje dice cuál de las dos es.
     *
     * Que el controlador haga algo más que `return $request->handle();` no se
     * mira aquí: es de ControllerShape (`controller-shape`), que lo comprueba
     * en todos los controladores, generados o no, con una sola regla.
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
                    $this->generatedBefore($file)
                        ? "{$what} {$action}, que {$model} ya no declara. Lo generó LaraPack cuando la declaraba: si la retiraste del laraimport, regenera con --force y borra lo que quede; si no, vuelve a declararla."
                        : "{$what} {$action}, que {$model} no declara. Alguien lo escribió a mano: declara la acción en el laraimport o retíralo."
                );
            }

            $findings = [
                ...$findings,
                ...$this->checkEndpoints($model, $declaration),
                ...$this->undeclaredEndpoints($model, $declaration),
            ];
        }

        return $findings;
    }

    /**
     * Que cada endpoint declarado tenga todas sus piezas.
     *
     * El controlador y las rutas se regeneran; la política y el trait de
     * operaciones son del proyecto y el generador no escribe en ellos si ya
     * existían. Un endpoint añadido después llega sin su habilidad ni su
     * operación, y aquí se dice cuál falta y cómo se llama.
     *
     * @param  array{actions: array<int, string>, immutable: bool, secret: array<int, string>, endpoints: array<int, array<string, mixed>>, controller: bool}  $declaration
     * @return array<int, array<string, mixed>>
     */
    private function checkEndpoints(string $model, array $declaration): array
    {
        if ($declaration['endpoints'] === []) {
            return [];
        }

        $findings = [];

        $controller = $this->fileFromStub($model, 'Controller/ControllerTemplate.txt');
        $controllerContent = $controller === null ? '' : (string) $this->contentsOf($controller);

        $routes = $this->fileFromStub($model, 'Route/RouteTemplate.txt');
        $routesContent = $routes === null ? '' : (string) $this->contentsOf($routes);

        $policy = $this->fileFromStub($model, 'Policy/PolicyTemplate.txt');
        $policyContent = $policy === null ? null : $this->contentsOf($policy);

        $app = $this->appDirectory($model);
        $operations = $app === null ? null : "{$app}/Models/Traits/Operations/{$model}Operations.php";
        $operationsContent = $operations === null ? null : $this->contentsOf($operations);

        // Regenerar con --force no toca lo editado a mano: el generador lo
        // conserva. Decir "regenera" ahí manda a un comando que no hace nada.
        $regenerate = fn (?string $file, string $what): string => $file !== null && $this->manifest->wasCustomised($this->manifest->absolute($file))
            ? "Regenerar no basta: {$what} está editado a mano y --force lo conserva. Lleva lo que tenga de más a la request o a la operación del modelo, bórralo y regenera."
            : "Regenera con --force: {$what} no se escribe a mano.";

        foreach ($declaration['endpoints'] as $endpoint) {
            $name = $endpoint['name'];
            $missing = fn (?string $file, string $message): array => $this->finding(self::ERROR, 'endpoint-missing', $model, $file, "El endpoint {$name} declarado {$message}");

            if (! preg_match('/function\s+'.preg_quote($name, '/').'\s*\(/', $controllerContent)) {
                $findings[] = $missing($controller, 'no tiene método en el controlador. '.$regenerate($controller, 'el controlador'));
            }

            if (! str_contains($routesContent, "->name('{$endpoint['route']}')")) {
                $findings[] = $missing($routes, "no tiene ruta '{$endpoint['route']}'. ".$regenerate($routes, 'el archivo de rutas'));
            }

            if ($app !== null) {
                $request = "{$app}/Http/Requests/{$model}/{$endpoint['request']}.php";

                if (! is_file($this->manifest->absolute($request))) {
                    $findings[] = $missing($request, "no tiene su {$endpoint['request']}. Regenera: se crea sin tocar lo demás.");
                }
            }

            if ($declaration['controller']) {
                continue;
            }

            $ability = (string) $endpoint['ability'];

            if ($policyContent !== null && ! Endpoints::isCrudAbility($ability) && ! preg_match('/function\s+'.preg_quote($ability, '/').'\s*\(/', $policyContent)) {
                $signature = ($endpoint['public'] ? '?User' : 'User').' $user'.($endpoint['target'] === 'record' ? ", {$model} \$".lcfirst($model) : '');

                $findings[] = $missing($policy, "pregunta a la política por '{$ability}' y la política no lo tiene. Añade `public function {$ability}({$signature}): Response|bool` y decide quién puede; sin él, Laravel lo niega siempre.");
            }

            if ($operationsContent === null || ! preg_match('/function\s+'.preg_quote((string) $endpoint['operation'], '/').'\s*\(/', $operationsContent)) {
                $findings[] = $missing($operations, "delega en {$model}::{$endpoint['operation']}(\$request) y el trait de operaciones no lo tiene. Escríbelo ahí: es donde va la lógica del endpoint.");
            }
        }

        return $findings;
    }

    /**
     * Lo que está en el controlador, en las rutas o en la carpeta de requests
     * del modelo sin que nadie lo haya declarado.
     *
     * Las acciones CRUD no declaradas las cuenta traces(); aquí va todo lo
     * demás: un método `__invoke`, un constructor que inyecta un servicio, una
     * ruta añadida a mano. Es la forma exacta que tomó la deriva cuando el
     * laraimport no sabía declarar un endpoint.
     *
     * @param  array{actions: array<int, string>, immutable: bool, secret: array<int, string>, endpoints: array<int, array<string, mixed>>, controller: bool}  $declaration
     * @return array<int, array<string, mixed>>
     */
    private function undeclaredEndpoints(string $model, array $declaration): array
    {
        $findings = [];
        $endpoints = $declaration['endpoints'];

        // Lo mismo aparece por dos caminos: alguien lo escribió a mano, o el
        // endpoint se declaró, se generó y después se quitó del laraimport.
        // El manifiesto distingue uno del otro, y culpar de lo segundo a
        // quien no tocó nada sólo enseña a desconfiar de verify.
        $hint = fn (string $file): string => $this->generatedBefore($file)
            ? 'Lo generó LaraPack para un endpoint que ya no está declarado: si lo retiraste del laraimport, regenera con --force para quitarlo del controlador y de las rutas, y borra su request y su test; si no, vuelve a declararlo.'
            : 'Alguien lo escribió a mano: declara el endpoint en el laraimport (endpoints, o controllers si no tiene modelo) y regenera.';

        $controller = $this->fileFromStub($model, 'Controller/ControllerTemplate.txt');

        if ($controller !== null && ($content = $this->contentsOf($controller)) !== null) {
            $known = [...Actions::ALL, ...array_column($endpoints, 'name'), 'middleware'];

            foreach ($this->publicMethods($content) as $method) {
                if (! in_array($method, $known, true)) {
                    $findings[] = $this->finding(self::ERROR, 'route-not-declared', $model, $controller,
                        "El controlador tiene un método {$method} que no es una acción ni un endpoint declarado. ".$hint($controller));
                }
            }
        }

        $routes = $this->fileFromStub($model, 'Route/RouteTemplate.txt');

        if ($routes !== null && ($content = $this->contentsOf($routes)) !== null) {
            $known = [...array_map(fn (string $action): string => Actions::routeName($action), Actions::ALL), ...array_column($endpoints, 'route')];

            preg_match_all("/->name\\(\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $content, $matches);

            foreach (array_diff($matches[1], $known) as $route) {
                $findings[] = $this->finding(self::ERROR, 'route-not-declared', $model, $routes,
                    "Hay una ruta '{$route}' que no es una acción ni un endpoint declarado. ".$hint($routes));
            }

            // Una ruta sin nombre no la puede llamar el front, y tampoco se ve
            // arriba: se cuentan las que hay contra las que tienen nombre.
            $registered = preg_match_all('/\bRoute::(?:get|post|put|patch|delete|options|any|match)\s*\(/', $content);

            if ($registered > count($matches[1])) {
                $findings[] = $this->finding(self::ERROR, 'route-not-declared', $model, $routes,
                    'Hay rutas sin nombre. Las generadas lo llevan todas; ésa la escribió alguien a mano: declara el endpoint en el laraimport (endpoints, o controllers si no tiene modelo) y regenera.');
            }
        }

        $app = $this->appDirectory($model);

        if ($app !== null) {
            $known = [...array_map(fn (string $action): string => Actions::requestClass($action), Actions::ALL), ...array_column($endpoints, 'request')];

            foreach (glob($this->manifest->absolute("{$app}/Http/Requests/{$model}").'/*Request.php') ?: [] as $file) {
                if (! in_array(basename($file, '.php'), $known, true)) {
                    $request = "{$app}/Http/Requests/{$model}/".basename($file);

                    $findings[] = $this->finding(self::ERROR, 'route-not-declared', $model, $request,
                        'Existe una request que no es de ninguna acción ni endpoint declarado. '.$hint($request));
                }
            }
        }

        return $findings;
    }

    /**
     * Los métodos públicos de la clase, sin los abstractos, que no tienen
     * cuerpo. Los lee PhpOutline, el mismo esqueleto que usa ControllerShape:
     * una expresión regular no sabe dónde acaba un método con llaves dentro.
     *
     * @return list<string>
     */
    private function publicMethods(string $php): array
    {
        $names = [];

        foreach (PhpOutline::ofCode($php)->classes() as $class) {
            foreach ($class['methods'] as $method) {
                if ($method['visibility'] === 'public' && $method['body'] !== null) {
                    $names[] = $method['name'];
                }
            }
        }

        return array_values(array_unique($names));
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
            if (str_contains($routesContent, "->name('".Actions::routeName($action)."')")) {
                $traces[] = [$action, $routes, 'Hay una ruta para'];
            }

            if (preg_match('/public function '.$action.'\s*\(/', $controllerContent)) {
                $traces[] = [$action, $controller, 'El controlador tiene un método para'];
            }

            if ($app !== null) {
                $request = "{$app}/Http/Requests/{$model}/".Actions::requestClass($action).'.php';

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
     * secretos no cuentan: no cambian qué componentes tiene. Un `controllers[]`
     * sólo se compara con otros: no tiene modelo, ni tabla, ni política.
     */
    private function shapeOf(string $model): string
    {
        $declaration = $this->manifest->declarationOf($model);

        return json_encode([$declaration['actions'], $declaration['immutable'], $declaration['controller']]);
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

    /**
     * El generador lo escribió: está en el manifiesto y, salvo una request,
     * nadie lo ha tocado desde entonces. Las requests se rellenan a mano por
     * diseño, así que basta con que el generador las haya creado.
     */
    private function generatedBefore(string $relative): bool
    {
        if ($this->manifest->stubOf($relative) === null) {
            return false;
        }

        return str_contains($relative, '/Http/Requests/')
            || ! $this->manifest->wasCustomised($this->manifest->absolute($relative));
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
        if (! preg_match('/\$'.$property.'\s*=\s*\[(.*?)\];/s', $php, $matches)) {
            return false;
        }

        return (bool) preg_match("/['\"]".preg_quote($value, '/')."['\"]/", $matches[1]);
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

        return 'api.'.$dotNamespace.str_replace('-', '_', $kebabModel).'.';
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
