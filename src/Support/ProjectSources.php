<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Dónde está el código PHP de un proyecto y de sus paquetes locales, sin
 * cargar su autoloader.
 *
 * ControllerShape y LegacySecurity recorren lo mismo —todo `Http/Controllers`,
 * todo `Http/Requests`, todo `Http/Resources`, de la raíz y de cada paquete
 * local, los haya generado LaraPack o no— y cada una lo descubría con su
 * propia copia del mismo código. Dos copias acaban divergiendo: un paquete que
 * una ve y la otra no es un agujero en la guardia que nadie nota.
 *
 * Qué cuenta como fuente:
 *
 *  - Raíces: la del proyecto, los repositorios `path` de su composer.json y,
 *    aunque no estén declarados, `packages/*`. Cada una una sola vez, por su
 *    ruta real.
 *  - De cada raíz, sus directorios PSR-4; sin composer.json, o sin PSR-4,
 *    `app` y `src`.
 *  - De cada raíz, además, lo que carga por `classmap` o PSR-0: un
 *    `lib/Http/Controllers` enrutado no deja de ser un controlador.
 *  - Dentro, todos los .php salvo el `vendor/` y el `node_modules/` de cada
 *    raíz y los directorios ocultos; los directorios enlazados se siguen.
 *
 * Lo que queda fuera de la guardia, a propósito: sólo se miran las clases que
 * cuelgan de `Http/Controllers`, `Http/Requests` y `Http/Resources`. Un
 * componente de Livewire, un recurso o una página de Filament, o un
 * controlador colocado fuera de `Http/Controllers` (un `Actions/`, un
 * `Handlers/`) no siguen el patrón que se comprueba: no reciben un FormRequest
 * ni delegan en su `handle()`, sino que validan, autorizan y responden con el
 * ciclo de vida de su propio framework. Exigirles `return $request->handle();`
 * sería un falso positivo en cada archivo, y la única salida sería un
 * #[HandRolled] por clase que no diría nada. Si algún día tienen su propia
 * forma comprobable, será otra comprobación, no ésta ensanchada.
 */
final class ProjectSources
{
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules'];

    /**
     * Sin composer.json, o sin PSR-4 declarado.
     */
    private const DEFAULT_DIRECTORIES = ['app', 'src'];

    private string $root;

    private bool $discovered = false;

    /**
     * @var list<string>
     */
    private array $roots = [];

    /**
     * @var array<string, list<string>>
     */
    private array $autoload = [];

    /**
     * @var list<string>
     */
    private array $sources = [];

    public function __construct(string $root)
    {
        // Real, como los directorios que se recorren: si no, una raíz con un
        // enlace simbólico dejaría las rutas sin relativizar.
        $this->root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * La raíz y la de cada paquete local: donde se buscan sus routes/.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $this->discover();

        return $this->roots;
    }

    /**
     * Prefijo PSR-4 => directorios absolutos, de la raíz y de cada paquete
     * local. Con esto se encuentra el archivo de una clase sin cargar el
     * autoloader del proyecto.
     *
     * @return array<string, list<string>>
     */
    public function autoload(): array
    {
        $this->discover();

        return $this->autoload;
    }

