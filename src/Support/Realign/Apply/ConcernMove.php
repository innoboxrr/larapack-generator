<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Apply;

use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\PhpOutline;
use PhpToken;

/**
 * Mueve un trait de `Http/Controllers/Concerns` a `Http/Requests/Concerns`
 * cuando sólo lo usan requests (el aviso `controller-concern`): cambia su
 * namespace y cada referencia del proyecto, por tokens.
 *
 * Qué se reescribe: la declaración del namespace del trait; cada nombre
 * cualificado que apunta a él —en un `use`, en un `\App\…\Trait::class`,
 * relativo al namespace del archivo o a uno de sus alias (`use …\Controllers;`
 * y luego `Controllers\Concerns\X`)—. Un `use X;` dentro de una clase es
 * relativo, como cualquier nombre del código. Nada más: un comentario o un
 * docblock que lo nombre se queda como está (no cambia el comportamiento).
 *
 * En el trait movido, lo que nombraba de su namespace viejo sin importarlo
 * (un hermano `Helper::x()`, un `Sub\Thing`) se importa o se escribe completo:
 * con el namespace nuevo resolvería a otra clase.
 *
 * Qué hace que no se mueva, con su razón:
 *
 *  - lo usa un controlador (es un error de `verify`, y el arreglo no es
 *    moverlo sino llevar la lógica a la request: controller-shape);
 *  - el archivo no tiene un único trait con el nombre del archivo, o su
 *    namespace no es el de su ruta;
 *  - ya hay un archivo en el destino;
 *  - el trait llama a una función o usa una constante de su namespace viejo
 *    sin importarla;
 *  - alguien lo nombra de una forma que no se reescribe con certeza: una
 *    importación agrupada (`use …\Concerns\{A, B}`), una cadena
 *    (`'App\Http\Controllers\Concerns\X'`, que podría ser configuración), o
 *    por su nombre corto desde su mismo namespace sin importarlo.
 *
 * Y commit(), después de escribir, busca en TODO el proyecto (no en la lista
 * con que se planeó) un nombre que siga resolviendo al trait viejo, o un
 * hermano que el trait movido ya no vea: si queda uno, lo devuelve todo.
 */
final class ConcernMove
{
    private const SEGMENT = 'Http/Controllers/Concerns/';

