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
 * un parámetro tipado con un FormRequest propio, o no lo es. Lo que de verdad
 * no puede serlo se declara con #[HandRolled('razón')] y se lista.
 *
 * @phpstan-import-type ClassOutline from PhpOutline
 * @phpstan-import-type MethodOutline from PhpOutline
 * @phpstan-import-type AttributeOutline from PhpOutline
 */
final class ControllerShape
{
    public const SHAPE = 'controller-shape';

    public const REQUEST_HANDLE = 'request-handle';

    public const CONCERN = 'controller-concern';

    public const HAND_ROLLED = 'hand-rolled';

    public const CHECKS = [self::SHAPE, self::REQUEST_HANDLE, self::CONCERN, self::HAND_ROLLED];

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

    private string $root;

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
                    if (! $this->isAction($method)) {
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

        return $findings;
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

    private function remedy(): string
    {
        return 'La acción es `return $request->handle();` con un FormRequest propio: authorize() autoriza, rules() valida y handle() hace el trabajo (o lo delega en la operación del modelo o en un soporte). '
            .'Si de verdad no puede serlo —un webhook firmado, el canje de un token OAuth—, márcala con #[\\'.HandRolled::class."('razón')].";
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

    private function ownedByProject(string $class): bool
    {
        foreach (array_keys($this->autoload) as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function locate(string $class): ?string
    {
        $prefixes = array_keys($this->autoload);

        // El prefijo más largo primero: `App\Models\` gana a `App\`.
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && ! str_starts_with($class, $prefix)) {
                continue;
            }

            foreach ($this->autoload[$prefix] as $directory) {
                $file = $directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                if (is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Si la clase o el método llevan #[HandRolled]: true si excusa (tiene
     * razón, y se lista como info), false si no lo lleva o no tiene razón (y
     * entonces es error).
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

            $reason = trim((string) PhpOutline::stringArgument($attribute['args'], 0, 'reason'));

            if ($reason === '') {
                $findings[] = $this->finding(Verifier::ERROR, self::HAND_ROLLED, $file,
                    "{$what} (línea {$attribute['line']}) lleva #[HandRolled] sin razón. La razón es obligatoria y tiene que ser una cadena literal: es lo que permite revisar la excepción sin leer el código. Sin ella no excusa nada.");

                return false;
            }

            $findings[] = $this->finding(Verifier::INFO, self::HAND_ROLLED, $file, "{$what} (línea {$attribute['line']}) escrito a mano a propósito: {$reason}");

            return true;
        }

        return false;
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
            $psr4 = (array) ($this->composer($real)['autoload']['psr-4'] ?? []);

            if ($psr4 === []) {
                $psr4 = ['' => ['app', 'src']];
            }

            foreach ($psr4 as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    $absolute = realpath($real.'/'.$directory);

                    if ($absolute === false || ! is_dir($absolute)) {
                        continue;
                    }

                    $absolute = str_replace('\\', '/', $absolute);

                    if ($prefix !== '') {
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
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $file): bool => ! $file->isDir()
                    || ! (in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true) || str_starts_with($file->getFilename(), '.'))
            ));

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                $path = str_replace('\\', '/', $file->getPathname());

                if ($file->getExtension() === 'php' && str_contains(substr($path, strlen($source)), '/Http/Controllers/')) {
                    $files[(string) ($file->getRealPath() ?: $path)] = $path;
                }
            }
        }

        $files = array_values($files);
        sort($files);

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
