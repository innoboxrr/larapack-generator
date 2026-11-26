<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Literal;
use Innoboxrr\LarapackGenerator\Support\Translations;
use Innoboxrr\LarapackGenerator\Tests\Support\HostileStrings;
use ParseError;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas por propiedades del escapado: miles de cadenas hostiles,
 * reproducibles (HostileStrings::SEED), por cada contexto donde un valor del
 * laraimport acaba como código.
 *
 * La propiedad es siempre la misma: lo generado se analiza sin errores, no
 * sale del literal y, al evaluarlo como lo haría su lenguaje, devuelve
 * exactamente el valor original. Un caso concreto lo cubre ya
 * GeneratedLiteralsTest; éste busca el que nadie pensó.
 */
final class LiteralFuzzTest extends TestCase
{
    private const COUNT = 3000;

    /**
     * @var list<string>
     */
    private static array $strings = [];

    public static function setUpBeforeClass(): void
    {
        self::$strings = HostileStrings::make(self::COUNT);
    }

    public function test_el_generador_es_reproducible(): void
    {
        $this->assertSame(self::$strings, HostileStrings::make(self::COUNT));
        $this->assertCount(self::COUNT, self::$strings);
    }

    public function test_el_literal_php_es_solo_una_cadena_y_vale_lo_mismo(): void
    {
        foreach (self::$strings as $value) {
            $literal = Literal::php($value);

            try {
                $tokens = token_get_all('<?php '.$literal.';', TOKEN_PARSE);
            } catch (ParseError $e) {
                $this->fail('No parsea el literal de '.json_encode($value).": {$literal}");
            }

            // Sólo cadenas, concatenación y espacios: nada que sea código, y
            // nada que salga del modo PHP: un cierre de etiqueta fuera del literal.
            foreach (array_slice($tokens, 1) as $token) {
                $kind = is_array($token) ? $token[0] : $token;

                $this->assertContains($kind, [T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE, '.', ';'],
                    'El literal de '.json_encode($value).' no es sólo una cadena: '.$literal);
            }

            $this->assertSame($value, eval('return '.$literal.';'), 'Valor distinto para '.json_encode($value));
        }
    }

    public function test_la_cadena_js_parsea_y_vale_lo_mismo(): void
    {
        $this->assertSame(
            self::$strings,
            $this->evaluateJs(array_map(fn (string $value): string => "'".Literal::js($value)."'", self::$strings))
        );
    }

    /**
     * El compilador de Vue corta la interpolación en el primer `}}` y, si
     * hay `&`, decodifica entidades antes de leer el JS. Si lo escapado no
     * trae ni lo uno ni lo otro, lo que lee es exactamente el JS escrito.
     */
    public function test_la_interpolacion_vue_no_lleva_cierres_ni_entidades_y_vale_lo_mismo(): void
    {
        $literals = [];

        foreach (self::$strings as $value) {
            $escaped = Literal::vueInterpolation($value);

            $this->assertStringNotContainsString('}', $escaped, 'Cierre posible dentro de la interpolación para '.json_encode($value));
            $this->assertStringNotContainsString('&', $escaped, 'Entidad decodificable para '.json_encode($value));

            $literals[] = "'{$escaped}'";
        }

        $this->assertSame(self::$strings, $this->evaluateJs($literals));
    }

    /**
     * Un atributo HTML (Vue) o JSX entre comillas dobles: los dos decodifican
     * las entidades y ninguno las barras.
     *
     * ENT_HTML401 y no ENT_HTML5 para comprobarlo: el decodificador de PHP en
     * modo HTML5 se niega a decodificar `&#13;`, que Vue y JSX sí decodifican.
     */
    public function test_el_atributo_no_sale_de_sus_comillas_y_vale_lo_mismo(): void
    {
        foreach (self::$strings as $value) {
            $attribute = Literal::htmlAttribute($value);

            $this->assertDoesNotMatchRegularExpression('/["<>\r\n]/', $attribute, 'Atributo inseguro para '.json_encode($value));
            $this->assertSame($value, html_entity_decode($attribute, ENT_QUOTES | ENT_HTML401, 'UTF-8'), 'Valor distinto para '.json_encode($value));
        }
    }

    /**
     * La clave que se escribe en el archivo de idioma es la que `t()` pide al
     * ejecutarse: la misma cadena, no la que queda al quitar las barras.
     */
    public function test_la_clave_de_traduccion_es_el_valor_original(): void
    {
        $expected = array_values(array_filter(self::$strings, fn (string $value): bool => $value !== ''));

        $this->assertSame($expected, $this->keysOf(array_map(fn (string $value): string => "t('".Literal::js($value)."')", $expected), Translations::FRONTEND));
        $this->assertSame($expected, $this->keysOf(array_map(fn (string $value): string => "{{ t('".Literal::vueInterpolation($value)."') }}", $expected), Translations::FRONTEND));

        // var_export parte en concatenaciones una cadena con NUL: eso no es
        // una clave literal y no se recoge.
        $php = array_values(array_filter($expected, fn (string $value): bool => ! str_contains($value, "\0")));

        $this->assertSame($php, $this->keysOf(array_map(fn (string $value): string => '__('.Literal::php($value).')', $php), Translations::BACKEND));
    }

    // AYUDAS

    /**
     * @param  list<string>  $calls
     * @return list<string>
     */
    private function keysOf(array $calls, string $function): array
    {
        $file = tempnam(sys_get_temp_dir(), 'larapack-keys-');
        file_put_contents((string) $file, implode("\n", $calls)."\n");

        try {
            return Translations::keysIn([(string) $file], $function);
        } finally {
            @unlink((string) $file);
        }
    }

    /**
     * Evalúa los literales JS con node y devuelve sus valores.
     *
     * @param  list<string>  $literals
     * @return list<string>
     */
    private function evaluateJs(array $literals): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        // Sin node no se comprueba nada: que conste como omitido y no como
        // pasado, o el test daría verde según la máquina.
        if ($node === '') {
            $this->markTestSkipped('node no disponible: no se puede evaluar el JS generado.');
        }

        $file = sys_get_temp_dir().'/larapack-fuzz-'.bin2hex(random_bytes(6)).'.mjs';
        file_put_contents($file, "const values = [\n".implode(",\n", $literals)."\n];\nprocess.stdout.write(JSON.stringify(values));\n");

        exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1', $output, $code);
        @unlink($file);

        $this->assertSame(0, $code, "El JS generado no se puede analizar:\n".implode("\n", array_slice($output, 0, 20)));

        $values = json_decode(implode("\n", $output), true);

        $this->assertIsArray($values, 'node no devolvió una lista.');

        return $values;
    }
}
