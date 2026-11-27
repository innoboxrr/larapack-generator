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
 * @phpstan-type FileEntry array{stub: string, hash: string, generator?: string, state?: string}
 * @phpstan-type Bucket array{namespace: string, files: array<string, FileEntry>}
 * @phpstan-type StoredDeclaration array{actions?: array<int, string>, immutable?: bool, secret?: array<int, string>, endpoints?: array<int, array<string, mixed>>, controller?: bool}
 * @phpstan-type ModelEntry array{namespace?: string, files?: array<string, FileEntry>, declaration?: StoredDeclaration}
 * @phpstan-type ManifestData array{version: int, package: Bucket, models: array<string, ModelEntry>}
 */
final class Manifest
{
    /**
     * Versión del formato. La 2 (9.4.0) añade a cada archivo, sin quitar
     * nada de la 1:
     *
     *  - `generator`: la versión del generador que lo escribió o lo adoptó.
     *    Sin ella, un archivo que difiere de la plantilla actual no dice si
     *    es porque la plantilla cambió o porque alguien lo editó.
     *  - `state`: `adopted` si el archivo no lo escribió el generador sino
     *    que se adoptó como si lo hubiera escrito (`larapack:realign
     *    adopt`). Ausente significa `generated`.
     *
     * Un manifiesto de la versión 1 se sigue leyendo igual —sus archivos no
     * dicen versión ni estado—, y la siguiente escritura lo deja en la 2. Uno
     * de una versión posterior se lee, pero no se escribe: lo que no se
     * entiende se perdería al reescribirlo.
     */
    public const VERSION = 2;

    public const GENERATED = 'generated';

    public const ADOPTED = 'adopted';

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

        // Un manifiesto sin versión, o con una que no es un número, es de la
        // forma 1: así nacieron todos.
        $decoded['version'] = is_int($decoded['version'] ?? null) ? $decoded['version'] : 1;

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

        $this->put($manifest, $model, $namespace, $file, [
            'stub' => $this->stubKey($stub),
            'hash' => $this->hash($file),
            'generator' => GeneratorVersion::current(),
        ]);

