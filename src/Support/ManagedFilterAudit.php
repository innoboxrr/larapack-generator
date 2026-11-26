<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Los ManagedFilter cuyo canView() no deja pasar al administrador: con
 * `managed` forzado, el listado le saldría vacío.
 *
 * `larapack:fix index-managed` fuerza `managed` en cada IndexRequest, y desde
 * entonces canView() acota a todos, también a quien administra: el before()
 * de la política no lo salta, sólo afecta a can(). Si canView() no tiene una
 * rama para el alcance sin restricción, el administrador deja de ver el
 * listado. Aquí no se arregla nada: se dice dónde mirar.
 *
 * Cómo se encuentra el ManagedFilter de un IndexRequest, sin cargar nada: la
 * llamada al Builder de SearchSurge (`->get(Post::class, ...)` y las demás de
 * BUILDER_METHODS) da el modelo, y el filtro es el primero que exista de
 *
 *  1. `<filtersNamespace>\<Modelo>\ManagedFilter`, si la llamada pasa
 *     `'filtersNamespace' => '...'` literal en sus opciones;
 *  2. `<espacio del modelo>\Filters\<Modelo>\ManagedFilter`, la convención
 *     del registro de SearchSurge y lo que genera la plantilla;
 *  3. `App\Models\Filters\<Modelo>\ManagedFilter`, el valor de fábrica.
 *
 * Lo que declare el modelo en surgeFilters() o la configuración en
 * `search-surge.filters.namespaces` no se lee: un filtro que sólo se
 * encuentra por ahí sale en `managed_filter_unresolved`, no como correcto.
 *
 * Qué cuenta como camino para el administrador, en el cuerpo de canView():
 *
 *  - una sentencia `return $query;` (con el nombre de su primer parámetro),
 *    en cualquier rama. El cuerpo de la plantilla, que es sólo eso, cuenta:
 *    no restringe a nadie;
 *  - un nombre de UNRESTRICTED_MARKERS como método, propiedad o cadena
 *    literal: `$reach->unrestricted`, `$user->isAdmin()`,
 *    `$user->can('viewAny', ...)`;
 *  - lo mismo dentro de lo que canView() llama estáticamente en una clase del
 *    proyecto (`PolicyScope::apply(...)`, `self::constrain(...)`), hasta
 *    FOLLOW_DEPTH saltos. Es donde lo tienen los proyectos que centralizan el
 *    alcance en un soporte.
 *
 * Un `return $cond ? X : $query;` no cuenta: la rama que devuelve `$query`
 * suele ser «no es un Builder», no «es el administrador».
 *
 * EL LÍMITE: es una heurística de lectura, no una prueba. Un nombre
 * reconocible en una rama que no es la del administrador da un falso
 * negativo; un camino escrito de otra forma, un falso positivo. El reporte se
 * revisa a mano, y lo que de verdad lo demuestra es una prueba que liste como
 * administrador.
 *
 * @phpstan-import-type Token from PhpOutline
 * @phpstan-import-type ClassOutline from PhpOutline
 * @phpstan-import-type MethodOutline from PhpOutline
 *
 * @phpstan-type AdminPathMissing array{file: string, model: string, requests: list<string>, reason: string}
 * @phpstan-type FilterUnresolved array{file: string, model: string|null, reason: string}
 */
final class ManagedFilterAudit
{
    /**
     * Lo que se reconoce como alcance completo, sin distinguir mayúsculas: un
     * método o una propiedad con este nombre, o una cadena literal igual
     * (`can('viewAny', ...)`, `Gate::allows('viewAny', ...)`).
     */
    public const UNRESTRICTED_MARKERS = ['unrestricted', 'isUnrestricted', 'viewAny', 'isAdmin'];

    /**
     * Las llamadas del Builder de SearchSurge cuyo primer argumento es el
     * modelo.
     */
    public const BUILDER_METHODS = ['get', 'query', 'count', 'exists', 'first', 'lazy', 'lazybyid', 'cursor'];

    /**
     * Cuántas llamadas estáticas se siguen desde canView().
     */
    public const FOLLOW_DEPTH = 3;

    /**
     * clase::método en minúsculas => si tiene camino; para no repetir ni
     * entrar en bucle.
     *
     * @var array<string, bool>
     */
    private array $memo = [];

    /**
     * clase::método en minúsculas => lo que se siguió desde él sin encontrar
     * camino, para decirlo igual cuando la respuesta sale del memo.
     *
     * @var array<string, list<string>>
     */
    private array $followedBy = [];

    /**
     * Clase en minúsculas => [outline de la clase, outline del archivo].
     *
     * @var array<string, array{0: ClassOutline, 1: PhpOutline}|null>
     */
    private array $classes = [];