    /**
     * Lo que precede a un T_STRING que no es un nombre de clase: un miembro,
     * una declaración, un alias.
     */
    private const NOT_A_NAME = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_AS, T_GOTO, T_CASE, T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM, T_NAMESPACE];

    /**
     * Los PHP del proyecto, por raíz, para no recorrerlo una vez por trait.
     *
     * @var array<string, list<string>>
     */
    private static array $files = [];

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param  string  $traitFile  relativo a la raíz del proyecto
     * @return array{changes: Changes, to: string, references: list<string>, old: string, new: string}|string lo que hay que escribir, o por qué no
     */
    public function plan(string $traitFile): array|string
    {
        $absolute = $this->projectRoot.'/'.$traitFile;

        if (! is_file($absolute)) {
            return 'Ya no existe.';
        }

        $position = strpos($traitFile, self::SEGMENT);

        if ($position === false || ($position > 0 && $traitFile[$position - 1] !== '/')) {
            return 'No está en Http/Controllers/Concerns: muévelo a mano a Http/Requests/Concerns o a un soporte.';
        }

        $to = substr($traitFile, 0, $position).'Http/Requests/Concerns/'.substr($traitFile, $position + strlen(self::SEGMENT));

        if (file_exists($this->projectRoot.'/'.$to)) {
            return "Ya existe {$to}.";
        }

        $code = Files::read($absolute);
        $classes = PhpOutline::ofCode($code)->classes();
        $name = basename($traitFile, '.php');

        if (count($classes) !== 1 || $classes[0]['kind'] !== 'trait' || $classes[0]['name'] !== $name) {
            return "No declara un único trait {$name}.";
        }

        $old = ltrim($classes[0]['fqcn'], '\\');
        $oldNamespace = substr($old, 0, (int) strrpos($old, '\\'));
        $subpath = str_replace('/', '\\', dirname(substr($traitFile, $position + strlen(self::SEGMENT))));
        $expected = '\\Http\\Controllers\\Concerns'.($subpath === '.' ? '' : '\\'.$subpath);

        if (! str_ends_with($oldNamespace, $expected)) {
            return "Su namespace ({$oldNamespace}) no corresponde a su ruta.";
        }

        $newNamespace = substr($oldNamespace, 0, -strlen($expected)).'\\Http\\Requests\\Concerns'.($subpath === '.' ? '' : '\\'.$subpath);
        $new = $newNamespace.'\\'.$name;

        $moved = self::relocate($code, $old, $new, $this->declaredIn($oldNamespace, $absolute));

        if (is_array($moved)) {
            return $moved[0];
        }

        $changes = new Changes($this->projectRoot);
        $changes->delete($absolute);
        $changes->put($this->projectRoot.'/'.$to, $moved);
        $references = [];

        foreach ($this->phpFiles() as $file) {
            // Otro movimiento de la misma corrida pudo quitarlo.
            if ($file === $absolute || ! is_file($file)) {
                continue;
            }

            $content = Files::read($file);

            if (stripos($content, $name) === false) {
                continue;
            }

            $rewritten = self::rewrite($content, $old, $new, $oldNamespace, $name);

            if (is_array($rewritten)) {
                return $this->relative($file).': '.$rewritten[0];
            }

            if ($rewritten !== $content) {
                $changes->put($file, $rewritten);
                $references[] = $this->relative($file);
            }
        }

        return ['changes' => $changes, 'to' => $to, 'references' => $references, 'old' => $old, 'new' => $new];
    }

    /**
     * Escribe el plan y comprueba lo escrito: que todo parsee (Changes) y que
     * en el proyecto, recorrido de nuevo, nada siga resolviendo al trait viejo
     * ni el trait movido nombre un hermano que ya no ve. Si algo falla, lo
     * devuelve todo a como estaba.
     *
     * @param  array{changes: Changes, to: string, old: string, new: string}  $plan
     * @return string|null null si se escribió; la razón, si se devolvió todo
     */
    public function commit(array $plan): ?string
    {
        $problem = $plan['changes']->commit();

        // Lo escrito cambia el proyecto: la siguiente corrida lo recorre de nuevo.
        unset(self::$files[$this->projectRoot]);

        if ($problem !== null) {
            return $problem;
        }

        $remaining = $this->remaining($plan['old'], $plan['new'], $this->projectRoot.'/'.$plan['to']);

        if ($remaining === []) {
            return null;
        }

        $plan['changes']->revert();
        unset(self::$files[$this->projectRoot]);

        return 'Después de escribir, '.implode('; ', $remaining).': no se escribió nada de este cambio.';
    }

    /**
     * Las referencias de un archivo al trait, reescritas; o, en una lista,
     * por qué no se pueden reescribir con certeza.
     *
     * @return string|array{0: string}
     */
    public static function rewrite(string $content, string $old, string $new, string $oldNamespace, string $name): string|array
    {
        $tokens = array_values(PhpToken::tokenize($content));
        $replace = [];

        foreach (self::names($tokens, $old, $new) as $found) {
            $token = $tokens[$found['index']];
            $resolved = $found['resolved'];

            switch ($found['role']) {
                case 'group':
                    break;

                case 'string':
                    if (stripos(str_replace('\\\\', '\\', $token->text), $old) !== false) {
                        return ['lo nombra en una cadena (¿configuración?): no se cambia a ciegas.'];
                    }
                    break;

                case 'import':
                    if (strcasecmp($resolved, $old) === 0 && $found['grouped']) {
                        return ['lo importa en un `use` agrupado: reescríbelo a mano.'];
                    }

                    if (strcasecmp($resolved, $old) === 0) {
                        $replace[$found['index']] = ($token->is(T_NAME_FULLY_QUALIFIED) ? '\\' : '').$new;
                    }
                    break;

                case 'namespace':
                case 'import-end':
                    break;

                case 'short':
                    if (strcasecmp($resolved, $old) === 0) {
                        return ['lo usa por su nombre corto desde su mismo namespace: muévelos juntos a mano.'];
                    }
                    break;

                default:
                    if (strcasecmp($resolved, $old) === 0) {
                        $replace[$found['index']] = '\\'.$new;
                    }
            }
        }

        return $replace === [] ? $content : self::join($tokens, $replace);
    }

    /**
     * El trait con su namespace nuevo: lo que nombraba de su namespace viejo
     * sin importarlo se importa (una clase) o se escribe completo (un nombre
     * cualificado); una función o una constante, no se mueve.
     *
     * @param  array{classes: array<string, string>, functions: array<string, true>, constants: array<string, true>}  $declared  del namespace viejo, en minúsculas
     * @return string|array{0: string}
     */
    private static function relocate(string $code, string $old, string $new, array $declared): string|array
    {
        $tokens = array_values(PhpToken::tokenize($code));
        $oldNamespace = substr($old, 0, (int) strrpos($old, '\\'));
        $newNamespace = substr($new, 0, (int) strrpos($new, '\\'));
        $self = strtolower(substr($old, strlen($oldNamespace) + 1));
        $replace = [];
        $imports = [];
        $namespaces = 0;
        $after = null;
        $afterImport = false;

        foreach (self::names($tokens, $old, $new) as $found) {
            $index = $found['index'];
            $token = $tokens[$index];
            $resolved = $found['resolved'];

            switch ($found['role']) {
                case 'namespace':
                    if (strcasecmp($resolved, $oldNamespace) !== 0 || $namespaces++ > 0) {
                        return ['No se encuentra su declaración de namespace.'];
                    }

                    $replace[$index] = $newNamespace;
                    $after = $found['end'];
                    break;

                case 'import-end':
                    $after = $found['end'];
                    $afterImport = true;
                    break;

                case 'import':
                case 'group':
                    break;

                case 'string':
                    if (stripos(str_replace('\\\\', '\\', $token->text), $old) !== false) {
                        return ['Se nombra a sí mismo en una cadena (¿configuración?): no se cambia a ciegas.'];
                    }
                    break;

                case 'short':
                    $lower = strtolower($token->text);

                    if (! $found['relative'] || $lower === $self) {
                        break;
                    }

                    if ($found['call'] && isset($declared['functions'][$lower])) {
                        return ["Llama a {$token->text}() de su namespace viejo sin importarla: impórtala (`use function`) y vuelve a correrlo."];
                    }

                    if (! $found['call'] && isset($declared['constants'][$lower])) {
                        return ["Usa la constante {$token->text} de su namespace viejo sin importarla: impórtala (`use const`) y vuelve a correrlo."];
                    }

                    if (isset($declared['classes'][$lower])) {
                        $imports[$lower] = $oldNamespace.'\\'.$declared['classes'][$lower];
                    }
                    break;

                default:
                    if (strcasecmp($resolved, $old) === 0) {
                        $replace[$index] = '\\'.$new;
                    } elseif ($found['relative']) {
                        // Relativo al namespace viejo: completo, resuelve a lo mismo.
                        $replace[$index] = '\\'.$resolved;
                    }
            }
        }

        if ($namespaces !== 1 || $after === null) {
            return ['No se encuentra su declaración de namespace.'];
        }

        if ($imports !== []) {
            // Pegadas a las que ya había, o separadas del namespace por una
            // línea en blanco.
            $lines = implode('', array_map(fn (string $import): string => "\nuse {$import};", $imports));
            $replace[$after] = $tokens[$after]->text.($afterImport ? '' : "\n").$lines;
        }

        return self::join($tokens, $replace);
    }

    /**
     * Cada nombre de un código con a qué resuelve: la declaración de namespace,
     * las importaciones de clases (con su alias), los grupos, los nombres del
     * código (cualificados o no) y las cadenas.
     *
     * Un `use` es una importación sólo en el primer nivel (o dentro de
     * `namespace X { }`) y si no es el de un closure; dentro de una clase es un
     * nombre más, relativo. Un alias importado del trait viejo cuenta ya como
     * el nuevo (`$from` → `$to`): esa importación también se reescribe.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{index: int, role: string, resolved: string, relative: bool, call: bool, grouped: bool, end: int}>
     */
    private static function names(array $tokens, string $from = '', string $to = ''): array
    {
        $found = [];
        $namespace = '';
        $aliases = [];
        $depth = 0;
        $base = 0;
        $import = null;
        $group = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->isIgnorable()) {
                continue;
            }

            $text = $token->text;
            $previous = self::previous($tokens, $i);
            $nextIndex = self::nextIndex($tokens, $i);
            $next = $nextIndex === null ? null : $tokens[$nextIndex];
            $entry = ['index' => $i, 'resolved' => '', 'relative' => false, 'call' => false, 'grouped' => false, 'end' => $i];

            if ($token->is(T_NAMESPACE) && $next !== null && ($next->is([T_STRING, T_NAME_QUALIFIED]) || $next->text === '{')) {
                $aliases = [];
                $namespace = '';

                if ($next->text !== '{') {
                    $namespace = $next->text;
                    $end = self::nextIndex($tokens, (int) $nextIndex);
                    $found[] = ['index' => (int) $nextIndex, 'role' => 'namespace', 'resolved' => $namespace, 'end' => (int) $end] + $entry;
                    $i = (int) $nextIndex;
                    $next = $end === null ? null : $tokens[$end];
                }

                $base = $next !== null && $next->text === '{' ? $depth + 1 : 0;

                continue;
            }

            if ($import !== null) {
                if ($text === ';') {
                    $found[] = ['role' => 'import-end'] + $entry;
                    $import = null;
                    $group = null;
                } elseif ($text === '}') {
                    $group = null;
                } elseif ($import === 'class' && $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ! ($previous !== null && $previous->is(T_AS))) {
                    $bare = ltrim($text, '\\');

                    if ($next !== null && $next->is(T_NS_SEPARATOR)) {
                        $group = $bare;
                        $found[] = ['role' => 'group', 'resolved' => $bare] + $entry;

                        continue;
                    }

                    $full = $group === null ? $bare : $group.'\\'.$bare;
                    $alias = $next !== null && $next->is(T_AS) ? ($tokens[(int) self::nextIndex($tokens, (int) $nextIndex)]->text ?? '') : substr($full, (int) strrpos('\\'.$full, '\\'));
                    $aliases[strtolower($alias)] = $from !== '' && strcasecmp($full, $from) === 0 ? $to : $full;
                    $found[] = ['role' => 'import', 'resolved' => $full, 'grouped' => $group !== null] + $entry;
                }

                continue;
            }

            if ($text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif ($token->is(T_USE)) {
                if ($depth === $base && $next !== null && $next->text !== '(') {
                    $import = $next->is([T_FUNCTION, T_CONST]) ? 'other' : 'class';
                }
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $found[] = ['role' => 'string'] + $entry;
            } elseif ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $found[] = ['role' => 'name', 'resolved' => ltrim($text, '\\')] + $entry;
            } elseif ($token->is(T_NAME_RELATIVE)) {
                $rest = substr($text, strlen('namespace\\'));
                $found[] = ['role' => 'name', 'resolved' => $namespace === '' ? $rest : $namespace.'\\'.$rest, 'relative' => true] + $entry;
            } elseif ($token->is(T_NAME_QUALIFIED)) {
                $first = strtolower(strstr($text, '\\', true) ?: $text);
                $resolved = isset($aliases[$first])
                    ? $aliases[$first].substr($text, strlen($first))
                    : ($namespace === '' ? $text : $namespace.'\\'.$text);
                $found[] = ['role' => 'name', 'resolved' => $resolved, 'relative' => ! isset($aliases[$first]) && $namespace !== ''] + $entry;
            } elseif ($token->is(T_STRING) && ! self::notAName($previous, $next)) {
                $lower = strtolower($text);
                $found[] = [
                    'role' => 'short',
                    'resolved' => $aliases[$lower] ?? ($namespace === '' ? $text : $namespace.'\\'.$text),
                    'relative' => ! isset($aliases[$lower]) && $namespace !== '',
                    'call' => $next !== null && $next->text === '(' && ! ($previous !== null && $previous->is(T_NEW)),
                ] + $entry;
            }
        }

        return $found;
    }

    /**
     * Lo que se declara en el proyecto directamente en un namespace: clases
     * (nombre corto en minúsculas => como se escribe), funciones y
     * constantes.
     *
     * @return array{classes: array<string, string>, functions: array<string, true>, constants: array<string, true>}
     */
    private function declaredIn(string $namespace, string $except): array
    {
        $declared = ['classes' => [], 'functions' => [], 'constants' => []];

        foreach ($this->phpFiles() as $file) {
            if ($file === $except || ! is_file($file)) {
                continue;
            }

            $content = Files::read($file);

            if (stripos($content, $namespace) === false) {
                continue;
            }

            $tokens = array_values(PhpToken::tokenize($content));
            $current = '';
            $depth = 0;
            $base = 0;

            foreach ($tokens as $i => $token) {
                $next = self::nextIndex($tokens, $i);
                $nextToken = $next === null ? null : $tokens[$next];

                if ($token->is(T_NAMESPACE) && $nextToken !== null && ($nextToken->is([T_STRING, T_NAME_QUALIFIED]) || $nextToken->text === '{')) {
                    $current = $nextToken->text === '{' ? '' : $nextToken->text;
                    $after = $nextToken->text === '{' ? $nextToken : ($tokens[(int) self::nextIndex($tokens, (int) $next)] ?? null);
                    $base = $after !== null && $after->text === '{' ? $depth + 1 : 0;
                } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($token->text === '}') {
                    $depth--;
                } elseif (strcasecmp($current, $namespace) !== 0 || $nextToken === null || ! $nextToken->is(T_STRING)) {
                    continue;
                } elseif ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && ! (self::previous($tokens, $i)?->is(T_DOUBLE_COLON) ?? false)) {
                    $declared['classes'][strtolower($nextToken->text)] = $nextToken->text;
                } elseif ($depth === $base && $token->is(T_FUNCTION)) {
                    $declared['functions'][strtolower($nextToken->text)] = true;
                } elseif ($depth === $base && $token->is(T_CONST)) {
                    $declared['constants'][strtolower($nextToken->text)] = true;
                }
            }
        }

        return $declared;
    }

    /**
     * Lo que, ya escrito, sigue roto: un archivo del proyecto con un nombre
     * que resuelve al trait viejo, o un nombre corto del trait movido que era
     * de su namespace viejo y ahora no se ve.
     *
     * @return list<string>
     */
    private function remaining(string $old, string $new, string $movedFile): array
    {
        $name = substr($old, (int) strrpos($old, '\\') + 1);
        $oldNamespace = substr($old, 0, (int) strrpos($old, '\\'));
        $problems = [];

        foreach ($this->phpFiles() as $file) {
            $content = Files::read($file);

            if (stripos($content, $name) === false) {
                continue;
            }

            $tokens = array_values(PhpToken::tokenize($content));

            foreach (self::names($tokens) as $found) {
                $token = $tokens[$found['index']];
                $names = $found['role'] === 'string'
                    ? stripos(str_replace('\\\\', '\\', $token->text), $old) !== false
                    : in_array($found['role'], ['import', 'name', 'short'], true) && strcasecmp($found['resolved'], $old) === 0;

                if ($names) {
                    $problems[] = $this->relative($file).' sigue nombrando '.$old;

                    break;
                }
            }
        }

        if (is_file($movedFile)) {
            $declared = $this->declaredIn($oldNamespace, $movedFile)['classes'];
            $tokens = array_values(PhpToken::tokenize(Files::read($movedFile)));

            foreach (self::names($tokens) as $found) {
                $lower = strtolower($tokens[$found['index']]->text);

                if ($found['role'] === 'short' && $found['relative'] && isset($declared[$lower]) && strcasecmp($found['resolved'], $new) !== 0) {
                    $problems[] = $this->relative($movedFile)." nombra {$tokens[$found['index']]->text} sin importarlo: era de {$oldNamespace}";

                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * Un T_STRING que no es un nombre de clase: un miembro, una declaración,
     * un alias, un argumento con nombre o una etiqueta. `case X::A:` sí lo es.
     */
    private static function notAName(?PhpToken $previous, ?PhpToken $next): bool
    {
        if ($next !== null && $next->text === ':') {
            return true;
        }

        if ($previous === null || ! $previous->is(self::NOT_A_NAME)) {
            return false;
        }

        return ! ($previous->is(T_CASE) && $next !== null && $next->is(T_DOUBLE_COLON));
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, string>  $replace
     */
    private static function join(array $tokens, array $replace): string
    {
        $output = '';

        foreach ($tokens as $index => $token) {
            $output .= $replace[$index] ?? $token->text;
        }

        return $output;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function previous(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function nextIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        if (isset(self::$files[$this->projectRoot])) {
            return self::$files[$this->projectRoot];
        }

        $skip = ['vendor', 'node_modules', 'storage', '.git', '.larapack', 'cache'];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), $skip, true))
        ));
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return self::$files[$this->projectRoot] = $files;
    }

    public static function reset(): void
    {
        self::$files = [];
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->projectRoot.'/') ? substr($file, strlen($this->projectRoot) + 1) : $file;
    }
}
