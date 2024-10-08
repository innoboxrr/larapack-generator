<?php

if(!function_exists('root_path')) {
	function root_path() {
        // PENDIENTE: Debe verificar que está instalado en un paquete y no es el paquete per se
		$ruta = __DIR__;
        // Busca la raíz de la aplicación
        while (!file_exists($ruta . '/vendor/autoload.php')) {
            $ruta = dirname($ruta);
        }
        return realpath($ruta); 
	}
}

if(!function_exists('app_dir_name')) {
    function app_dir_name() {
        $rootPath = root_path();
        $composerJson = json_decode(file_get_contents($rootPath . '/composer.json'), true);
        $packageType = (isset($composerJson['type'])) ? $composerJson['type'] : 'library';
        return ($packageType == 'library') ? 'src' : 'app';
    }
}

if(!function_exists('stubs_path')) {
    function stubs_path($path) {
        // Modificar esto de manera dinámica: innoboxrr/larapack-generator        
        $path = root_path() . '/vendor/innoboxrr/larapack-generator/src/Stubs/' . $path;
        return $path;
    }
}

if(!function_exists('get_path')) {
    function get_path($path) {
        $path = root_path() . '/' . $path;
        if (!file_exists($path)) mkdir($path, 0777, true);
        return $path;
    }
}

if(!function_exists('get_namespace')) {
    function get_namespace()
    {
        $rootPath = root_path();
        $composerJson = json_decode(file_get_contents($rootPath . '/composer.json'), true);
        foreach ($composerJson as $key => $package) {
            if ($key == 'autoload') {
                foreach ($package['psr-4'] as $key => $path) {
                    if (strpos($path, app_dir_name() . '/') !== false) {
                        return $key;
                    }
                }
            }
        }
    }
}

if(!function_exists('get_dot_namespace')) {
    function get_dot_namespace()
    {
        $namespace = get_namespace();
        $dotNamespace = str_replace('\\', '.', $namespace);
        $dotNamespace = strtolower($dotNamespace);
        return $dotNamespace;
    }
}

if(!function_exists('get_kebab_namespace')) {
    function get_kebab_namespace()
    {
        $namespace = get_namespace();
        $dotNamespace = str_replace('\\', '-', $namespace);
        $dotNamespace = strtolower($dotNamespace);
        return $dotNamespace;
    }
}

if(!function_exists('get_accessor')) {
    function get_accessor($PascalCaseClass)
    {
        return pascal_case_to_kebab_case($PascalCaseClass);
    }
}

if(!function_exists('pascal_case_to_kebab_case')) {
    function pascal_case_to_kebab_case($string) {
        $kebabCase = "";
        $len = strlen($string);
        for ($i = 0; $i < $len; $i++) {
            $char = $string[$i];
            if (ctype_upper($char)) {
                if ($i > 0) {
                    $kebabCase .= "-";
                }
                $kebabCase .= strtolower($char);
            } else {
                $kebabCase .= $char;
            }
        }
        return $kebabCase;
    }
}