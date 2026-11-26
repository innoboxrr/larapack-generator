<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

/**
 * Cadenas hostiles, reproducibles, para las pruebas por propiedades del
 * escapado.
 *
 * Mezclan lo que rompe cada contexto donde un valor del laraimport acaba como
 * código: comillas y barras (PHP, JS), saltos y separadores de línea (JS),
 * `?>` y `*\/` (comentarios y modo PHP), `${` y comillas invertidas
 * (plantillas JS), `</script>`, `}}` y entidades (plantillas Vue), NUL, BOM,
 * marcas combinantes, control bidireccional y secuencias de escape del
 * terminal. Siempre UTF-8 válido: es lo único que deja pasar json_decode, y
 * el laraimport es JSON.
 *
 * La semilla es fija: un fallo se reproduce con la misma lista, y el mensaje
 * del test dice qué cadena fue.
 */
final class HostileStrings
{
    public const SEED = 20261126;

    /**
     * Los trozos con los que se arma cada cadena.
     */
    private const FRAGMENTS = [
        "'", '"', '\\', '\\\\', "\\'", '\\"', "\0", "\n", "\r", "\r\n", "\t", "\u{2028}", "\u{2029}",
        '*/', '/*', '//', '#', '?>', '<?php', '<?=', '<?', '${', '{$', '$x', '`', '</script>', '</template>',
        '<!--', '-->', '{{', '}}', '{', '}', '&amp;', '&#39;', '&quot;', '&#x27;', '&lt;', '&', '<', '>',
        "\u{0301}", "e\u{0301}", "\u{FEFF}", "\u{202E}", "\u{200D}", "\e[31m", "\x7f", "\x08", '😀',
        '%s', '%1$s', '\\u{41}', '\\x41', '\\101', '\\n', ']]>', '__proto__', 'ñ', ' ', '0', '08', '-',
        ':attr', '|', ',', 'regex:/^a\\d+$/',
    ];

    /**
     * Las que tienen que estar siempre, antes de las aleatorias: cada una
     * rompía algo concreto en algún momento.
     */
    public const EDGES = [
        '', ' ', "it's", 'C:\\temp\\', '\\', '\\\\', "\\'", "x'); throw new \\RuntimeException('inyectado'); //",
        "Dos\nlíneas", "a\u{2028}b", '?>', '<?php echo 1; ?>', '*/', '${x}', '`${x}`', '</script>',
        'a}}b', '&#39;) + t.constructor.constructor(&#39;return 1&#39;)() + (&#39;', 'R&D', '&amp;',
        "\0", "a\0b", "\u{FEFF}", "e\u{0301}", "\e[2J", '08', '007', '1e3', ' 1', '-0', '1.50',
    ];

    /**
     * @return list<string>
     */
    public static function make(int $count, int $seed = self::SEED): array
    {
        mt_srand($seed);

        $strings = array_fill_keys(self::EDGES, true);

        while (count($strings) < $count) {
            $value = '';

            for ($parts = mt_rand(1, 6); $parts > 0; $parts--) {
                $value .= mt_rand(0, 3) === 0
                    ? self::randomCharacter()
                    : self::FRAGMENTS[mt_rand(0, count(self::FRAGMENTS) - 1)];
            }

            $strings[$value] = true;
        }

        // Que la semilla no se quede fijada para el resto de la suite.
        mt_srand();

        return array_map('strval', array_keys($strings));
    }

    /**
     * Un carácter cualquiera: ASCII imprimible o de control, o un punto de
     * código fuera del ASCII que no sea un sustituto.
     */
    private static function randomCharacter(): string
    {
        return match (mt_rand(0, 2)) {
            0 => chr(mt_rand(0x20, 0x7E)),
            1 => chr(mt_rand(0x00, 0x1F)),
            default => (string) mb_chr(self::nonSurrogate(), 'UTF-8'),
        };
    }

    private static function nonSurrogate(): int
    {
        do {
            $code = mt_rand(0x80, 0x10FFFF);
        } while ($code >= 0xD800 && $code <= 0xDFFF);

        return $code;
    }
}
