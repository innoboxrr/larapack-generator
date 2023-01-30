<?php

if(!function_exists('root_path')) {

	function root_path() {

        // PENDIENTE: Debe verificar que está instalado en un paquete y no es el paquete per se

		$ruta = __DIR__;

        // Busca la raíz de la aplicación
        while (!file_exists($ruta . '/vendor/autoload.php')) {

            $ruta = dirname($ruta);

        }

        return realpath($ruta); // Dentro del paquete

        //return realpath($ruta . '/../../..'); //

	}

}



if(!function_exists('app_dir_name')) {

    function app_dir_name() {

        $rootPath = root_path();

        $composerJson = json_decode(file_get_contents($rootPath . '/composer.json'), true);

        $packageType = $composerJson['type'];

        return ($packageType == 'library') ? 'src' : 'app';

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