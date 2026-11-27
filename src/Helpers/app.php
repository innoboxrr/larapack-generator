<?php

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;

if (! function_exists('root_path')) {
    function root_path(): string
    {
        return ProjectRoot::path();
    }
}

if (! function_exists('root_composer_json')) {
    /**
     * El composer.json de la raíz, ya decodificado.
     *
     * De él sale si se genera en src/ o en app/ y con qué namespace. Uno que
     * no se puede leer o que no es un objeto JSON no se interpreta: antes se
     * leía como vacío, y un proyecto pasaba a ser un paquete sin namespace.
     *
     * @return array<mixed>
     */
    function root_composer_json(): array
    {
        $path = root_path().'/composer.json';
        $composerJson = json_decode(Files::read($path), true);

        if (! is_array($composerJson)) {
            throw MakerException::invalidComposerJson($path, json_last_error_msg());
        }

        return $composerJson;
    }
}

if (! function_exists('app_dir_name')) {
    function app_dir_name(): string
    {
        $packageType = root_composer_json()['type'] ?? 'library';

        return ($packageType == 'library') ? 'src' : 'app';
    }
}

if (! function_exists('stubs_path')) {
    function stubs_path(string $path): string
    {
        // Se resuelve relativo a este archivo (src/Helpers), así funciona tanto
        // instalado en vendor/ como ejecutando el paquete directamente.
        return dirname(__DIR__).'/Stubs/'.$path;
    }
}

if (! function_exists('get_path')) {
    function get_path(string $path): string
    {
        $path = root_path().'/'.$path;
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }
}

if (! function_exists('get_namespace')) {
    /**
     * El namespace PSR-4 que apunta a src/ o app/, con su separador final.
     *
     * Se lee como lo lee Composer: la ruta puede ir sin barra final («src») o
     * ser una lista de rutas («["lib/", "src/"]»); basta con que una apunte al
     * directorio. El prefijo vacío ("") también lo acepta Composer, pero no
     * es un namespace que dar: lo generado acabaría en el global.
     *
     * Sin ninguno no hay namespace, y se falla. En 9.1 devolvía null y
     * reventaba más abajo con un TypeError; devolver '' era peor: el modelo
     * salía en el namespace global (`namespace Models;`) y la factoría
     * escribía `Database\Factories\` sin el del paquete en su composer.json.
     */
    function get_namespace(): string
    {
        $directory = app_dir_name();
        $psr4 = root_composer_json()['autoload']['psr-4'] ?? [];
        $onlyGlobal = false;

        foreach (is_array($psr4) ? $psr4 : [] as $key => $paths) {
            if (! is_string($key) || ! psr4_points_to($paths, $directory)) {
                continue;
            }

            if ($key === '') {
                $onlyGlobal = true;

                continue;
            }

            return $key;
        }

        throw MakerException::missingNamespace(root_path().'/composer.json', $directory, $onlyGlobal);
    }
}

if (! function_exists('psr4_points_to')) {
    /**
     * Si una entrada PSR-4 —una ruta o una lista de rutas— apunta al
     * directorio. La barra final se normaliza: Composer acepta «src» y «src/».
     */
    function psr4_points_to(mixed $paths, string $directory): bool
    {
        foreach (is_array($paths) ? $paths : [$paths] as $path) {
            if (is_string($path) && strpos(rtrim($path, '/').'/', $directory.'/') !== false) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('get_dot_namespace')) {
    function get_dot_namespace(): string
    {
        $namespace = get_namespace();
        $dotNamespace = str_replace('\\', '.', $namespace);
        $dotNamespace = strtolower($dotNamespace);

        return $dotNamespace;
    }
}

if (! function_exists('get_kebab_namespace')) {
    function get_kebab_namespace(): string
    {
        $namespace = get_namespace();
        $dotNamespace = str_replace('\\', '-', $namespace);
        $dotNamespace = strtolower($dotNamespace);

        return $dotNamespace;
    }
}

if (! function_exists('get_accessor')) {
    function get_accessor(string $PascalCaseClass): string
    {
        return pascal_case_to_kebab_case($PascalCaseClass);
    }
}

if (! function_exists('pascal_case_to_kebab_case')) {
    function pascal_case_to_kebab_case(string $string): string
    {
        $kebabCase = '';
        $len = strlen($string);
        for ($i = 0; $i < $len; $i++) {
            $char = $string[$i];
            if (ctype_upper($char)) {
                if ($i > 0) {
                    $kebabCase .= '-';
                }
                $kebabCase .= strtolower($char);
            } else {
                $kebabCase .= $char;
            }
        }

        return $kebabCase;
    }
}
