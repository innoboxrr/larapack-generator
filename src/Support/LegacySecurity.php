<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Los dos agujeros que las plantillas ya cerraron, buscados en el código que
 * se generó antes de que los cerraran.
 *
 * Regenerar arregla lo nuevo, pero un proyecto generado con una versión
 * anterior conserva sus IndexRequests y sus Resources tal como salieron, y
 * `verify` compara con el hash de lo que hay en disco: no ve nada que
 * cambiar. Peor, un agente que copia uno de esos archivos —o que quita la
 * protección de uno nuevo— propaga el patrón viejo sin que nada se queje.
 *
 *  - index-managed: una request que lista con el Builder de SearchSurge y no
 *    fuerza `managed` en prepareForValidation(). ManagedFilter::canView()
 *    sólo acota si llega `managed`, y si lo manda el cliente, omitirlo lista
 *    todas las filas.
 *  - resource-appends: un Resource que pasa lo que manda el cliente a
 *    setAppends() sin cruzarlo con una lista blanca. El cliente decide qué
 *    accessor se ejecuta en cada fila, y uno que no existe da un 500.
 *
 * Como ControllerShape, no depende del manifiesto: se recorren las
 * `Http/Requests` y `Http/Resources` del proyecto y de sus paquetes locales
 * (ProjectSources), los haya generado LaraPack o no. Es aviso y no error: el
 * código viejo funciona, y arreglarlo es trabajo del proyecto, no algo que
 * `verify` pueda exigir de golpe a quien actualiza. `larapack:fix` lo arregla
 * de forma determinista a partir de inspect(), que da lo mismo que run() pero
 * con la clase y el método de cada caso en vez del mensaje.
 *
 * @phpstan-import-type ClassOutline from PhpOutline
 * @phpstan-import-type MethodOutline from PhpOutline
 * @phpstan-import-type Token from PhpOutline
 *
 * @phpstan-type Debt array{check: string, file: string, class: string, fqcn: string, line: int, method: string|null, extends: string|null, traits: list<string>}
 *
 * @phpstan-import-type Finding from Verifier
 */
final class LegacySecurity
{
    public const INDEX_MANAGED = 'index-managed';

    public const RESOURCE_APPENDS = 'resource-appends';

    public const CHECKS = [self::INDEX_MANAGED, self::RESOURCE_APPENDS];

    private const FORM_REQUEST = 'illuminate\foundation\http\formrequest';

    /**
     * Lo que convierte lo que pidió el cliente en un subconjunto de una
     * lista conocida.
     */
    private const SANITIZERS = ['array_intersect', 'array_intersect_key', 'array_intersect_assoc', 'in_array'];

    private ProjectSources $project;

    /**
     * Las clases de Http/Requests por nombre completo en minúsculas, para
     * seguir la herencia hasta una request base.
     *
     * @var array<string, array{0: ClassOutline, 1: string}>
     */
    private array $requests = [];

    /**
     * $overlay es ruta => código que se lee en lugar del archivo: lo que
     * `larapack:fix` va a escribir, comprobado antes de escribirlo.
     *
     * @param  array<string, string>  $overlay
     */
    public function __construct(string $root, private array $overlay = [])
    {
        $this->project = new ProjectSources($root);
    }

    public function project(): ProjectSources
    {
        return $this->project;
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];

        foreach ($this->inspect()['debt'] as $debt) {
            $findings[] = $debt['check'] === self::INDEX_MANAGED
                ? $this->finding($debt,
                    "{$debt['class']} (línea {$debt['line']}) lista con el Builder de SearchSurge y no fuerza `managed` en prepareForValidation(): ManagedFilter::canView() sólo acota si llega `managed`, y quien lo omite en la petición ve todas las filas. "
                    ."Añade `\$this->merge(['managed' => true]);` en prepareForValidation(), como la plantilla actual del IndexRequest. Con `merge` gana sobre lo que mande el cliente; `mergeIfMissing` no. `larapack:fix index-managed` lo hace.")
                : $this->finding($debt,
                    "{$debt['class']}::{$debt['method']}() (línea {$debt['line']}) pasa a setAppends() lo que manda el cliente sin cruzarlo con una lista blanca: el cliente decide qué accessor del modelo se ejecuta en cada fila, también los de uso interno, y uno que no existe tumba la respuesta. "
                    .'Declara los permitidos en `protected array $appendable = [...]` y pasa sólo `array_intersect($this->appendable, $appends)`, sumado a getAppends(), como la plantilla actual del Resource. `larapack:fix resource-appends` lo hace.');
        }

