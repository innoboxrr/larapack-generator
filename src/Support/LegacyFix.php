<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use ParseError;
use PhpToken;
use RuntimeException;

/**
 * El arreglo determinista de lo que LegacySecurity avisa: deja cada
 * IndexRequest y cada Resource como los escribe hoy la plantilla.
 *
 * Un proyecto generado con versiones anteriores tiene cientos de archivos con
 * la misma deuda —school-management, 524 IndexRequests y 691 Resources—, y
 * que un agente los edite uno a uno es lento, caro y no sale dos veces igual.
 * Aquí el cambio es el mismo en todos y sale de la plantilla.
 *
 *  - index-managed: `$this->merge(['managed' => true])` al principio de
 *    prepareForValidation(), con el comentario de la plantilla. Si el cuerpo
 *    es sólo el `//` que dejaba la plantilla, lo sustituye; si tiene más, se
 *    conserva detrás (también `parent::`). Si el método no existe, se crea
 *    delante del primer método de la clase, con la visibilidad del heredado
 *    y llamando al padre si hereda uno del proyecto.
 *  - resource-appends: el `if (...) { $this->resource->setAppends(<lo que
 *    pidió el cliente>); }` se sustituye por el bloque de la plantilla —sólo
 *    lo que está en `$appendable`, sumado a getAppends()— y se declara
 *    `protected array $appendable = [];` si la clase no la tiene.
 *
 * Se trabaja sobre tokens, no con expresiones regulares sobre el archivo: se
 * localiza la clase, el método y la sentencia exactos, y se reemplaza ese
 * tramo del texto. Lo que no tiene una forma reconocida con certeza no se
 * toca: se devuelve como `skipped` con la razón. Y nada se da por arreglado
 * hasta que el resultado parsea y LegacySecurity, leyéndolo, ya no lo marca.
 * Como lo que ya está bien no se marca, correrlo dos veces no cambia nada la
 * segunda.
 *
 * Sólo toca lo que viene del generador: reescribe prepareForValidation() y
 * el bloque setAppends() cuando tienen la forma generada; si no, `skipped`.
 * La lógica propia de alrededor —otros métodos, sentencias propias en
 * prepareForValidation(), lo que haya en toArray()— queda igual.
 *
 * Un `$appendable` vacío deja de añadir lo que pida el cliente: es lo
 * conservador, y la interfaz que pedía accessors con `appends[]` tiene que
 * declararlos en la lista. Del PHP no se deducen; AppendableProposals los lee
 * del front y los PROPONE en el reporte, sin escribirlos nunca.
 *
 * Y se reporta, sin arreglar nada, lo que ManagedFilterAudit encuentra: los
 * ManagedFilter cuyo canView() no tiene camino para el administrador, que con
 * `managed` forzado le dejarían el listado vacío.
 *
 * @phpstan-import-type Debt from LegacySecurity
 * @phpstan-import-type AdminPathMissing from ManagedFilterAudit
 * @phpstan-import-type FilterUnresolved from ManagedFilterAudit
 * @phpstan-import-type Proposals from AppendableProposals
 *
 * @phpstan-type Fixed array{file: string, diff?: string}
 * @phpstan-type Skipped array{file: string, reason: string}
 * @phpstan-type MethodWithBody array{start: int, open: int, close: int, params: list<string>}
 * @phpstan-type Method MethodWithBody|array{start: int, open: null, close: null, params: list<string>}
 * @phpstan-type CheckReport array{fixed: list<Fixed>, skipped: list<Skipped>, unchanged: int, admin_path_missing?: list<AdminPathMissing>, managed_filter_unresolved?: list<FilterUnresolved>, appendable_proposals?: Proposals}
 */
final class LegacyFix
{
    private const JSON_RESOURCE = 'illuminate\http\resources\json\jsonresource';

    /**
     * El cuerpo de prepareForValidation() en src/Stubs/Requests/IndexRequest.txt,
     * sin sangría. Una prueba comprueba que sigue siendo el de la plantilla.
     */
    public const MANAGED_LINES = [
        '// La restricción de ManagedFilter::canView() sólo se aplica si llega',
        '// `managed`, y llegaba del cliente: quien lo omitía listaba todas las',
        '// filas, también las que canView() le oculta. Se fuerza aquí, mande lo',
        '// que mande la petición.',
        '//',
        '// `except_view_any` no se fuerza, y el frontend generado no lo manda:',
        '// en el listado canView() acota a todos, también al administrador (el',
        '// before() de la política no la salta, sólo afecta a can()). Por eso',
        '// canView() tiene que cubrir el caso administrador él mismo, p. ej.',
        "// devolviendo \$query sin tocar si \$user->can('viewAny', ...). La",
        '// exportación sí fuerza `except_view_any`: si canView() no deja pasar',
        '// al administrador, exporta filas que el listado le oculta. Que un',
        '// cliente lo mande aquí no amplía nada: sólo salta la restricción a',
        '// quien ya tiene viewAny.',
        '$this->merge([',
        "    'managed' => true,",
        ']);',
    ];

