<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Infer;

use Illuminate\Support\Str;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\Import\Endpoints;
use Innoboxrr\LarapackGenerator\Support\PhpOutline;
use Innoboxrr\LarapackGenerator\Support\ProjectSources;

/**
 * Las rutas escritas a mano de un ámbito, leídas sin cargar nada, y cuáles se
 * pueden declarar en el laraimport CONSERVANDO su nombre y su URI.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ SÓLO SE DECLARA LO QUE SE CONSERVA
 *
 * El front llama a las rutas por nombre (`route('api.app.scribe.dictate')`)
 * o por URL escrita a mano. Un endpoint declarado vive en el archivo
 * `routes/api/models/<x>.php` de LaraPack, que se carga con el prefijo
 * `api/<namespace>/<x>` y el nombre `api.<namespace>.<x>.`. Una ruta sólo
 * se puede adoptar sin romper el front si su nombre completo y su URI
 * empiezan así: entonces `<x>` es el controlador (o el modelo), y lo que
 * queda es su `route_name` y su `uri`. Una ruta que no cabe va a
 * `decisions` y NO se escribe en el borrador.
 * ────────────────────────────────────────────────────────────────────────
 *
 * El prefijo de cada archivo de rutas se lee de donde se carga: los
 * proveedores (`Route::prefix('api/app')->as('api.app.')->group(...'routes/
 * api/clinic.php')`), `bootstrap/app.php` (`api: …routes/api.php` lleva
 * `api`) o la convención de LaraPack para `routes/api/models/`. Un archivo
 * cuyo prefijo no se encuentra no se adopta: adivinarlo rompería el front.
 *
 * @phpstan-import-type Token from PhpOutline
 *
 * @phpstan-type RouteInfo array{file: string, line: int, verb: string, uri: string, name: string|null, controller: string|null, method: string|null, middleware: list<string>, unreadable: string|null}
 */
