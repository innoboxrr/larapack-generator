<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * Los huecos: lo generado que se rellena a mano. Es lo que el README llama
 * «Dónde va tu código», escrito como datos para que el diagnóstico lo
 * aplique igual en cada archivo.
 *
 * Dos formas de hueco:
 *
 *  - El archivo entero (WHOLE_FILE): un trait de operaciones, una política,
 *    un observer. Editarlo es el trabajo normal.
 *  - Unos métodos de un archivo que por lo demás es del generador
 *    (METHODS): `rules()` de una request, `canView()` del ManagedFilter.
 *    Si lo que cambió está sólo ahí, el archivo está en su sitio; si cambió
 *    `authorize()` o `handle()`, la lógica se salió del hueco.
 *
 * Se reconocen por el stub con el que el manifiesto registró el archivo, el
 * mismo nombre que pone el generador, así que no pueden desincronizarse de
 * lo generado. Si se añade un hueco al README, se añade aquí.
 */
final class Holes
{
    /**
     * Prefijos de stub cuyo archivo entero es hueco.
     */
    public const WHOLE_FILE = [
        'ModelTraits/',
        'Resource/',
        'Events/Listeners/',
        'Observer/',
        'Factory/',
        'Test/',
        'Policy/',
        'Endpoint/TestPackageTemplate',
        'Endpoint/TestProjectTemplate',
    ];

    /**
     * Prefijo de stub => métodos que son hueco. Cualquier otra diferencia
     * —otro método, una propiedad, un `use`— está fuera.
     */
    public const METHODS = [
        'Requests/' => ['rules'],
        'Endpoint/RequestTemplate' => ['rules'],
        'Filters/ManagedFilter' => ['canView'],
    ];

    public static function wholeFile(string $stub): bool
    {
        foreach (self::WHOLE_FILE as $prefix) {
            if (str_starts_with($stub, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los métodos que son hueco en un archivo de este stub (vacío si
     * ninguno).
     *
     * @return list<string>
     */
    public static function methods(string $stub): array
    {
        foreach (self::METHODS as $prefix => $methods) {
            if (str_starts_with($stub, $prefix)) {
                return $methods;
            }
        }

        return [];
    }

    /**
     * Si un archivo de este stub, con estas piezas distintas de lo
     * generado, sólo cambió dentro de sus huecos. `$members` son los nombres
     * que da FileComparison::members(): `method:<nombre>` o `outside`.
     *
     * @param  list<string>  $members
     */
    public static function onlyHoles(string $stub, array $members): bool
    {
        if (self::wholeFile($stub)) {
            return true;
        }

        $allowed = array_map(fn (string $method): string => 'method:'.strtolower($method), self::methods($stub));

        return $members !== [] && array_diff(array_map('strtolower', $members), $allowed) === [];
    }
}