    /**
     * La propiedad de src/Stubs/Resource/ResourceTemplate.txt, con su
     * comentario.
     */
    public const APPENDABLE_LINES = [
        '/**',
        ' * Los accessors que el cliente puede pedir con `appends[]`.',
        ' *',
        ' * Antes se añadía cualquiera que llegara: el cliente decidía qué accessor',
        ' * del modelo se ejecutaba, en cada fila del listado, aunque fuera de uso',
        ' * interno —una URL firmada, un dato descifrado, una consulta cara—, y un',
        ' * nombre que no existía tumbaba la respuesta con un 500. Escribe aquí los',
        ' * que la interfaz necesita; lo que no esté en la lista se ignora.',
        ' *',
        ' * @var array<int, string>',
        ' */',
        'protected array $appendable = [];',
    ];

    /**
     * El bloque de toArray() de la plantilla. `%s` es de dónde se lee la
     * petición: `$request` o `request()`, lo que usaba el archivo.
     */
    public const APPENDS_LINES = [
        "\$appends = %s->input('appends');",
        '',
        'if (is_array($appends) && $this->appendable !== []) {',
        '    // Se suman a los que el modelo ya declara en $appends: setAppends()',
        '    // reemplaza, y pedir uno de más no debe quitar los de siempre.',
        "    \$requested = array_intersect(\$this->appendable, array_filter(\$appends, 'is_string'));",
        '    $this->resource->setAppends(array_values(array_unique([...$this->resource->getAppends(), ...$requested])));',
        '}',
    ];

    /**
     * Lo que puede aparecer en la condición del `if` que envuelve al
     * setAppends() viejo: preguntas sobre `appends` y nada más.
     */
    private const CONDITION_CALLS = ['has', 'filled', 'get', 'input', 'exists', 'is_array', 'request', 'array_key_exists', 'all', 'query'];

    private const TRIVIA = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * Ruta => código original, de lo que se va a escribir.
     *
     * @var array<string, string>
     */
    private array $originals = [];

    /**
     * Ruta => código arreglado.
     *
     * @var array<string, string>
     */
    private array $fixes = [];

    /**
     * Ruta => comprobación que la arregla.
     *
     * @var array<string, string>
     */
    private array $checkOf = [];

    /**
     * @var array<string, CheckReport>
     */
    private array $report = [];

    private LegacySecurity $security;

