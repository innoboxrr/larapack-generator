<?php

namespace Innoboxrr\LarapackGenerator\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
 *  - Dentro, todos los .php salvo vendor, node_modules y los directorios
 *    ocultos.
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
     * Los prefijos de $autoload, del más largo al más corto (locate()).
     *
     * @var list<string>|null
     */
    private ?array $prefixes = null;

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
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    public function filesWithExtensions(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! $file->isDir()
                || ! (in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true) || str_starts_with($file->getFilename(), '.'))
        ));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                $path = str_replace('\\', '/', $file->getPathname());
                $files[(string) ($file->getRealPath() ?: $path)] = $path;
            }
        }

        return $files;
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

        // Ordenados una vez, no en cada búsqueda: verify busca la clase de
        // cada request de cada controlador.
        if ($this->prefixes === null) {
            $this->prefixes = array_map('strval', array_keys($autoload));

            // El prefijo más largo primero: `App\Models\` gana a `App\`.
            usort($this->prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        foreach ($this->prefixes as $prefix) {

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

        $this->sources = array_values(array_unique($this->sources));
    }
}