    public function __construct(private ProjectSources $project) {}

    /**
     * @param  list<string>  $requests  los IndexRequest (rutas absolutas)
     * @return array{missing: list<AdminPathMissing>, unresolved: list<FilterUnresolved>}
     */
    public function audit(array $requests): array
    {
        $filters = [];
        $unresolved = [];

        foreach ($requests as $request) {
            foreach ($this->modelsOf($request) as [$model, $filtersNamespace]) {
                $candidates = $this->candidates($model, $filtersNamespace);
                $filter = null;

                foreach ($candidates as $candidate) {
                    if ($this->project->locate($candidate) !== null) {
                        $filter = $candidate;

                        break;
                    }
                }

                if ($filter === null) {
                    $unresolved[] = [
                        'file' => $this->project->relative($request),
                        'model' => $model,
                        'reason' => 'No se encontró su ManagedFilter en el proyecto. Se buscó: '.implode(', ', $candidates).'.',
                    ];

                    continue;
                }

                $filters[$filter]['model'] = $model;
                $filters[$filter]['requests'][] = $this->project->relative($request);
            }
        }

        $missing = [];

        foreach ($filters as $filter => $info) {
            $followed = [];

            if ($this->filterHasPath($filter, $followed)) {
                continue;
            }

            $followed = array_values(array_unique($followed));
            $missing[] = [
                'file' => $this->project->relative((string) $this->project->locate($filter)),
                'model' => $info['model'],
                'requests' => array_values(array_unique($info['requests'])),
                'reason' => 'canView() acota siempre: no hay `return $query;` ni '
                    .implode(', ', array_map(fn (string $m): string => "`{$m}`", self::UNRESTRICTED_MARKERS))
                    .($followed === [] ? '' : ', tampoco en lo que llama ('.implode(', ', $followed).')')
                    .'. Con `managed` forzado, el administrador vería el listado vacío.',
            ];
        }

        usort($missing, fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return ['missing' => $missing, 'unresolved' => $unresolved];
    }

    /**
     * Los modelos que un IndexRequest pasa al Builder, con el
     * filtersNamespace literal de sus opciones si lo hay.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function modelsOf(string $file): array
    {
        $outline = PhpOutline::ofCode((string) file_get_contents($file));
        $models = [];

        foreach ($outline->classes() as $class) {
            foreach ($class['methods'] as $method) {
                $body = $method['body'] ?? [];

                foreach ($body as $index => $token) {
                    if ($token[0] !== '(' || $index < 2
                        || ! in_array($body[$index - 2][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                        || ! in_array(strtolower($body[$index - 1][1]), self::BUILDER_METHODS, true)
                    ) {
                        continue;
                    }

                    $arguments = PhpOutline::splitTopLevel(self::enclosed($body, $index));
                    $first = $arguments[0] ?? [];

                    if (count($first) !== 3 || ! in_array($first[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                        || $first[1][0] !== T_DOUBLE_COLON || strtolower($first[2][1]) !== 'class'
                    ) {
                        continue;
                    }

                    $model = $outline->resolveName($first[0][1]);

                    if (in_array(strtolower($model), PhpOutline::BUILTIN_TYPES, true)) {
                        continue;
                    }

                    $models[strtolower($model)] = [$model, self::filtersNamespace($arguments[2] ?? [])];
                }
            }
        }

        return array_values($models);
    }

    /**
     * @param  list<Token>  $options
     */
    private static function filtersNamespace(array $options): ?string
    {
        foreach ($options as $index => $token) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === 'filtersNamespace'
                && ($options[$index + 1][0] ?? null) === T_DOUBLE_ARROW
                && ($options[$index + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
            ) {
                return trim(str_replace('\\\\', '\\', trim($options[$index + 2][1], '\'"')), '\\');
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidates(string $model, ?string $filtersNamespace): array
    {
        $position = strrpos($model, '\\');
        $short = $position === false ? $model : substr($model, $position + 1);
        $namespace = $position === false ? '' : substr($model, 0, $position);
        $candidates = [];

        if ($filtersNamespace !== null && $filtersNamespace !== '') {
            $candidates[] = "{$filtersNamespace}\\{$short}\\ManagedFilter";
        }

        $candidates[] = ltrim("{$namespace}\\Filters\\{$short}\\ManagedFilter", '\\');
        $candidates[] = "App\\Models\\Filters\\{$short}\\ManagedFilter";

        return array_values(array_unique($candidates));
    }

    /**
     * Si el filtro tiene camino: su canView(), el heredado de una clase del
     * proyecto, o ninguno (el de Managed no restringe).
     *
     * @param  list<string>  $followed
     */
    private function filterHasPath(string $filter, array &$followed): bool
    {
        $visited = [];
        $class = $filter;

        while (($entry = $this->classOutline($class)) !== null && ! isset($visited[strtolower($class)])) {
            $visited[strtolower($class)] = true;

            foreach ($entry[0]['methods'] as $method) {
                if (strcasecmp($method['name'], 'canView') === 0) {
                    return $this->methodHasPath($class, $method, $entry[1], 0, $followed);
                }
            }

            if ($entry[0]['extends'] === null) {
                return true;
            }

            $class = $entry[0]['extends'];
        }

        // Hereda canView() de fuera del proyecto (Managed u otro paquete): no
        // se ve, y no se reporta lo que no se puede leer.
        return true;
    }

    /**
     * @param  MethodOutline  $method
     * @param  list<string>  $followed
     */
    private function methodHasPath(string $class, array $method, PhpOutline $outline, int $depth, array &$followed): bool
    {
        $key = strtolower($class.'::'.$method['name']);

        if (isset($this->memo[$key])) {
            $followed = [...$followed, ...($this->followedBy[$key] ?? [])];

            return $this->memo[$key];
        }

        // Mientras se analiza, cuenta como sin camino: una recursión no lo crea.
        $this->memo[$key] = false;
        $body = $method['body'] ?? [];
        $query = $method['params'][0]['name'] ?? null;
        $markers = array_map('strtolower', self::UNRESTRICTED_MARKERS);
        $calls = [];

        foreach ($body as $index => $token) {
            $previous = $body[$index - 1][0] ?? null;
            $next = $body[$index + 1][0] ?? null;

            // `return $query;`
            if ($token[0] === T_RETURN && $query !== null
                && ($body[$index + 1][0] ?? null) === T_VARIABLE && ltrim($body[$index + 1][1], '$') === ltrim($query, '$')
                && ($body[$index + 2][0] ?? null) === ';'
            ) {
                return $this->memo[$key] = true;
            }

            if ($token[0] === T_STRING && in_array(strtolower($token[1]), $markers, true)
                && (in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) || $next === '(')
            ) {
                return $this->memo[$key] = true;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($token[1], '\'"')), $markers, true)) {
                return $this->memo[$key] = true;
            }

            if ($token[0] === T_DOUBLE_COLON && $next === T_STRING && ($body[$index + 2][0] ?? null) === '('
                && in_array($body[$index - 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
            ) {
                $calls[] = [$body[$index - 1][1], $body[$index + 1][1]];
            }
        }

        if ($depth >= self::FOLLOW_DEPTH) {
            return false;
        }

        $mine = [];

        foreach ($calls as [$target, $name]) {
            $target = match (strtolower($target)) {
                'self', 'static' => $class,
                'parent' => (string) ($this->classOutline($class)[0]['extends'] ?? ''),
                default => $outline->resolveName($target),
            };

            $entry = $target === '' ? null : $this->classOutline($target);

            if ($entry === null) {
                continue;
            }

            foreach ($entry[0]['methods'] as $called) {
                if (strcasecmp($called['name'], $name) !== 0) {
                    continue;
                }

                $mine[] = $entry[0]['name'].'::'.$called['name'].'()';

                if ($this->methodHasPath($entry[0]['fqcn'], $called, $entry[1], $depth + 1, $mine)) {
                    return $this->memo[$key] = true;
                }
            }
        }

        $this->followedBy[$key] = $mine;
        $followed = [...$followed, ...$mine];

        return false;
    }

    /**
     * @return array{0: ClassOutline, 1: PhpOutline}|null
     */
    private function classOutline(string $class): ?array
    {
        $key = strtolower(ltrim($class, '\\'));

        if (array_key_exists($key, $this->classes)) {
            return $this->classes[$key];
        }

        $this->classes[$key] = null;

        if (! $this->project->owns($key)) {
            return null;
        }

        $file = $this->project->locate(ltrim($class, '\\'));

        if ($file === null) {
            return null;
        }

        $outline = PhpOutline::ofCode((string) file_get_contents($file));

        foreach ($outline->classes() as $outlined) {
            if (strtolower($outlined['fqcn']) === $key) {
                return $this->classes[$key] = [$outlined, $outline];
            }
        }

        return null;
    }

    /**
     * Los tokens entre el paréntesis de $open y su cierre.
     *
     * @param  list<Token>  $tokens
     * @return list<Token>
     */
    private static function enclosed(array $tokens, int $open): array
    {
        $depth = 0;
        $inside = [];
        $count = count($tokens);

        for ($index = $open; $index < $count; $index++) {
            $token = $tokens[$index];

            if (in_array($token[0], ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($token[0], [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            $inside[] = $token;
        }

        return $inside;
    }
}
