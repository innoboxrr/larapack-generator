<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Illuminate\Support\Pluralizer;

/**
 * El prefijo de tablas del laraimport que se está generando.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * EL PROBLEMA QUE RESUELVE.
 *
 * El nombre de la tabla salía sólo del nombre del modelo: `Student` daba
 * `students`. En una aplicación suelta eso está bien. En un ecosistema donde
 * veinte paquetes comparten UNA base de datos, no: `students`, `groups`,
 * `subjects`, `programs` o `terms` son los primeros nombres que se le ocurren
 * a cualquiera, y el segundo paquete que los quiera choca con el primero.
 *
 * La salida conocida era prefijar el nombre de la CLASE —`AcademicsStudent`—,
 * que resuelve la colisión a costa de tartamudear en cada `use`, cada relación
 * y cada prueba del paquete. El prefijo es infraestructura de persistencia; no
 * tiene por qué subir al nombre de dominio.
 *
 *     {
 *         "table_prefix": "academics_",
 *         "models": [ { "name": "Student", ... } ]
 *     }
 *
 *     class Student     ->  tabla academics_students
 * ────────────────────────────────────────────────────────────────────────────
 *
 * SIN LA CLAVE, NADA CAMBIA. El prefijo vacío es la única forma de que un
 * proyecto que ya existe salga byte a byte igual que antes, y por eso todos los
 * métodos de aquí devuelven el nombre sin tocar cuando no hay prefijo.
 *
 * LO QUE SE PREFIJA Y LO QUE NO. Se prefija todo lo que nombra una tabla de
 * ESTE contrato: `Schema::create`, el `$table` del modelo, el `->constrained()`
 * de una clave ajena interna y el nombre del archivo de migración. NO se
 * prefija una clave ajena que apunta fuera —`users`, `parties`, la tabla de
 * otro paquete—, porque esa tabla no es nuestra y ya tiene el nombre que tiene.
 * Esa distinción es la razón de que esta clase guarde también la lista de
 * tablas propias.
 */
final class TablePrefix
{
    /**
     * El prefijo, ya normalizado. Vacío cuando el laraimport no lo declara.
     */
    private static string $prefix = '';

    /**
     * Las tablas SIN prefijar que declara este laraimport: las de sus modelos,
     * sus metas y sus pivotes.
     *
     * @var array<int, string>
     */
    private static array $own = [];

    /**
     * Toma el prefijo y las tablas propias del documento ya normalizado.
     *
     * @param  array<string, mixed>  $document
     */
    public static function fromDocument(array $document): void
    {
        self::set(is_string($document['table_prefix'] ?? null) ? $document['table_prefix'] : '');

        $own = [];

        foreach ($document['models'] ?? [] as $model) {
            if (! isset($model['name'])) {
                continue;
            }

            $own[] = self::tableOf((string) $model['name']);

            // Las metas son una tabla más, y una clave ajena puede apuntarle.
            $own[] = self::singularOf((string) $model['name']).'_metas';
        }

        foreach ($document['pivots'] ?? [] as $pivot) {
            if (isset($pivot['name'])) {
                $own[] = (string) $pivot['name'];
            }
        }

        self::$own = array_values(array_unique($own));
    }

    /**
     * Normaliza y guarda el prefijo.
     *
     * SE ACEPTA CON Y SIN LA BARRA BAJA FINAL. `"academics"` y `"academics_"`
     * dan lo mismo, porque la alternativa es que alguien escriba el primero y
     * se encuentre con la tabla `academicsstudents` sin entender por qué. El
     * esquema documenta la forma con barra baja; esto perdona la otra.
     */
    public static function set(string $prefix): void
    {
        $prefix = trim($prefix);

        self::$prefix = $prefix === '' ? '' : rtrim($prefix, '_').'_';
    }

    public static function get(): string
    {
        return self::$prefix;
    }

    /**
     * Devuelve el estado a como estaba. La importación lo llama en un `finally`:
     * un prefijo que sobreviviera a la orden se colaría en la siguiente.
     */
    public static function reset(): void
    {
        self::$prefix = '';
        self::$own = [];
    }

    /**
     * El nombre real de una tabla de este contrato.
     */
    public static function table(string $table): string
    {
        return self::$prefix.$table;
    }

    /**
     * El nombre al que apunta un `->constrained()`.
     *
     * Sólo se prefija si la tabla es de este contrato. Una clave ajena hacia
     * `users` o hacia la tabla de otro paquete se deja intacta: prefijarla
     * generaría una restricción contra una tabla que no existe, y el fallo
     * aparecería en `migrate`, no aquí.
     */
    public static function constraint(string $table): string
    {
        return in_array($table, self::$own, true) ? self::table($table) : $table;
    }

    /**
     * La tabla que le correspondería a un modelo, sin prefijar.
     *
     * Es la misma regla que aplica el generador —`tableize` + plural—, escrita
     * aquí para que la lista de tablas propias no dependa de instanciar nada.
     */
    public static function tableOf(string $model): string
    {
        return Pluralizer::plural(self::singularOf($model));
    }

    private static function singularOf(string $model): string
    {
        return mb_strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $model));
    }
}
