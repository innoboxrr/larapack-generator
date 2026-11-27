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
 * cualificado que apunta a él —en un `use`, en un `\App\…\Trait::class`, o
 * relativo al namespace del archivo—. Nada más: un comentario o un docblock
 * que lo nombre se queda como está (no cambia el comportamiento).
 *
 * Qué hace que no se mueva, con su razón:
 *
 *  - lo usa un controlador (es un error de `verify`, y el arreglo no es
 *    moverlo sino llevar la lógica a la request: controller-shape);
 *  - el archivo no tiene un único trait con el nombre del archivo, o su
 *    namespace no es el de su ruta;
 *  - ya hay un archivo en el destino;
 *  - alguien lo nombra de una forma que no se reescribe con certeza: una
 *    importación agrupada (`use …\Concerns\{A, B}`), una cadena
 *    (`'App\Http\Controllers\Concerns\X'`, que podría ser configuración), o
 *    por su nombre corto desde su mismo namespace sin importarlo.
 */
final class ConcernMove
{
    private const SEGMENT = 'Http/Controllers/Concerns/';

    /**
     * Los PHP del proyecto, por raíz, para no recorrerlo una vez por trait.
     *
     * @var array<string, list<string>>
     */
    private static array $files = [];

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param  string  $traitFile  relativo a la raíz del proyecto
     * @return array{changes: Changes, to: string, references: list<string>}|string lo que hay que escribir, o por qué no
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

        $moved = preg_replace('/namespace\s+'.preg_quote($oldNamespace, '/').'\s*;/', 'namespace '.$newNamespace.';', $code, 1, $count);

        if (! is_string($moved) || $count !== 1) {
            return 'No se encuentra su declaración de namespace.';
        }

        $changes = new Changes($this->projectRoot);
        $changes->delete($absolute);
        $changes->put($this->projectRoot.'/'.$to, $moved);
        $references = [];

        foreach ($this->phpFiles() as $file) {
            if ($file === $absolute) {
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

        return ['changes' => $changes, 'to' => $to, 'references' => $references];
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
        $namespace = '';
        $imports = [];
        $output = '';
        $changed = false;

        foreach ($tokens as $index => $token) {
            $text = $token->text;
            $previous = self::previous($tokens, $index);

            if ($token->is([T_NAME_QUALIFIED, T_STRING]) && $previous !== null && $previous->is(T_NAMESPACE)) {
                $namespace = $text;
            } elseif ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $bare = ltrim($text, '\\');
                $absolute = $token->is(T_NAME_FULLY_QUALIFIED) || ($previous !== null && $previous->is(T_USE));
                $resolved = $absolute ? $bare : ($namespace === '' ? $bare : $namespace.'\\'.$bare);
                $next = $tokens[$index + 1] ?? null;

                if (strcasecmp($bare, $oldNamespace) === 0 && $next !== null && $next->is(T_NS_SEPARATOR)) {
                    return ['lo importa en un `use` agrupado: reescríbelo a mano.'];
                }

                if (strcasecmp($resolved, $old) === 0) {
                    $text = ($token->is(T_NAME_FULLY_QUALIFIED) || ! $absolute ? '\\' : '').$new;
                    $changed = true;
                }

                if ($previous !== null && $previous->is(T_USE) && strcasecmp($resolved, $old) !== 0) {
                    $imports[strtolower(substr($bare, (int) strrpos('\\'.$bare, '\\')))] = true;
                }
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && stripos(str_replace('\\\\', '\\', $text), $old) !== false) {
                return ['lo nombra en una cadena (¿configuración?): no se cambia a ciegas.'];
            } elseif ($token->is(T_STRING) && strcasecmp($text, $name) === 0 && strcasecmp($namespace, $oldNamespace) === 0 && ! isset($imports[strtolower($name)])) {
                return ['lo usa por su nombre corto desde su mismo namespace: muévelos juntos a mano.'];
            }

            $output .= $text;
        }

        return $changed ? $output : $content;
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
