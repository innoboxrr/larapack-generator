<?php

namespace Innoboxrr\LarapackGenerator\Support;

use FilesystemIterator;
use Innoboxrr\LarapackGenerator\Attributes\HandRolled;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * La forma de todos los controladores del proyecto, también los que el
 * generador no escribió.
 *
 * El resto de `verify` mira lo que dice el manifiesto, y eso es justo lo que
 * los agentes respetan: los controladores generados siguen siendo
 * `return $request->handle();`. La deriva aparece en los que el generador no
 * sabe hacer —un asistente, un escritorio, una ruta pública, un webhook—,
 * donde no hay plantilla que copiar y la validación, la autorización y las
 * consultas acaban en el controlador. Por eso aquí se recorren todas las
 * clases de cualquier `Http/Controllers` del proyecto y de sus paquetes
 * locales, estén o no en el manifiesto.
 *
 * Sigue siendo una comprobación determinista, no una opinión sobre "cuánta
 * lógica es demasiada": una acción es `return $var->handle(...);`, con `$var`
 * un parámetro tipado con un FormRequest propio, o no lo es. Lo mismo vale
 * para las puertas laterales por las que el router o el framework ejecutan
 * código del controlador sin pasar por una acción: __call, el constructor y
 * los middleware en closure. Y, fuera de los controladores, para los closures
 * con cuerpo de routes/*.php, que son acciones sin controlador.
 *
 * Lo que de verdad no puede seguir la forma se declara con
 * #[HandRolled('razón')] y se lista como aviso en cada ejecución: con
 * --strict falla. Es una excepción que aprueba el dueño del proyecto, y por
 * eso ningún mensaje la ofrece como arreglo.
 *
 * @phpstan-import-type ClassOutline from PhpOutline
 * @phpstan-import-type MethodOutline from PhpOutline
 * @phpstan-import-type AttributeOutline from PhpOutline
 * @phpstan-import-type ClosureOutline from PhpOutline
 */
final class ControllerShape
{
    public const SHAPE = 'controller-shape';

    public const REQUEST_HANDLE = 'request-handle';

    public const CONCERN = 'controller-concern';

    public const HAND_ROLLED = 'hand-rolled';

    public const ROUTE_CLOSURE = 'route-closure';

    public const CHECKS = [self::SHAPE, self::REQUEST_HANDLE, self::CONCERN, self::HAND_ROLLED, self::ROUTE_CLOSURE];

    /**
     * Tipar la acción con estas clases es lo que deja la validación y la
     * autorización en el controlador: ninguna trae un handle() que llamar.
     */
    private const BASE_REQUESTS = [
        'illuminate\http\request',
        'illuminate\foundation\http\formrequest',
    ];

    /**
     * Ganchos del framework, no rutas. `middleware()` sólo cuando es el
     * estático de HasMiddleware.
     */
    private const FRAMEWORK_HOOKS = ['callaction', 'getmiddleware'];

    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules'];

