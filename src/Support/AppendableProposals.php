<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Illuminate\Support\Str;

/**
 * Qué accessors pide el front con `appends`, por Resource: una PROPUESTA para
 * el `$appendable` que `larapack:fix resource-appends` deja vacío.
 *
 * El arreglo no la escribe nunca. Una lista mal hecha deja de mostrar un dato
 * en pantalla sin que nada falle, y lo único que se lee aquí es texto del
 * front: una persona confirma cada nombre y lo copia a mano.
 *
 * Qué se lee: los .js, .jsx, .ts, .tsx y .vue de `resources/` del proyecto y
 * de `resources/{react,js,vue}` de cada paquete local en alcance. Se reconocen
 * `appends[]=x` y `appends=x,y` en una URL, `appends: ['x']` y
 * `appends = ['x']` (también `'appends[]': ...` y `params.appends = ...`),
 * `appends: 'x,y'` y `appends.push('x')`. Lo que pasa por una variable no se
 * ve.
 *
 * A qué Resource va cada nombre: al modelo que el front menciona cerca
 * (ASSOCIATION_WINDOW líneas arriba o abajo) o, si cerca no hay ninguno, en el
 * archivo entero. Cuenta como mención un segmento de una cadena —el nombre de
 * una ruta o una URL— igual al modelo en snake_case, kebab-case o plural
 * (`api.app.bib_copy.index`, `/bib-copies/`), y el nombre del modelo o de su
 * Resource como identificador. Con dos modelos o ninguno, o con dos Resources
 * del mismo modelo en paquetes distintos que el archivo no desempata, el
 * nombre sale «sin asociar» con su archivo:línea.
 *
 * `exists` dice si el modelo del Resource (o un trait o una clase padre del
 * proyecto) declara ese accessor: `getXAttribute()` o un método que devuelve
 * `Attribute`. Un `exists: false` es un nombre que hoy tumba la respuesta o
 * una errata del front; no se añade sin mirarlo.
 *
 * @phpstan-type AccessorProposal array{name: string, exists: bool, sources: list<string>}
 * @phpstan-type ResourceProposal array{file: string, model: string|null, accessors: list<AccessorProposal>}
 * @phpstan-type Unassociated array{name: string, source: string, reason: string}
 * @phpstan-type Proposals array{confirmed: false, warning: string, resources: list<ResourceProposal>, unassociated: list<Unassociated>}
 */
final class AppendableProposals
{
    public const WARNING = 'Propuestas sin confirmar, leídas del front: no se escriben en $appendable. Revisa cada una y cópiala a mano; una lista mal hecha deja de mostrar un dato en pantalla.';

