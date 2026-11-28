<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Closure;
use Innoboxrr\LarapackGenerator\Attributes\HandRolled;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use RuntimeException;

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
 * Qué se recorre lo decide ProjectSources, que también explica lo que queda
 * fuera a propósito: Livewire, Filament y los controladores que no cuelgan de
 * `Http/Controllers` no tienen el patrón FormRequest::handle() que se
 * comprueba aquí.
 *
 * Lo que de verdad no puede seguir la forma se declara con
 * #[HandRolled('razón')]. Es una excepción que aprueba el dueño del proyecto,
 * y por eso ningún mensaje la ofrece como arreglo. Sin candado
 * (.larapack/hand-rolled.lock) se lista como aviso en cada ejecución, y con
 * --strict falla, como en 9.0. Con candado, la que el dueño aprobó con esa
 * misma razón y sobre ese mismo código (la huella de HandRolledLock) se
 * informa, y la que no, es error.
 *
 * @phpstan-import-type Approval from HandRolledLock
 * @phpstan-import-type ClassOutline from PhpOutline
 * @phpstan-import-type MethodOutline from PhpOutline
 * @phpstan-import-type AttributeOutline from PhpOutline
 * @phpstan-import-type ClosureOutline from PhpOutline
 * @phpstan-import-type Finding from Verifier
 */
final class ControllerShape
{
    public const SHAPE = 'controller-shape';

    public const REQUEST_HANDLE = 'request-handle';

    public const CONCERN = 'controller-concern';

    public const HAND_ROLLED = 'hand-rolled';

    public const ROUTE_CLOSURE = 'route-closure';

    public const HAND_ROLLED_UNAPPROVED = 'hand-rolled-unapproved';

    public const HAND_ROLLED_STALE = 'hand-rolled-stale';

    public const CHECKS = [
        self::SHAPE, self::REQUEST_HANDLE, self::CONCERN, self::HAND_ROLLED, self::ROUTE_CLOSURE,
        self::HAND_ROLLED_UNAPPROVED, self::HAND_ROLLED_STALE,
    ];

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