    /**
     * @param  list<string>  $checks
     * @param  string|null  $path  sólo lo que cuelga de este subdirectorio de la raíz
     * @param  list<string>  $packages  sólo lo de estas raíces (ProjectSources::roots()); la del
     *                                  proyecto es el núcleo, sin lo que cuelga de un paquete local
     */
    public function __construct(private string $root, private array $checks, private ?string $path = null, private array $packages = [])
    {
        $this->security = new LegacySecurity($root);
        $this->path = $path === null || trim($path, '/\\') === '' ? null : trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Calcula los arreglos sin escribir nada.
     *
     * @return array<string, CheckReport>
     */
    public function plan(): array
    {
        $inspection = $this->security->inspect();
        $byFile = [];

        foreach ($this->checks as $check) {
            $this->report[$check] = ['fixed' => [], 'skipped' => [], 'unchanged' => count(array_filter(
                $inspection['clean'][$check] ?? [],
                fn (string $file): bool => $this->inScope($file)
            ))];
        }

        foreach ($inspection['debt'] as $debt) {
            if (isset($this->report[$debt['check']]) && $this->inScope($debt['file'])) {
                $byFile[$debt['file']][] = $debt;
            }
        }

        foreach ($byFile as $file => $debts) {
            $check = $debts[0]['check'];
            try {
                $original = Files::read($file);
                $code = $original;

                foreach ($debts as $debt) {
                    $code = $check === LegacySecurity::INDEX_MANAGED
                        ? $this->fixIndex($code, $debt)
                        : $this->fixAppends($code, $debt);
                }

                self::assertParses($code);
            } catch (RuntimeException|MakerException $e) {
                $this->skip($check, $file, $e->getMessage());

                continue;
            }

            $this->originals[$file] = $original;
            $this->fixes[$file] = $code;
            $this->checkOf[$file] = $check;
        }

        // Que LegacySecurity, leyendo lo arreglado, ya no lo marque. Es la
        // misma comprobación que hará verify después.
        $still = [];

        foreach ((new LegacySecurity($this->root, $this->fixes))->inspect()['debt'] as $debt) {
            $still[$debt['file']] = true;
        }

        foreach (array_keys($this->fixes) as $file) {
            $check = $this->checkOf[$file];

            if (isset($still[$file])) {
                $this->skip($check, $file, 'Después del arreglo verify lo seguiría marcando: la forma no es la que se esperaba.');
                unset($this->fixes[$file], $this->originals[$file]);

                continue;
            }

            $this->report[$check]['fixed'][] = ['file' => $this->security->project()->relative($file)];
        }

        $this->review($inspection['clean']);

        return $this->report;
    }

    /**
     * Lo que se reporta sin arreglar: los ManagedFilter sin camino para el
     * administrador, de los IndexRequest arreglados o ya correctos, y la
     * propuesta de `$appendable` de cada Resource en alcance. Ni una cosa ni
     * la otra cambia el código de salida: son lecturas heurísticas que revisa
     * una persona.
     *
     * @param  array<string, list<string>>  $clean
     */
    private function review(array $clean): void
    {
        $project = $this->security->project();

        if (isset($this->report[LegacySecurity::INDEX_MANAGED])) {
            $requests = array_values(array_unique([
                ...array_filter($clean[LegacySecurity::INDEX_MANAGED] ?? [], fn (string $file): bool => $this->inScope($file)),
                ...array_keys(array_filter($this->checkOf, fn (string $check, string $file): bool => $check === LegacySecurity::INDEX_MANAGED && isset($this->fixes[$file]), ARRAY_FILTER_USE_BOTH)),
            ]));
            sort($requests);
            $audit = (new ManagedFilterAudit($project))->audit($requests);
            $this->report[LegacySecurity::INDEX_MANAGED]['admin_path_missing'] = $audit['missing'];
            $this->report[LegacySecurity::INDEX_MANAGED]['managed_filter_unresolved'] = $audit['unresolved'];
        }

        if (isset($this->report[LegacySecurity::RESOURCE_APPENDS])) {
            $resources = array_values(array_filter($project->files('/Http/Resources/'), fn (string $file): bool => $this->inScope($file)));
            $frontRoots = $this->packages === []
                ? $project->roots()
                : array_values(array_unique([$project->root(), ...$this->packages]));

            $this->report[LegacySecurity::RESOURCE_APPENDS]['appendable_proposals'] = (new AppendableProposals($project))->propose($resources, $frontRoots);
        }
    }

    /**
     * Lo que plan() calculó, con el diff unificado de cada archivo.
     *
     * @return array<string, CheckReport>
     */
    public function withDiffs(): array
    {
        $report = $this->report;
        $diffs = [];

        foreach ($this->fixes as $file => $code) {
            $relative = $this->security->project()->relative($file);
            $diffs[$relative] = UnifiedDiff::of($this->originals[$file], $code, $relative);
        }

        foreach ($report as $check => $entry) {
            $report[$check]['fixed'] = array_map(
                fn (array $fixed): array => [...$fixed, 'diff' => $diffs[$fixed['file']] ?? ''],
                $entry['fixed']
            );
        }

        return $report;
    }

    /**
     * Escribe lo que plan() calculó. Si el proyecto usa Pint, lo pasa por lo
     * escrito; lo que el manifiesto conocía sin personalizar se vuelve a
     * anotar con el hash nuevo, como hace el Formatter.
     *
     * @return array{report: array<string, CheckReport>, formatted: bool}
     */
    public function apply(): array
    {
        $manifests = [];
        $tracked = [];

        foreach (array_keys($this->fixes) as $file) {
            $owner = $this->security->project()->rootOf($file);

            if (! is_file($owner.'/.larapack/manifest.json')) {
                continue;
            }

            $manifests[$owner] ??= new Manifest($owner);

            if ($manifests[$owner]->knows($file) && ! $manifests[$owner]->wasCustomised($file)) {
                $tracked[$owner][] = $file;
            }
        }

        foreach ($this->fixes as $file => $code) {
            file_put_contents($file, $code);
        }

        $formatted = false;

        if ($this->fixes !== [] && Formatter::pint() !== null) {
            $formatted = Formatter::format(array_keys($this->fixes), new Manifest($this->root)) !== null;

            // Pint no rompe un archivo, pero lo escrito tiene que seguir
            // parseando pase lo que pase: si no, se devuelve el original.
            foreach ($this->fixes as $file => $code) {
                try {
                    self::assertParses(Files::read($file));
                } catch (RuntimeException|MakerException $e) {
                    file_put_contents($file, $this->originals[$file]);
                    $this->revert($file, 'Después de formatearlo no parseaba, y se devolvió el original.');
                }
            }
        }

        foreach ($tracked as $owner => $files) {
            $manifests[$owner]->rehash(array_values(array_filter($files, fn (string $file): bool => isset($this->fixes[$file]))));
        }

        return ['report' => $this->report, 'formatted' => $formatted];
    }

    // INDEX-MANAGED

    /**
     * @param  Debt  $debt
     */
    private function fixIndex(string $code, array $debt): string
    {
        $tokens = self::tokenize($code);
        [$open, $close, $classIndent] = $this->classBody($tokens, $code, $debt['class']);
        $methods = $this->methods($tokens, $open, $close);
        $method = $methods[strtolower('prepareForValidation')] ?? null;

        if ($method !== null) {
            if ($method['open'] === null) {
                throw new RuntimeException('prepareForValidation() es abstracto.');
            }

            $indent = $this->indentOf($code, $tokens[$method['start']]->pos);
            $inner = $indent.$this->unit($indent, $classIndent);
            $snippet = self::indent(self::MANAGED_LINES, $inner);
            $bodyOpen = $tokens[$method['open']];
            $bodyClose = $tokens[$method['close']];
            $between = array_slice($tokens, $method['open'] + 1, $method['close'] - $method['open'] - 1);

            if ($this->isPlaceholder($between)) {
                return substr($code, 0, $bodyOpen->pos + 1)."\n".$snippet."\n".$indent.substr($code, $bodyClose->pos);
            }

            $after = $tokens[$method['open'] + 1];

            if ($after->id !== T_WHITESPACE || ! str_contains($after->text, "\n")) {
                throw new RuntimeException('prepareForValidation() tiene código en la línea de su llave de apertura.');
            }

            return substr($code, 0, $bodyOpen->pos + 1)."\n".$snippet."\n".substr($code, $bodyOpen->pos + 1);
        }

        // No lo declara: se crea, sin tapar lo que herede.
        if ($debt['traits'] !== []) {
            throw new RuntimeException('No declara prepareForValidation() y usa traits: si uno lo declara, añadirlo en la clase lo taparía.');
        }

        $inherited = $this->security->inheritedPrepare($debt['fqcn']);

        if ($inherited === 'unknown' || $inherited === 'private') {
            throw new RuntimeException("Hereda de {$debt['extends']}, que no se ve entero en el proyecto: no se sabe qué prepareForValidation() taparía.");
        }

        $first = $this->firstMethod($methods);
        $indent = $this->indentOf($code, $tokens[$first]->pos);
        $inner = $indent.$this->unit($indent, $classIndent);
        $body = $inherited === null
            ? self::MANAGED_LINES
            : ['parent::prepareForValidation();', '', ...self::MANAGED_LINES];
        $visibility = $inherited ?? 'protected';

        $method = $indent."{$visibility} function prepareForValidation(): void\n"
            .$indent."{\n"
            .self::indent($body, $inner)."\n"
            .$indent."}\n\n";

        $at = $this->lineStart($code, $tokens[$first]->pos);

        return substr($code, 0, $at).$method.substr($code, $at);
    }

    /**
     * Un cuerpo vacío o con sólo el `//` que dejaba la plantilla.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function isPlaceholder(array $tokens): bool
    {
        $comments = 0;

        foreach ($tokens as $token) {
            if ($token->id === T_WHITESPACE) {
                continue;
            }

            if ($token->id !== T_COMMENT || trim($token->text) !== '//' || ++$comments > 1) {
                return false;
            }
        }

        return true;
    }

    // RESOURCE-APPENDS

    /**
     * @param  Debt  $debt
     */
    private function fixAppends(string $code, array $debt): string
    {
        $tokens = self::tokenize($code);
        [$open, $close, $classIndent] = $this->classBody($tokens, $code, $debt['class']);
        $methods = $this->methods($tokens, $open, $close);
        $method = $methods[strtolower($debt['method'] ?? '')] ?? null;

        if ($method === null || $method['open'] === null) {
            throw new RuntimeException("No se encontró el cuerpo de {$debt['class']}::{$debt['method']}().");
        }

        $calls = [];

        for ($i = $method['open'] + 1; $i < $method['close']; $i++) {
            if ($tokens[$i]->id === T_STRING && strcasecmp($tokens[$i]->text, 'setAppends') === 0) {
                $calls[] = $i;
            }
        }

        if (count($calls) !== 1) {
            throw new RuntimeException("{$debt['class']}::{$debt['method']}() llama ".count($calls).' veces a setAppends(): sólo se arregla la forma de la plantilla, con una.');
        }

        [$start, $end, $receiver] = $this->appendsStatement($tokens, $calls[0], $method);

        // $appends y $requested son los nombres de la plantilla: si el método
        // ya los usa, el bloque nuevo los pisaría.
        for ($i = $method['open'] + 1; $i < $method['close']; $i++) {
            if (($i < $start || $i > $end) && $tokens[$i]->id === T_VARIABLE && in_array($tokens[$i]->text, ['$appends', '$requested'], true)) {
                throw new RuntimeException("{$debt['class']}::{$debt['method']}() ya usa {$tokens[$i]->text}: el bloque de la plantilla la pisaría.");
            }
        }

        $indent = $this->indentOf($code, $tokens[$start]->pos);

        if ($this->lineStart($code, $tokens[$start]->pos) + strlen($indent) !== $tokens[$start]->pos) {
            throw new RuntimeException("{$debt['class']}::{$debt['method']}(): el setAppends() no empieza su línea.");
        }

        $lines = self::APPENDS_LINES;
        $lines[0] = sprintf($lines[0], $receiver);
        $replacement = ltrim(self::indent($lines, $indent));
        $endPos = $tokens[$end]->pos + strlen($tokens[$end]->text);
        $code = substr($code, 0, $tokens[$start]->pos).$replacement.substr($code, $endPos);

        return $this->declareAppendable($code, $debt, $classIndent);
    }

    /**
     * La sentencia que se reemplaza: el `if` que sólo envuelve al
     * setAppends(), o la llamada suelta, siempre en el primer nivel del
     * método. Devuelve sus tokens de inicio y fin y de dónde se leía la
     * petición.
     *
     * @param  list<PhpToken>  $tokens
     * @param  MethodWithBody  $method
     * @return array{0: int, 1: int, 2: string}
     */
    private function appendsStatement(array $tokens, int $call, array $method): array
    {
        $chain = [];
        $i = $call;

        for ($n = 0; $n < 4; $n++) {
            // Sin token anterior la cadena ya no es `$this->resource->`; seguir
            // preguntando por el anterior de null era un TypeError.
            $i = $i === null ? null : $this->previous($tokens, $i);
            $chain[] = $i === null ? null : $tokens[$i];
        }

        if ($i === null
            || $chain[0]?->id !== T_OBJECT_OPERATOR
            || $chain[1]?->id !== T_STRING || $chain[1]->text !== 'resource'
            || $chain[2]?->id !== T_OBJECT_OPERATOR
            || $chain[3]?->id !== T_VARIABLE || $chain[3]->text !== '$this'
        ) {
            throw new RuntimeException('setAppends() no se llama sobre $this->resource.');
        }

        $statement = $i;
        $parenthesis = $this->next($tokens, $call);

        if ($parenthesis === null || $tokens[$parenthesis]->text !== '(') {
            throw new RuntimeException('setAppends sin argumentos.');
        }

        $closing = $this->matching($tokens, $parenthesis);
        $semicolon = $this->next($tokens, $closing);

        if ($semicolon === null || $tokens[$semicolon]->text !== ';') {
            throw new RuntimeException('El resultado de setAppends() se usa en otra expresión.');
        }

        $receiver = $this->receiver($this->significant($tokens, $parenthesis + 1, $closing), $method['params']);
        $before = $this->previous($tokens, $statement);

        if ($before === null) {
            throw new RuntimeException('setAppends() fuera de un método.');
        }

        // Suelta, en el primer nivel del método.
        if ($before === $method['open'] || (in_array($tokens[$before]->text, [';', '}'], true) && $this->depth($tokens, $method['open'], $statement) === 1)) {
            return [$statement, $semicolon, $receiver];
        }

        // Dentro de un if que no hace nada más.
        $brace = $before;
        $after = $this->next($tokens, $semicolon);
        $conditionClose = $this->previous($tokens, $brace);

        if ($tokens[$brace]->text !== '{' || $after === null || $tokens[$after]->text !== '}'
            || $conditionClose === null || $tokens[$conditionClose]->text !== ')'
        ) {
            throw new RuntimeException('setAppends() está dentro de un bloque que no es el `if` de la plantilla.');
        }

        $conditionOpen = $this->opening($tokens, $conditionClose);
        $if = $this->previous($tokens, $conditionOpen);

        if ($if === null || $tokens[$if]->id !== T_IF || $this->depth($tokens, $method['open'], $if) !== 1) {
            throw new RuntimeException('setAppends() está dentro de un bloque que no es un `if` del primer nivel del método.');
        }

        $else = $this->next($tokens, $after);

        if ($else !== null && in_array($tokens[$else]->id, [T_ELSE, T_ELSEIF], true)) {
            throw new RuntimeException('El `if` del setAppends() tiene else: quitarlo cambiaría lo que hace.');
        }

        $this->assertConditionReadsAppends($this->significant($tokens, $conditionOpen + 1, $conditionClose), $method['params']);

        return [$if, $after, $receiver];
    }

    /**
     * `$request->get('appends')`, `$request->input('appends', [])`,
     * `request('appends')` o `request()->input('appends')`, con `$request` un
     * parámetro del método. Devuelve `$request` o `request()`.
     *
     * @param  list<PhpToken>  $argument
     * @param  list<string>  $params
     */
    private function receiver(array $argument, array $params): string
    {
        $texts = array_map(fn (PhpToken $token): string => $token->id === T_CONSTANT_ENCAPSED_STRING ? trim($token->text, '\'"') : $token->text, $argument);
        $tail = static fn (array $rest): bool => $rest === ['appends', ')'] || $rest === ['appends', ',', '[', ']', ')'];

        if (count($texts) >= 5 && $argument[0]->id === T_VARIABLE && in_array($texts[0], $params, true)
            && $texts[1] === '->' && in_array(strtolower($texts[2]), ['get', 'input'], true) && $texts[3] === '('
            && $tail(array_slice($texts, 4))
        ) {
            return $texts[0];
        }

        if (count($texts) >= 3 && strtolower($texts[0]) === 'request' && $texts[1] === '(' && $tail(array_slice($texts, 2))) {
            return 'request()';
        }

        if (count($texts) >= 7 && strtolower($texts[0]) === 'request' && $texts[1] === '(' && $texts[2] === ')'
            && $texts[3] === '->' && in_array(strtolower($texts[4]), ['get', 'input'], true) && $texts[5] === '('
            && $tail(array_slice($texts, 6))
        ) {
            return 'request()';
        }

        throw new RuntimeException('setAppends() recibe algo que no es la lectura directa de `appends` de la petición.');
    }

    /**
     * @param  list<PhpToken>  $condition
     * @param  list<string>  $params
     */
    private function assertConditionReadsAppends(array $condition, array $params): void
    {
        foreach ($condition as $token) {
            $ok = match (true) {
                in_array($token->text, ['(', ')', '!', '&&', '->', ',', '[', ']'], true) => true,
                $token->id === T_VARIABLE => in_array($token->text, $params, true),
                $token->id === T_STRING => in_array(strtolower($token->text), self::CONDITION_CALLS, true),
                $token->id === T_ISSET, $token->id === T_EMPTY => true,
                $token->id === T_CONSTANT_ENCAPSED_STRING => trim($token->text, '\'"') === 'appends',
                default => false,
            };

            if (! $ok) {
                throw new RuntimeException("La condición del `if` del setAppends() hace algo más que mirar `appends` ({$token->text}).");
            }
        }
    }

    /**
     * `protected array $appendable = [];` con el comentario de la plantilla,
     * delante del primer método, si la clase no la declara. Sólo en un
     * JsonResource directo: si hereda de otro Resource, éste puede declararla
     * con otro tipo y redeclararla rompería la clase.
     *
     * @param  Debt  $debt
     */
    private function declareAppendable(string $code, array $debt, string $classIndent): string
    {
        $tokens = self::tokenize($code);
        [$open, $close] = $this->classBody($tokens, $code, $debt['class']);
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '{' || $tokens[$i]->id === T_CURLY_OPEN || $tokens[$i]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif ($depth === 0 && $tokens[$i]->id === T_VARIABLE && $text === '$appendable') {
                return $code;
            }
        }

        if (strtolower((string) $debt['extends']) !== self::JSON_RESOURCE) {
            throw new RuntimeException("Extiende {$debt['extends']} y no declara \$appendable: no se sabe si su padre la declara ni con qué tipo.");
        }

        $first = $this->firstMethod($this->methods($tokens, $open, $close));
        $indent = $this->indentOf($code, $tokens[$first]->pos);
        $at = $this->lineStart($code, $tokens[$first]->pos);

        return substr($code, 0, $at).self::indent(self::APPENDABLE_LINES, $indent)."\n\n".substr($code, $at);
    }