    /**
     * Los directorios de código de todas las raíces, sin repetir.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $this->discover();

        return $this->sources;
    }

    /**
     * Los PHP de las fuentes que están bajo $segment (p. ej.
     * `/Http/Requests/`), ordenados y sin repetir los que se alcanzan por dos
     * caminos. El segmento se busca en la ruta relativa a la fuente: un
     * proyecto que vive dentro de un directorio `Http/Requests` no lo cuela
     * todo.
     *
     * @return list<string>
     */
    public function files(string $segment): array
    {
        $files = [];

        foreach ($this->sources() as $source) {
            foreach ($this->phpFiles($source) as $real => $path) {
                if (str_contains(substr($path, strlen($source)), $segment)) {
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
    public function phpFiles(string $directory): array
    {
        return $this->filesWithExtensions($directory, ['php']);
    }

    /**
     * Los archivos de un directorio con alguna de estas extensiones (en
     * minúsculas), con las mismas exclusiones que phpFiles(): ruta real =>
     * ruta tal como se recorrió. Un directorio que no existe no tiene ninguno.
     *
     * Lo que se salta es lo que instalan composer y npm —el `vendor/` y el
     * `node_modules/` de la raíz del proyecto o de un paquete local— y los
     * directorios ocultos. Un `vendor` en cualquier otro sitio es código del
     * proyecto: `src/Http/Controllers/vendor/` lo carga PSR-4 como cualquier
     * otro directorio, y saltárselo por el nombre dejaba fuera de `verify`
     * todo controlador que se guardara ahí. Un directorio enlazado se
     * recorre como lo recorre el autoloader; uno ya visitado (por su ruta
     * real) no se repite, así que un enlace circular no cuelga nada.
     *
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    public function filesWithExtensions(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $visited = [];
        $this->walk(rtrim(str_replace('\\', '/', $directory), '/'), $extensions, $files, $visited);

        return $files;
    }

    /**
     * @param  list<string>  $extensions
     * @param  array<string, string>  $files
     * @param  array<string, true>  $visited
     */
    private function walk(string $directory, array $extensions, array &$files, array &$visited): void
    {
        $real = realpath($directory);

        if ($real === false || isset($visited[$real])) {
            return;
        }

        $visited[$real] = true;

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                if (! str_starts_with($entry, '.') && ! $this->isInstalled($real, $entry)) {
                    $this->walk($path, $extensions, $files, $visited);
                }
            } elseif (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $extensions, true)) {
                $files[str_replace('\\', '/', (string) (realpath($path) ?: $path))] = $path;
            }
        }
    }

    /**
     * Si `$entry`, dentro de `$parent` (ruta real), es donde composer o npm
     * instalan lo de terceros: `vendor` o `node_modules` justo bajo la raíz
     * del proyecto o de un paquete local.
     */
    private function isInstalled(string $parent, string $entry): bool
    {
        return in_array($entry, self::SKIPPED_DIRECTORIES, true)
            && in_array(str_replace('\\', '/', $parent), $this->roots(), true);
    }

    /**
     * La raíz de un paquete local por el nombre de su directorio
     * (`school-library`), su ruta relativa (`packages/school-library`) o su
     * nombre de Composer (`innoboxrr/school-library`); null si no hay ninguno
     * con ese nombre. La raíz del proyecto no es un paquete.
     */
    public function packageRoot(string $name): ?string
    {
        $name = strtolower(trim(str_replace('\\', '/', $name), '/'));

        foreach ($this->roots() as $root) {
            if ($root === $this->root) {
                continue;
            }

            $composer = self::composer($root)['name'] ?? null;

            if (strtolower(basename($root)) === $name
                || strtolower($this->relative($root)) === $name
                || (is_string($composer) && strtolower($composer) === $name)
            ) {
                return $root;
            }
        }

        return null;
    }

    /**
     * El archivo de una clase por los prefijos PSR-4 del proyecto, o null.
     */
    public function locate(string $class): ?string
    {
        $autoload = $this->autoload();
        $prefixes = array_keys($autoload);

        // El prefijo más largo primero: `App\Models\` gana a `App\`.
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;

            if (! self::hasPrefix($class, $prefix)) {
                continue;
            }

            $path = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            foreach ($autoload[$prefix] as $directory) {
                $file = is_file($directory.'/'.$path) ? $directory.'/'.$path : self::findIgnoringCase($directory, $path);

                if ($file !== null) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Si la clase cae bajo un prefijo PSR-4 del proyecto. El prefijo vacío no
     * cuenta: es un directorio de respaldo para cualquier nombre, y no
     * encontrar ahí una clase no dice que no exista.
     */
    public function owns(string $class): bool
    {
        foreach (array_keys($this->autoload()) as $prefix) {
            if ($prefix !== '' && self::hasPrefix($class, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La raíz más profunda que contiene el archivo: la del paquete local al
     * que pertenece, o la del proyecto. Es la que tiene su manifiesto.
     */
    public function rootOf(string $file): string
    {
        $file = str_replace('\\', '/', realpath($file) ?: $file);
        $owner = $this->root;

        foreach ($this->roots() as $root) {
            if (str_starts_with($file, $root.'/') && strlen($root) > strlen($owner)) {
                $owner = $root;
            }
        }

        return $owner;
    }

    /**
     * La ruta relativa a la raíz del proyecto, con `/`; tal cual si cae fuera.
     */
    public function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $this->root.'/') ? substr($file, strlen($this->root) + 1) : $file;
    }

    /**
     * El composer.json de un directorio, o [] si no hay o no se lee.
     *
     * @return array<string, mixed>
     */
    public static function composer(string $directory): array
    {
        $file = $directory.'/composer.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * PHP no distingue mayúsculas en los nombres de clase: una request
     * importada como `app\Http\...` es la misma.
     */
    private static function hasPrefix(string $class, string $prefix): bool
    {
        return strncasecmp($class, $prefix, strlen($prefix)) === 0;
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
     * Se descubre una vez, la primera vez que se pregunta.
     */
    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;

        $roots = [$this->root];

        foreach ((array) (self::composer($this->root)['repositories'] ?? []) as $repository) {
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
            $psr4 = (array) (self::composer($real)['autoload']['psr-4'] ?? []);
            $declared = $psr4 !== [];

            if (! $declared) {
                $psr4 = ['' => self::DEFAULT_DIRECTORIES];
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

        // Lo que composer carga por classmap o PSR-0 también es código del
        // proyecto, aunque no tenga prefijo PSR-4 con el que localizar una
        // clase: un `lib/Http/Controllers` en `autoload.classmap` se
        // enruta igual que uno de `src/`.
        foreach ($this->roots as $root) {
            $autoload = (array) (self::composer($root)['autoload'] ?? []);
            $extra = [...array_values((array) ($autoload['classmap'] ?? []))];

            foreach ((array) ($autoload['psr-0'] ?? []) as $directories) {
                $extra = [...$extra, ...array_values((array) $directories)];
            }

            foreach ($extra as $directory) {
                $absolute = is_string($directory) ? realpath($root.'/'.$directory) : false;

                if ($absolute !== false && is_dir($absolute)) {
                    $this->sources[] = str_replace('\\', '/', $absolute);
                }
            }
        }

        $this->sources = array_values(array_unique($this->sources));
    }
}
