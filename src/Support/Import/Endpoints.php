<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

/**
 * Los endpoints declarados que no son una de las doce acciones CRUD.
 *
 * Es el único sitio que decide cómo se llama cada pieza que sale de uno —el
 * método del controlador, la request, la ruta, la habilidad, la operación—,
 * para que el generador y `verify` no puedan discrepar sobre un nombre.
 *
 * Existe porque lo que el laraimport no sabía declarar se escribía a mano, y
 * lo escrito a mano es justo donde se perdía el patrón de que el controlador
 * sólo llama a `$request->handle()`.
 */
final class Endpoints
{
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public const TARGETS = ['record', 'collection'];

    /**
     * Las condiciones de los stubs de endpoint. No son del modelo sino del
     * endpoint que se está generando, así que no las resuelve Declaration.
     */
    public const FLAGS = ['endpoint_record', 'endpoint_id_in_body', 'endpoint_public', 'endpoint_model', 'endpoint_gate', 'endpoint_authenticated'];

    /**
     * Las habilidades que la política CRUD ya trae, la acción que las genera y
     * si reciben un registro. Un endpoint que reutiliza una no genera método:
     * pregunta al que hay, así que la acción tiene que existir y el registro
     * tiene que casar con su firma.
     */
    public const CRUD_ABILITIES = [
        'index' => ['index', 'collection'],
        'viewAny' => ['index', 'collection'],
        'view' => ['show', 'record'],
        'create' => ['create', 'collection'],
        'update' => ['update', 'record'],
        'delete' => ['delete', 'record'],
        'restore' => ['restore', 'record'],
        'forceDelete' => ['forceDelete', 'record'],
        'export' => ['export', 'collection'],
    ];

    /**
     * Lo que no puede ser el nombre de un endpoint aunque sea camelCase.
     *
     * Los métodos que el controlador ya tiene —por HasMiddleware o por los
     * traits del Controller base— se pisarían en silencio. `form` daría una
     * clase `FormRequest` que se extiende a sí misma. `before` es el método de
     * la política que decide por el administrador.
     */
    public const RESERVED = [
        'middleware', 'getMiddleware', 'callAction', 'authorize', 'authorizeForUser',
        'authorizeResource', 'validate', 'validateWith', 'validateWithBag', 'form',
        'before', 'handle',
    ];