        return $findings;
    }

    /**
     * @param  Debt  $debt
     * @return Finding
     */
    private function finding(array $debt, string $message): array
    {
        return ['level' => Verifier::WARNING, 'check' => $debt['check'], 'model' => null, 'file' => $this->project->relative($debt['file']), 'message' => $message];
    }

    /**
     * Lo que run() avisa, con la clase y el método de cada caso, y por
     * comprobación los archivos que se miraron y están bien: los IndexRequest
     * que listan con SearchSurge y ya fuerzan `managed` (o heredan de una
     * request que no se ve) y los Resources que llaman a setAppends() con una
     * lista blanca.
     *
     * @return array{debt: list<Debt>, clean: array<string, list<string>>}
     */
    public function inspect(): array
    {
        $debt = [];
        $clean = [self::INDEX_MANAGED => [], self::RESOURCE_APPENDS => []];
        $builders = [];
        $this->requests = [];

        foreach ($this->project->files('/Http/Requests/') as $file) {
            $code = $this->read($file);
            $usesSearchSurge = str_contains($code, 'SearchSurge\\Search\\Builder');

            foreach (PhpOutline::ofCode($code)->classes() as $class) {
                if ($class['kind'] !== 'class') {
                    continue;
                }

                $this->requests[strtolower($class['fqcn'])] = [$class, $file];

                if ($usesSearchSurge && $this->buildsQuery($class)) {
                    $builders[] = [$class, $file];
                }
            }
        }

        foreach ($builders as [$class, $file]) {
            if ($this->forcesManaged($class, []) === false) {
                $debt[] = $this->debt(self::INDEX_MANAGED, $file, $class, $class['line'], null);
            } else {
                $clean[self::INDEX_MANAGED][] = $file;
            }
        }

        foreach ($this->project->files('/Http/Resources/') as $file) {
            $code = $this->read($file);
            $flagged = false;

            foreach (PhpOutline::ofCode($code)->classes() as $class) {
                foreach ($class['methods'] as $method) {
                    $line = $this->unfilteredAppends($method);

                    if ($line !== null) {
                        $debt[] = $this->debt(self::RESOURCE_APPENDS, $file, $class, $line, $method['name']);
                        $flagged = true;
                    }
                }
            }

            if (! $flagged && stripos($code, 'setAppends') !== false) {
                $clean[self::RESOURCE_APPENDS][] = $file;
            }
        }

        return ['debt' => $debt, 'clean' => array_map(fn (array $files): array => array_values(array_unique($files)), $clean)];
    }

    /**
     * La visibilidad del prepareForValidation() que la clase hereda de una
     * request del proyecto: null si no hereda ninguno antes de FormRequest
     * (cuyo método está vacío), 'unknown' si la herencia sale del código que
     * se ve. Sólo vale después de inspect().
     */
    public function inheritedPrepare(string $fqcn): ?string
    {
        $visited = [];
        $class = $this->requests[strtolower($fqcn)][0] ?? null;

        while ($class !== null) {
            $parent = strtolower((string) $class['extends']);

            if ($parent === '' || $parent === self::FORM_REQUEST) {
                return null;
            }

            if (isset($visited[$parent]) || ! isset($this->requests[$parent])) {
                return 'unknown';
            }

            $visited[$parent] = true;
            $class = $this->requests[$parent][0];

            foreach ($class['methods'] as $method) {
                if (strcasecmp($method['name'], 'prepareForValidation') === 0) {
                    return $method['visibility'];
                }
            }
        }

        return 'unknown';
    }

    /**
     * @param  ClassOutline  $class
     * @return Debt
     */
    private function debt(string $check, string $file, array $class, int $line, ?string $method): array
    {
        return [
            'check' => $check,
            'file' => $file,
            'class' => $class['name'],
            'fqcn' => $class['fqcn'],
            'line' => $line,
            'method' => $method,
            'extends' => $class['extends'],
            'traits' => $class['traits'],
        ];
    }

    private function read(string $file): string
    {
        return $this->overlay[$file] ?? (string) file_get_contents($file);
    }

    // INDEX-MANAGED

    /**
     * Si algún método de la clase hace `new Builder`. El archivo ya importa o
     * nombra el de SearchSurge; un IndexRequest escrito a mano que consulta
     * con Eloquent no tiene ManagedFilter que activar.
     *
     * @param  ClassOutline  $class
     */
    private function buildsQuery(array $class): bool
    {
        foreach ($class['methods'] as $method) {
            $body = $method['body'] ?? [];

            foreach ($body as $index => $token) {
                $next = $body[$index + 1] ?? null;

                if ($token[0] === T_NEW && $next !== null
                    && in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && strcasecmp((string) preg_replace('/^.*\\\\/', '', $next[1]), 'Builder') === 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si prepareForValidation() —el suyo o el heredado— fuerza
     * `managed`; false si se sabe que no; null si la herencia sale del código
     * que se ve (una request base de vendor) y no se puede saber sin cargarla.
     *
     * @param  ClassOutline  $class
     * @param  array<string, true>  $visited
     */
    private function forcesManaged(array $class, array $visited): ?bool
    {
        $key = strtolower($class['fqcn']);

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;

        foreach ($class['methods'] as $method) {
            if (strcasecmp($method['name'], 'prepareForValidation') !== 0) {
                continue;
            }

            $body = $method['body'] ?? [];

            if ($this->mergesManaged($body)) {
                return true;
            }

            return $this->callsParent($body) ? $this->parentForcesManaged($class, $visited) : false;
        }

        return $this->parentForcesManaged($class, $visited);
    }

    /**
     * @param  ClassOutline  $class
     * @param  array<string, true>  $visited
     */
    private function parentForcesManaged(array $class, array $visited): ?bool
    {
        $parent = strtolower((string) $class['extends']);

        if ($parent === '' || $parent === self::FORM_REQUEST) {
            return false;
        }

        return isset($this->requests[$parent]) ? $this->forcesManaged($this->requests[$parent][0], $visited) : null;
    }

    /**
     * `merge([... 'managed' => true ...])` o `replace(...)`, con true, 1, '1'
     * o 'true'. `mergeIfMissing` no cuenta: deja ganar al cliente.
     *
     * @param  list<Token>  $body
     */
    private function mergesManaged(array $body): bool
    {
        foreach ($body as $index => $token) {
            if ($token[0] !== T_STRING
                || ! in_array(strtolower($token[1]), ['merge', 'replace'], true)
                || ($body[$index + 1][0] ?? null) !== '('
            ) {
                continue;
            }

            $depth = 0;

            for ($j = $index + 1, $count = count($body); $j < $count; $j++) {
                $current = $body[$j][0];

                if (in_array($current, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($current, [')', ']', '}'], true) && --$depth === 0) {
                    break;
                }

                if ($current === T_CONSTANT_ENCAPSED_STRING
                    && trim($body[$j][1], '\'"') === 'managed'
                    && ($body[$j + 1][0] ?? null) === T_DOUBLE_ARROW
                    && $this->isTrue($body[$j + 2] ?? null)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Token|null  $token
     */
    private function isTrue(?array $token): bool
    {
        if ($token === null) {
            return false;
        }

        $value = strtolower(trim($token[1], '\'"'));

        return in_array($token[0], [T_STRING, T_LNUMBER, T_CONSTANT_ENCAPSED_STRING], true)
            && in_array($value, ['true', '1'], true);
    }

    /**
     * @param  list<Token>  $body
     */
    private function callsParent(array $body): bool
    {
        foreach ($body as $index => $token) {
            if ($token[0] === T_STRING && strtolower($token[1]) === 'parent'
                && ($body[$index + 1][0] ?? null) === T_DOUBLE_COLON
                && strcasecmp($body[$index + 2][1] ?? '', 'prepareForValidation') === 0
            ) {
                return true;
            }
        }

        return false;
    }

    // RESOURCE-APPENDS

    /**
     * La línea de un setAppends() cuyo argumento sale de la petición sin pasar
     * por una lista blanca, o null.
     *
     * Se sigue la asignación a variables dentro del método —`$appends =
     * $request->input('appends'); ...setAppends($appends)`—, que es como lo
     * escribe la plantilla actual, pero no lo que pasa por otros métodos: lo
     * que no se ve se da por bueno antes que avisar en falso.
     *
     * @param  MethodOutline  $method
     */
    private function unfilteredAppends(array $method): ?int
    {
        $body = $method['body'] ?? [];

        if ($body === []) {
            return null;
        }

        // Variable => si lleva datos de la petición sin filtrar. Los
        // parámetros de toArray() son la petición. Por su nombre exacto: en
        // PHP `$Appends` y `$appends` son dos variables, y confundirlas
        // avisaba de un setAppends() que recibía la que estaba limpia.
        $tainted = [];

        foreach ($method['params'] as $param) {
            $type = strtolower((string) $param['type']);

            if (str_ends_with($type, 'request') || ($param['type'] === null && strtolower($param['name']) === '$request')) {
                $tainted[$param['name']] = true;
            }
        }

        foreach ($this->statements($body) as $statement) {
            if (count($statement) > 2 && $statement[0][0] === T_VARIABLE && $statement[1][0] === '=') {
                $tainted[$statement[0][1]] = $this->carriesInput(array_slice($statement, 2), $tainted);
            }

            foreach ($statement as $index => $token) {
                if ($token[0] !== T_STRING || strcasecmp($token[1], 'setAppends') !== 0 || ($statement[$index + 1][0] ?? null) !== '(') {
                    continue;
                }

                if ($this->carriesInput($this->arguments($statement, $index + 1), $tainted)) {
                    return $token[2];
                }
            }
        }

        return null;
    }

    /**
     * Si la expresión lleva algo de la petición y no pasa por un filtro.
     *
     * @param  list<Token>  $tokens
     * @param  array<string, bool>  $tainted
     */
    private function carriesInput(array $tokens, array $tainted): bool
    {
        $input = false;

        foreach ($tokens as $index => $token) {
            if ($token[0] === T_STRING && in_array(strtolower($token[1]), self::SANITIZERS, true)) {
                return false;
            }

            $call = ($tokens[$index + 1][0] ?? null) === '(' || ($tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON;

            $input = $input
                || ($token[0] === T_VARIABLE && ($tainted[$token[1]] ?? false))
                || ($token[0] === T_STRING && $call && strtolower($token[1]) === 'request' && ! $this->isMethodCall($tokens, $index));
        }

        return $input;
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function isMethodCall(array $tokens, int $index): bool
    {
        return in_array($tokens[$index - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    /**
     * Lo que va entre los paréntesis que abren en $open.
     *
     * @param  list<Token>  $tokens
     * @return list<Token>
     */
    private function arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $inner = [];

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $current = $tokens[$i][0];

            if (in_array($current, ['(', '[', '{'], true) && $depth++ === 0) {
                continue;
            }

            if (in_array($current, [')', ']', '}'], true) && --$depth === 0) {
                break;
            }

            $inner[] = $tokens[$i];
        }

        return $inner;
    }

    /**
     * El cuerpo partido en sentencias por los `;`, `{` y `}`: basta para ver
     * asignaciones y llamadas en el orden en que se escriben.
     *
     * @param  list<Token>  $body
     * @return list<list<Token>>
     */
    private function statements(array $body): array
    {
        $statements = [];
        $current = [];
        $depth = 0;

        foreach ($body as $token) {
            if (in_array($token[0], ['(', '['], true)) {
                $depth++;
            } elseif (in_array($token[0], [')', ']'], true)) {
                $depth--;
            }

            if ($depth === 0 && in_array($token[0], [';', '{', '}'], true)) {
                if ($current !== []) {
                    $statements[] = $current;
                }

                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $statements[] = $current;
        }

        return $statements;
    }
}
