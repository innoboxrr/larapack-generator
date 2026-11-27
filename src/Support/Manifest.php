<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Import\Actions;

/**
 * Registro de lo que el generador ha producido en un proyecto.
 *
 * Sin esto no hay forma de distinguir un archivo generado de uno escrito a
 * mano, que es justo lo que hace falta para poder regenerar sin destruir
 * personalizaciones y para detectar desviaciones.
 *
 * El hash es el del contenido en el momento de generarlo: si cambia, el
 * archivo se ha personalizado, lo cual es legítimo y esperado. Lo que no es
 * legítimo es que falte.
 *
 * @phpstan-type FileEntry array{stub: string, hash: string}
 * @phpstan-type Bucket array{namespace: string, files: array<string, FileEntry>}
 * @phpstan-type StoredDeclaration array{actions?: array<int, string>, immutable?: bool, secret?: array<int, string>, endpoints?: array<int, array<string, mixed>>, controller?: bool}
 * @phpstan-type ModelEntry array{namespace?: string, files?: array<string, FileEntry>, declaration?: StoredDeclaration}
 * @phpstan-type ManifestData array{version: int, package: Bucket, models: array<string, ModelEntry>}
 */
final class Manifest
{
    public const VERSION = 1;

    private const RELATIVE_PATH = '.larapack/manifest.json';

    /**
     * El contenido congelado de una instantánea; null en un manifiesto vivo.
     *
     * @var ManifestData|null
     */
    private ?array $frozen = null;

    /**
     * Cuántas veces se intenta mover el temporal sobre el manifiesto antes de
     * rendirse, y la espera de la primera (se dobla en cada intento).
     */
    private const ATTEMPTS = 6;

    private const FIRST_DELAY_MS = 25;

    /**
     * Ruta del manifiesto => su contenido, mientras dura una corrida.
     *
     * @var array<string, ManifestData>
     */
    private static array $buffers = [];

    /**
     * Ruta => true, de lo que se cambió en la corrida y hay que escribir.
     *
     * @var array<string, true>
     */
    private static array $dirty = [];

    private static int $depth = 0;

    private static int $diskWrites = 0;

    /**
     * Cómo se mueve el temporal sobre el manifiesto. Sólo lo cambian las
     * pruebas, para simular el bloqueo de Windows.
     *
     * @var (\Closure(string, string): bool)|null
     */
    private static ?\Closure $move = null;

    public function __construct(private ?string $root = null) {}

    /**
     * Ejecuta una corrida de generación con el manifiesto en memoria.
     *
     * ────────────────────────────────────────────────────────────────────────
     * POR QUÉ: antes cada archivo generado releía y reescribía el manifiesto
     * entero. En expediente pesa 1 MB y registra 3 969 archivos: era cuadrático
     * y, en Windows, el archivo recién escrito seguía abierto por el antivirus
     * o el indexador cuando llegaba la siguiente escritura, y el import moría
     * con «Failed to open stream: Permission denied» en un modelo distinto cada
     * vez, sin converger.
     * ────────────────────────────────────────────────────────────────────────
     *
     * Dentro de la corrida todas las instancias leen y anotan sobre la misma
     * copia en memoria (por ruta), así que lo que una anota la siguiente lo
     * ve. Se escribe una sola vez al salir de la corrida exterior, y en un
     * `finally`: si algo lanza a mitad, lo que ya quedó en disco queda también
     * registrado. Las corridas se pueden anidar: sólo escribe la de fuera. Si
     * además falla la escritura, PHP encadena el error original como
     * getPrevious() del de la escritura: no se pierde ninguno de los dos.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function batch(callable $work): mixed
    {
        self::$depth++;

        try {
            return $work();
        } finally {
            self::$depth--;

            if (self::$depth === 0) {
                self::flush();
            }
        }
    }

    /**
     * Cuántas veces se ha escrito un manifiesto en disco en este proceso. Es
     * para diagnosticar —y para las pruebas—: una corrida escribe una vez.
     */
    public static function diskWrites(): int
    {
        return self::$diskWrites;
    }

