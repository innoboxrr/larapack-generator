<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use RuntimeException;

/**
 * Proyecto desechable en un directorio temporal, para generar dentro de él
 * sin tocar el repositorio del propio generador.
 */
final class FakeProject
{
    private function __construct(
        public readonly string $path,
        public readonly string $namespace,
    ) {
    }

    /**
     * Paquete Composer: `app_dir_name()` devolverá `src`.
     */
    public static function library(string $namespace = 'TestVendor\\TestPkg\\'): self
    {
        return self::make('library', $namespace);
    }

    /**
     * Aplicación Laravel: `app_dir_name()` devolverá `app`.
     */
    public static function application(string $namespace = 'App\\'): self
    {
        return self::make('project', $namespace);
    }

    private static function make(string $type, string $namespace): self
    {
        $path = sys_get_temp_dir() . '/larapack-' . bin2hex(random_bytes(6));

        if (! mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException("No se pudo crear el directorio temporal {$path}");
        }

        $sourceDir = $type === 'library' ? 'src' : 'app';

        file_put_contents($path . '/composer.json', json_encode([
            'name' => 'testvendor/test-pkg',
            'type' => $type,
            'autoload' => ['psr-4' => [$namespace => $sourceDir . '/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return new self(realpath($path), $namespace);
    }

    public function has(string $relative): bool
    {
        return file_exists($this->path . '/' . $relative);
    }

    public function read(string $relative): string
    {
        $file = $this->path . '/' . $relative;

        if (! is_file($file)) {
            throw new RuntimeException("El archivo generado no existe: {$relative}");
        }

        return file_get_contents($file);
    }

    /**
     * Primer archivo cuyo nombre coincide con el patrón glob dado, relativo a
     * la raíz. Útil para migraciones, cuyo nombre lleva timestamp.
     */
    public function glob(string $pattern): ?string
    {
        $matches = glob($this->path . '/' . $pattern);

        return $matches ? substr($matches[0], strlen($this->path) + 1) : null;
    }

    /**
     * Todos los .php generados, en rutas relativas.
     *
     * @return array<int, string>
     */
    public function phpFiles(): array
    {
        $files = [];
        $prefixLength = strlen($this->path) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), $prefixLength));
            }
        }

        sort($files);

        return $files;
    }

    public function cleanup(): void
    {
        self::remove($this->path);
    }

    private static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            is_dir($path) ? self::remove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