    // TOKENS

    /**
     * Los tokens del código, como lista: los índices son posiciones y todo lo
     * de abajo avanza y retrocede por ellos.
     *
     * @return list<PhpToken>
     */
    private static function tokenize(string $code): array
    {
        return array_values(PhpToken::tokenize($code));
    }

    /**
     * La llave de apertura y de cierre del cuerpo de la clase, y la sangría
     * de su declaración.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{0: int, 1: int, 2: string}
     */
    private function classBody(array $tokens, string $code, string $name): array
    {
        $found = [];

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_CLASS) {
                continue;
            }

            // `Post::class` y `new class (…)` también son T_CLASS, pero lo que
            // los sigue nunca es un nombre: sólo `class Nombre` lo es.
            $after = $this->next($tokens, $index);

            if ($after === null || $tokens[$after]->id !== T_STRING || strcasecmp($tokens[$after]->text, $name) !== 0) {
                continue;
            }

            $found[] = $index;
        }

        if (count($found) !== 1) {
            throw new RuntimeException(count($found) === 0 ? "No se encontró la clase {$name}." : "La clase {$name} aparece más de una vez.");
        }

        $open = $found[0];

        while (isset($tokens[$open]) && $tokens[$open]->text !== '{') {
            $open++;
        }

        if (! isset($tokens[$open])) {
            throw new RuntimeException("La clase {$name} no tiene cuerpo.");
        }

        return [$open, $this->matching($tokens, $open), $this->indentOf($code, $tokens[$found[0]]->pos)];
    }

    /**
     * Los métodos del primer nivel de la clase, por nombre en minúsculas: el
     * primer token de su declaración (comentario incluido), las llaves de su
     * cuerpo (null si es abstracto) y sus parámetros.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, Method>
     */
    private function methods(array $tokens, int $open, int $close): array
    {
        $methods = [];
        $start = null;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if ($token->id === T_WHITESPACE) {
                continue;
            }

            $start ??= $i;

            if ($token->text === ';') {
                $start = null;

                continue;
            }

            if (in_array($token->text, ['{', '(', '['], true) || $token->id === T_ATTRIBUTE) {
                $i = $this->matching($tokens, $i);

                if ($token->text === '{') {
                    $start = null;
                }

                continue;
            }

            if ($token->id !== T_FUNCTION) {
                continue;
            }

            $name = $this->next($tokens, $i);

            if ($name !== null && $tokens[$name]->text === '&') {
                $name = $this->next($tokens, $name);
            }

            $parenthesis = $name === null ? null : $this->next($tokens, $name);

            if ($name === null || $parenthesis === null || $tokens[$parenthesis]->text !== '(') {
                throw new RuntimeException('Una declaración de método que no se reconoce.');
            }

            $paramsClose = $this->matching($tokens, $parenthesis);
            $params = [];

            for ($j = $parenthesis + 1; $j < $paramsClose; $j++) {
                if ($tokens[$j]->id === T_VARIABLE) {
                    $params[] = $tokens[$j]->text;
                }
            }

            $j = $paramsClose + 1;

            while ($j < $close && ! in_array($tokens[$j]->text, ['{', ';'], true)) {
                $j++;
            }

            $bodyOpen = $tokens[$j]->text === '{' ? $j : null;
            $bodyClose = $bodyOpen === null ? null : $this->matching($tokens, $bodyOpen);

            // Las dos llaves o ninguna (abstracto): así lo dice el tipo, y quien
            // comprueba `open` sabe que `close` también está.
            $methods[strtolower($tokens[$name]->text)] ??= $bodyOpen === null || $bodyClose === null
                ? ['start' => $start, 'open' => null, 'close' => null, 'params' => $params]
                : ['start' => $start, 'open' => $bodyOpen, 'close' => $bodyClose, 'params' => $params];
            $i = $bodyClose ?? $j;
            $start = null;
        }

        return $methods;
    }

    /**
     * El primer token del primer método: delante de él se inserta.
     *
     * @param  array<string, Method>  $methods
     */
    private function firstMethod(array $methods): int
    {
        // methods() los recoge en el orden del archivo: el primero es el de
        // más arriba.
        $first = reset($methods);

        if ($first === false) {
            throw new RuntimeException('La clase no tiene métodos delante de los que insertar.');
        }

        return $first['start'];
    }

    /**
     * Cuántas llaves abiertas hay entre la del cuerpo del método y $index: 1
     * es el primer nivel del método.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function depth(array $tokens, int $open, int $index): int
    {
        $depth = 0;

        for ($i = $open; $i < $index; $i++) {
            if ($tokens[$i]->text === '{' || $tokens[$i]->id === T_CURLY_OPEN || $tokens[$i]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($tokens[$i]->text === '}') {
                $depth--;
            }
        }

        return $depth;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function matching(array $tokens, int $open): int
    {
        $closers = ['{' => '}', '(' => ')', '[' => ']'];
        $closer = $tokens[$open]->id === T_ATTRIBUTE ? ']' : ($closers[$tokens[$open]->text] ?? null);

        if ($closer === null) {
            throw new RuntimeException('Se esperaba un paréntesis o una llave.');
        }

        $depth = 0;

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if (in_array($text, ['{', '(', '['], true) || in_array($tokens[$i]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
                $depth++;
            } elseif (in_array($text, ['}', ')', ']'], true) && --$depth === 0) {
                if ($text !== $closer) {
                    throw new RuntimeException('Paréntesis o llaves sin equilibrar.');
                }

                return $i;
            }
        }

        throw new RuntimeException('Paréntesis o llaves sin cerrar.');
    }

    /**
     * El `(` que abre el `)` de $close.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function opening(array $tokens, int $close): int
    {
        $depth = 0;

        for ($i = $close; $i >= 0; $i--) {
            $text = $tokens[$i]->text;

            if (in_array($text, [')', ']', '}'], true)) {
                $depth++;
            } elseif ((in_array($text, ['(', '[', '{'], true) || in_array($tokens[$i]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) && --$depth === 0) {
                return $i;
            }
        }

        throw new RuntimeException('Paréntesis sin abrir.');
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>
     */
    private function significant(array $tokens, int $from, int $to): array
    {
        $result = [];

        for ($i = $from; $i < $to; $i++) {
            if (! in_array($tokens[$i]->id, self::TRIVIA, true)) {
                $result[] = $tokens[$i];
            }
        }

        return $result;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function previous(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (! in_array($tokens[$i]->id, self::TRIVIA, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function next(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! in_array($tokens[$i]->id, self::TRIVIA, true)) {
                return $i;
            }
        }

        return null;
    }

    // TEXTO

    private function lineStart(string $code, int $pos): int
    {
        $newline = strrpos(substr($code, 0, $pos), "\n");

        return $newline === false ? 0 : $newline + 1;
    }

    /**
     * Los espacios o tabuladores con que empieza la línea de $pos.
     */
    private function indentOf(string $code, int $pos): string
    {
        $start = $this->lineStart($code, $pos);

        // $pos es el de un token que no es espacio: la sangría acaba antes.
        return substr($code, $start, strspn($code, " \t", $start));
    }

    /**
     * Un nivel de sangría: lo que el miembro lleva de más que la clase.
     */
    private function unit(string $member, string $class): string
    {
        $unit = str_starts_with($member, $class) ? substr($member, strlen($class)) : '';

        if ($unit === '') {
            throw new RuntimeException('No se pudo deducir la sangría de la clase.');
        }

        return $unit;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function indent(array $lines, string $indent): string
    {
        return implode("\n", array_map(fn (string $line): string => $line === '' ? '' : $indent.$line, $lines));
    }

    private static function assertParses(string $code): void
    {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException("El resultado no parsea ({$e->getMessage()}, línea {$e->getLine()}).");
        }

        if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
            throw new RuntimeException('El resultado no empieza con <?php.');
        }
    }

    private function inScope(string $file): bool
    {
        $project = $this->security->project();

        if ($this->packages !== [] && ! in_array($project->rootOf($file), $this->packages, true)) {
            return false;
        }

        if ($this->path === null) {
            return true;
        }

        $relative = $project->relative($file);

        return $relative === $this->path || str_starts_with($relative, $this->path.'/');
    }

    private function skip(string $check, string $file, string $reason): void
    {
        $this->report[$check]['skipped'][] = ['file' => $this->security->project()->relative($file), 'reason' => $reason];
    }

    private function revert(string $file, string $reason): void
    {
        $relative = $this->security->project()->relative($file);

        foreach ($this->report as $check => $entry) {
            // Se filtra la lista entera de una vez. Quitar por índice dentro
            // del bucle, reindexando cada vez, dejaba el segundo de dos
            // arreglos del mismo archivo: su índice ya no era el suyo.
            $kept = array_values(array_filter($entry['fixed'], fn (array $fixed): bool => $fixed['file'] !== $relative));

            if (count($kept) === count($entry['fixed'])) {
                continue;
            }

            $this->report[$check]['fixed'] = $kept;

            for ($n = count($entry['fixed']) - count($kept); $n > 0; $n--) {
                $this->skip($check, $file, $reason);
            }
        }

        unset($this->fixes[$file]);
    }
}