    /**
     * Los métodos del router que registran una ruta con su acción.
     */
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'fallback', 'addroute'];

    /**
     * Los que registran de una vez todas las acciones de un controlador.
     */
    private const RESOURCE_METHODS = ['resource', 'apiresource', 'singleton', 'apisingleton'];

    /**
     * Lo que en una expresión ejecuta código que no se ve: un closure, un
     * include, eval, exit o una orden de shell.
     */
    private const OPAQUE_TOKENS = [T_FUNCTION, T_FN, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_EXIT, '`'];

    private ProjectSources $project;

    /**
     * Lo aprobado en el candado, por objetivo; null sin candado, que es el
     * comportamiento de 9.0.
     *
     * @var array<string, Approval>|null
     */
    private ?array $approved = null;

    /**
     * Cada #[HandRolled] que se evaluó, por objetivo, con su razón saneada
     * (vacía si no tiene) y la huella de su código.
     *
     * @var array<string, array{reason: string, fingerprint: string, file: string, line: int}>
     */
    private array $declared = [];

    /**
     * Los closures de rutas que se evaluaron, por su objetivo de excepción
     * del realineamiento (ExceptionTargets): lo aprobado que no está aquí
     * sobra.
     *
     * @var array<string, true>
     */
    private array $closures = [];

    /**
     * Si lleva #[HandRolled] con razón cada clase o método de fuera de
     * Http/Controllers que ya se evaluó (por objetivo): lo heredan o lo
     * enrutan varios, y su excepción se anota una vez.
     *
     * @var array<string, bool>
     */
    private array $excusedElsewhere = [];

    /**
     * @var array<string, list<ClassOutline>>
     */
    private array $outlines = [];

    /**
     * @var array<string, bool|null>
     */
    private array $handles = [];

    public function __construct(private string $root)
    {
        $this->project = new ProjectSources($root);
    }

    /**
     * Las excepciones #[HandRolled] con razón que evaluó la última ejecución
     * de run(), por objetivo: `Clase` o `Clase::metodo`, con el nombre
     * completo de la clase. Es lo que `larapack:hand-rolled` aprueba: la razón
     * y la huella del código.
     *
     * @return array<string, array{reason: string, fingerprint: string, file: string, line: int}>
     */
    public function exceptions(): array
    {
        $exceptions = array_filter($this->declared, fn (array $declared): bool => $declared['reason'] !== '');
        ksort($exceptions, SORT_STRING);

        return $exceptions;
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];
        $requests = [];
        $this->declared = [];
        $this->closures = [];
        $this->excusedElsewhere = [];
        $this->loadLock($findings);
        $files = $this->project->files('/Http/Controllers/');

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
            $relative = $this->project->relative($file);

            foreach ($this->outline($file) as $class) {
                if (! in_array($class['kind'], ['class', 'trait'], true)) {
                    continue;
                }

                $excused = $this->handRolled($class['attributes'], $class['name'], $class['fqcn'], $relative, $findings,
                    fn (): string => HandRolledLock::classFingerprint($class));

                if ($class['kind'] === 'trait') {
                    $this->checkConcern($class, $users[strtolower($class['fqcn'])] ?? [], $excused, $relative, $findings);

                    continue;
                }

                foreach ($class['methods'] as $method) {
                    $side = $this->sideEntrance($method);

                    if ($side !== null) {
                        $where = "{$class['name']}::{$method['name']}()";

                        if (! $this->handRolled($method['attributes'], $where, "{$class['fqcn']}::{$method['name']}", $relative, $findings, fn (): string => HandRolledLock::methodFingerprint($method)) && ! $excused) {
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

                    if ($this->handRolled($method['attributes'], $where, "{$class['fqcn']}::{$method['name']}", $relative, $findings, fn (): string => HandRolledLock::methodFingerprint($method)) || $excused) {
                        continue;
                    }

                    $request = $this->delegatedRequest($method, $where, $relative, $findings);

                    if ($request !== null) {
                        $requests[strtolower($request)] ??= [$request, $where, $relative];
                    }
                }

                if (! $class['abstract']) {
                    $this->checkInherited($class, $excused, $relative, $findings, $requests);
                }
            }
        }

        $this->checkRouteActions($findings, $requests);

        foreach ($requests as [$request, $where, $relative]) {
            $this->checkRequestHandles($request, $where, $relative, $findings);
        }

        $this->checkRouteClosures($findings);

        // Con un candado ilegible no hay nada aprobado, y nada que sobre.
        $this->checkStale($findings);

        return $findings;
    }

    /**
     * Lee el candado. Uno ilegible es error y deja todo sin aprobar: volver
     * al comportamiento sin candado sería aprobar por un archivo roto.
     *
     * @param  list<Finding>  $findings
     */
    private function loadLock(array &$findings): void
    {
        try {
            $this->approved = HandRolledLock::read($this->root);
        } catch (RuntimeException $e) {
            $this->approved = [];
            $findings[] = $this->finding(Verifier::ERROR, self::HAND_ROLLED_UNAPPROVED, HandRolledLock::PATH,
                self::sanitise($e->getMessage()).' Mientras no se corrija, ninguna excepción #[HandRolled] cuenta como aprobada. Lo mantiene el dueño del proyecto con larapack:hand-rolled.');
        }
    }

    /**
     * Lo aprobado que ya no está en el código: se quitó el atributo, o la
     * clase cambió de nombre. Es aviso y no error —no deja pasar nada—, pero
     * con --strict falla: un candado que aprueba lo que no existe ya no dice
     * qué se aprobó.
     *
     * @param  list<Finding>  $findings
     */
    private function checkStale(array &$findings): void
    {
        // Las excepciones del realineamiento que no son de un closure las
        // juzga ProjectVerification, que ve todos los hallazgos.
        $stale = array_filter(
            array_diff_key($this->approved ?? [], $this->declared, $this->closures),
            fn (string|int $target): bool => ! ExceptionTargets::isRealign((string) $target) || str_starts_with((string) $target, ExceptionTargets::PREFIX.'route-closure:'),
            ARRAY_FILTER_USE_KEY
        );
        ksort($stale, SORT_STRING);

        foreach (array_keys($stale) as $target) {
            $findings[] = $this->finding(Verifier::WARNING, self::HAND_ROLLED_STALE, HandRolledLock::PATH, ExceptionTargets::isRealign((string) $target)
                ? 'Aprueba la excepción '.self::sanitise((string) $target).', y esa ruta ya no lleva su acción en un closure (o cambió de URI). El dueño del proyecto la retira con larapack:hand-rolled revoke.'
                : 'Aprueba '.self::sanitise((string) $target).', que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.');
        }
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
     *   con cuerpo: lógica en el controlador por la puerta de atrás. Lo mismo
     *   un getMiddleware() redefinido que devuelve uno.
     * - callAction() redefinido corre delante de cada acción, y un
     *   __destruct() con cuerpo, detrás de cada petición: siempre son error.
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

        // callAction() es por donde el router llama a TODAS las acciones:
        // redefinirlo es poner lógica delante de cada una, sin que ninguna
        // la muestre. Con cuerpo: una declaración abstracta no hace nada.
        if ($name === 'callaction' && $method['body'] !== null) {
            return 'redefine callAction(), por donde el router llama a todas las acciones: lo que haga ahí corre en cada una y ninguna lo muestra. '
                .'Quítalo; lo que tenga que pasar antes de una acción es un middleware con nombre, y lo demás va en el handle() de su request.';
        }

        if ($name === '__destruct' && $body !== []) {
            return 'es un destructor con cuerpo: corre al terminar cada petición que pasa por el controlador, y es lógica que ninguna acción muestra. '
                .'Quítalo; lo que haya que hacer después de responder va en un middleware terminable o en un listener.';
        }

        if ($name === 'getmiddleware' && array_intersect(array_column($body, 0), [T_FUNCTION, T_FN]) !== []) {
            return 'devuelve un middleware en un closure desde getMiddleware(): es lógica en el controlador que no se ve al leer sus acciones. '
                .'Hazlo una clase de middleware y regístrala por su nombre o su alias.';
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
            && PhpOutline::is($statement[3], '=')
        ) {
            return ! $this->callsSomething(array_slice($statement, 4));
        }

        // parent::__construct(...)
        if (count($statement) > 3
            && strtolower($statement[0][1]) === 'parent'
            && $statement[1][0] === T_DOUBLE_COLON
            && strtolower($statement[2][1]) === '__construct'
            && PhpOutline::is($statement[3], '(')
        ) {
            return $this->onlyCalls(array_slice($statement, 3), []);
        }

        // $this->middleware(...)->only(...)->except(...)
        if (count($statement) > 3
            && $statement[0][1] === '$this'
            && $statement[1][0] === T_OBJECT_OPERATOR
            && strtolower($statement[2][1]) === 'middleware'
            && PhpOutline::is($statement[3], '(')
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

            if (PhpOutline::is($token, '(', '[', '{')) {
                $depth++;
            } elseif (PhpOutline::is($token, ')', ']', '}')) {
                $depth--;
            } elseif ($depth === 0) {
                if ($token[0] !== T_OBJECT_OPERATOR
                    || ! in_array(strtolower($tokens[$index + 1][1] ?? ''), $chain, true)
                    || ! PhpOutline::is($tokens[$index + 2] ?? null, '(')
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
            if (! PhpOutline::is($token, '(') || $index === 0) {
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
     * Las acciones que un controlador concreto hereda sin declararlas: las de
     * un trait o de un padre que viven fuera de Http/Controllers, y las de un
     * padre abstracto de dentro (cuyos métodos no se cuentan en él: el router
     * nunca lo instancia).
     *
     * El router las llama igual que a las declaradas. Sin esto bastaba mover
     * la acción —con su validación y sus consultas— a un trait de
     * `Support/` o a una clase base, y dejar el controlador vacío: verify no
     * veía nada. Un trait de Http/Controllers lo cuenta controller-concern;
     * un padre concreto de Http/Controllers se comprueba como controlador
     * que es. Lo que viene de vendor no se ve sin cargarlo, y no se adivina.
     *
     * El hallazgo es del controlador: es él quien convierte ese método en
     * una acción.
     *
     * @param  ClassOutline  $class
     * @param  list<Finding>  $findings
     * @param  array<string, array{0: string, 1: string, 2: string}>  $requests
     */
    private function checkInherited(array $class, bool $excused, string $relative, array &$findings, array &$requests): void
    {
        foreach ($this->inherited($class) as [$owner, $method, $ownerFile]) {
            $from = "heredada de {$owner['name']} ({$ownerFile})";
            $where = "{$class['name']}::{$method['name']}(), {$from},";
            $outside = ! $this->inControllers($ownerFile);
            $side = $outside ? $this->sideEntrance($method) : null;

            if (! ($side !== null || $this->isAction($method))) {
                continue;
            }

            $ownerExcused = $this->excusedElsewhere($owner['attributes'], $owner['name'], $owner['fqcn'], $ownerFile, $findings,
                fn (): string => HandRolledLock::classFingerprint($owner), $outside);
            $methodExcused = $this->excusedElsewhere($method['attributes'], "{$owner['name']}::{$method['name']}()", "{$owner['fqcn']}::{$method['name']}", $ownerFile, $findings,
                fn (): string => HandRolledLock::methodFingerprint($method), true);

            if ($excused || $ownerExcused || $methodExcused) {
                continue;
            }

            if ($side !== null) {
                $findings[] = $this->finding(Verifier::ERROR, self::SHAPE, $relative, "{$where} (línea {$method['line']}) {$side}");

                continue;
            }

            $request = $this->delegatedRequest($method, $where, $relative, $findings);

            if ($request !== null) {
                $requests[strtolower($request)] ??= [$request, $where, $relative];
            }
        }
    }

    /**
     * Los métodos que una clase recibe de sus traits y de sus padres, sin los
     * que ella misma declara: cada uno con la clase o el trait que lo declara
     * y su archivo. Gana el más cercano, como en PHP: la clase, sus traits,
     * su padre.
     *
     * @param  ClassOutline  $class
     * @return list<array{0: ClassOutline, 1: MethodOutline, 2: string}>
     */
    private function inherited(array $class): array
    {
        $found = [];
        $names = [];

        foreach ($class['methods'] as $method) {
            $names[strtolower($method['name'])] = true;
        }

        $visited = [strtolower($class['fqcn']) => true];
        $current = $class;

        while (true) {
            foreach ($current['traits'] as $trait) {
                $this->collectFrom($trait, true, $found, $names, $visited);
            }

            $parent = $current['extends'] === null ? null : $this->classOutline($current['extends']);

            if ($parent === null || isset($visited[strtolower($parent['fqcn'])])) {
                break;
            }

            $file = $this->project->relative((string) $this->project->locate($parent['fqcn']));

            // Un padre concreto de Http/Controllers es un controlador: sus
            // acciones, y lo que él herede, se comprueban en él.
            if ($this->inControllers($file) && ! $parent['abstract']) {
                break;
            }

            $this->collectFrom($parent['fqcn'], false, $found, $names, $visited);
            $current = $parent;
        }

        return $found;
    }

    /**
     * @param  list<array{0: ClassOutline, 1: MethodOutline, 2: string}>  $found
     * @param  array<string, true>  $names
     * @param  array<string, true>  $visited
     */
    private function collectFrom(string $name, bool $trait, array &$found, array &$names, array &$visited): void
    {
        $key = strtolower($name);
        $outline = isset($visited[$key]) ? null : $this->classOutline($name);

        if ($outline === null) {
            return;
        }

        $visited[$key] = true;
        $file = $this->project->relative((string) $this->project->locate($name));

        // Los de un trait de Http/Controllers los cuenta controller-concern.
        if (! ($trait && $this->inControllers($file))) {
            foreach ($outline['methods'] as $method) {
                $lower = strtolower($method['name']);

                if (! isset($names[$lower]) && $method['body'] !== null) {
                    $names[$lower] = true;
                    $found[] = [$outline, $method, $file];
                }
            }
        }

        if ($trait) {
            foreach ($outline['traits'] as $nested) {
                $this->collectFrom($nested, true, $found, $names, $visited);
            }
        }
    }

    private function inControllers(string $relative): bool
    {
        return str_contains('/'.$relative, '/Http/Controllers/');
    }

    /**
     * handRolled() para una clase o un método que puede evaluarse varias
     * veces —lo heredan varios controladores, lo nombran varias rutas—: la
     * excepción se anota la primera vez. Con `$declare` a false sólo se mira
     * si lleva el atributo con razón: la clase de Http/Controllers ya lo
     * anotó en su turno.
     *
     * @param  list<AttributeOutline>  $attributes
     * @param  list<Finding>  $findings
     * @param  Closure(): string  $fingerprint
     */
    private function excusedElsewhere(array $attributes, string $what, string $target, string $file, array &$findings, Closure $fingerprint, bool $declare): bool
    {
        if (! $declare) {
            foreach ($attributes as $attribute) {
                if (strcasecmp($attribute['name'], HandRolled::class) === 0) {
                    return self::sanitise((string) PhpOutline::stringArgument($attribute['args'], 0, 'reason')) !== '';
                }
            }

            return false;
        }

        return $this->excusedElsewhere[strtolower($target)] ??= $this->handRolled($attributes, $what, $target, $file, $findings, $fingerprint);
    }

    /**
     * Las acciones de las rutas que no son ni un closure en línea ni un
     * controlador de Http/Controllers.
     *
     *  - Una clase del proyecto de fuera de Http/Controllers puesta como
     *    acción (`[Exportar::class, 'run']`, `Exportar::class`,
     *    `'…\Exportar@run'`, `Route::resource()`): para el router es un
     *    controlador, y su acción tiene que ser `return $request->handle();`
     *    como la de cualquiera. Antes quedaba fuera a propósito —«no siguen
     *    el patrón»—, y eso era la forma más corta de saltarse verify: el
     *    mismo controlador con lógica, en `Actions/`. Sólo se mira si el
     *    método enrutado está en el código del proyecto: un componente de
     *    Livewire o una página de Filament, cuyo `__invoke` viene de vendor,
     *    siguen fuera.
     *  - Una acción que no se puede leer —una variable, una función con
     *    nombre pasada como `f(...)`, un objeto, un closure dentro de un
     *    array— es un closure por otro camino: `route-closure`, como aviso.
     *
     * Sólo las llamadas sobre el router (`Route::…`, `$router->…`) en los
     * routes/*.php: una ruta registrada en otro sitio no se ve desde aquí.
     *
     * @param  list<Finding>  $findings
     * @param  array<string, array{0: string, 1: string, 2: string}>  $requests
     */
    private function checkRouteActions(array &$findings, array &$requests): void
    {
        $seen = [];

        foreach ($this->routeFiles() as $file) {
            $relative = $this->project->relative($file);
            $outline = PhpOutline::ofFile($file);

            foreach ($outline->callsTo([...self::ROUTE_METHODS, ...self::RESOURCE_METHODS]) as $call) {
                $root = strtolower($call['root']);

                if ($root !== '$router' && basename(str_replace('\\', '/', $root)) !== 'route') {
                    continue;
                }

                $name = strtolower($call['call']);
                $resource = in_array($name, self::RESOURCE_METHODS, true);
                $position = match (true) {
                    $name === 'fallback' => 0,
                    in_array($name, ['match', 'addroute'], true) => 2,
                    default => 1,
                };
                $target = self::routeTarget($outline, $call['named']['action'] ?? $call['named']['controller'] ?? $call['arguments'][$position] ?? []);

                if ($target === null) {
                    continue;
                }

                if ($target === []) {
                    $findings[] = $this->finding(Verifier::WARNING, self::ROUTE_CLOSURE, $relative,
                        "La ruta de la línea {$call['line']} ({$call['call']}()) lleva como acción algo que no se puede leer sin ejecutarlo —una variable, una función, un objeto o un closure dentro de un array—: es una acción sin controlador, como un closure, y nada de lo que verify comprueba en los controladores la alcanza. "
                        .'Llévala a un controlador cuya acción sea `return $request->handle();` con un FormRequest propio, y nómbralo en la ruta (`[Controlador::class, \'accion\']`).');

                    continue;
                }

                $this->checkRoutedClass($target['class'], $resource ? null : ($target['method'] ?? '__invoke'), $relative, $seen, $findings, $requests);
            }
        }
    }

    /**
     * La acción de una ruta: `['class' => …, 'method' => …|null]` si nombra
     * una clase, `[]` si no se puede leer, null si es un closure en línea
     * (route-closure ya lo cuenta) o no hay nada que mirar.
     *
     * @param  list<array{0: int|string, 1: string, 2: int}>  $action
     * @return array{class: string, method: string|null}|array{}|null
     */
    private static function routeTarget(PhpOutline $outline, array $action): ?array
    {
        if ($action === []) {
            return null;
        }

        $kinds = array_column($action, 0);
        $first = $kinds[0] === T_STATIC ? ($kinds[1] ?? null) : $kinds[0];

        if (in_array($first, [T_FUNCTION, T_FN], true)) {
            return null;
        }

        if (array_intersect($kinds, [T_FUNCTION, T_FN]) !== []) {
            return [];
        }

        $className = fn (array $tokens): ?string => count($tokens) === 3
            && in_array($tokens[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && $tokens[1][0] === T_DOUBLE_COLON
            && $tokens[2][0] === T_CLASS
                ? $outline->resolveName($tokens[0][1])
                : null;

        // 'Clase@metodo', o 'Clase' (invocable).
        if (count($action) === 1 && $action[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            [$class, $method] = [...explode('@', (string) PhpOutline::stringArgument($action, 0, ''), 2), null];

            return ['class' => ltrim((string) $class, '\\'), 'method' => $method];
        }

        if (($class = $className($action)) !== null) {
            return ['class' => $class, 'method' => null];
        }

        if (PhpOutline::is($action[0], '[') && PhpOutline::is($action[count($action) - 1], ']')) {
            $parts = PhpOutline::splitTopLevel(array_slice($action, 1, -1));
            $class = $className($parts[0] ?? []);
            $method = count($parts) === 2 ? PhpOutline::stringArgument($parts[1], 0, '') : null;

            if ($class !== null && (count($parts) === 1 || $method !== null)) {
                return ['class' => $class, 'method' => $method];
            }
        }

        // Lo que queda sólo es opaco si ejecuta o trae algo: una constante o
        // un literal suelto no son una acción que esconda nada.
        return array_intersect($kinds, [T_VARIABLE, T_NEW, '(']) !== [] ? [] : null;
    }

    /**
     * Una clase del proyecto de fuera de Http/Controllers que una ruta usa
     * de controlador: su acción enrutada (todas las públicas, con
     * `$method` null: un `Route::resource()`) y sus puertas laterales.
     *
     * @param  array<string, true>  $seen
     * @param  list<Finding>  $findings
     * @param  array<string, array{0: string, 1: string, 2: string}>  $requests
     */
    private function checkRoutedClass(string $fqcn, ?string $method, string $routes, array &$seen, array &$findings, array &$requests): void
    {
        $file = $this->project->locate($fqcn);
        $class = $file === null ? null : $this->classOutline($fqcn);
        $relative = $file === null ? '' : $this->project->relative($file);

        if ($class === null || $class['kind'] !== 'class' || $this->inControllers($relative)) {
            return;
        }

        $candidates = [];

        foreach ($class['methods'] as $own) {
            $candidates[] = [$class, $own, $relative];
        }

        foreach ([...$candidates, ...$this->inherited($class)] as [$owner, $candidate, $ownerFile]) {
            $side = $this->sideEntrance($candidate);
            $routed = $method === null ? $this->isAction($candidate) : strcasecmp($candidate['name'], $method) === 0 && $this->isAction($candidate);
            $key = strtolower("{$fqcn}::{$candidate['name']}");

            if (($side === null && ! $routed) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $where = "{$class['name']}::{$candidate['name']}()".($owner === $class ? '' : ", heredada de {$owner['name']} ({$ownerFile}),");

            $excused = $this->excusedElsewhere($class['attributes'], $class['name'], $class['fqcn'], $relative, $findings,
                fn (): string => HandRolledLock::classFingerprint($class), true);
            $excused = $this->excusedElsewhere($candidate['attributes'], "{$owner['name']}::{$candidate['name']}()", "{$owner['fqcn']}::{$candidate['name']}", $ownerFile, $findings,
                fn (): string => HandRolledLock::methodFingerprint($candidate), true) || $excused;

            if ($excused) {
                continue;
            }

            $context = "{$where} es la acción de una ruta de {$routes} aunque su clase no esté en Http/Controllers: para el router es un controlador.";

            if ($side !== null) {
                $findings[] = $this->finding(Verifier::ERROR, self::SHAPE, $relative, "{$context} (línea {$candidate['line']}) {$side}");

                continue;
            }

            $before = count($findings);
            $request = $this->delegatedRequest($candidate, $where, $relative, $findings);

            if ($request !== null) {
                $requests[strtolower($request)] ??= [$request, $where, $relative];
            } elseif (isset($findings[$before])) {
                $findings[$before]['message'] .= " Es la acción de una ruta de {$routes}: aunque su clase no esté en Http/Controllers, para el router es un controlador.";
            }
        }
    }

    /**
     * Un closure con cuerpo en routes/*.php es una acción sin controlador:
     * nada de lo que `verify` comprueba en los controladores lo alcanza. Es
     * aviso y no error porque es el patrón de la documentación de Laravel y
     * aparece en proyectos que nunca lo usaron como atajo; con --strict falla.
     *
     * Cuando es el fallback de la SPA que escribía laravel-setup <= 7.2.0
     * (SpaFallback), el mensaje dice que `larapack:fix` lo corrige.
     *
     * @param  list<Finding>  $findings
     */
    private function checkRouteClosures(array &$findings): void
    {
        foreach ($this->routeClosures() as $file => $closures) {
            $relative = $this->project->relative($file);
            $named = [];

            // El nombre y la huella con que se aprueba cada closure como
            // excepción del realineamiento (ExceptionTargets), por su línea.
            foreach ($closures === [] ? [] : ExceptionTargets::routeClosures($relative, (string) file_get_contents($file)) as $exception) {
                $named[$exception['call'].'@'.$exception['line']] = $exception;
                $this->closures[$exception['target']] = true;
            }

            foreach ($closures as ['closure' => $closure, 'spa' => $spa]) {
                $message = self::routeClosureMessage($closure, $spa);
                $exception = $named[strtolower($closure['call']).'@'.$closure['line']] ?? null;
                $approved = $exception === null ? null : ($this->approved[$exception['target']] ?? null);

                if ($exception !== null && $approved !== null && $approved['fingerprint'] === $exception['fingerprint']) {
                    $findings[] = $this->finding(Verifier::INFO, self::ROUTE_CLOSURE, $relative,
                        "La ruta de la línea {$closure['line']} lleva su acción en un closure ({$closure['call']}()), escrito así a propósito: «".self::sanitise($approved['reason']).'». Excepción aprobada por el dueño del proyecto en '.HandRolledLock::PATH." ({$exception['target']}).");

                    continue;
                }

                if ($exception !== null && $approved !== null) {
                    $message .= " La excepción aprobada {$exception['target']} era de otro código: el closure cambió y ya no lo cubre. El dueño del proyecto la vuelve a aprobar (larapack:hand-rolled approve) o la ruta se lleva a un controlador.";
                }

                $findings[] = $this->finding(Verifier::WARNING, self::ROUTE_CLOSURE, $relative, $message);
            }
        }
    }

    /**
     * Los closures que `route-closure` marca en cada routes/*.php del proyecto
     * y de sus paquetes locales, por archivo; también los archivos sin
     * ninguno, con la lista vacía. `spa` dice si es el fallback de la SPA de
     * laravel-setup <= 7.2.0, que sólo se reconoce en el routes/web.php de la
     * raíz: es donde lo escribía, y donde SpaController es de la aplicación.
     *
     * @return array<string, list<array{closure: ClosureOutline, spa: bool}>>
     */
    public function routeClosures(): array
    {
        $result = [];
        $web = $this->project->root().'/routes/web.php';

        foreach ($this->routeFiles() as $file) {
            $code = (string) file_get_contents($file);
            $spa = $file === $web ? SpaFallback::fallback($code) : null;
            $result[$file] = array_map(
                fn (array $closure): array => [
                    'closure' => $closure,
                    'spa' => $spa !== null && $closure['call'] === 'fallback' && $closure['line'] === $spa['line'],
                ],
                self::flaggedClosures($code)
            );
        }

        return $result;
    }

    /**
     * Los closures con cuerpo que se pasan a una ruta en este código. No se
     * cuenta el que sólo pinta una vista —`return view('welcome');`, el
     * routes/web.php con el que nace toda aplicación—: equivale a
     * Route::view() y no tiene dónde esconder lógica. Tampoco los closures de
     * group(), que agrupan rutas y no son una acción.
     *
     * @return list<ClosureOutline>
     */
    public static function flaggedClosures(string $code): array
    {
        return array_values(array_filter(
            PhpOutline::ofCode($code)->closuresPassedTo(self::ROUTE_METHODS),
            fn (array $closure): bool => ! self::onlyRendersAView($closure)
        ));
    }

    /**
     * El mensaje de `route-closure`, que `larapack:fix route-closure` repite
     * como razón de lo que no corrige.
     *
     * @param  ClosureOutline  $closure
     */
    public static function routeClosureMessage(array $closure, bool $spa): string
    {
        $message = "La ruta de la línea {$closure['line']} lleva su acción en un closure ({$closure['call']}()): es una acción sin controlador, y nada de lo que verify comprueba en los controladores la alcanza. ";

        if ($spa) {
            return $message.'Es el fallback de la SPA que escribía laravel-setup hasta la 7.2.0: larapack:fix lo corrige (`larapack:fix route-closure` lo lleva a SpaController y SpaRequest, como laravel-setup 7.2.1).';
        }

        return $message.'Llévala a un controlador cuya acción sea `return $request->handle();` con un FormRequest propio (o usa Route::view() si sólo pinta una vista).';
    }

    /**
     * @param  ClosureOutline  $closure
     */
    private static function onlyRendersAView(array $closure): bool
    {
        $body = $closure['body'];

        if (! $closure['arrow']) {
            if (($body[0][0] ?? null) !== T_RETURN || ! PhpOutline::is($body[count($body) - 1] ?? null, ';')) {
                return false;
            }

            $body = array_slice($body, 1, -1);
        }

        return $closure['params'] === []
            && count($body) === 4
            && $body[0][0] === T_STRING && strtolower($body[0][1]) === 'view'
            && PhpOutline::is($body[1], '(')
            && $body[2][0] === T_CONSTANT_ENCAPSED_STRING
            && PhpOutline::is($body[3], ')');
    }

    /**
     * @return list<string>
     */
    private function routeFiles(): array
    {
        $files = [];

        // Un directorio que no existe no tiene archivos.
        foreach ($this->project->roots() as $root) {
            $files = [...$files, ...$this->project->phpFiles($root.'/routes')];
        }

        sort($files);

        return $files;
    }

    /**
     * Una acción es lo que el router puede llamar: un método público con
     * cuerpo. Los heredados los trae checkInherited(), y los de un trait de
     * Http/Controllers los cuenta controller-concern.
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
     * @param  list<Finding>  $findings
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
            || ! PhpOutline::is($body[4], '(')
            || ! PhpOutline::is($body[$count - 2], ')')
            || ! PhpOutline::is($body[$count - 1], ';')
            || $body[1][1] === '$this'
        ) {
            return null;
        }

        foreach (PhpOutline::splitTopLevel(array_slice($body, 5, $count - 7)) as $argument) {
            $named = count($argument) === 3 && $argument[0][0] === T_STRING && PhpOutline::is($argument[1], ':');
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

        if (($body[0][0] ?? null) === T_RETURN && strtolower($body[3][1] ?? '') === 'handle' && PhpOutline::is($body[4] ?? null, '(')) {
            $reasons[] = 'pasa a handle() algo que no es una variable: esa expresión es trabajo hecho en el controlador';
        }

        foreach ($body as $index => $token) {
            if ($token[0] === T_STRING && strtolower($token[1]) === 'validate' && PhpOutline::is($body[$index + 1] ?? null, '(')) {
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
            .'Marcarla como escrita a mano (#[HandRolled]) no es un arreglo: es una excepción que sólo aprueba el dueño del proyecto, y verify la sigue señalando.';
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
     * @param  list<Finding>  $findings
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

            if (! $this->handRolled($method['attributes'], "{$trait['name']}::{$method['name']}()", "{$trait['fqcn']}::{$method['name']}", $file, $findings, fn (): string => HandRolledLock::methodFingerprint($method))) {
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
     * @param  list<Finding>  $findings
     */
    private function checkRequestHandles(string $request, string $where, string $controller, array &$findings): void
    {
        $handles = $this->handles($request, []);
        $file = $this->project->locate($request);
        $relative = $file === null ? $controller : $this->project->relative($file);

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
            return $this->handles[$key] = $this->project->owns($class) ? false : null;
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
        $file = $this->project->locate($class);

        foreach ($file === null ? [] : $this->outline($file) as $outline) {
            if (strcasecmp($outline['fqcn'], $class) === 0) {
                return $outline;
            }
        }

        return null;
    }

    /**
     * Si la clase o el método llevan #[HandRolled]: true si excusa (tiene
     * razón), false si no lo lleva o no tiene razón (y entonces es error).
     *
     * Excusar la forma no es aprobar. Sin candado, se lista como aviso: así
     * --strict falla mientras la excepción exista, y quien la añade no puede
     * dejar el proyecto en verde con una línea. Con candado, se informa si el
     * dueño la aprobó con esta misma razón y este mismo código, y si no, es
     * error: la forma no se cuenta dos veces, pero verify falla igual.
     *
     * @param  list<AttributeOutline>  $attributes
     * @param  string  $target  `Clase` o `Clase::metodo`, con el nombre completo
     * @param  list<Finding>  $findings
     * @param  Closure(): string  $fingerprint  la huella del código que excusa
     */
    private function handRolled(array $attributes, string $what, string $target, string $file, array &$findings, Closure $fingerprint): bool
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute['name'], HandRolled::class) !== 0) {
                continue;
            }

            $reason = self::sanitise((string) PhpOutline::stringArgument($attribute['args'], 0, 'reason'));
            $this->declared[$target] = ['reason' => $reason, 'fingerprint' => $fingerprint(), 'file' => $file, 'line' => $attribute['line']];

            if ($reason === '') {
                $findings[] = $this->finding(Verifier::ERROR, self::HAND_ROLLED, $file,
                    "{$what} (línea {$attribute['line']}) lleva #[HandRolled] sin razón. La razón es obligatoria y tiene que ser una cadena literal: es lo que permite revisar la excepción sin leer el código. Sin ella no excusa nada.");

                return false;
            }

            $findings[] = $this->approval($what, $target, $reason, $this->declared[$target]['fingerprint'], $attribute['line'], $file);

            return true;
        }

        return false;
    }

    /**
     * El hallazgo de una excepción con razón, según el candado.
     *
     * El mensaje de la que no está aprobada nombra el objetivo —es lo que el
     * dueño aprueba— pero no se la ofrece al agente como arreglo: le dice que
     * no es suya.
     *
     * @return Finding
     */
    private function approval(string $what, string $target, string $reason, string $fingerprint, int $line, string $file): array
    {
        $where = "{$what} (línea {$line})";

        if ($this->approved === null) {
            return $this->finding(Verifier::WARNING, self::HAND_ROLLED, $file,
                "{$where} escrito a mano a propósito: «{$reason}». Es una excepción al patrón que aprueba el dueño del proyecto; si no la aprobó, devuelve la acción a `return \$request->handle();`.");
        }

        $approved = $this->approved[$target] ?? null;

        if ($approved !== null && $approved['reason'] === $reason && $approved['fingerprint'] === $fingerprint) {
            return $this->finding(Verifier::INFO, self::HAND_ROLLED, $file,
                "{$where} escrito a mano a propósito: «{$reason}». Aprobada por el dueño del proyecto en ".HandRolledLock::PATH.'.');
        }

        $notYours = 'Sólo el dueño del proyecto aprueba excepciones: devuelve la acción a `return $request->handle();` o pídele que la revise. No edites '.HandRolledLock::PATH.' ni corras la aprobación.';

        if ($approved === null) {
            return $this->finding(Verifier::ERROR, self::HAND_ROLLED_UNAPPROVED, $file,
                "{$where} lleva #[HandRolled] («{$reason}») y no está aprobada en ".HandRolledLock::PATH." ({$target}). {$notYours}");
        }

        if ($approved['reason'] !== $reason) {
            return $this->finding(Verifier::ERROR, self::HAND_ROLLED_UNAPPROVED, $file,
                "{$where} cambió la razón de su #[HandRolled]: se aprobó «".self::sanitise($approved['reason'])."» y ahora dice «{$reason}». La aprobación era de la otra razón ({$target}). {$notYours}");
        }

        if ($approved['fingerprint'] === null) {
            return $this->finding(Verifier::ERROR, self::HAND_ROLLED_UNAPPROVED, $file,
                "{$where} está en ".HandRolledLock::PATH." sin la huella del código aprobado ({$target}): la aprobación no dice qué código cubre, y no cuenta. {$notYours}");
        }

        $scope = str_contains($target, '::')
            ? 'su firma o su cuerpo'
            : 'sus métodos —uno nuevo también—, su padre o sus traits';

        return $this->finding(Verifier::ERROR, self::HAND_ROLLED_UNAPPROVED, $file,
            "{$where} cambió desde que se aprobó: el código aprobado ya no es el mismo (cambió {$scope}; la huella de ".HandRolledLock::PATH." no coincide). La aprobación era de aquel código ({$target}). {$notYours}");
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
     * @return list<ClassOutline>
     */
    private function outline(string $file): array
    {
        return $this->outlines[$file] ??= PhpOutline::ofFile($file)->classes();
    }

    /**
     * @return Finding
     */
    private function finding(string $level, string $check, string $file, string $message): array
    {
        return ['level' => $level, 'check' => $check, 'model' => null, 'file' => $file, 'message' => $message];
    }
}