    /**
     * Las palabras reservadas de PHP. Unas no valen como nombre de método,
     * otras sí; explicar cuáles cuesta más que elegir otro nombre, y un
     * endpoint `new` o `default` no dice qué hace.
     */
    public const PHP_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval',
        'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset',
        'list', 'match', 'namespace', 'new', 'or', 'parent', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'return', 'self', 'static', 'switch', 'throw', 'trait',
        'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'mixed', 'never', 'void', 'null',
        'true', 'false', 'bool', 'int', 'float', 'string', 'object', 'iterable',
    ];

    /**
     * La URI de las acciones CRUD. Un endpoint con la misma URI quedaría
     * detrás de la CRUD, que se registra antes.
     */
    public static function crudUris(): array
    {
        return array_map(fn (string $action): string => self::kebab($action), Actions::ALL);
    }

    /**
     * Completa lo que el laraimport deja por omisión. Lo que sale de aquí es lo
     * que leen las herramientas y lo que se guarda en el manifiesto.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  bool  $model  Si cuelga de un modelo (o de un `controllers[]`).
     * @return array{name: string, method: string, uri: string, route: string, request: string, target: string|null, ability: string|null, operation: string|null, public: bool, middleware: array<int, string>, authorize?: string}
     */
    public static function normalise(array $endpoint, bool $model): array
    {
        $name = (string) $endpoint['name'];

        $normalised = [
            'name' => $name,
            'method' => (string) $endpoint['method'],
            'uri' => self::uri($endpoint),
            'route' => self::routeName($name),
            'request' => self::requestClass($name),
            'target' => $model ? (string) ($endpoint['target'] ?? 'record') : null,
            'ability' => $model ? (string) ($endpoint['ability'] ?? $name) : (isset($endpoint['ability']) ? (string) $endpoint['ability'] : null),
            'operation' => $model ? self::operation($name) : null,
            'public' => (bool) ($endpoint['public'] ?? false),
            'middleware' => array_values($endpoint['middleware'] ?? []),
        ];

        // Sólo un `controllers[]` lo admite (el esquema lo rechaza en un
        // modelo, que pregunta siempre a su política), y sólo se anota si se
        // declaró: el manifiesto de un endpoint que no lo usa sale como antes.
        if (! $model && ($endpoint['authorize'] ?? null) === 'authenticated') {
            $normalised['authorize'] = 'authenticated';
        }

        return $normalised;
    }

    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<int, array<string, mixed>>
     */
    public static function normaliseAll(array $endpoints, bool $model): array
    {
        return array_map(fn (array $endpoint): array => self::normalise($endpoint, $model), $endpoints);
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    public static function uri(array $endpoint): string
    {
        return isset($endpoint['uri']) && $endpoint['uri'] !== ''
            ? (string) $endpoint['uri']
            : self::kebab((string) $endpoint['name']);
    }

    /**
     * Como `forceDelete` → `force.delete`: el front compone el nombre con
     * puntos, y así un endpoint se nombra igual que una acción CRUD.
     */
    public static function routeName(string $name): string
    {
        return str_replace('-', '.', self::kebab($name));
    }

    public static function requestClass(string $name): string
    {
        return ucfirst($name).'Request';
    }

    /**
     * La operación del modelo en la que delega la request. Lleva `Model` como
     * createModel o updateModel: con el nombre pelado, un endpoint `refresh` o
     * `touch` sobrescribiría un método de Eloquent.
     */
    public static function operation(string $name): string
    {
        return $name.'Model';
    }

    /**
     * La función del contrato JS que llama al endpoint. La de un modelo se
     * llama como su operación (`publishModel`), igual que `createModel` o
     * `deleteModel`; la de un `controllers[]`, `<name>Endpoint`. Con el nombre
     * pelado, un endpoint `import` o `typeof` sería una palabra reservada de
     * JavaScript, que la validación no rechaza porque en PHP no lo es.
     *
     * @param  array<string, mixed>  $endpoint  Normalizado.
     */
    public static function jsFunction(array $endpoint): string
    {
        return isset($endpoint['operation'])
            ? (string) $endpoint['operation']
            : $endpoint['name'].'Endpoint';
    }

    /**
     * Si la interfaz lo ofrece como acción: un botón en la barra de la tabla
     * (`collection`) o una entrada en el menú de la fila y de la ficha
     * (`record`).
     *
     * Sólo lo que cambia algo y de un modelo: un `get` devuelve datos que
     * alguien tiene que pintar, y lanzarlo desde un menú para tirar la
     * respuesta no sirve de nada. Y sólo si la interfaz sabe rellenar la URI:
     * el registro lo pone la fila, pero cualquier otro parámetro no tiene de
     * dónde salir. La validación ya exige que un `record` con parámetros lleve
     * `{<modelo>_id}`, así que basta contarlos.
     *
     * @param  array<string, mixed>  $endpoint  Normalizado.
     */
    public static function offersInterface(array $endpoint): bool
    {
        $target = $endpoint['target'] ?? null;

        if ($target === null || $endpoint['method'] === 'get') {
            return false;
        }

        return count(self::parameters((string) $endpoint['uri'])) <= ($target === 'record' ? 1 : 0);
    }

    /**
     * El texto del botón, como clave de traducción: `importRoster` →
     * `Import roster`. Es la misma forma que las etiquetas de los campos.
     */
    public static function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', self::kebab($name)));
    }

    public static function isCrudAbility(string $ability): bool
    {
        return array_key_exists($ability, self::CRUD_ABILITIES);
    }

    /**
     * Los parámetros de una URI, sin llaves ni interrogación.
     *
     * @return array<int, string>
     */
    public static function parameters(string $uri): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\??\}/', $uri, $matches);

        return $matches[1];
    }

    /**
     * `importRoster` → `import-roster`. Una sigla es una palabra, no una letra
     * por mayúscula: `importCSV` → `import-csv` y `exportCSVFile` →
     * `export-csv-file`, no `import-c-s-v`.
     */
    public static function kebab(string $value): string
    {
        return mb_strtolower((string) preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1-$2', $value));
    }

    /**
     * La URI como patrón, para saber si una ruta registrada antes se queda
     * con las peticiones de otra. Un parámetro casa con un segmento, como en
     * Laravel; el del registro lleva whereNumber() y sólo casa con números.
     */
    public static function pattern(string $uri, ?string $numeric = null): string
    {
        $regex = '';

        foreach (explode('/', $uri) as $position => $segment) {
            $slash = $position === 0 ? '' : '/';

            if (preg_match('/^\{([a-z][a-z0-9_]*)(\??)\}$/', $segment, $parameter)) {
                $value = $parameter[1] === $numeric ? '[0-9]+' : '[^/]+';
                $regex .= $parameter[2] === '?' ? "(?:{$slash}{$value})?" : $slash.$value;

                continue;
            }

            $regex .= $slash.preg_quote($segment, '#');
        }

        return '#^'.$regex.'$#';
    }
}
