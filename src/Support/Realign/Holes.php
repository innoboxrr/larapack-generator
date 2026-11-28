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
 *    (METHODS): los ganchos de validación de una request —`rules()`,
 *    `messages()`, `attributes()`, `prepareForValidation()`,
 *    `passedValidation()`, que la plantilla deja vacíos—, `canView()` del
 *    ManagedFilter, `defineGates()` del AuthServiceProvider. Si lo que
 *    cambió está sólo ahí, el archivo está en su sitio; si cambió
 *    `authorize()` o `handle()`, la lógica se salió del hueco.
 *  - Un método que la plantilla deja sin implementar sólo en una de sus
 *    variantes (PLACEHOLDERS): el `handle()` con `abort(501)` y el
 *    `authorize()` que nace cerrado de un endpoint sin modelo. Es hueco si
 *    lo regenerado trae ese marcador; en el endpoint de modelo, que delega
 *    en la operación y pregunta a la política, no.
 *
 * Se reconocen por el stub con el que el manifiesto registró el archivo, el
 * mismo nombre que pone el generador, así que no pueden desincronizarse de
 * lo generado. Si se añade un hueco al README, se añade aquí; HolesTest
 * cruza esta lista con las plantillas y con el README.
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
        // IndexRequest::prepareForValidation() no nace vacío: fuerza
        // `managed`, que es lo que acota el listado. Va antes que
        // `Requests/`: gana el primer prefijo que coincide.
        'Requests/IndexRequest' => ['rules', 'messages', 'attributes', 'passedValidation'],
        'Requests/' => ['rules', 'messages', 'attributes', 'prepareForValidation', 'passedValidation'],
        'Endpoint/RequestTemplate' => ['prepareForValidation', 'rules'],
        'Filters/ManagedFilter' => ['canView'],
        'Providers/AuthServiceProvider' => ['defineGates'],
    ];

    /**
     * Prefijo de stub => método => el marcador (tokens de FileComparison::
     * pieces(), sin espacios ni comentarios) que lo regenerado tiene que
     * traer para que ese método sea hueco: la plantilla lo deja sin
     * implementar sólo en una de sus variantes.
     */
    public const PLACEHOLDERS = [
        'Endpoint/RequestTemplate' => [
            'authorize' => '{ return false ; }',
            'handle' => '{ abort ( 501 ,',
        ],
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
     * ninguno). Con lo regenerado (`$regenerated`, su contenido), también
     * los de PLACEHOLDERS cuyo marcador trae.
     *
     * @return list<string>
     */
    public static function methods(string $stub, ?string $regenerated = null): array
    {
        $found = [];

        foreach (self::METHODS as $prefix => $methods) {
            if (str_starts_with($stub, $prefix)) {
                $found = $methods;

                break;
            }
        }

        if ($regenerated === null) {
            return $found;
        }

        foreach (self::PLACEHOLDERS as $prefix => $markers) {
            if (! str_starts_with($stub, $prefix)) {
                continue;
            }

            $pieces = FileComparison::pieces($regenerated);

            foreach ($markers as $method => $marker) {
                if (str_contains($pieces['method:'.strtolower($method)] ?? '', $marker)) {
                    $found[] = $method;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Si un archivo de este stub, con estas piezas distintas de lo
     * generado, sólo cambió dentro de sus huecos. `$members` son los nombres
     * que da FileComparison::members(): `method:<nombre>`, `imports` u
     * `outside`. Los `imports` cuentan como hueco en un archivo con métodos
     * hueco: son los del código que se escribió en ellos. `$regenerated` es
     * el contenido de lo regenerado, para los huecos que dependen de la
     * variante (PLACEHOLDERS).
     *
     * @param  list<string>  $members
     */
    public static function onlyHoles(string $stub, array $members, ?string $regenerated = null): bool
    {
        if (self::wholeFile($stub)) {
            return true;
        }

        $methods = self::methods($stub, $regenerated);

        // El código de un hueco trae sus `use`: añadirlos es parte de
        // escribir en él (en school-management, 605 canView() con los suyos).
        $allowed = $methods === [] ? [] : ['imports', ...array_map(fn (string $method): string => 'method:'.strtolower($method), $methods)];

        return $members !== [] && array_diff(array_map('strtolower', $members), $allowed) === [];
    }
}