    public const FRONT_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'vue'];

    /**
     * Dónde está el front de un paquete local.
     */
    public const PACKAGE_FRONT = ['resources/react', 'resources/js', 'resources/vue'];

    public const ASSOCIATION_WINDOW = 15;

    /**
     * nombre corto del modelo en minúsculas => Resources (ruta absoluta =>
     * [modelo completo o null, nombre corto]).
     *
     * @var array<string, array<string, array{0: string|null, 1: string}>>
     */
    private array $resources = [];

    /**
     * clave de mención (snake, kebab, plural, Studly) => nombre corto en
     * minúsculas.
     *
     * @var array<string, string>
     */
    private array $keys = [];

    /**
     * Nombre corto del modelo, con sus mayúsculas => en minúsculas.
     *
     * @var array<string, string>
     */
    private array $studly = [];

    public function __construct(private ProjectSources $project) {}

    /**
     * @param  list<string>  $resourceFiles  los Resources en alcance (rutas absolutas)
     * @param  list<string>  $frontRoots  las raíces cuyo front se lee
     * @return Proposals
     */
    public function propose(array $resourceFiles, array $frontRoots): array
    {
        $this->index($resourceFiles);

        /** @var array<string, array<string, list<string>>> $byResource */
        $byResource = [];
        $unassociated = [];

        foreach ($this->frontFiles($frontRoots) as $file) {
            $text = (string) file_get_contents($file);
            $lines = explode("\n", $text);
            $relative = $this->project->relative($file);

            foreach (self::requested($text) as [$name, $line]) {
                $source = "{$relative}:{$line}";
                [$resource, $reason] = $this->associate($file, $lines, $line);

                if ($resource === null) {
                    $unassociated[] = ['name' => $name, 'source' => $source, 'reason' => $reason];

                    continue;
                }

                $byResource[$resource][$name][] = $source;
            }
        }

        ksort($byResource);
        $resources = [];

        foreach ($byResource as $resource => $names) {
            $model = $this->resources[strtolower($this->shortOf($resource))][$resource][0] ?? null;
            $accessors = $model === null ? [] : $this->accessors($model, []);
            ksort($names);
            $proposals = [];

            foreach ($names as $name => $sources) {
                $proposals[] = [
                    'name' => (string) $name,
                    'exists' => in_array(Str::snake((string) $name), $accessors, true) || in_array((string) $name, $accessors, true),
                    'sources' => array_values(array_unique($sources)),
                ];
            }

            $resources[] = ['file' => $this->project->relative($resource), 'model' => $model, 'accessors' => $proposals];
        }

        return ['confirmed' => false, 'warning' => self::WARNING, 'resources' => $resources, 'unassociated' => $unassociated];
    }

    /**
     * Los nombres que pide un archivo del front, con su línea.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function requested(string $text): array
    {
        $found = [];
        $add = function (string $names, int $offset) use (&$found, $text): void {
            $line = substr_count($text, "\n", 0, $offset) + 1;

            foreach (preg_split('/[\s,]+/', $names) ?: [] as $name) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                    $found["{$line}|{$name}"] = [$name, $line];
                }
            }
        };
        $quoted = function (string $fragment, int $offset) use ($add): void {
            preg_match_all('/([\'"`])([A-Za-z0-9_,\s]+)\1/', $fragment, $strings);

            foreach ($strings[2] as $string) {
                $add($string, $offset);
            }
        };

        // En una URL: appends[]=x, appends%5B%5D=x, appends=x,y.
        preg_match_all('/\bappends(?:\[\]|%5B%5D)?=([A-Za-z0-9_,]+)/i', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$names, $offset]) {
            $add($names, $offset);
        }

        // Una lista: appends: [...], appends = [...], 'appends[]': [...].
        preg_match_all('/\bappends(?:\[\])?[\'"]?\s*[:=]\s*\[([^\]]*)\]/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$fragment, $offset]) {
            $quoted($fragment, $offset);
        }

        // Una cadena: appends: 'x,y'.
        preg_match_all('/\bappends(?:\[\])?[\'"]?\s*[:=]\s*([\'"`][A-Za-z0-9_,\s]+[\'"`])/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$fragment, $offset]) {
            $quoted($fragment, $offset);
        }

        // appends.push('x', 'y').
        preg_match_all('/\bappends\.push\(([^)]*)\)/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$fragment, $offset]) {
            $quoted($fragment, $offset);
        }

        $found = array_values($found);
        usort($found, fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return $found;
    }

    /**
     * @param  list<string>  $resourceFiles
     */
    private function index(array $resourceFiles): void
    {
        $this->resources = [];
        $this->keys = [];
        $this->studly = [];

        foreach ($resourceFiles as $file) {
            $outline = PhpOutline::ofCode((string) file_get_contents($file));

            foreach ($outline->classes() as $class) {
                if ($class['kind'] !== 'class' || ! str_ends_with($class['name'], 'Resource') || $class['name'] === 'Resource') {
                    continue;
                }

                $short = substr($class['name'], 0, -strlen('Resource'));
                $this->resources[strtolower($short)][$file] = [$this->modelOf($outline, $class['fqcn'], $short), $short];
                $this->studly[$short] = strtolower($short);

                foreach ([Str::snake($short), Str::kebab($short), Str::snake(Str::plural($short)), Str::kebab(Str::plural($short))] as $key) {
                    $this->keys[strtolower($key)] = strtolower($short);
                }
            }
        }
    }

    /**
     * El modelo de un Resource: el que importa con ese nombre, o
     * `<raíz>\Models\<Modelo>` si el Resource vive en `<raíz>\Http\...`.
     */
    private function modelOf(PhpOutline $outline, string $resource, string $short): ?string
    {
        $imported = $outline->resolveName($short);

        if ($this->project->locate($imported) !== null) {
            return $imported;
        }

        $position = stripos($resource, '\\Http\\');

        if ($position !== false) {
            $conventional = substr($resource, 0, $position).'\\Models\\'.$short;

            if ($this->project->locate($conventional) !== null) {
                return $conventional;
            }
        }

        return null;
    }

    private function shortOf(string $resource): string
    {
        foreach ($this->resources as $files) {
            if (isset($files[$resource])) {
                return $files[$resource][1];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: string|null, 1: string}
     */
    private function associate(string $file, array $lines, int $line): array
    {
        $from = max(0, $line - 1 - self::ASSOCIATION_WINDOW);
        $near = $this->mentions(implode("\n", array_slice($lines, $from, 2 * self::ASSOCIATION_WINDOW + 1)));
        $models = $near !== [] ? $near : $this->mentions(implode("\n", $lines));

        if ($models === []) {
            return [null, 'El archivo no menciona ningún modelo con Resource en alcance.'];
        }

        if (count($models) > 1) {
            return [null, 'Menciona varios modelos y no se sabe de cuál es: '.implode(', ', array_map(
                fn (string $model): string => $this->resources[$model][array_key_first($this->resources[$model])][1],
                $models
            )).'.'];
        }

        $candidates = $this->resources[$models[0]];

        if (count($candidates) > 1) {
            $owner = $this->project->rootOf($file);
            $same = array_filter(array_keys($candidates), fn (string $resource): bool => $this->project->rootOf($resource) === $owner);

            if (count($same) !== 1) {
                return [null, 'Hay varios Resources de '.$candidates[array_key_first($candidates)][1].' ('.implode(', ', array_map(
                    fn (string $resource): string => $this->project->relative($resource),
                    array_keys($candidates)
                )).') y el archivo no es de ninguno de sus paquetes.'];
            }

            return [array_values($same)[0], ''];
        }

        return [(string) array_key_first($candidates), ''];
    }

    /**
     * Los modelos (nombre corto en minúsculas) que menciona un texto.
     *
     * @return list<string>
     */
    private function mentions(string $text): array
    {
        $models = [];

        preg_match_all('/([\'"`])([^\'"`\n]*)\1/', $text, $strings);

        foreach ($strings[2] as $string) {
            foreach (preg_split('/[.\/?&=:]+/', strtolower($string)) ?: [] as $segment) {
                if (isset($this->keys[$segment])) {
                    $models[$this->keys[$segment]] = true;
                }
            }
        }

        preg_match_all('/\b([A-Z][A-Za-z0-9]*)\b/', $text, $identifiers);

        foreach ($identifiers[1] as $identifier) {
            // Tal cual se escribe la clase: `Loan` o `LoanResource`.
            $short = str_ends_with($identifier, 'Resource') ? substr($identifier, 0, -strlen('Resource')) : $identifier;

            if (isset($this->studly[$short])) {
                $models[$this->studly[$short]] = true;
            }
        }

        $models = array_keys($models);
        sort($models);

        return $models;
    }

    /**
     * Los accessors (en snake_case) del modelo, sus traits y sus padres del
     * proyecto.
     *
     * @param  array<string, true>  $visited
     * @return list<string>
     */
    private function accessors(string $class, array $visited): array
    {
        $key = strtolower($class);

        if (isset($visited[$key]) || ! $this->project->owns($class)) {
            return [];
        }

        $visited[$key] = true;
        $file = $this->project->locate($class);

        if ($file === null) {
            return [];
        }

        $code = (string) file_get_contents($file);
        $accessors = [];

        preg_match_all('/function\s+get([A-Za-z0-9_]+)Attribute\s*\(/', $code, $matches);

        foreach ($matches[1] as $name) {
            $accessors[] = Str::snake($name);
        }

        preg_match_all('/function\s+([A-Za-z0-9_]+)\s*\([^)]*\)\s*:\s*\??\s*\\\\?(?:[A-Za-z0-9_]+\\\\)*Attribute\b/', $code, $matches);

        foreach ($matches[1] as $name) {
            $accessors[] = Str::snake($name);
        }

        foreach (PhpOutline::ofCode($code)->classes() as $outlined) {
            if (strcasecmp($outlined['fqcn'], $class) !== 0) {
                continue;
            }

            foreach ([...$outlined['traits'], ...($outlined['extends'] === null ? [] : [$outlined['extends']])] as $related) {
                $accessors = [...$accessors, ...$this->accessors($related, $visited)];
            }
        }

        return array_values(array_unique($accessors));
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function frontFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            $directories = $root === $this->project->root()
                ? [$root.'/resources']
                : array_map(fn (string $directory): string => $root.'/'.$directory, self::PACKAGE_FRONT);

            foreach ($directories as $directory) {
                foreach ($this->project->filesWithExtensions($directory, self::FRONT_EXTENSIONS) as $real => $path) {
                    $files[$real] = $path;
                }
            }
        }

        $files = array_values($files);
        sort($files);

        return $files;
    }
}
