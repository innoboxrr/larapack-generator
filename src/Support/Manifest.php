<?php

namespace Innoboxrr\LarapackGenerator\Support;

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
 */
final class Manifest
{
    public const VERSION = 1;

    private const RELATIVE_PATH = '.larapack/manifest.json';

    public function __construct(private ?string $root = null)
    {
    }

    public function path(): string
    {
        return ($this->root ?? root_path()) . '/' . self::RELATIVE_PATH;
    }

    private const EMPTY = [
        'version' => self::VERSION,
        'package' => ['namespace' => '', 'files' => []],
        'models' => [],
    ];

    /**
     * @return array{version: int, package: array{namespace: string, files: array<string, array{stub: string, hash: string}>}, models: array<string, array{namespace: string, files: array<string, array{stub: string, hash: string}>}>}
     */
    public function read(): array
    {
        if (! is_file($this->path())) {
            return self::EMPTY;
        }

        $decoded = json_decode(file_get_contents($this->path()), true);

        if (! is_array($decoded) || ! isset($decoded['models'])) {
            return self::EMPTY;
        }

        return $decoded + self::EMPTY;
    }

    /**
     * Anota un archivo generado. Escribe en cada llamada a propósito: una
     * herramienta puede abortar a mitad y el manifiesto tiene que reflejar lo
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
     * @return array{actions: array<int, string>, immutable: bool, secret: array<int, string>}
     */
    public function declarationOf(string $model): array
    {
        $declared = $this->read()['models'][$model]['declaration'] ?? [];

        return [
            'actions' => $declared['actions'] ?? Actions::ALL,
            'immutable' => (bool) ($declared['immutable'] ?? false),
            'secret' => $declared['secret'] ?? [],
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
        return array_keys($this->read()['models']);
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
     * Los dos contenedores del manifiesto: el del paquete y el de cada modelo.
     *
     * @return array<int, array{namespace: string, files: array<string, array{stub: string, hash: string}>}>
     */
    private function buckets(): array
    {
        $manifest = $this->read();

        return [$manifest["package"], ...array_values($manifest["models"])];
    }

    public function absolute(string $relative): string
    {
        return ($this->root ?? root_path()) . '/' . $relative;
    }

    public function hash(string $file): string
    {
        // Se normalizan los finales de línea: el mismo contenido no debe
        // parecer modificado por haber pasado por git en Windows.
        return hash('sha256', str_replace("\r\n", "\n", file_get_contents($file)));
    }

    private function relative(string $file): string
    {
        $root = str_replace('\\', '/', $this->root ?? root_path());
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
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
     * @param  array<string, mixed>  $manifest
     */
    private function write(array $manifest): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        ksort($manifest['models']);
        ksort($manifest['package']['files']);

        foreach ($manifest['models'] as &$model) {
            if (isset($model['files'])) {
                ksort($model['files']);
            }
        }

        file_put_contents(
            $this->path(),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
