<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Un valor libre del laraimport —un `default`, un `cast`, la etiqueta de un
 * `enum`— convertido en literal del lenguaje donde acaba.
 *
 * Cada contexto tiene sus reglas y el mismo escape no sirve para dos: una
 * cadena JS bien escapada dentro de una interpolación de Vue todavía pasa por
 * el decodificador de entidades HTML del compilador de plantillas, y una
 * comilla doble en un atributo no se arregla con una barra. Por eso cada
 * contexto tiene su función, y las pruebas por propiedades
 * (tests/Unit/LiteralFuzzTest) comprueban las cuatro con miles de cadenas
 * hostiles: lo generado parsea y, al evaluarlo, devuelve exactamente el valor
 * declarado.
 *
 * Lo que ya era correcto sale igual que antes: regenerar no cambia los
 * archivos de quien no tenía valores raros.
 */
final class Literal
{
    /**
     * Literal PHP de comilla simple.
     *
     * Dentro de comilla simple PHP sólo interpreta \' y \\, y una barra al
     * final se comería la comilla de cierre. Lo demás —un salto de línea,
     * `?>`, un NUL— es texto dentro del literal.
     */
    public static function php(string $value): string
    {
        $plain = ! str_contains($value, "'")
            && ! str_contains($value, '\\\\')
            && ! str_ends_with($value, '\\');

        return $plain ? "'{$value}'" : var_export($value, true);
    }

    /**
     * El interior de una cadena JS de comilla simple.
     *
     * Escapar sólo la comilla no bastaba: una etiqueta que acababa en `\` se
     * comía la comilla de cierre, y una con `\'` dejaba la comilla suelta y lo
     * que la seguía como código del módulo. strtr() sustituye en una sola
     * pasada, así que la barra que escapa una comilla no se vuelve a escapar.
     * Los saltos de línea cortaban la cadena.
     */
    public static function js(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            "'" => "\\'",
            "\n" => '\\n',
            "\r" => '\\r',
            "\u{2028}" => '\\u2028',
            "\u{2029}" => '\\u2029',
        ]);
    }

    /**
     * El interior de una cadena JS de comilla simple que va dentro de una
     * interpolación `{{ … }}` de una plantilla Vue.
     *
     * El compilador de plantillas hace dos cosas antes de leer el JS: corta la
     * expresión en el primer `}}`, aunque esté dentro de una cadena, y si la
     * expresión lleva `&` decodifica las entidades HTML. Con el escape JS a
     * secas, una etiqueta `&#39;) + t.constructor.constructor(&#39;…&#39;)() + (&#39;`
     * llegaba al JS como `t('') + t.constructor.constructor('…')() + ('')`:
     * código que se ejecuta en el navegador de quien abre el formulario. Y
     * una con `}}` dejaba la plantilla sin compilar.
     *
     * `&` y `}` se escriben como escapes JS (`\x26`, `\x7d`): el compilador ya
     * no ve ni entidades ni cierres, y el JS los convierte de vuelta en el
     * mismo carácter. Son escapes de cadena y no entidades para que la clave
     * de traducción que lee Translations::keysIn() sea la misma que se pide
     * en tiempo de ejecución.
     */
    public static function vueInterpolation(string $value): string
    {
        return strtr(self::js($value), ['&' => '\\x26', '}' => '\\x7d']);
    }

    /**
     * Un valor dentro de un atributo HTML o JSX entre comillas dobles: el
     * `value` de una opción. Los dos decodifican las entidades.
     *
     * Los saltos de línea van como entidades numéricas: el analizador HTML
     * convierte un `\r` en bruto en `\n`, y el valor que llegaba al
     * formulario no era el declarado.
     */
    public static function htmlAttribute(string $value): string
    {
        return strtr(
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ["\r" => '&#13;', "\n" => '&#10;']
        );
    }
}