        $this->write($manifest);
    }

    /**
     * Anota como gestionado un archivo que el generador no escribió en esta
     * corrida: uno escrito a mano que coincide con lo que generaría el
     * contrato, o uno generado cuyo registro se había perdido. Se anota el
     * hash de lo que hay en disco —así `--force` lo podrá regenerar y
     * `verify` lo dará por no editado— y `state: adopted`, para que se sepa
     * siempre que no salió del generador.
     *
     * `$stub` es el nombre relativo a Stubs/ (`Model/ModelTemplate.txt`) o
     * la ruta del stub.
     *
     * Quién decide que es seguro adoptarlo no es el manifiesto: es el
     * diagnóstico de `larapack:realign`, que lo compara con lo regenerado.
     *
     * `$hashOf`, si se da, es el archivo cuyo hash se anota en vez del de
     * `$file`: adoptar algo escrito a mano «como editado» anota el hash de lo
     * que generaría el contrato, así `verify` lo ve personalizado y `--force`
     * no lo toca nunca.
     */
    public function adopt(string $model, string $namespace, string $file, string $stub, ?string $hashOf = null): void
    {
        $manifest = $this->read();

        $this->put($manifest, $model, $namespace, $file, [
            'stub' => $this->stubKey($stub),
            'hash' => $this->hash($hashOf ?? $file),
            'generator' => GeneratorVersion::current(),
            'state' => self::ADOPTED,
        ]);

        $this->write($manifest);
    }

    /**
     * Vuelve a anotar un archivo registrado con el hash de lo que hay en
     * disco y la versión actual del generador, conservando su stub y su
     * estado: un archivo generado cuyo hash es de antes de pasarlo por Pint
     * sigue siendo generado. `$generator` lo anota además; sin él, sólo el
     * hash.
     *
     * @return bool si estaba registrado
     */
    public function refresh(string $file, bool $generator = true): bool
    {
        return $this->updateEntry($file, fn (array $entry): array => ['hash' => $this->hash($file)]
            + ($generator ? ['generator' => GeneratorVersion::current()] : [])
            + $entry);
    }

    /**
     * Anota la versión actual del generador en un archivo registrado que no
     * la dice (los de un manifiesto v1), sin tocar su hash: es lo que se hace
     * con lo heredado que el diagnóstico encuentra igual que lo que generaría
     * hoy el contrato fuera de sus huecos. Uno que ya dice su versión no se
     * toca.
     *
     * @return bool si se anotó
     */
    public function stamp(string $file): bool
    {
        $stamped = false;

        $this->updateEntry($file, function (array $entry) use (&$stamped): array {
            if (isset($entry['generator'])) {
                return $entry;
            }

            $stamped = true;

            return $entry + ['generator' => GeneratorVersion::current()];
        });

        return $stamped;
    }

    /**
     * Pasa el registro de un archivo a otro dueño (un modelo, o el paquete
     * con ''), con su stub, su hash, su versión y su estado. Es lo que
     * corresponde al módulo de la interfaz que registró un modelo que se
     * retiró del contrato y que hoy genera otro.
     *
     * @return bool si estaba registrado
     */
    public function move(string $file, string $model, string $namespace): bool
    {
        $manifest = $this->read();
        $relative = $this->relative($file);
        $entry = $manifest['package']['files'][$relative] ?? null;
        unset($manifest['package']['files'][$relative]);

        foreach ($manifest['models'] as $owner => $bucket) {
            if (isset($bucket['files'][$relative])) {
                $entry ??= $bucket['files'][$relative];
                unset($manifest['models'][$owner]['files'][$relative]);
            }
        }

        if ($entry === null) {
            return false;
        }

        $this->put($manifest, $model, $namespace, $file, $entry);
        $this->write($manifest);

        return true;
    }

    /**
     * El namespace con el que se registran los archivos de un modelo (o del
     * paquete, con ''), o null si el manifiesto no lo conoce.
     */
    public function namespaceOf(string $model): ?string
    {
        $manifest = $this->read();
        $namespace = $model === '' ? $manifest['package']['namespace'] : ($manifest['models'][$model]['namespace'] ?? null);

        return is_string($namespace) ? $namespace : null;
    }

    /**
     * @param  callable(FileEntry): FileEntry  $change
     */
    private function updateEntry(string $file, callable $change): bool
    {
        $manifest = $this->read();
        $relative = $this->relative($file);
        $found = false;

        if (isset($manifest['package']['files'][$relative])) {
            $manifest['package']['files'][$relative] = $change($manifest['package']['files'][$relative]);
            $found = true;
        }

        foreach ($manifest['models'] as $model => $entry) {
            if (isset($entry['files'][$relative])) {
                $manifest['models'][$model]['files'][$relative] = $change($entry['files'][$relative]);
                $found = true;
            }
        }

        if ($found) {
            $this->write($manifest);
        }

        return $found;
    }

    /**
     * Mueve el registro de un archivo a otra ruta, conservando su stub, su
     * hash, su versión y su estado: una migración renombrada a mano sigue
     * siendo la misma migración. Si el contenido cambió además de la ruta,
     * el hash viejo hace que `verify` la siga viendo editada, que es cierto.
     *
     * @return bool si había algo que mover
     */
    public function relink(string $from, string $to): bool
    {
        $manifest = $this->read();
        $from = $this->relative($from);
        $to = $this->relative($to);
        $moved = false;

        if (isset($manifest['package']['files'][$from])) {
            $manifest['package']['files'][$to] = $manifest['package']['files'][$from];
            unset($manifest['package']['files'][$from]);
            $moved = true;
        }

        foreach ($manifest['models'] as $model => $entry) {
            if (isset($entry['files'][$from])) {
                $manifest['models'][$model]['files'][$to] = $entry['files'][$from];
                unset($manifest['models'][$model]['files'][$from]);
                $moved = true;
            }
        }

        if ($moved) {
            $this->write($manifest);
        }

        return $moved;
    }

    /**
     * Olvida un archivo: deja de estar gestionado. Es lo que corresponde a
     * uno que se borró a propósito (de un modelo retirado del contrato);
     * el archivo en disco, si existe, no se toca.
     *
     * @return bool si estaba registrado
     */
    public function drop(string $file): bool
    {
        $manifest = $this->read();
        $relative = $this->relative($file);
        $dropped = false;

        if (isset($manifest['package']['files'][$relative])) {
            unset($manifest['package']['files'][$relative]);
            $dropped = true;
        }

        foreach ($manifest['models'] as $model => $entry) {
            if (isset($entry['files'][$relative])) {
                unset($manifest['models'][$model]['files'][$relative]);
                $dropped = true;
            }
        }

        if ($dropped) {
            $this->write($manifest);
        }

        return $dropped;
    }

    /**
     * El registro completo de un archivo, con su versión y su estado ya
     * resueltos (los de un manifiesto v1 no los traen), o null si el
     * manifiesto no lo conoce.
     *
     * @return array{model: string, stub: string, hash: string, generator: string|null, state: string}|null
     */
    public function entryOf(string $file): ?array
    {
        $relative = $this->relative($file);
        $manifest = $this->read();

        $candidates = ['' => $manifest['package']['files'][$relative] ?? null];

        foreach ($manifest['models'] as $model => $entry) {
            $candidates[(string) $model] = $entry['files'][$relative] ?? null;
        }

        foreach ($candidates as $model => $entry) {
            if ($entry !== null) {
                return [
                    'model' => (string) $model,
                    'stub' => $entry['stub'],
                    'hash' => $entry['hash'],
                    'generator' => $entry['generator'] ?? null,
                    'state' => $entry['state'] ?? self::GENERATED,
                ];
            }
        }

        return null;
    }

    /**
     * La versión del formato tal como está en disco (o en la copia de la
     * corrida): 1 en un manifiesto anterior a 9.4.0.
     */
    public function formatVersion(): int
    {
        return $this->read()['version'];
    }

    /**
     * @param  ManifestData  $manifest
     * @param  FileEntry  $entry
     */
    private function put(array &$manifest, string $model, string $namespace, string $file, array $entry): void
    {
        if ($model === '') {
            $manifest['package']['namespace'] = $namespace;
            $manifest['package']['files'][$this->relative($file)] = $entry;
        } else {
            $manifest['models'][$model]['namespace'] = $namespace;
            $manifest['models'][$model]['files'][$this->relative($file)] = $entry;
        }
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
     * El nombre con el que se guarda un stub: una ruta absoluta se recorta
     * a lo que cuelga de Stubs/ (stubName); un nombre ya relativo
     * (`Model/ModelTemplate.txt`, el que da el diagnóstico de `realign`) se
     * guarda tal cual.
     */
    private function stubKey(string $stub): string
    {
        return preg_match('#^(/|[A-Za-z]:[/\\\\])#', $stub) === 1 ? $this->stubName($stub) : str_replace('\\', '/', $stub);
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

        // Uno escrito por una versión posterior del formato no se reescribe:
        // lo que esta versión no conoce se perdería en silencio.
        if ($manifest['version'] > self::VERSION) {
            throw MakerException::manifestTooNew($path, $manifest['version'], self::VERSION);
        }

        $manifest['version'] = self::VERSION;

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
