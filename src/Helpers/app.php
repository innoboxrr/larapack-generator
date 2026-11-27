<?php

use Innoboxrr\LarapackGenerator\Support\ProjectRoot;

if (! function_exists('root_path')) {
    function root_path(): string
    {
        return ProjectRoot::path();
    }
}

if (! function_exists('app_dir_name')) {
    function app_dir_name(): string
    {
        $rootPath = root_path();
        $composerJson = json_decode((string) file_get_contents($rootPath.'/composer.json'), true);
        $packageType = (is_array($composerJson) && isset($composerJson['type'])) ? $composerJson['type'] : 'library';

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
     * Sin ninguno devuelve '' —lo mismo que daba el null de antes al
     * concatenarlo—, y un composer.json sin `autoload.psr-4` ya no avisa de
     * que recorre un null.
     */
    function get_namespace(): string
    {
        $rootPath = root_path();
        $composerJson = json_decode((string) file_get_contents($rootPath.'/composer.json'), true);
        $psr4 = is_array($composerJson) ? ($composerJson['autoload']['psr-4'] ?? []) : [];

        foreach (is_array($psr4) ? $psr4 : [] as $key => $path) {
            if (is_string($path) && strpos($path, app_dir_name().'/') !== false) {
                return (string) $key;
            }
        }

        return '';
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