    /**
     * Los métodos del router que registran una ruta con su acción.
     */
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'fallback', 'addroute'];

    /**
     * Lo que en una expresión ejecuta código que no se ve: un closure, un
     * include, eval, exit o una orden de shell.
     */
    private const OPAQUE_TOKENS = [T_FUNCTION, T_FN, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_EXIT, '`'];

    private string $root;

    /**
     * La raíz y la de cada paquete local: donde se buscan sus routes/.
     *
     * @var list<string>
     */
    private array $roots = [];

    /**
     * Prefijo PSR-4 => directorios absolutos, de la raíz y de cada paquete
     * local. Con esto se encuentra el archivo de una request sin cargar el
     * autoloader del proyecto.
     *
     * @var array<string, list<string>>
     */
    private array $autoload = [];

    /**
     * @var list<string>
     */
    private array $sources = [];

    /**
     * @var array<string, list<ClassOutline>>
     */
    private array $outlines = [];

    /**
     * @var array<string, bool|null>
     */
    private array $handles = [];

    public function __construct(string $root)
    {
        // Real, como los directorios que se recorren: si no, una raíz con un
        // enlace simbólico dejaría las rutas sin relativizar.
        $this->root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    }

    /**
     * @return list<array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    public function run(): array
    {
        $this->discover();

        $findings = [];
        $requests = [];
        $files = $this->controllerFiles();

        // Qué controladores usa cada trait: decide si un trait de
        // Http/Controllers mete lógica en un controlador o sólo está mal
        // colocado.
        $users = [];

        foreach ($files as $file) {
            foreach ($this->outline($file) as $class) {
                foreach ($class['kind'] === 'class' ? $class['traits'] : [] as $trait) {
                    $users[strtolower($trait)][] = $class['name'];
                }
            }
        }

        foreach ($files as $file) {
            $relative = $this->relative($file);

            foreach ($this->outline($file) as $class) {
                if (! in_array($class['kind'], ['class', 'trait'], true)) {
                    continue;
                }

                $excused = $this->handRolled($class['attributes'], $class['name'], $relative, $findings);

                if ($class['kind'] === 'trait') {
                    $this->checkConcern($class, $users[strtolower($class['fqcn'])] ?? [], $excused, $relative, $findings);

                    continue;
                }

                foreach ($class['methods'] as $method) {
                    $side = $this->sideEntrance($method);

                    if ($side !== null) {
                        $where = "{$class['name']}::{$method['name']}()";

                        if (! $this->handRolled($method['attributes'], $where, $relative, $findings) && ! $excused) {
                            $findings[] = $this->finding(Verifier::ERROR, self::SHAPE, $relative, "{$where} (línea {$method['line']}) {$side}");
                        }

                        continue;
                    }

                    // El router nunca llama a una clase abstracta: sus métodos
                    // públicos se comprueban en la concreta si los redeclara,
                    // y los heredados ya no se cuentan. Su constructor y su
                    // __call, que sí heredan todas, se acaban de mirar.
                    if ($class['abstract'] || ! $this->isAction($method)) {
                        continue;
                    }

                    $where = "{$class['name']}::{$method['name']}()";

                    if ($this->handRolled($method['attributes'], $where, $relative, $findings) || $excused) {
                        continue;
                    }

                    $request = $this->delegatedRequest($method, $where, $relative, $findings);

                    if ($request !== null) {
                        $requests[strtolower($request)] ??= [$request, $where, $relative];
                    }
                }
            }
        }

        foreach ($requests as [$request, $where, $relative]) {
            $this->checkRequestHandles($request, $where, $relative, $findings);
        }

        $this->checkRouteClosures($findings);

        return $findings;
    }

    /**
     * Las puertas por las que se ejecuta código del controlador sin pasar por
     * una acción. Devuelve por qué no puede estar ahí, o null si no lo es o
     * está bien.
     *
     * - __call: callAction() del router lo alcanza con cualquier nombre de
     *   acción que no exista. Es una acción sin forma, invisible al leer la
     *   clase. Siempre es error.
     * - El constructor corre antes del middleware de la ruta y en todas las
     *   acciones. Se admite lo que hace un constructor de controlador
     *   legítimo: asignar propiedades sin llamar a nada (lo inyectado, un
     *   literal, un `new`), `parent::__construct(...)` y
     *   `$this->middleware(...)` con su `->only()`/`->except()` (Laravel 10 y
     *   anteriores). Cualquier otra sentencia —una llamada, un if, leer
     *   auth()— es lógica que ninguna acción muestra.
     * - El middleware() estático de HasMiddleware puede registrar un closure
     *   con cuerpo: lógica en el controlador por la puerta de atrás.
     *
     * @param  MethodOutline  $method
     */
    private function sideEntrance(array $method): ?string
    {
        $name = strtolower($method['name']);
        $body = $method['body'] ?? [];

        if ($name === '__call') {
            return 'es __call: el router llega a él con cualquier nombre de acción que no exista (callAction), así que es una acción que no se ve al leer el controlador. '
                .'Quítalo y declara cada acción como un método público que haga `return $request->handle();`.';
        }

        if ($name === '__construct') {
            foreach (PhpOutline::splitTopLevel($body, ';') as $statement) {
                if (! $this->isConstructorWiring($statement)) {
                    return "hace algo más que asignar propiedades, llamar a parent::__construct() o registrar middleware (línea {$statement[0][2]}): "
                        .'el constructor corre en cada acción y antes del middleware de la ruta, y lo que haga ahí es lógica que ninguna acción muestra. '
                        .'Inyecta las dependencias como propiedades promovidas y deja el trabajo en el handle() de la request.';
                }
            }

            return null;
        }

        if ($name === 'middleware' && $method['static'] && array_intersect(array_column($body, 0), [T_FUNCTION, T_FN]) !== []) {
            return 'registra un middleware en un closure: es lógica en el controlador que no se ve al leer sus acciones. '
                .'Hazlo una clase de middleware y regístrala por su nombre o su alias.';
        }

        return null;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $statement
     */
    private function isConstructorWiring(array $statement): bool
    {
        if (array_intersect(array_column($statement, 0), self::OPAQUE_TOKENS) !== []) {
            return false;
        }

        // $this->propiedad = <expresión sin llamadas>
        if (count($statement) > 4
            && $statement[0][1] === '$this'
            && $statement[1][0] === T_OBJECT_OPERATOR
            && $statement[2][0] === T_STRING
            && $statement[3][0] === '='
        ) {
            return ! $this->callsSomething(array_slice($statement, 4));
        }

        // parent::__construct(...)
        if (count($statement) > 3
            && strtolower($statement[0][1]) === 'parent'
            && $statement[1][0] === T_DOUBLE_COLON
            && strtolower($statement[2][1]) === '__construct'
            && $statement[3][0] === '('
        ) {
            return $this->onlyCalls(array_slice($statement, 3), []);
        }

        // $this->middleware(...)->only(...)->except(...)
        if (count($statement) > 3
            && $statement[0][1] === '$this'
            && $statement[1][0] === T_OBJECT_OPERATOR
            && strtolower($statement[2][1]) === 'middleware'
            && $statement[3][0] === '('
        ) {
            return $this->onlyCalls(array_slice($statement, 3), ['only', 'except']);
        }

        return false;
    }

    /**
     * Que lo que queda sea `(...)` seguido sólo de `->nombre(...)` con
     * nombres de esta lista.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     * @param  list<string>  $chain
     */
    private function onlyCalls(array $tokens, array $chain): bool
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (in_array($token[0], ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token[0], [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0) {
                if ($token[0] !== T_OBJECT_OPERATOR
                    || ! in_array(strtolower($tokens[$index + 1][1] ?? ''), $chain, true)
                    || ($tokens[$index + 2][0] ?? null) !== '('
                ) {
                    return false;
                }

                $index++;
            }
        }

        return true;
    }

    /**
     * Si la expresión llama a algo. `new Clase(...)` y los constructos que no
     * ejecutan nada (array(), isset(), empty()) no cuentan.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $tokens
     */
    private function callsSomething(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if ($token[0] !== '(' || $index === 0) {
                continue;
            }

            $before = $tokens[$index - 1][0];
            $named = in_array($before, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC], true);

            if ($named && ($tokens[$index - 2][0] ?? null) === T_NEW) {
                continue;
            }

            if ($named || in_array($before, [T_VARIABLE, ')', ']', '}'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un closure con cuerpo en routes/*.php es una acción sin controlador:
     * nada de lo que `verify` comprueba en los controladores lo alcanza. Es
     * aviso y no error porque es el patrón de la documentación de Laravel y
     * aparece en proyectos que nunca lo usaron como atajo; con --strict falla.
     *
     * No se cuenta el que sólo pinta una vista —`return view('welcome');`, el
     * routes/web.php con el que nace toda aplicación—: equivale a
     * Route::view() y no tiene dónde esconder lógica. Tampoco los closures de
     * group(), que agrupan rutas y no son una acción.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkRouteClosures(array &$findings): void
    {
        foreach ($this->routeFiles() as $file) {
            foreach (PhpOutline::ofFile($file)->closuresPassedTo(self::ROUTE_METHODS) as $closure) {
                if ($this->onlyRendersAView($closure)) {
                    continue;
                }

                $findings[] = $this->finding(Verifier::WARNING, self::ROUTE_CLOSURE, $this->relative($file),
                    "La ruta de la línea {$closure['line']} lleva su acción en un closure ({$closure['call']}()): es una acción sin controlador, y nada de lo que verify comprueba en los controladores la alcanza. "
                    .'Llévala a un controlador cuya acción sea `return $request->handle();` con un FormRequest propio (o usa Route::view() si sólo pinta una vista).');
            }
        }
    }

    /**
     * @param  ClosureOutline  $closure
     */
    private function onlyRendersAView(array $closure): bool
    {
        $body = $closure['body'];

        if (! $closure['arrow']) {
            if (($body[0][0] ?? null) !== T_RETURN || ($body[count($body) - 1][0] ?? null) !== ';') {
                return false;
            }

            $body = array_slice($body, 1, -1);
        }

        return $closure['params'] === []
            && count($body) === 4
            && $body[0][0] === T_STRING && strtolower($body[0][1]) === 'view'
            && $body[1][0] === '('
            && $body[2][0] === T_CONSTANT_ENCAPSED_STRING
            && $body[3][0] === ')';
    }

    /**
     * @return list<string>
     */
    private function routeFiles(): array
    {
        $files = [];

        foreach ($this->roots as $root) {
            if (is_dir($root.'/routes')) {
                $files = [...$files, ...$this->phpFiles($root.'/routes')];
            }
        }

        $files = array_values($files);
        sort($files);

        return $files;
    }

    /**
     * Una acción es lo que el router puede llamar: un método público con
     * cuerpo, declarado en la clase. Los heredados no se ven aquí, y los de un
     * trait de Http/Controllers los cuenta controller-concern.
     *
     * @param  MethodOutline  $method
     */
    private function isAction(array $method): bool
    {
        $name = strtolower($method['name']);

        if ($method['visibility'] !== 'public' || $method['body'] === null) {
            return false;
        }

        if (str_starts_with($name, '__')) {
            return $name === '__invoke';
        }

        if ($name === 'middleware' && $method['static']) {
            return false;
        }

        return ! in_array($name, self::FRAMEWORK_HOOKS, true);
    }

    /**
     * Comprueba que la acción sea `return $var->handle(...);` y devuelve la
     * request en la que delega, o null si no delega (y lo anota).
     *
     * Los argumentos de handle() sólo pueden ser variables —`$this`, un modelo
     * enlazado por la ruta—: cualquier expresión ahí es trabajo hecho en el
     * controlador.
     *
     * @param  MethodOutline  $method
     * @param  list<array<string, mixed>>  $findings
     */
    private function delegatedRequest(array $method, string $where, string $file, array &$findings): ?string
    {
        $body = $method['body'] ?? [];
        $params = array_column($method['params'], null, 'name');
        $line = $method['line'];

        $variable = $this->delegatingVariable($body);

        if ($variable === null) {
            $findings[] = $this->finding(Verifier::ERROR, self::SHAPE, $file, $this->whyNotDelegating($method, $where, $line));

            return null;
        }

        $type = $params[$variable]['type'] ?? null;

        if (! isset($params[$variable])) {
            $problem = "llama a {$variable}->handle(), y {$variable} no es un parámetro de la acción";
        } elseif ($type === null || in_array($type, PhpOutline::BUILTIN_TYPES, true)) {
            $problem = "no tipa {$variable} con un FormRequest";
        } elseif (in_array(strtolower($type), self::BASE_REQUESTS, true)) {
            $problem = "tipa {$variable} con {$type}, que no tiene handle()";
        } else {
            return $type;
        }

        $findings[] = $this->finding(Verifier::ERROR, self::SHAPE, $file, "{$where} (línea {$line}) {$problem}. ".$this->remedy());

        return null;
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $body
     */
    private function delegatingVariable(array $body): ?string
    {
        $count = count($body);

        if ($count < 7
            || $body[0][0] !== T_RETURN
            || $body[1][0] !== T_VARIABLE
            || $body[2][0] !== T_OBJECT_OPERATOR
            || strtolower($body[3][1]) !== 'handle'
            || $body[4][0] !== '('
            || $body[$count - 2][0] !== ')'
            || $body[$count - 1][0] !== ';'
            || $body[1][1] === '$this'
        ) {
            return null;
        }

        foreach (PhpOutline::splitTopLevel(array_slice($body, 5, $count - 7)) as $argument) {
            $named = count($argument) === 3 && $argument[0][0] === T_STRING && $argument[1][0] === ':';
            $value = $named ? [$argument[2]] : $argument;

            if (count($value) !== 1 || $value[0][0] !== T_VARIABLE) {
                return null;
            }
        }

        return $body[1][1];
    }

    /**
     * Lo que se ve sin interpretar nada: con qué se tipa la petición y si se
     * valida a mano. Sirve para que quien lo lea sepa por dónde empezar.
     *
     * @param  MethodOutline  $method
     */
    private function whyNotDelegating(array $method, string $where, int $line): string
    {
        $reasons = [];

        foreach ($method['params'] as $param) {
            if ($param['type'] !== null && in_array(strtolower($param['type']), self::BASE_REQUESTS, true)) {
                $reasons[] = "recibe {$param['type']}";
            }
        }

        $body = $method['body'] ?? [];

        if (($body[0][0] ?? null) === T_RETURN && strtolower($body[3][1] ?? '') === 'handle' && ($body[4][0] ?? null) === '(') {
            $reasons[] = 'pasa a handle() algo que no es una variable: esa expresión es trabajo hecho en el controlador';
        }

        foreach ($body as $index => $token) {
            if ($token[0] === T_STRING && strtolower($token[1]) === 'validate' && ($body[$index + 1][0] ?? null) === '(') {
                $reasons[] = 'valida en el controlador con validate()';

                break;
            }
        }

        if ($reasons === [] || $body === []) {
            $reasons[] = $body === [] ? 'tiene el cuerpo vacío' : 'hace algo más que `return $request->handle();`';
        }

        return "{$where} (línea {$line}) no delega: ".implode(', ', $reasons).'. '.$this->remedy();
    }

    /**
     * El arreglo es la forma. #[HandRolled] no se ofrece como salida: un
     * agente que repite `verify` hasta verlo verde tomaría el atajo de una
     * línea. Se nombra sólo para decir que no es suyo decidirlo.
     */
    private function remedy(): string
    {
        return 'La acción es `return $request->handle();` con un FormRequest propio: authorize() autoriza, rules() valida y handle() hace el trabajo (o lo delega en la operación del modelo o en un soporte). '
            .'Marcarla como escrita a mano (#[HandRolled]) no es un arreglo: es una excepción que sólo aprueba el dueño del proyecto, y verify la sigue listando como aviso.';
    }

    /**
     * Un trait con lógica dentro de Http/Controllers.
     *
     * Si lo usa un controlador es error: mete en él lógica que no se ve al
     * leer sus acciones, y sus métodos públicos son acciones que el router
     * puede llamar sin que aparezcan en el controlador. Que sea error y no
     * aviso es deliberado: un aviso es justo lo que un agente aprende a
     * ignorar, y así fue como la deriva llegó a los controladores.
     *
     * Si no lo usa ningún controlador —lo usan requests, que es donde esa
     * lógica sí va— el comportamiento es correcto y sólo está mal colocado: es
     * aviso. Pero se dice, porque un `Http\Controllers\Concerns\...` invita al
     * siguiente a usarlo desde un controlador.
     *
     * @param  ClassOutline  $trait
     * @param  list<string>  $users  los controladores que lo usan
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkConcern(array $trait, array $users, bool $excused, string $file, array &$findings): void
    {
        if ($excused) {
            return;
        }

        $logic = [];

        foreach ($trait['methods'] as $method) {
            if ($method['body'] === null || (strtolower($method['name']) === 'middleware' && $method['static'])) {
                continue;
            }

            if (! $this->handRolled($method['attributes'], "{$trait['name']}::{$method['name']}()", $file, $findings)) {
                $logic[] = $method['name'].'()';
            }
        }

        if ($logic === []) {
            return;
        }

        $where = "El trait {$trait['name']} (línea {$trait['line']}) vive en Http/Controllers y lleva lógica (".implode(', ', $logic).')';

        if ($users === []) {
            $findings[] = $this->finding(Verifier::WARNING, self::CONCERN, $file,
                "{$where}, pero ningún controlador lo usa: está en el sitio equivocado. Muévelo a Http/Requests/Concerns o a un soporte; en Http/Controllers invita a usarlo desde un controlador.");

            return;
        }

        $findings[] = $this->finding(Verifier::ERROR, self::CONCERN, $file,
            "{$where}, y lo usa ".implode(', ', array_unique($users)).'. Lo que comparten varios controladores no es del controlador: llévalo a una request base o a un trait en Http/Requests, o a un soporte, y deja las acciones en `return $request->handle();`.');
    }

    /**
     * La request en la que delega una acción tiene que definir handle(): si
     * no, la acción tiene la forma correcta y falla en cuanto se llama.
     *
     * Se busca en la clase, en sus traits y en sus padres, hasta llegar a
     * FormRequest. Lo que no se encuentra en el código del proyecto (una
     * request de vendor) no se puede comprobar sin cargarlo: se informa y no
     * se adivina.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function checkRequestHandles(string $request, string $where, string $controller, array &$findings): void
    {
        $handles = $this->handles($request, []);
        $file = $this->locate($request);
        $relative = $file === null ? $controller : $this->relative($file);

        if ($handles === false && $file === null) {
            $findings[] = $this->finding(Verifier::ERROR, self::REQUEST_HANDLE, $relative,
                "{$where} delega en {$request}, que no existe: su espacio de nombres es del proyecto y no está donde PSR-4 dice. Genérala (larapack:requests) o escríbela con su handle().");
        } elseif ($handles === false) {
            $findings[] = $this->finding(Verifier::ERROR, self::REQUEST_HANDLE, $relative,
                "{$request} no define handle(), y {$where} en {$controller} delega en él: la petición falla con BadMethodCallException. La operación va en su handle().");
        } elseif ($handles === null) {
            $findings[] = $this->finding(Verifier::INFO, self::REQUEST_HANDLE, $relative,
                "No se encontró en el código del proyecto dónde define handle() {$request} (¿viene de vendor?), y {$where} delega en él: no se comprobó sin cargarlo.");
        }
    }

    /**
     * @param  array<string, true>  $visited
     */
    private function handles(string $class, array $visited): ?bool
    {
        $key = strtolower($class);

        if (in_array($key, self::BASE_REQUESTS, true) || isset($visited[$key])) {
            return false;
        }

        if (array_key_exists($key, $this->handles)) {
            return $this->handles[$key];
        }

        $visited[$key] = true;
        $outline = $this->classOutline($class);

        // Bajo un prefijo PSR-4 del proyecto, no encontrarla es saber que no
        // existe. Fuera de ellos (vendor) no se sabe: no se carga para verlo.
        if ($outline === null) {
            return $this->handles[$key] = $this->ownedByProject($class) ? false : null;
        }

        foreach ($outline['methods'] as $method) {
            if (strtolower($method['name']) === 'handle') {
                return $this->handles[$key] = true;
            }
        }

        $unknown = false;

        foreach ([...$outline['traits'], ...array_filter([$outline['extends']])] as $other) {
            $result = $this->handles($other, $visited);

            if ($result === true) {
                return $this->handles[$key] = true;
            }

            $unknown = $unknown || $result === null;
        }

        return $this->handles[$key] = $unknown ? null : false;
    }

    /**
     * @return ClassOutline|null
     */
    private function classOutline(string $class): ?array
    {
        $file = $this->locate($class);

        foreach ($file === null ? [] : $this->outline($file) as $outline) {
            if (strcasecmp($outline['fqcn'], $class) === 0) {
                return $outline;
            }
        }

        return null;
    }

    /**
     * Si la clase cae bajo un prefijo PSR-4 del proyecto. El prefijo vacío no
     * cuenta: es un directorio de respaldo para cualquier nombre, y no
     * encontrar ahí una clase no dice que no exista.
     */
    private function ownedByProject(string $class): bool
    {
        foreach (array_keys($this->autoload) as $prefix) {
            if ($prefix !== '' && self::hasPrefix($class, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP no distingue mayúsculas en los nombres de clase: una request
     * importada como `app\Http\...` es la misma.
     */
    private static function hasPrefix(string $class, string $prefix): bool
    {
        return strncasecmp($class, $prefix, strlen($prefix)) === 0;
    }

    private function locate(string $class): ?string
    {
        $prefixes = array_keys($this->autoload);

        // El prefijo más largo primero: `App\Models\` gana a `App\`.
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;

            if (! self::hasPrefix($class, $prefix)) {
                continue;
            }

            $path = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            foreach ($this->autoload[$prefix] as $directory) {
                $file = is_file($directory.'/'.$path) ? $directory.'/'.$path : self::findIgnoringCase($directory, $path);

                if ($file !== null) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * El archivo de un nombre escrito con otras mayúsculas que su ruta. Sólo
     * se busca cuando la ruta exacta no existe.
     */
    private static function findIgnoringCase(string $directory, string $path): ?string
    {
        foreach (explode('/', $path) as $segment) {
            $match = null;

            foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $entry) {
                if (strcasecmp($entry, $segment) === 0) {
                    $match = $entry;

                    break;
                }
            }

            if ($match === null) {
                return null;
            }

            $directory .= '/'.$match;
        }

        return is_file($directory) ? $directory : null;
    }

    /**
     * Si la clase o el método llevan #[HandRolled]: true si excusa (tiene
     * razón, y se lista como aviso), false si no lo lleva o no tiene razón (y
     * entonces es error).
     *
     * Con razón es aviso y no información: así --strict falla mientras la
     * excepción exista, y quien la añade no puede dejar el proyecto en verde
     * con una línea sin que nadie lo apruebe.
     *
     * @param  list<AttributeOutline>  $attributes
     * @param  list<array<string, mixed>>  $findings
     */
    private function handRolled(array $attributes, string $what, string $file, array &$findings): bool
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute['name'], HandRolled::class) !== 0) {
                continue;
            }

            $reason = self::sanitise((string) PhpOutline::stringArgument($attribute['args'], 0, 'reason'));

            if ($reason === '') {
                $findings[] = $this->finding(Verifier::ERROR, self::HAND_ROLLED, $file,
                    "{$what} (línea {$attribute['line']}) lleva #[HandRolled] sin razón. La razón es obligatoria y tiene que ser una cadena literal: es lo que permite revisar la excepción sin leer el código. Sin ella no excusa nada.");

                return false;
            }

            $findings[] = $this->finding(Verifier::WARNING, self::HAND_ROLLED, $file,
                "{$what} (línea {$attribute['line']}) escrito a mano a propósito: «{$reason}». Es una excepción al patrón que aprueba el dueño del proyecto; si no la aprobó, devuelve la acción a `return \$request->handle();`.");

            return true;
        }

        return false;
    }

    /**
     * La razón la escribe el proyecto que se audita: sin caracteres de
     * control ni de formato invisibles (secuencias de escape del terminal,
     * cambios de dirección del texto), en una línea.
     */
    private static function sanitise(string $reason): string
    {
        $clean = preg_replace(['/[\p{Cc}\p{Cf}]+/u', '/\s+/u'], ' ', mb_scrub($reason, 'UTF-8'));

        return trim((string) $clean);
    }

    /**
     * La raíz y los paquetes locales: los repositorios `path` de su
     * composer.json y, aunque no estén declarados, `packages/*`. De cada uno,
     * sus directorios PSR-4; sin composer.json, `app` y `src`.
     */
    private function discover(): void
    {
        $roots = [$this->root];
        $composer = $this->composer($this->root);

        foreach ((array) ($composer['repositories'] ?? []) as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && is_string($repository['url'] ?? null)) {
                $url = $repository['url'];
                $pattern = str_starts_with($url, '/') ? $url : $this->root.'/'.$url;

                $roots = [...$roots, ...(glob($pattern, GLOB_ONLYDIR) ?: [])];
            }
        }

        $roots = [...$roots, ...(glob($this->root.'/packages/*', GLOB_ONLYDIR) ?: [])];
        $seen = [];

        foreach ($roots as $root) {
            $real = realpath($root);

            if ($real === false || isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;
            $this->roots[] = str_replace('\\', '/', $real);
            $psr4 = (array) ($this->composer($real)['autoload']['psr-4'] ?? []);
            $declared = $psr4 !== [];

            if (! $declared) {
                $psr4 = ['' => ['app', 'src']];
            }

            foreach ($psr4 as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    $absolute = realpath($real.'/'.$directory);

                    if ($absolute === false || ! is_dir($absolute)) {
                        continue;
                    }

                    $absolute = str_replace('\\', '/', $absolute);

                    // Un prefijo vacío declarado (`"": "src/"`) es un respaldo
                    // donde buscar. El supuesto, sin composer.json, no: no se
                    // sabe qué espacio de nombres corresponde a app/.
                    if ($prefix !== '' || $declared) {
                        $this->autoload[(string) $prefix][] = $absolute;
                    }

                    $this->sources[] = $absolute;
                }
            }
        }

        $this->sources = array_values(array_unique($this->sources));
    }

    /**
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $files = [];

        foreach ($this->sources as $source) {
            foreach ($this->phpFiles($source) as $real => $path) {
                if (str_contains(substr($path, strlen($source)), '/Http/Controllers/')) {
                    $files[$real] = $path;
                }
            }
        }

        $files = array_values($files);
        sort($files);

        return $files;
    }

    /**
     * Los .php de un directorio, sin vendor, node_modules ni ocultos: ruta
     * real => ruta tal como se recorrió.
     *
     * @return array<string, string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! $file->isDir()
                || ! (in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true) || str_starts_with($file->getFilename(), '.'))
        ));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $path = str_replace('\\', '/', $file->getPathname());
                $files[(string) ($file->getRealPath() ?: $path)] = $path;
            }
        }

        return $files;
    }

    /**
     * @return list<ClassOutline>
     */
    private function outline(string $file): array
    {
        return $this->outlines[$file] ??= PhpOutline::ofFile($file)->classes();
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(string $directory): array
    {
        $file = $directory.'/composer.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $this->root.'/') ? substr($file, strlen($this->root) + 1) : $file;
    }

    /**
     * @return array{level: string, check: string, model: string|null, file: string|null, message: string}
     */
    private function finding(string $level, string $check, string $file, string $message): array
    {
        return ['level' => $level, 'check' => $check, 'model' => null, 'file' => $file, 'message' => $message];
    }
}