    /**
     * @internal sólo para las pruebas: simula un bloqueo del sistema de archivos.
     *
     * @param  (\Closure(string, string): bool)|null  $move
     */
    public static function moveUsing(?\Closure $move): void
    {
        self::$move = $move;
    }

    /**
     * Escribe lo que cambió en la corrida. Si un manifiesto no se puede
     * escribir se intentan los demás igualmente, y después se lanza el primer
     * error: callarlo dejaría archivos generados que nadie registró.
     */
    private static function flush(): void
    {
        $buffers = self::$buffers;
        $dirty = self::$dirty;
        self::$buffers = [];
        self::$dirty = [];

        $error = null;

        foreach (array_keys($dirty) as $path) {
            try {
                self::persist($path, $buffers[$path]);
            } catch (MakerException $e) {
                $error ??= $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Una copia de sólo lectura que lee el archivo una vez.
     *
     * read() vuelve al disco en cada llamada, y es lo correcto mientras se
     * genera: otra instancia puede haber escrito entre medias. Pero quien sólo
     * lee —el Verifier, la guardia de los agentes— lo llama miles de veces: en
     * school-management, con 128 modelos y un manifiesto de 1,5 MB, verificar
     * pasaba de varios minutos, demasiado para un hook que corre tras cada
     * edición.
     */
    public function snapshot(): self
    {
        $snapshot = new self($this->root ?? root_path());
        $snapshot->frozen = $this->read();

        return $snapshot;
    }

    public function path(): string
    {
        return ($this->root ?? root_path()).'/'.self::RELATIVE_PATH;
    }

    private const EMPTY = [
        'version' => self::VERSION,
        'package' => ['namespace' => '', 'files' => []],
        'models' => [],
    ];

    /**
     * @return ManifestData
     */
    public function read(): array
    {
        if ($this->frozen !== null) {
            return $this->frozen;
        }

        if (self::$depth > 0) {
            return self::$buffers[$this->path()] ??= $this->readFromDisk();
        }

        return $this->readFromDisk();
    }

    /**
     * @return ManifestData
     */
    private function readFromDisk(): array
    {
        if (! is_file($this->path())) {
            return self::EMPTY;
        }

        $decoded = json_decode(Files::read($this->path()), true);

        if (! is_array($decoded) || ! isset($decoded['models'])) {
            return self::EMPTY;
        }

        return $decoded + self::EMPTY;
    }

    /**
     * Anota un archivo generado. Fuera de una corrida (batch()) escribe en
     * cada llamada; dentro, se anota en memoria y se escribe al terminar la
     * corrida, también si aborta a mitad: el manifiesto tiene que reflejar lo
     * que de verdad quedó en disco.
     *
     * Los proveedores, la config o el andamiaje del módulo npm no pertenecen a
     * ninguna entidad: se registran aparte. Mezclarlos con los modelos hacía
     * que la comprobación de consistencia los tratara como una entidad más a
     * la que le faltaba todo.
     */
    public function record(string $model, string $namespace, string $file, string $stub): void
    {
        $manifest = $this->read();

        $bucket = $model === '' ? 'package' : 'models';
        $entry = [
            'stub' => $this->stubName($stub),
            'hash' => $this->hash($file),
        ];

        if ($bucket === 'package') {
            $manifest['package']['namespace'] = $namespace;
            $manifest['package']['files'][$this->relative($file)] = $entry;
        } else {
            $manifest['models'][$model]['namespace'] = $namespace;
            $manifest['models'][$model]['files'][$this->relative($file)] = $entry;
        }

        $this->write($manifest);
    }

    /**
     * Anota la forma que declaró el modelo.
     *
     * `verify` no recibe el laraimport, así que sin esto no tendría con qué
     * distinguir un modelo al que le falta el formulario de edición de uno que
     * declaró que no se edita. Con una declaración vacía se retira la clave:
     * es la forma de siempre.
     *
     * @param  array<string, mixed>  $declaration
     */
    public function declare(string $model, array $declaration): void
    {
        $manifest = $this->read();

        if ($declaration === []) {
            if (! isset($manifest['models'][$model]['declaration'])) {
                return;
            }

            unset($manifest['models'][$model]['declaration']);
        } else {
            $manifest['models'][$model]['declaration'] = $declaration;
        }

        $this->write($manifest);
    }

    /**
     * Un manifiesto anterior a los endpoints no los trae: la ausencia significa
     * que no hay ninguno, igual que la de `actions` significa todas.
     *
     * @return array{actions: array<int, string>, immutable: bool, secret: array<int, string>, endpoints: array<int, array<string, mixed>>, controller: bool}
     */
    public function declarationOf(string $model): array
    {
        $declared = $this->read()['models'][$model]['declaration'] ?? [];

        return [
            'actions' => $declared['actions'] ?? Actions::ALL,
            'immutable' => (bool) ($declared['immutable'] ?? false),
            'secret' => $declared['secret'] ?? [],
            'endpoints' => $declared['endpoints'] ?? [],
            'controller' => (bool) ($declared['controller'] ?? false),
        ];
    }

    public function forget(string $model): void
    {
        $manifest = $this->read();

        unset($manifest['models'][$model]);

        $this->write($manifest);
    }

    /**
     * @return array<int, string>
     */
    public function models(): array
    {
        // Ordenados: dentro de una corrida la copia en memoria aún no lo está.
        $models = array_keys($this->read()['models']);
        sort($models);

        return $models;
    }

    /**
     * @return array<string, array{stub: string, hash: string}>
     */
    public function filesFor(string $model): array
    {
        $manifest = $this->read();

        return $model === ''
            ? $manifest['package']['files']
            : ($manifest['models'][$model]['files'] ?? []);
    }

    /**
     * Lo que no pertenece a ninguna entidad: proveedores, config, andamiaje
     * del módulo npm.
     *
     * @return array<string, array{stub: string, hash: string}>
     */
    public function packageFiles(): array
    {
        return $this->read()['package']['files'];
    }

    public function knows(string $file): bool
    {
        $relative = $this->relative($file);

        foreach ($this->buckets() as $bucket) {
            if (isset($bucket['files'][$relative])) {
                return true;
            }
        }

        return false;
    }

    /**
     * El stub del que salió un archivo, o null si el generador no lo escribió.
     *
     * Es lo que distingue un hueco (una Policy, un trait de operaciones) de
     * lo que se regenera entero (el controlador, las rutas): el nombre del
     * stub lo pone el propio generador, así que no puede desincronizarse.
     */
    public function stubOf(string $file): ?string
    {
        $relative = $this->relative($file);

        foreach ($this->buckets() as $bucket) {
            if (isset($bucket['files'][$relative])) {
                return $bucket['files'][$relative]['stub'];
            }
        }

        return null;
    }

    /**
     * Un archivo cuyo hash ya no coincide con el registrado se ha editado a
     * mano. No es un error: es la señal de que regenerarlo destruiría trabajo.
     *
     * Un archivo que el manifiesto no conoce también cuenta como propio del
     * proyecto: el generador no sobrescribe lo que no escribió él.
     */
    public function wasCustomised(string $file): bool
    {
        if (! is_file($file)) {
            return false;
        }

        $relative = $this->relative($file);

        foreach ($this->buckets() as $bucket) {
            if (isset($bucket['files'][$relative])) {
                return $this->hash($file) !== $bucket['files'][$relative]['hash'];
            }
        }

        return true;
    }

    /**
     * Vuelve a anotar el hash de archivos que el generador reescribió después
     * de registrarlos, como al formatearlos.
     *
     * @param  array<int, string>  $files
     */
    public function rehash(array $files): void
    {
        if ($files === []) {
            return;
        }

        $manifest = $this->read();

        foreach ($files as $file) {
            $relative = $this->relative($file);

            if (isset($manifest['package']['files'][$relative])) {
                $manifest['package']['files'][$relative]['hash'] = $this->hash($file);
            }

            foreach ($manifest['models'] as $model => $entry) {
                if (isset($entry['files'][$relative])) {
                    $manifest['models'][$model]['files'][$relative]['hash'] = $this->hash($file);
                }
            }
        }

        $this->write($manifest);
    }

    /**
     * Los dos contenedores del manifiesto: el del paquete y el de cada modelo.
     *
     * @return list<Bucket>
     */
    private function buckets(): array
    {
        $manifest = $this->read();

        // Un modelo del que sólo se anotó la declaración —declare() antes de
        // registrar ningún archivo— no trae `namespace` ni `files`.
        return [$manifest['package'], ...array_map(
            fn (array $entry): array => ['namespace' => $entry['namespace'] ?? '', 'files' => $entry['files'] ?? []],
            array_values($manifest['models'])
        )];
    }

    /**
     * La raíz del proyecto al que pertenece este manifiesto. Lo que `verify`
     * comprueba fuera del manifiesto se busca a partir de ella.
     */
    public function root(): string
    {
        return $this->root ?? root_path();
    }

    public function absolute(string $relative): string
    {
        return ($this->root ?? root_path()).'/'.$relative;
    }

    public function hash(string $file): string
    {
        // Se normalizan los finales de línea: el mismo contenido no debe
        // parecer modificado por haber pasado por git en Windows. Uno que no
        // se puede leer falla: con '' su hash no coincidía y pasaba por editado
        // a mano.
        return hash('sha256', str_replace("\r\n", "\n", Files::read($file)));
    }

    private function relative(string $file): string
    {
        $root = str_replace('\\', '/', $this->root ?? root_path());
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;
    }

    /**
     * El stub se guarda relativo a Stubs/ para que el manifiesto sea legible
     * y no dependa de dónde esté instalado el paquete.
     */
    private function stubName(string $stub): string
    {
        $stub = str_replace('\\', '/', $stub);
        $marker = '/Stubs/';
        $position = strpos($stub, $marker);

        return $position === false ? basename($stub) : substr($stub, $position + strlen($marker));
    }

    /**
     * @param  ManifestData  $manifest
     */
    private function write(array $manifest): void
    {
        // Una instantánea que escribe sigue viendo lo que escribió.
        if ($this->frozen !== null) {
            $this->frozen = $manifest;
        }

        if (self::$depth > 0) {
            self::$buffers[$this->path()] = $manifest;
            self::$dirty[$this->path()] = true;

            return;
        }

        self::persist($this->path(), $manifest);
    }

    /**
     * Escribe el manifiesto de forma atómica: a un temporal junto a él y
     * después rename() encima, así que nunca queda a medio escribir.
     *
     * Un bloqueo pasajero —en Windows, el antivirus o el indexador con el
     * archivo abierto— se reintenta unas pocas veces con espera creciente. Si
     * no cede, el temporal NO se borra: es lo registrado en la corrida, y el
     * error dice dónde está para moverlo a mano.
     *
     * @param  ManifestData  $manifest
     */
    private static function persist(string $path, array $manifest): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw MakerException::directoryNotCreated($directory);
        }

        ksort($manifest['models']);
        ksort($manifest['package']['files']);

        foreach ($manifest['models'] as &$model) {
            if (isset($model['files'])) {
                ksort($model['files']);
            }
        }

        unset($model);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temporary = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json) !== strlen($json)) {
            @unlink($temporary);

            throw MakerException::manifestNotWritten($path, self::lastError(), null);
        }

        $move = self::$move ?? static fn (string $from, string $to): bool => @rename($from, $to);
        $delay = self::FIRST_DELAY_MS;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if ($move($temporary, $path)) {
                self::$diskWrites++;

                return;
            }

            if ($attempt < self::ATTEMPTS) {
                usleep($delay * 1000);
                $delay *= 2;
            }
        }

        throw MakerException::manifestNotWritten($path, self::lastError(), $temporary);
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'motivo desconocido';
    }
}
