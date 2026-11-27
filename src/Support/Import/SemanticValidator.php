<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\TablePrefix;

/**
 * Comprobaciones que el esquema no puede expresar porque miran a varias partes
 * del documento a la vez.
 *
 * Un JSON Schema valida la forma de cada pieza; esto valida que las piezas
 * encajen entre sí: que una clave foránea apunte a algo que exista, que una
 * relación pueda resolverse, que no haya nombres repetidos.
 *
 * @phpstan-type Finding array{level: string, path: string, message: string}
 */
final class SemanticValidator
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * Symfony\Component\Routing\RouteCompiler::VARIABLE_MAXIMUM_LENGTH: el
     * paquete no depende de symfony/routing, así que se copia.
     */
    private const ROUTE_PARAMETER_MAX_LENGTH = 32;

    /**
     * @param  array<string, mixed>  $document  Ya normalizado con los defaults.
     * @param  array<string, mixed>  $raw  Tal como se escribió. Hace falta para
     *                                     distinguir un valor declarado de uno
     *                                     puesto por omisión.
     * @return list<Finding>
     */
    public static function validate(array $document, array $raw = []): array
    {
        $models = $document['models'] ?? [];
        $pivots = $document['pivots'] ?? [];

        return [
            ...self::duplicateModels($models),
            ...self::duplicateProps($models),
            ...self::duplicatePivots($pivots),
            ...self::tables($models, $pivots),
            ...self::columnShapes($models),
            ...self::indexes($models),
            ...self::foreignKeys($models, $pivots),
            ...self::relations($models),
            ...self::ruleFields($models),
            ...self::enums($models),
            ...self::routes($models),
            ...self::secrets($models, $raw['models'] ?? []),
            ...self::metas($models, $raw['models'] ?? []),
            ...self::display($models),
            ...self::authenticatable($models),
            ...self::prefixedRules($document),
            ...self::referentialActions($models, $pivots),
            ...self::endpoints($models, $document['controllers'] ?? []),
        ];
    }

    /**
     * Los endpoints declarados, de los modelos y de `controllers`.
     *
     * Todo lo que se comprueba aquí acabaría, si no, en un archivo generado que
     * no compila, en una ruta tapada por otra o en una política que pregunta
     * por un método que no existe. Se dice antes de escribir nada.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, array<string, mixed>>  $controllers
     * @return list<Finding>
     */
    private static function endpoints(array $models, array $controllers): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $findings = [...$findings, ...self::endpointsOf($model, "/models/{$index}", Actions::resolve($model))];
        }

        $modelNames = array_column($models, 'name');
        $seen = [];

        foreach ($controllers as $index => $controller) {
            $path = "/controllers/{$index}";
            $name = $controller['name'];

            if (in_array($name, $modelNames, true)) {
                $findings[] = self::error("{$path}/name", "El controlador '{$name}' se llama como un modelo: compartirían archivo de rutas y carpeta de requests. Declara el endpoint en el modelo o elige otro nombre.");
            }

            if (isset($seen[$name])) {
                $findings[] = self::error("{$path}/name", "El controlador '{$name}' está declarado dos veces (también en /controllers/{$seen[$name]}).");
            }

            if (str_ends_with($name, 'Controller')) {
                $findings[] = self::error("{$path}/name", "'{$name}' lleva el sufijo Controller, y la clase saldría {$name}Controller. Declara '".substr($name, 0, -strlen('Controller'))."'.");
            }

            $seen[$name] = $index;

            $findings = [...$findings, ...self::endpointsOf($controller, $path, null)];
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $owner  El modelo o el controlador.
     * @param  array<int, string>|null  $actions  Las acciones CRUD que quedan; null si no es un modelo.
     * @return list<Finding>
     */
    private static function endpointsOf(array $owner, string $path, ?array $actions): array
    {
        $findings = [];
        $names = [];
        $routeNames = [];
        $declaredRoutes = [];
        $routes = [];
        $abilities = [];
        $idParameter = self::snake($owner['name']).'_id';

        foreach ($owner['endpoints'] ?? [] as $position => $endpoint) {
            $at = "{$path}/endpoints/{$position}";
            $name = $endpoint['name'];
            $uri = Endpoints::uri($endpoint);
            $public = ! empty($endpoint['public']);

            if (isset($names[$name])) {
                $findings[] = self::error("{$at}/name", "El endpoint '{$name}' está declarado dos veces en {$owner['name']}.");
            }

            $names[$name] = true;

            // importCsv e importCSV son dos métodos y una sola ruta, import.csv,
            // y en un disco que no distingue mayúsculas, una sola request.
            $routeName = Endpoints::routeName($name);

            if (isset($routeNames[$routeName]) && $routeNames[$routeName] !== $name) {
                $findings[] = self::error("{$at}/name", "'{$name}' y '{$routeNames[$routeName]}' dan la misma ruta, '{$routeName}': el front no podría llamar a uno de los dos. Elige otro nombre.");
            }

            $routeNames[$routeName] ??= $name;

            // Un `route_name` declarado (para conservar el nombre de una ruta
            // escrita a mano) tampoco puede repetirse, ni ser el de una acción
            // CRUD que siga existiendo: route() devolvería la otra.
            if (isset($endpoint['route_name'])) {
                $declared = Endpoints::declaredRouteName($endpoint);

                if (isset($declaredRoutes[$declared])) {
                    $findings[] = self::error("{$at}/route_name", "'{$name}' y '{$declaredRoutes[$declared]}' se llaman igual, '{$declared}': el front no podría llamar a uno de los dos.");
                }

                $declaredRoutes[$declared] ??= $name;

                if ($actions !== null && in_array($declared, array_map(fn (string $action): string => Actions::routeName($action), $actions), true)) {
                    $findings[] = self::error("{$at}/route_name", "'{$declared}' es el nombre de ruta de una acción CRUD de {$owner['name']}: las dos rutas se llamarían igual. Quita la acción con `routes` o elige otro nombre.");
                }
            } else {
                $declaredRoutes[$routeName] ??= $name;
            }

            // Con un nombre inválido no se sigue mirando la habilidad: por
            // omisión es el propio nombre, y repetiría el mismo error.
            $validName = true;

            if (in_array($name, Actions::ALL, true)) {
                $findings[] = self::error("{$at}/name", "'{$name}' es una acción CRUD: se declara con `routes`, no como endpoint.");
                $validName = false;
            } elseif (self::reservedName($name)) {
                $findings[] = self::error("{$at}/name", "'{$name}' no puede ser el nombre de un endpoint: choca con PHP o con un método que el controlador, la request o la política ya tienen. Elige un verbo que diga qué hace.");
                $validName = false;
            }

            $route = $endpoint['method'].' '.$uri;

            if (isset($routes[$route])) {
                $findings[] = self::error("{$at}/uri", "{$owner['name']} declara dos veces ".strtoupper($endpoint['method'])." {$uri}.");
            }

            $routes[$route] = true;

            // Sólo tapa una ruta CRUD que exista: un controllers[] no tiene
            // ninguna, y un modelo sólo las de sus acciones. Hasta 9.4.0 se
            // rechazaba siempre, y una ruta escrita a mano `.../create` de un
            // controlador sin modelo no se podía adoptar conservando su URI.
            if ($actions !== null && in_array($uri, array_map(fn (string $action): string => Endpoints::kebab($action), $actions), true)) {
                $findings[] = self::error("{$at}/uri", "La URI '{$uri}' es la de una acción CRUD: la ruta CRUD se registra antes y taparía este endpoint.");
            }

            $parameters = Endpoints::parameters($uri);

            // El compilador de rutas de Symfony, que usa Laravel, lanza una
            // excepción con un parámetro repetido o de más de 32 caracteres.
            // No al registrar la ruta sino al compilarla: en la primera
            // petición con ese verbo que llegue hasta ella —también un 404—, y
            // en `route:cache`. El esquema no lo ve: la URI es válida.
            foreach (array_unique(array_diff_assoc($parameters, array_unique($parameters))) as $repeated) {
                $findings[] = self::error("{$at}/uri", "La URI '{$uri}' repite el parámetro {{$repeated}}: Laravel no puede compilar esa ruta y la petición acaba en un 500.");
            }

            foreach (array_unique($parameters) as $parameter) {
                if (strlen($parameter) > self::ROUTE_PARAMETER_MAX_LENGTH) {
                    $findings[] = self::error("{$at}/uri", "El parámetro {{$parameter}} de '{$uri}' tiene más de ".self::ROUTE_PARAMETER_MAX_LENGTH.' caracteres: Laravel no puede compilar esa ruta y la petición acaba en un 500. Acórtalo.');
                }
            }

            if ($actions !== null && ($endpoint['target'] ?? 'record') === 'record' && $parameters !== [] && ! in_array($idParameter, $parameters, true)) {
                $findings[] = self::error("{$at}/uri", "'{$name}' actúa sobre un registro y la request lo lee de {$idParameter}. Si el identificador va en la URI, el parámetro se llama {{$idParameter}}.");
            }

            if ($public && ! self::throttled($endpoint['middleware'] ?? [])) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => "{$at}/middleware",
                    'message' => "'{$name}' es público y no declara throttle: cualquiera puede llamarlo tantas veces como quiera. Añade, por ejemplo, \"throttle:30,1\".",
                ];
            }

            if ($actions !== null && $validName) {
                $findings = [...$findings, ...self::endpointAbility($owner, $endpoint, $at, $actions, $abilities)];
            }

            // `authorize: authenticated` dice que basta la sesión: un Gate que
            // no se pregunta, o un público sin sesión, lo contradicen.
            if (($endpoint['authorize'] ?? null) === 'authenticated') {
                if (isset($endpoint['ability'])) {
                    $findings[] = self::error("{$at}/authorize", "'{$name}' declara authorize: authenticated y ability '{$endpoint['ability']}': con authenticated no se pregunta a ningún Gate. Quita una de las dos.");
                }

                if ($public) {
                    $findings[] = self::error("{$at}/authorize", "'{$name}' es público y declara authorize: authenticated: un público no exige sesión. Quita una de las dos.");
                }
            }
        }

        return [...$findings, ...self::openFirstSegments($owner, $path, $actions === null ? null : $idParameter)];
    }

    /**
     * Un endpoint cuya URI empieza por un parámetro sin restricción, como
     * GET `{slug}`.
     *
     * Casa con cualquier primer segmento, y Laravel se queda con la primera
     * ruta que casa: todo endpoint con el mismo verbo declarado después
     * —GET `stats`— llega a su método. Las CRUD van antes en el archivo y no
     * se ven afectadas. El `{<modelo>_id}` de un endpoint de registro no
     * cuenta: la ruta lo restringe a números.
     *
     * Si tapa a otro endpoint del laraimport es un error; si no, un aviso:
     * taparía al siguiente que se declare.
     *
     * @param  array<string, mixed>  $owner
     * @param  string|null  $idParameter  El parámetro numérico; null en un controllers[].
     * @return list<Finding>
     */
    private static function openFirstSegments(array $owner, string $path, ?string $idParameter): array
    {
        $findings = [];
        $endpoints = array_values($owner['endpoints'] ?? []);

        $numeric = fn (array $endpoint): ?string => $idParameter !== null && ($endpoint['target'] ?? 'record') === 'record'
            ? $idParameter
            : null;

        foreach ($endpoints as $position => $endpoint) {
            $uri = Endpoints::uri($endpoint);

            if (! preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\??\}/', $uri, $parameter) || $parameter[1] === $numeric($endpoint)) {
                continue;
            }

            $pattern = Endpoints::pattern($uri, $numeric($endpoint));
            $shadowed = [];

            foreach (array_slice($endpoints, $position + 1) as $later) {
                if ($later['method'] !== $endpoint['method']) {
                    continue;
                }

                // Una petición de verdad al de después: sus parámetros con un
                // valor que su propia ruta aceptaría.
                $request = (string) preg_replace_callback(
                    '/\{([A-Za-z_][A-Za-z0-9_]*)\??\}/',
                    fn (array $match): string => $match[1] === $numeric($later) ? '1' : 'x',
                    Endpoints::uri($later)
                );

                if (preg_match($pattern, $request)) {
                    $shadowed[] = "'{$later['name']}'";
                }
            }

            $name = (string) $endpoint['name'];
            $verb = strtoupper((string) $endpoint['method']);
            $fixed = Endpoints::kebab($name).'/'.$uri;

            $findings[] = $shadowed === []
                ? [
                    'level' => self::WARNING,
                    'path' => "{$path}/endpoints/{$position}/uri",
                    'message' => "'{$name}' empieza por {{$parameter[1]}}, un parámetro sin restricción: casa con cualquier primer segmento y se quedaría con todo endpoint {$verb} que se declare después. Pon delante un segmento fijo, como '{$fixed}'.",
                ]
                : self::error(
                    "{$path}/endpoints/{$position}/uri",
                    "'{$name}' empieza por {{$parameter[1]}}, que casa con cualquier primer segmento, y se registra antes que ".implode(', ', $shadowed).": esas peticiones llegarían a '{$name}'. Pon delante un segmento fijo, como '{$fixed}', o decláralo el último."
                );
        }

        return $findings;
    }

    /**
     * La habilidad de un endpoint de modelo: una CRUD tiene que existir y casar
     * con su firma; una propia, tener la misma firma en todos los endpoints que
     * la comparten.
     *
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $endpoint
     * @param  array<int, string>  $actions
     * @param  array<string, array{0: string, 1: bool, 2: string}>  $abilities
     * @return list<Finding>
     */
    private static function endpointAbility(array $model, array $endpoint, string $at, array $actions, array &$abilities): array
    {
        // El esquema ya garantizó que son cadenas; aquí sólo se dice.
        $name = (string) $endpoint['name'];
        $ability = (string) ($endpoint['ability'] ?? $name);
        $target = (string) ($endpoint['target'] ?? 'record');
        $public = ! empty($endpoint['public']);

        if (Endpoints::isCrudAbility($ability)) {
            [$action, $signature] = Endpoints::CRUD_ABILITIES[$ability];
            $findings = [];

            if (! in_array($action, $actions, true)) {
                $findings[] = self::error("{$at}/ability", "'{$name}' pregunta a la política por '{$ability}', y {$model['name']} no tiene {$action}: la política no tendrá ese método. Declara una habilidad propia o añade {$action} a routes.");
            }

            if ($signature !== $target) {
                $findings[] = self::error("{$at}/ability", "'{$ability}' ".($signature === 'record' ? 'recibe un registro' : 'no recibe un registro')." y '{$name}' es target: {$target}. Cambia el target o declara una habilidad propia.");
            }

            if ($public) {
                $findings[] = self::error("{$at}/ability", "'{$name}' es público y pregunta por '{$ability}', que no admite invitados. Declara una habilidad propia: se genera con ?User.");
            }

            return $findings;
        }

        if (self::reservedName($ability)) {
            return [self::error("{$at}/ability", "'{$ability}' no puede ser una habilidad: sería un método de la política que choca con PHP o con before(), que decide por el administrador.")];
        }

        if (isset($abilities[$ability]) && [$abilities[$ability][0], $abilities[$ability][1]] !== [$target, $public]) {
            return [self::error("{$at}/ability", "'{$name}' y '{$abilities[$ability][2]}' comparten la habilidad '{$ability}' con distinto target o distinto public: el método de la política tendría dos firmas.")];
        }

        $abilities[$ability] = [$target, $public, $name];

        return [];
    }

    private static function reservedName(string $name): bool
    {
        return in_array($name, Endpoints::RESERVED, true)
            || in_array(mb_strtolower($name), Endpoints::PHP_KEYWORDS, true);
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private static function throttled(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === 'throttle' || str_starts_with($entry, 'throttle:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{level: string, path: string, message: string}
     */
    private static function error(string $path, string $message): array
    {
        return ['level' => self::ERROR, 'path' => $path, 'message' => $message];
    }

    /**
     * `set null` sobre una columna que no admite nulos.
     *
     * MySQL lo rechaza al crear la tabla con un 1215 pelado, que no dice cuál
     * de las claves ajenas es ni por qué. Aquí se dice antes de escribir nada, y
     * con el nombre de la columna delante.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, array<string, mixed>>  $pivots
     * @return list<Finding>
     */
    private static function referentialActions(array $models, array $pivots): array
    {
        $findings = [];

        foreach ([['models', $models], ['pivots', $pivots]] as [$section, $entries]) {
            foreach ($entries as $index => $entry) {
                foreach ($entry['props'] as $position => $prop) {
                    if ($prop['type'] !== 'foreignId') {
                        continue;
                    }

                    foreach (['on_delete', 'on_update'] as $key) {
                        if (($prop[$key] ?? null) !== 'set null' || ! empty($prop['nullable'])) {
                            continue;
                        }

                        $findings[] = [
                            'level' => self::ERROR,
                            'path' => "/{$section}/{$index}/props/{$position}/{$key}",
                            'message' => "'{$prop['name']}' declara {$key}: 'set null' y no es nullable: "
                                .'la base no puede poner NULL donde no cabe. Añade "nullable": true o '
                                .'usa "restrict".',
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Una regla `unique:` o `exists:` que nombra una tabla de este archivo SIN
     * el prefijo.
     *
     * ES EL ÚNICO SITIO DONDE EL PREFIJO NO SE PUEDE RESOLVER SOLO. Las reglas
     * son cadenas libres que escribe quien hace el contrato; reescribirlas aquí
     * sería adivinar —`unique:students` puede apuntar a otra tabla llamada
     * igual en la aplicación anfitriona— así que se avisa y se deja decidir.
     *
     * Lo que no se avisa se paga tarde: la validación consulta una tabla que no
     * existe, y el error sale en producción con un SQLSTATE, no aquí.
     *
     * @param  array<string, mixed>  $document
     * @return list<Finding>
     */
    private static function prefixedRules(array $document): array
    {
        $prefix = is_string($document['table_prefix'] ?? null) ? $document['table_prefix'] : '';

        if ($prefix === '') {
            return [];
        }

        $models = $document['models'] ?? [];
        $tables = array_map(fn (array $model): string => self::tableOf($model['name']), $models);

        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['requests'] ?? [] as $position => $request) {
                foreach ($request['rules'] ?? [] as $field => $rules) {
                    foreach ((array) $rules as $rule) {
                        if (! is_string($rule)) {
                            continue;
                        }

                        foreach (['unique', 'exists'] as $kind) {
                            if (! preg_match('/\b'.$kind.':([A-Za-z0-9_]+)/', $rule, $matches)) {
                                continue;
                            }

                            if (! in_array($matches[1], $tables, true)) {
                                continue;
                            }

                            $findings[] = [
                                'level' => self::WARNING,
                                'path' => "/models/{$index}/requests/{$position}/rules/{$field}",
                                'message' => "'{$kind}:{$matches[1]}' nombra una tabla de este archivo sin el prefijo "
                                    ."'{$prefix}'. Escribe '{$kind}:{$prefix}{$matches[1]}' o, mejor, la clase del "
                                    .'modelo, que no depende del prefijo.',
                            ];
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Un usuario que inicia sesión necesita con qué entrar: su correo y su
     * contraseña. Sin ellos el modelo se genera, pero nadie puede usarlo.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function authenticatable(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            if (empty($model['authenticatable'])) {
                continue;
            }

            $names = array_column($model['props'] ?? [], 'name');

            foreach (['email', 'password'] as $required) {
                if (! in_array($required, $names, true)) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props",
                        'message' => "{$model['name']} es authenticatable y no declara `{$required}`: sin él nadie puede iniciar sesión.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Un `controllers[]` que se llama como un modelo que ya está generado.
     *
     * validate() sólo ve este laraimport, y el choque con un modelo del mismo
     * archivo ya lo dice. Pero el manifiesto guarda también lo que generaron
     * otros laraimports del proyecto: el controlador escribiría encima del
     * controlador y de las rutas de ese modelo —con --force, sus CRUD
     * desaparecerían— y su anotación pasaría a decir que no es un modelo.
     *
     * Al revés no se comprueba a propósito: declarar como modelo lo que era
     * un controlador sin modelo es la forma de convertirlo.
     *
     * @param  array<string, mixed>  $document
     * @return list<Finding>
     */
    public static function againstManifest(array $document, Manifest $manifest): array
    {
        $generated = $manifest->models();
        $findings = [];

        foreach ($document['controllers'] ?? [] as $index => $controller) {
            $name = (string) $controller['name'];

            if (in_array($name, $generated, true) && ! $manifest->declarationOf($name)['controller']) {
                $findings[] = self::error("/controllers/{$index}/name", "El controlador '{$name}' se llama como el modelo {$name}, que ya está generado (está en .larapack/manifest.json): escribiría sobre su controlador, sus rutas y su carpeta de requests. Declara el endpoint en ese modelo o elige otro nombre.");
            }
        }

        return $findings;
    }

    /**
     * Lo que sólo importa si además se genera el módulo de interfaz.
     *
     * Va aparte a propósito. Un paquete que sólo expone una API declara modelos
     * sin index constantemente, y un aviso que aparece siempre y no hay que
     * atender es como se deja de leer la lista entera.
     *
     * @param  array<string, mixed>  $document
     * @return list<Finding>
     */
    public static function interface(array $document): array
    {
        $findings = [];

        foreach ($document['models'] ?? [] as $index => $model) {
            $actions = $model['actions'] ?? Actions::resolve($model);
            $path = "/models/{$index}/routes";

            if (! in_array('index', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} no tiene index: las vistas cuelgan del índice, así que su módulo de interfaz sólo trae el contrato y el store.",
                ];

                continue;
            }

            if (! in_array('policies', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} tiene index pero no policies: la tabla consulta las políticas para decidir qué acciones ofrece, así que no se generan vistas.",
                ];

                continue;
            }

            if (in_array('update', $actions, true) && ! in_array('show', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} tiene update pero no show: la edición cuelga de la vista de detalle, así que la interfaz no trae formulario de edición.",
                ];
            }

            // Un endpoint que cambia algo sale como acción en la tabla y en la
            // ficha, salvo si su URI pide algo que la pantalla no tiene: el
            // registro lo pone la fila, pero otro parámetro no tiene de dónde
            // salir. Se queda con su función del contrato y nada más.
            foreach ($model['endpoints'] ?? [] as $position => $endpoint) {
                $endpoint = Endpoints::normalise($endpoint, true);

                if ($endpoint['method'] !== 'get' && ! Endpoints::offersInterface($endpoint)) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/models/{$index}/endpoints/{$position}/uri",
                        'message' => "{$model['name']}.{$endpoint['name']} lleva en la URI parámetros que la interfaz no sabe rellenar: no sale en la tabla ni en la ficha. Llama a ".Endpoints::jsFunction($endpoint).'() del contrato JS desde tu pantalla.',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * La coherencia entre las acciones que quedan.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function routes(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $actions = Actions::resolve($model);
            $path = "/models/{$index}/routes";

            // Declarar a la vez que la fila no cambia y que se puede cambiar.
            // Con `except` o sin `routes` no hay contradicción: immutable
            // simplemente las quita.
            if (! empty($model['immutable'])) {
                $contradicted = array_intersect(Actions::MODIFY, $model['routes']['only'] ?? []);

                if ($contradicted !== []) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "{$path}/only",
                        'message' => "{$model['name']} es immutable y declara ".implode(', ', $contradicted).': una fila inmutable no se modifica ni se borra.',
                    ];
                }
            }

            // Una masiva sin su individual se quitaría en silencio: con `only`
            // alguien la pidió expresamente, así que se le dice.
            foreach (Actions::REQUIRES as $bulk => $single) {
                $only = $model['routes']['only'] ?? null;

                if (is_array($only) && in_array($bulk, $only, true) && ! in_array($single, $only, true)) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "{$path}/only",
                        'message' => "{$model['name']} declara {$bulk} sin {$single}: la acción masiva usa la política y las reglas de la individual. Añade {$single} o quita {$bulk}.",
                    ];
                }
            }

            // No es error: una fila que borra un proceso en segundo plano y se
            // restaura desde la API es una forma legítima.
            if (! in_array('delete', $actions, true)) {
                $orphans = array_intersect(['restore', 'forceDelete'], $actions);

                if ($orphans !== []) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => $path,
                        'message' => "{$model['name']} declara ".implode(' y ', $orphans).' sin delete: nada de la API puede producir una fila borrada. Es correcto si la borra otro proceso.',
                    ];
                }
            }

            if (in_array('export', $actions, true) && ! in_array('index', $actions, true)) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => $path,
                    'message' => "{$model['name']} declara export sin index: la exportación reutiliza los filtros del índice.",
                ];
            }

            $writable = array_intersect(['create', 'update'], $actions) !== [];

            foreach ($model['props'] as $position => $prop) {
                if (! empty($prop['form']) && ! $writable) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props/{$position}/form",
                        'message' => "'{$prop['name']}' va en el formulario, pero {$model['name']} no tiene create ni update: el campo no tiene dónde vivir.",
                    ];
                }
            }

            foreach ($model['requests'] as $position => $request) {
                $action = strtolower($request['name']);

                if (! in_array($action, $actions, true)) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/models/{$index}/requests/{$position}",
                        'message' => "Reglas para {$request['name']}, pero {$model['name']} no tiene {$action}: no se genera ese request y las reglas no se usan.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * `display` tiene que ser una columna que la API devuelva: una que no existe
     * o una secreta dejarían la ficha, las migas y la pestaña sin nombre.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function display(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            if (empty($model['display']) || $model['display'] === 'id') {
                continue;
            }

            $prop = null;

            foreach ($model['props'] as $candidate) {
                if ($candidate['name'] === $model['display']) {
                    $prop = $candidate;

                    break;
                }
            }

            if ($prop === null) {
                $findings[] = [
                    'level' => self::ERROR,
                    'path' => "/models/{$index}/display",
                    'message' => "'{$model['name']}' usa display: '{$model['display']}', que no es ninguna de sus columnas.",
                ];

                continue;
            }

            if (! empty($prop['secret'])) {
                $findings[] = [
                    'level' => self::ERROR,
                    'path' => "/models/{$index}/display",
                    'message' => "'{$model['display']}' es secret: nunca sale por la API, así que no puede nombrar a un registro en pantalla.",
                ];
            }
        }

        return $findings;
    }

    /**
     * Metas declaradas a medias, o un payload que se quiere escribir.
     *
     * Son avisos y no errores: el generador ya impone lo correcto, pero quien
     * lo escribió tiene que saber que lo que pidió no es lo que va a pasar.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, mixed>  $raw
     * @return list<Finding>
     */
    private static function metas(array $models, array $raw): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $editable = $model['editable_metas'] ?? [];
            $protected = $model['protected_metas'] ?? [];

            if (empty($model['metas'])) {
                foreach (['editable_metas' => $editable, 'protected_metas' => $protected] as $key => $list) {
                    if ($list !== []) {
                        $findings[] = [
                            'level' => self::WARNING,
                            'path' => "/models/{$index}/{$key}",
                            'message' => "'{$model['name']}' declara {$key} pero no metas: true, así que no hay tabla donde guardarlas. Añade metas: true o quita la lista.",
                        ];
                    }
                }

                continue;
            }

            foreach (array_intersect($editable, $protected) as $meta) {
                $findings[] = [
                    'level' => self::WARNING,
                    'path' => "/models/{$index}/editable_metas",
                    'message' => "'{$meta}' está en editable_metas y en protected_metas: gana protected y el formulario no la escribe. Quítala de una de las dos.",
                ];
            }

            foreach ($raw[$index]['props'] ?? [] as $position => $written) {
                if (! is_array($written) || ($written['name'] ?? null) !== 'payload') {
                    continue;
                }

                foreach (['fillable', 'creatable', 'updatable', 'exports_cols'] as $key) {
                    if (($written[$key] ?? null) === true) {
                        $findings[] = [
                            'level' => self::WARNING,
                            'path' => "/models/{$index}/props/{$position}/{$key}",
                            'message' => "'payload' lo rehace updatePayload() a partir de las metas: no se escribe desde la petición ni se exporta, así que {$key}: true se ignora.",
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Un secreto que se declara también visible.
     *
     * Se mira el documento tal como se escribió: `exports_cols` vale true por
     * omisión, así que en el normalizado todos los secretos parecerían
     * contradecirse.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, mixed>  $raw
     * @return list<Finding>
     */
    private static function secrets(array $models, array $raw): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                if (empty($prop['secret'])) {
                    continue;
                }

                $written = $raw[$index]['props'][$position] ?? [];

                foreach (['datatable' => 'la tabla', 'exports_cols' => 'la exportación'] as $key => $where) {
                    if (is_array($written) && ($written[$key] ?? null) === true) {
                        $findings[] = [
                            'level' => self::ERROR,
                            'path' => "/models/{$index}/props/{$position}/{$key}",
                            'message' => "'{$prop['name']}' es secret y a la vez pide salir en {$where}. Quita una de las dos.",
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function duplicateModels(array $models): array
    {
        $seen = [];
        $findings = [];

        foreach ($models as $index => $model) {
            $name = $model['name'];

            if (isset($seen[$name])) {
                $findings[] = [
                    'level' => self::ERROR,
                    'path' => "/models/{$index}/name",
                    'message' => "El modelo '{$name}' está declarado dos veces (también en /models/{$seen[$name]}).",
                ];
            }

            $seen[$name] = $index;
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function duplicateProps(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $seen = [];

            foreach ($model['props'] as $position => $prop) {
                if (in_array($prop['name'], $seen, true)) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props/{$position}/name",
                        'message' => "La columna '{$prop['name']}' está declarada dos veces en {$model['name']}.",
                    ];
                }

                $seen[] = $prop['name'];
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pivots
     * @return list<Finding>
     */
    private static function duplicatePivots(array $pivots): array
    {
        $seen = [];
        $findings = [];

        foreach ($pivots as $index => $pivot) {
            if (in_array($pivot['name'], $seen, true)) {
                $findings[] = [
                    'level' => self::ERROR,
                    'path' => "/pivots/{$index}/name",
                    'message' => "La tabla pivote '{$pivot['name']}' está declarada dos veces.",
                ];
            }

            $seen[] = $pivot['name'];
        }

        return $findings;
    }

    /**
     * Una clave foránea que apunta a una tabla definida en este mismo archivo
     * obliga a un orden de migración. Si además ese orden es circular, no hay
     * ninguna secuencia válida y `php artisan migrate` fallará siempre.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, array<string, mixed>>  $pivots
     * @return list<Finding>
     */
    private static function foreignKeys(array $models, array $pivots): array
    {
        $tables = [];

        $derived = [];

        foreach ($models as $model) {
            $tables[self::tableFor($model)] = $model['name'];
            $derived[self::tableOf($model['name'])] = $model;
        }

        $findings = [];
        $edges = [];
        $nullable = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                if ($prop['type'] !== 'foreignId' || ! $prop['constraint']) {
                    continue;
                }

                if (! isset($tables[$prop['constraint']])) {
                    // Apuntar fuera del archivo es normal: users, por ejemplo.
                    // Pero apuntar a la tabla DEDUCIDA de un modelo que declara
                    // otra es casi seguro una errata: esa tabla no existe.
                    $other = $derived[$prop['constraint']] ?? null;

                    if ($other !== null && isset($other['table'])) {
                        $findings[] = [
                            'level' => self::WARNING,
                            'path' => "/models/{$index}/props/{$position}/constraint",
                            'message' => "'{$prop['constraint']}' sería la tabla de {$other['name']}, pero {$other['name']} declara table '{$other['table']}': escribe ésa en constraint.",
                        ];
                    }

                    continue;
                }

                $edges[$model['name']][] = $tables[$prop['constraint']];
                $nullable[$model['name'].'>'.$tables[$prop['constraint']]] = ! empty($prop['nullable'])
                    || ! empty($nullable[$model['name'].'>'.$tables[$prop['constraint']]]);

                if ($tables[$prop['constraint']] === $model['name'] && ! $prop['nullable']) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/props/{$position}",
                        'message' => "'{$prop['name']}' apunta a la propia tabla de {$model['name']} y no es nullable: no se podrá insertar la primera fila.",
                    ];
                }
            }
        }

        foreach ($pivots as $index => $pivot) {
            foreach ($pivot['props'] as $position => $prop) {
                if ($prop['type'] !== 'foreignId' || ! $prop['constraint']) {
                    continue;
                }

                if (! isset($tables[$prop['constraint']]) && ! self::isKnownExternalTable($prop['constraint'])) {
                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/pivots/{$index}/props/{$position}/constraint",
                        'message' => "'{$prop['constraint']}' no es tabla de ningún modelo de este archivo; asegúrate de que exista en la aplicación.",
                    ];
                }
            }
        }

        return [...$findings, ...self::cycles($edges, $nullable)];
    }

    /**
     * Un ciclo de llaves: A apunta a B y B a A.
     *
     * Si alguna de sus llaves admite nulos, tiene arreglo (9.3.1): las tablas
     * se crean sin la llave que apunta a una tabla que aún no existe, y la
     * llave va en una migración aparte, después de las dos. Se avisa, no se
     * rechaza: brandai tiene dos ciclos así. Si ninguna admite nulos, no se
     * puede insertar la primera fila de ninguna de las dos tablas: eso sí es
     * un error.
     *
     * @param  array<string, array<int, string>>  $edges
     * @param  array<string, bool>  $nullable  'A>B' => si alguna llave de A hacia B admite nulos
     * @return list<Finding>
     */
    private static function cycles(array $edges, array $nullable = []): array
    {
        $findings = [];
        $state = [];

        $walk = function (string $node, array $path) use (&$walk, &$state, $edges, $nullable, &$findings): void {
            if (($state[$node] ?? null) === 'done') {
                return;
            }

            if (($state[$node] ?? null) === 'visiting') {
                // 'visiting' quiere decir que está en el camino: se marca al
                // entrar y se cambia a 'done' al salir.
                $start = array_search($node, $path, true);

                if (! is_int($start)) {
                    throw new \LogicException("{$node} está a medio visitar y no está en el camino.");
                }

                $cycle = [...array_slice($path, $start), $node];
                $breakable = false;

                for ($i = 0; $i < count($cycle) - 1; $i++) {
                    $breakable = $breakable || ! empty($nullable[$cycle[$i].'>'.$cycle[$i + 1]]);
                }

                $findings[] = $breakable ? [
                    'level' => self::WARNING,
                    'path' => '/models',
                    'message' => 'Ciclo de claves foráneas: '.implode(' -> ', $cycle).'. Se resuelve creando las tablas sin la llave que '
                        .'apunta a una tabla que aún no existe y escribiéndola en una migración aparte, después de las dos.',
                ] : [
                    'level' => self::ERROR,
                    'path' => '/models',
                    'message' => 'Ciclo de claves foráneas: '.implode(' -> ', $cycle).'. Ninguna de sus llaves admite nulos: no se podría '
                        .'insertar la primera fila de ninguna de las tablas. Haz nullable al menos una.',
                ];

                return;
            }

            $state[$node] = 'visiting';

            foreach ($edges[$node] ?? [] as $next) {
                if ($next !== $node) {
                    $walk($next, [...$path, $node]);
                }
            }

            $state[$node] = 'done';
        };

        foreach (array_keys($edges) as $node) {
            $walk($node, []);
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function relations(array $models): array
    {
        $declared = array_column($models, 'name');
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['load_relations'] as $position => $relation) {
                if (! Relations::hasRelated($relation) || in_array($relation['related'], $declared, true)) {
                    continue;
                }

                if (! empty($relation['namespace'])) {
                    continue;
                }

                $findings[] = [
                    'level' => self::WARNING,
                    'path' => "/models/{$index}/load_relations/{$position}/related",
                    'message' => "'{$relation['related']}' no está en este archivo y no declara namespace: se resolverá contra App\\Models.",
                ];
            }
        }

        return $findings;
    }

    /**
     * Una regla sobre un campo que no existe no valida nada, y suele ser un
     * nombre mal escrito.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function ruleFields(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $columns = array_column($model['props'], 'name');
            $identifier = self::snake($model['name']).'_id';

            foreach ($model['requests'] as $position => $request) {
                foreach (array_keys($request['rules'] ?? []) as $field) {
                    if (in_array($field, $columns, true) || $field === $identifier) {
                        continue;
                    }

                    $findings[] = [
                        'level' => self::WARNING,
                        'path' => "/models/{$index}/requests/{$position}/rules/{$field}",
                        'message' => "'{$field}' no es columna de {$model['name']}; la regla no valida nada.",
                    ];
                }
            }

            foreach ($model['requests'] as $position => $request) {
                // Sin la acción no se genera el request, así que no hay handle()
                // que necesite el identificador.
                if ($request['name'] !== 'Update' || ! in_array('update', Actions::resolve($model), true)) {
                    continue;
                }

                if (! isset($request['rules'][$identifier])) {
                    $findings[] = [
                        'level' => self::ERROR,
                        'path' => "/models/{$index}/requests/{$position}/rules",
                        'message' => "Update debe validar '{$identifier}': su authorize() y su handle() hacen findOrFail con ese valor.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function enums(array $models): array
    {
        $selects = ['SelectInputComponent', 'SelectSearchInputComponent', 'RadioInputComponent', 'MultiCheckboxInputComponent'];
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                if (! isset($prop['enum']) || ! $prop['form']) {
                    continue;
                }

                if (in_array($prop['form_component'] ?? null, $selects, true)) {
                    continue;
                }

                $findings[] = [
                    'level' => self::WARNING,
                    'path' => "/models/{$index}/props/{$position}/enum",
                    'message' => "'{$prop['name']}' declara enum pero su componente no lo pinta; las opciones se ignorarán.",
                ];
            }
        }

        return $findings;
    }

    /**
     * La misma regla que el generador y que TablePrefix: se lee de ahí para
     * que las tres copias que había no puedan divergir.
     */
    private static function tableOf(string $model): string
    {
        return TablePrefix::tableOf($model);
    }

    /**
     * La tabla de un modelo del documento: la que declara `table`, o la que
     * se deduce de su nombre (sin prefijo, como se escribe en `constraint`).
     *
     * @param  array<string, mixed>  $model
     */
    public static function tableFor(array $model): string
    {
        return isset($model['table']) && is_string($model['table']) && $model['table'] !== ''
            ? $model['table']
            : self::tableOf((string) $model['name']);
    }

    /**
     * Los tipos en los que tiene sentido cada medida de columna.
     */
    public const LENGTH_TYPES = ['string', 'char'];

    public const PRECISION_TYPES = ['decimal'];

    public const USE_CURRENT_TYPES = ['timestamp', 'timestampTz', 'dateTime', 'dateTimeTz'];

    /**
     * Dos modelos con la misma tabla —declarada o deducida— crearían dos veces
     * la misma tabla; una tabla declarada que coincide con un pivote, igual.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, array<string, mixed>>  $pivots
     * @return list<Finding>
     */
    private static function tables(array $models, array $pivots): array
    {
        $findings = [];
        $seen = [];

        foreach ($pivots as $pivot) {
            $seen[(string) $pivot['name']] = 'el pivote '.$pivot['name'];
        }

        foreach ($models as $index => $model) {
            $table = self::tableFor($model);

            if (isset($seen[$table])) {
                $findings[] = self::error("/models/{$index}/table", "{$model['name']} usa la tabla '{$table}', que ya es de {$seen[$table]}: se crearía dos veces.");
            }

            $seen[$table] = $model['name'];
        }

        return $findings;
    }

    /**
     * `length`, `precision`, `scale` y `use_current` sólo significan algo en
     * ciertos tipos. En otro, el generador escribiría una migración que Laravel
     * no acepta o que ignora el dato en silencio: se dice antes.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function columnShapes(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            foreach ($model['props'] as $position => $prop) {
                $at = "/models/{$index}/props/{$position}";
                $type = (string) $prop['type'];

                if (isset($prop['length']) && ! in_array($type, self::LENGTH_TYPES, true)) {
                    $findings[] = self::error("{$at}/length", "'{$prop['name']}' es {$type}: length sólo vale en ".implode(' y ', self::LENGTH_TYPES).'.');
                }

                foreach (['precision', 'scale'] as $key) {
                    if (isset($prop[$key]) && ! in_array($type, self::PRECISION_TYPES, true)) {
                        $findings[] = self::error("{$at}/{$key}", "'{$prop['name']}' es {$type}: {$key} sólo vale en decimal.");
                    }
                }

                if (isset($prop['scale']) && ! isset($prop['precision'])) {
                    $findings[] = self::error("{$at}/scale", "'{$prop['name']}' declara scale sin precision: Laravel los recibe en ese orden, decimal('{$prop['name']}', precision, scale).");
                }

                if (isset($prop['scale'], $prop['precision']) && $prop['scale'] > $prop['precision']) {
                    $findings[] = self::error("{$at}/scale", "'{$prop['name']}': scale ({$prop['scale']}) no puede pasar de precision ({$prop['precision']}); la base rechazaría la columna.");
                }

                if (! empty($prop['use_current'])) {
                    if (! in_array($type, self::USE_CURRENT_TYPES, true)) {
                        $findings[] = self::error("{$at}/use_current", "'{$prop['name']}' es {$type}: use_current sólo vale en ".implode(', ', self::USE_CURRENT_TYPES).'.');
                    }

                    if (($prop['default'] ?? null) !== null) {
                        $findings[] = self::error("{$at}/use_current", "'{$prop['name']}' declara use_current y default: son dos valores por omisión para la misma columna. Quita uno.");
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Un índice sólo puede nombrar columnas que existan, y dos índices con las
     * mismas columnas (o el mismo nombre) chocarían en la base.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return list<Finding>
     */
    private static function indexes(array $models): array
    {
        $findings = [];

        foreach ($models as $index => $model) {
            $columns = [...array_column($model['props'], 'name'), 'id', 'created_at', 'updated_at', 'deleted_at'];

            foreach ($model['props'] as $prop) {
                if (in_array($prop['type'], ['morphs', 'nullableMorphs'], true)) {
                    $columns[] = $prop['name'].'_type';
                    $columns[] = $prop['name'].'_id';
                }
            }

            $byColumns = [];
            $byName = [];

            foreach ((array) ($model['indexes'] ?? []) as $position => $declared) {
                $at = "/models/{$index}/indexes/{$position}";
                $names = array_values(array_map('strval', (array) ($declared['columns'] ?? [])));

                foreach ($names as $column) {
                    if (! in_array($column, $columns, true)) {
                        $findings[] = self::error("{$at}/columns", "El índice nombra '{$column}', que no es columna de {$model['name']}.");
                    }
                }

                $key = (! empty($declared['unique']) ? 'unique:' : 'index:').implode(',', $names);

                if (isset($byColumns[$key])) {
                    $findings[] = self::error($at, "{$model['name']} declara dos veces el mismo índice sobre ".implode(', ', $names).'.');
                }

                $byColumns[$key] = true;

                if (isset($declared['name'])) {
                    if (isset($byName[$declared['name']])) {
                        $findings[] = self::error("{$at}/name", "{$model['name']} declara dos índices llamados '{$declared['name']}'.");
                    }

                    $byName[$declared['name']] = true;
                }
            }
        }

        return $findings;
    }

    private static function snake(string $value): string
    {
        return TablePrefix::singularOf($value);
    }

    private static function isKnownExternalTable(string $table): bool
    {
        return in_array($table, ['users', 'roles', 'permissions', 'teams'], true);
    }
}