final class RouteSource
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    private const IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML];

    /**
     * Los middleware que ya pone LaraPack o que deciden `public`: no se
     * copian a `middleware`.
     */
    private const IMPLIED_MIDDLEWARE = ['api', 'web', 'auth:sanctum', 'auth'];

    private ?PhpOutline $outline = null;

    public function __construct(private readonly string $directory) {}

    /**
     * Las rutas de todos los archivos de `routes/` con su prefijo completo.
     *
     * @return array{routes: list<RouteInfo>, unknown: list<string>}
     */
    public function routes(): array
    {
        $prefixes = $this->filePrefixes();
        $routes = [];
        $unknown = [];

        foreach ($this->routeFiles() as $relative) {
            if (! isset($prefixes[$relative])) {
                $unknown[] = $relative;

                continue;
            }

            [$prefix, $as] = $prefixes[$relative];
            $this->outline = PhpOutline::ofFile($this->directory.'/'.$relative);
            $tokens = self::tokens(Files::read($this->directory.'/'.$relative));
            $this->walk($tokens, 0, count($tokens), ['prefix' => $prefix, 'as' => $as, 'middleware' => [], 'controller' => null], $relative, $routes);
        }

        return ['routes' => $routes, 'unknown' => $unknown];
    }

    /**
     * Añade al resultado del inferidor los endpoints que se pueden conservar
     * y las decisiones de los que no.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $models  Los modelos del borrador.
     */
    public function inferInto(array &$result, array $models): void
    {
        ['routes' => $routes, 'unknown' => $unknown] = $this->routes();
        [$dotNs, $slashNs] = $this->namespaces();
        $base = 'api.'.$dotNs;
        $slashBase = 'api/'.$slashNs;
        $modelBySnake = [];

        foreach ($models as $model) {
            $modelBySnake[Str::snake($model)] = $model;
        }

        foreach ($unknown as $file) {
            $result['decisions'][] = ['type' => 'route-file-prefix', 'subject' => $file, 'file' => $file,
                'why' => "No se encontró con qué prefijo y nombre se carga {$file}: sin eso no se sabe qué nombre completo tiene cada ruta y no se adopta ninguna.",
                'options' => [['key' => 'declare', 'label' => 'Cargarlo desde un proveedor con ->prefix()->as()->group() y volver a inferir'], ['key' => 'leave', 'label' => 'Dejar sus rutas como código propio']]];
        }

        $owners = [];

        foreach ($routes as $route) {
            $where = "{$route['file']}:{$route['line']}";

            if ($route['unreadable'] !== null) {
                $result['lost'][] = ['model' => '(rutas)', 'file' => $where, 'what' => $route['unreadable']];

                continue;
            }

            // Lo que LaraPack ya genera para un modelo no es código a mano.
            if ($route['controller'] !== null && in_array($route['method'], Actions::ALL, true) && isset($modelBySnake[Str::snake(substr(self::short($route['controller']), 0, -10))])
                && str_starts_with($route['file'], 'routes/api/models/')) {
                continue;
            }

            $owner = $this->owner($route, $base, $slashBase);

            if ($owner === null) {
                $result['decisions'][] = ['type' => 'route-not-preservable', 'subject' => ($route['name'] ?? strtoupper($route['verb']).' '.$route['uri']), 'file' => $where,
                    'why' => 'La ruta '.strtoupper($route['verb']).' '.$route['uri'].($route['name'] !== null ? " ({$route['name']})" : '')." no empieza por {$slashBase}<x>/ y {$base}<x>.: declarada en el laraimport cambiaría de URI o de nombre, y el front que la llama dejaría de encontrarla.",
                    'options' => [['key' => 'keep', 'label' => 'Dejarla como código propio (controller-shape la sigue vigilando)'], ['key' => 'move', 'label' => 'Cambiar la ruta y el front a la vez, a propósito, y volver a inferir']]];

                continue;
            }

            [$segment, $uri, $routeName] = $owner;
            $isModel = isset($modelBySnake[$segment]);
            $ownerName = $isModel ? $modelBySnake[$segment] : Str::studly($segment);
            $owners[$ownerName]['model'] = $isModel;
            $owners[$ownerName]['endpoints'] ??= [];
            $endpoint = $this->endpoint($route, $uri, $routeName, $ownerName, $isModel, $owners[$ownerName]['endpoints'], $result, $where);

            if ($endpoint !== null) {
                $owners[$ownerName]['endpoints'][] = $endpoint;
            }
        }

        foreach ($owners as $ownerName => $owner) {
            if ($owner['endpoints'] === []) {
                continue;
            }

            if ($owner['model']) {
                foreach ($result['contract']['models'] as $index => $model) {
                    if ($model['name'] === $ownerName) {
                        $result['contract']['models'][$index]['endpoints'] = $owner['endpoints'];
                    }
                }
            } else {
                $result['contract']['controllers'][] = ['name' => $ownerName, 'endpoints' => $owner['endpoints']];
                $result['stats']['controllers']++;
            }

            $result['stats']['endpoints'] += count($owner['endpoints']);
        }
    }

    /**
     * El segmento `<x>`, la URI y el nombre relativos, o null si no cabe.
     *
     * @param  RouteInfo  $route
     * @return array{0: string, 1: string, 2: string|null}|null
     */
    private function owner(array $route, string $base, string $slashBase): ?array
    {
        $uri = trim($route['uri'], '/');

        if (! str_starts_with($uri, $slashBase)) {
            return null;
        }

        $restUri = substr($uri, strlen($slashBase));
        $segment = explode('/', $restUri, 2)[0];
        $uriInside = explode('/', $restUri, 2)[1] ?? '';

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $segment) || $uriInside === '') {
            return null;
        }

        $routeName = null;

        if ($route['name'] !== null) {
            if (! str_starts_with($route['name'], $base.$segment.'.')) {
                return null;
            }

            $routeName = substr($route['name'], strlen($base.$segment.'.'));

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:[.:-][A-Za-z0-9_]+)*$/', $routeName)) {
                return null;
            }
        }

        if (! preg_match('#^(?:[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_-])?|\{[A-Za-z_][A-Za-z0-9_]*\??\})(?:/(?:[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_-])?|\{[A-Za-z_][A-Za-z0-9_]*\??\}))*$#', $uriInside)) {
            return null;
        }

        return [$segment, $uriInside, $routeName];
    }

    /**
     * @param  RouteInfo  $route
     * @param  list<array<string, mixed>>  $siblings
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function endpoint(array $route, string $uri, ?string $routeName, string $owner, bool $isModel, array $siblings, array &$result, string $where): ?array
    {
        foreach ($siblings as $sibling) {
            if ($sibling['method'] === $route['verb'] && $sibling['uri'] === $uri) {
                $result['lost'][] = ['model' => $owner, 'file' => $where, 'what' => strtoupper($route['verb'])." {$uri} repetida: se conserva la primera"];

                return null;
            }
        }

        $controller = $route['controller'] !== null ? self::short($route['controller']) : $owner;
        $base = lcfirst((string) preg_replace('/Controller$/', '', $controller));
        $method = $route['method'] ?? '__invoke';
        $name = $method === '__invoke' ? $base : $method;
        $taken = array_column($siblings, 'name');

        if (! preg_match('/^[a-z][A-Za-z0-9]*$/', $name) || in_array($name, Actions::ALL, true) || in_array($name, Endpoints::RESERVED, true)
            || in_array($name, Endpoints::PHP_KEYWORDS, true) || in_array($name, $taken, true)) {
            $name = $base.Str::studly($method === '__invoke' ? 'invoke' : $method);
        }

        $suffix = 2;
        $candidate = $name;

        while (in_array($candidate, $taken, true) || ! preg_match('/^[a-z][A-Za-z0-9]*$/', $candidate)) {
            $candidate = preg_match('/^[a-z][A-Za-z0-9]*$/', $name) ? $name.$suffix++ : 'endpoint'.$suffix++;
        }

        $endpoint = ['name' => $candidate, 'method' => $route['verb'], 'uri' => $uri];

        if ($routeName !== null) {
            $endpoint['route_name'] = $routeName;
        }

        $authenticated = in_array('auth:sanctum', $route['middleware'], true) || in_array('auth', $route['middleware'], true);
        $middleware = array_values(array_filter($route['middleware'], fn (string $entry): bool => ! in_array($entry, self::IMPLIED_MIDDLEWARE, true)
            && preg_match('/^[A-Za-z][A-Za-z0-9_.\-]*(?::[A-Za-z0-9_.,|\-]+)?$/', $entry) === 1));

        foreach (array_diff($route['middleware'], $middleware, self::IMPLIED_MIDDLEWARE) as $lost) {
            $result['lost'][] = ['model' => $owner, 'file' => $where, 'what' => "el middleware {$lost} de {$candidate}: no cabe en el laraimport"];
        }

        if ($isModel) {
            $endpoint['target'] = in_array(Str::snake($owner).'_id', Endpoints::parameters($uri), true) ? 'record' : 'collection';
            $result['not_inferred'][] = ['model' => $owner, 'key' => "endpoints.{$candidate}.ability", 'reason' => "la habilidad de la política: por omisión '{$candidate}', que nace denegando. Decide cuál pregunta, o se cerrará la ruta"];
        }

        if (! $authenticated) {
            $endpoint['public'] = true;
            $result['not_inferred'][] = ['model' => $owner, 'key' => "endpoints.{$candidate}", 'reason' => 'la ruta no exige sesión: se declaró public, y su autorización nace cerrada hasta que se decida (ability o el handle() de su request)'];
        } elseif (! $isModel) {
            $endpoint['authorize'] = 'authenticated';
        }

        if ($middleware !== []) {
            $endpoint['middleware'] = $middleware;
        }

        $result['provenance'][] = ['model' => $owner, 'key' => 'endpoint:'.$candidate, 'source' => $where.' → '.($route['controller'] ?? '?').'@'.$method, 'confidence' => 'high'];

        return $endpoint;
    }

    /**
     * El namespace de la aplicación como lo pone LaraPack en las rutas:
     * `app.` y `app/` para `App\`.
     *
     * @return array{0: string, 1: string}
     */
    private function namespaces(): array
    {
        $psr4 = ProjectSources::composer($this->directory)['autoload']['psr-4'] ?? [];
        $namespace = 'App\\';

        foreach (is_array($psr4) ? $psr4 : [] as $key => $paths) {
            foreach ((array) $paths as $path) {
                if (in_array(trim(str_replace('\\', '/', (string) $path), '/'), ['app', 'src'], true)) {
                    $namespace = (string) $key;

                    break 2;
                }
            }
        }

        $lower = strtolower($namespace);

        return [str_replace('\\', '.', $lower), str_replace('\\', '/', $lower)];
    }

    /**
     * El prefijo y el nombre con que se carga cada archivo de rutas.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function filePrefixes(): array
    {
        [$dotNs, $slashNs] = $this->namespaces();
        $prefixes = [];

        foreach ($this->routeFiles() as $relative) {
            if (preg_match('#^routes/api/models/([^/]+)\.php$#', $relative, $m)) {
                $prefixes[$relative] = ['api/'.$slashNs.$m[1], 'api.'.$dotNs.$m[1].'.'];
            }
        }

        $bootstrap = $this->directory.'/bootstrap/app.php';

        if (is_file($bootstrap)) {
            $code = Files::read($bootstrap);

            if (preg_match("#\bapi\s*:\s*[^,)]*['\"][^'\"]*routes/api\.php['\"]#", $code)) {
                $prefixes['routes/api.php'] = ['api', ''];
            }

            if (preg_match("#\bweb\s*:\s*[^,)]*['\"][^'\"]*routes/web\.php['\"]#", $code)) {
                $prefixes['routes/web.php'] = ['', ''];
            }
        }

        $providers = [
            ...(glob($this->directory.'/app/Providers/*.php') ?: []),
            ...(glob($this->directory.'/src/Providers/*.php') ?: []),
            ...(glob($this->directory.'/src/*.php') ?: []),
            ...(is_file($bootstrap) ? [$bootstrap] : []),
        ];

        foreach ($providers as $provider) {
            $tokens = self::tokens(Files::read($provider));
            $count = count($tokens);
            $variables = self::assignments($tokens);

            for ($i = 0; $i < $count; $i++) {
                if (! PhpOutline::is($tokens[$i], T_STRING) || $tokens[$i][1] !== 'Route' || ! PhpOutline::is($tokens[$i + 1] ?? null, T_DOUBLE_COLON)) {
                    continue;
                }

                [$calls, $end] = self::chain($tokens, $i + 2);
                $i = $end;
                $prefix = '';
                $as = '';
                $file = null;

                foreach ($calls as [$call, $args]) {
                    $string = self::firstString($args);

                    if ($call === 'prefix' && $string !== null) {
                        $prefix = trim($string, '/');
                    } elseif (in_array($call, ['as', 'name'], true) && $string !== null) {
                        $as = $string;
                    } elseif ($call === 'group') {
                        $path = self::pathOf($args, $variables);

                        if ($path !== null && preg_match('#(routes/[A-Za-z0-9_./-]+\.php)$#', $path, $m)) {
                            $file = $m[1];
                        }
                    }
                }

                if ($file !== null && ! str_contains($file, '*')) {
                    $prefixes[$file] = [$prefix, $as];
                }
            }
        }

        return $prefixes;
    }

    /**
     * Lo que vale cada `$variable = …;` de un archivo, juntando sus cadenas
     * literales y las variables que ya se conocen: `$dir = __DIR__.'/../routes';
     * $public = $dir.'/public.php';` da `/../routes/public.php`.
     *
     * @param  list<Token>  $tokens
     * @return array<string, string>
     */
    private static function assignments(array $tokens): array
    {
        $values = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! PhpOutline::is($tokens[$i], T_VARIABLE) || ! PhpOutline::is($tokens[$i + 1] ?? null, '=')) {
                continue;
            }

            $expression = [];

            for ($j = $i + 2; $j < $count && ! PhpOutline::is($tokens[$j], ';'); $j++) {
                $expression[] = $tokens[$j];
            }

            $value = self::pathOf($expression, $values);

            if ($value !== null) {
                $values[(string) $tokens[$i][1]] = $value;
            }
        }

        return $values;
    }

    /**
     * Las cadenas y variables conocidas de una expresión, unidas; null si no
     * hay ninguna cadena.
     *
     * @param  list<Token>  $tokens
     * @param  array<string, string>  $variables
     */
    private static function pathOf(array $tokens, array $variables): ?string
    {
        $value = '';
        $any = false;

        foreach ($tokens as $token) {
            if (PhpOutline::is($token, T_CONSTANT_ENCAPSED_STRING)) {
                $value .= self::unquote((string) $token[1]);
                $any = true;
            } elseif (PhpOutline::is($token, T_VARIABLE) && isset($variables[$token[1]])) {
                $value .= $variables[$token[1]];
                $any = true;
            }
        }

        return $any ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function routeFiles(): array
    {
        if (! is_dir($this->directory.'/routes')) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory.'/routes', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', (string) $file);

            if (str_ends_with($path, '.php') && ! str_ends_with($path, '/console.php') && ! str_ends_with($path, '/channels.php')) {
                $files[] = substr($path, strlen($this->directory) + 1);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<Token>  $tokens
     * @param  array{prefix: string, as: string, middleware: list<string>, controller: string|null}  $context
     * @param  list<RouteInfo>  $routes
     */
    private function walk(array $tokens, int $from, int $to, array $context, string $file, array &$routes): void
    {
        for ($i = $from; $i < $to; $i++) {
            if (! PhpOutline::is($tokens[$i], T_STRING) || $tokens[$i][1] !== 'Route' || ! PhpOutline::is($tokens[$i + 1] ?? null, T_DOUBLE_COLON)) {
                continue;
            }

            [$calls, $end] = self::chain($tokens, $i + 2);
            $line = (int) $tokens[$i][2];
            $i = $end;
            $inner = $context;
            $group = null;
            $verb = null;
            $route = ['uri' => null, 'action' => [], 'name' => null, 'middleware' => []];

            foreach ($calls as $position => [$call, $args]) {
                $string = self::firstString($args);

                switch ($call) {
                    case 'prefix':
                        $inner['prefix'] = self::join($inner['prefix'], (string) $string);
                        break;
                    case 'as':
                    case 'name':
                        if ($verb === null) {
                            $inner['as'] .= (string) $string;
                        } else {
                            $route['name'] = $string;
                        }
                        break;
                    case 'middleware':
                        if ($verb === null) {
                            $inner['middleware'] = [...$inner['middleware'], ...self::strings($args)];
                        } else {
                            $route['middleware'] = [...$route['middleware'], ...self::strings($args)];
                        }
                        break;
                    case 'controller':
                        $class = self::classConstant($args);
                        $inner['controller'] = $class !== null ? $this->resolve($class) : null;
                        break;
                    case 'group':
                        $group = $args;
                        break;
                    default:
                        if ($position === 0 && in_array($call, self::VERBS, true)) {
                            $verb = $call;
                            $parts = PhpOutline::splitTopLevel($args);
                            $route['uri'] = self::firstString($parts[0] ?? []);
                            $route['action'] = $parts[1] ?? [];
                        } elseif ($position === 0 && in_array($call, ['match', 'any', 'resource', 'apiResource', 'resources', 'apiResources'], true)) {
                            $routes[] = $this->unreadable($file, $line, "Route::{$call}(): el laraimport declara un verbo por endpoint");
                        }
                }
            }

            if ($group !== null) {
                $open = null;

                foreach ($group as $index => $token) {
                    if (PhpOutline::is($token, '{')) {
                        $open = $index;

                        break;
                    }
                }

                if ($open !== null) {
                    $this->walk($group, $open + 1, count($group) - 1, $inner, $file, $routes);
                }

                continue;
            }

            if ($verb === null || $route['uri'] === null) {
                continue;
            }

            [$controller, $method, $why] = $this->action($route['action'], $inner['controller']);

            $routes[] = [
                'file' => $file,
                'line' => $line,
                'verb' => $verb,
                'uri' => self::join($inner['prefix'], $route['uri']),
                'name' => $route['name'] !== null ? $inner['as'].$route['name'] : null,
                'controller' => $controller,
                'method' => $method,
                'middleware' => array_values(array_unique([...$inner['middleware'], ...$route['middleware']])),
                'unreadable' => $why,
            ];
        }
    }

    /**
     * @param  list<Token>  $action
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function action(array $action, ?string $groupController): array
    {
        $string = self::firstString($action);

        if ($string !== null && count($action) === 1) {
            if (str_contains($string, '@')) {
                [$class, $method] = explode('@', $string, 2);

                return [$this->resolve($class), $method, null];
            }

            return $groupController !== null ? [$groupController, $string, null] : [null, null, "la acción '{$string}' sin controlador"];
        }

        if (PhpOutline::is($action[0] ?? null, '[')) {
            $parts = PhpOutline::splitTopLevel(array_slice($action, 1, -1));
            $class = self::classConstant($parts[0] ?? []);
            $method = self::firstString($parts[1] ?? []);

            if ($class !== null && $method !== null) {
                return [$this->resolve($class), $method, null];
            }
        }

        $class = self::classConstant($action);

        if ($class !== null) {
            return [$this->resolve($class), '__invoke', null];
        }

        return [null, null, 'una ruta con closure o con una acción que no se lee (route-closure)'];
    }

    /**
     * @return RouteInfo
     */
    private function unreadable(string $file, int $line, string $why): array
    {
        return ['file' => $file, 'line' => $line, 'verb' => '', 'uri' => '', 'name' => null, 'controller' => null, 'method' => null, 'middleware' => [], 'unreadable' => $why];
    }

    private function resolve(string $class): string
    {
        return ltrim($this->outline !== null ? $this->outline->resolveName($class) : $class, '\\');
    }

    /**
     * Las llamadas encadenadas desde `$i` (tras `Route::`) hasta el `;`:
     * [[nombre, tokens de sus argumentos], …] y dónde acaba.
     *
     * @param  list<Token>  $tokens
     * @return array{0: list<array{0: string, 1: list<Token>}>, 1: int}
     */
    private static function chain(array $tokens, int $i): array
    {
        $calls = [];
        $count = count($tokens);

        while ($i < $count && PhpOutline::is($tokens[$i], T_STRING) && PhpOutline::is($tokens[$i + 1] ?? null, '(')) {
            $name = (string) $tokens[$i][1];
            $depth = 0;
            $args = [];

            for ($j = $i + 1; $j < $count; $j++) {
                if (PhpOutline::is($tokens[$j], '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) {
                    $depth++;

                    if ($depth === 1) {
                        continue;
                    }
                } elseif (PhpOutline::is($tokens[$j], ')', ']', '}')) {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }

                $args[] = $tokens[$j];
            }

            $calls[] = [$name, $args];
            $i = $j + 1;

            if (! PhpOutline::is($tokens[$i] ?? null, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR)) {
                break;
            }

            $i++;
        }

        return [$calls, $i];
    }

    /**
     * @param  list<Token>  $tokens
     */
    private static function firstString(array $tokens): ?string
    {
        $first = PhpOutline::splitTopLevel($tokens)[0] ?? [];

        if (count($first) === 1 && PhpOutline::is($first[0], T_CONSTANT_ENCAPSED_STRING)) {
            return self::unquote((string) $first[0][1]);
        }

        return null;
    }

    /**
     * Las cadenas literales de un argumento: `'a'` o `['a', 'b']`.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private static function strings(array $tokens): array
    {
        $strings = [];

        foreach ($tokens as $token) {
            if (PhpOutline::is($token, T_CONSTANT_ENCAPSED_STRING)) {
                $strings[] = self::unquote((string) $token[1]);
            } elseif (PhpOutline::is($token, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED) && strtolower((string) $token[1]) !== 'class') {
                $strings[] = '\\'.$token[1];
            }
        }

        return $strings;
    }

    /**
     * `X::class` como único contenido de los tokens.
     *
     * @param  list<Token>  $tokens
     */
    private static function classConstant(array $tokens): ?string
    {

        if (count($tokens) === 3 && PhpOutline::is($tokens[0], T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED)
            && PhpOutline::is($tokens[1], T_DOUBLE_COLON) && strtolower((string) $tokens[2][1]) === 'class') {
            return (string) $tokens[0][1];
        }

        return null;
    }

    private static function join(string $prefix, string $uri): string
    {
        return trim(trim($prefix, '/').'/'.trim($uri, '/'), '/');
    }

    private static function short(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * @return list<Token>
     */
    private static function tokens(string $code): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");

                if (! in_array($token[0], self::IGNORED, true)) {
                    $tokens[] = [$token[0], $token[1], $token[2]];
                }

                continue;
            }

            $tokens[] = [$token, $token, $line];
        }

        return $tokens;
    }

    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
    }
}
