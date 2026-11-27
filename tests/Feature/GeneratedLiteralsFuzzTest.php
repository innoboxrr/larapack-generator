<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\HostileStrings;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use ParseError;
use Symfony\Component\Console\Command\Command;

/**
 * La prueba por propiedades de LiteralFuzzTest, pero sobre lo que genera de
 * verdad `larapack:import`: cadenas hostiles en cada campo libre del
 * laraimport —`default` de modelo y de pivote, `cast`, valores y etiquetas de
 * `enum`, claves y valores de `rules`— y, en cada archivo donde acaban, que el
 * archivo se analiza y que el valor se recupera idéntico.
 *
 * Así se cubre lo que la prueba unitaria no ve: un sitio que pegue el valor
 * sin pasar por Literal, o con el escape de otro contexto.
 */
final class GeneratedLiteralsFuzzTest extends TestCase
{
    private const COUNT = 48;

    /**
     * @var list<string>
     */
    private array $strings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Shop\\'));

        // Una clave que empieza por NUL no llega a generarse: el validador la
        // rechaza (test_una_clave_que_empieza_por_nul_se_rechaza_y_se_dice_por_que).
        // Aquí las cadenas también son claves —del enum, de las reglas—.
        $this->strings = array_values(array_filter(
            HostileStrings::make(self::COUNT),
            fn (string $value): bool => ! str_starts_with($value, "\0")
        ));

        $props = [];
        $enum = [];
        $rules = [];

        foreach ($this->strings as $i => $value) {
            $props[] = ['name' => "d{$i}", 'type' => 'string', 'default' => $value];
            $props[] = ['name' => "c{$i}", 'type' => 'string', 'cast' => $value];
            // Etiqueta al revés de la clave, para que una no se confunda con
            // la otra al recuperarlas.
            $enum[$value] = $this->label($value);
            $rules["d{$i}"] = $value;
            $rules[$value] = [$value, 'required'];
        }

        $props[] = [
            'name' => 'status', 'type' => 'string', 'datatable' => true, 'form' => true,
            'form_component' => 'SelectInputComponent', 'enum' => $enum,
        ];

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, json_encode([
            'models' => [[
                'name' => 'Product',
                'props' => $props,
                'requests' => [['name' => 'Create', 'rules' => $rules]],
            ]],
            'pivots' => [[
                'name' => 'product_tag',
                'props' => array_map(fn (int $i): array => ['name' => "p{$i}", 'type' => 'string', 'default' => $this->strings[$i]], array_keys($this->strings)),
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true, '--react' => true]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    /**
     * Más que `php -l`: un `?>` en un comentario compila, y deja el resto del
     * archivo como HTML que se imprime.
     */
    /**
     * Antes salía «The data (null) must match the type: object», que no
     * decía qué había que cambiar.
     */
    public function test_una_clave_que_empieza_por_nul_se_rechaza_y_se_dice_por_que(): void
    {
        $path = $this->project->path.'/nul.json';

        file_put_contents($path, json_encode(['models' => [[
            'name' => 'Tag',
            'props' => [['name' => 'kind', 'type' => 'string', 'enum' => ["\0x" => 'X']]],
        ]]], JSON_THROW_ON_ERROR));

        $this->assertNotSame(Command::SUCCESS, $this->runCommand('larapack:validate', ['jsonPath' => $path]));
        $this->assertStringContainsString('NUL', $this->lastOutput);
        $this->assertStringNotContainsString('(null)', $this->lastOutput);
    }

    public function test_todo_el_php_generado_parsea_sin_salir_del_modo_php(): void
    {
        foreach ($this->project->phpFiles() as $relative) {
            // Una vista Blade es HTML por definición.
            if (str_ends_with($relative, '.blade.php')) {
                continue;
            }

            $code = $this->project->read($relative);

            try {
                $tokens = token_get_all($code, TOKEN_PARSE);
            } catch (ParseError $e) {
                $this->fail("{$relative} no parsea: {$e->getMessage()} (línea {$e->getLine()})");
            }

            foreach ($tokens as $token) {
                $this->assertFalse(is_array($token) && $token[0] === T_INLINE_HTML, "{$relative} sale del modo PHP: ".json_encode(is_array($token) ? $token[1] : ''));
            }
        }
    }

    public function test_el_default_de_cada_columna_es_el_valor_declarado(): void
    {
        $model = $this->project->read((string) $this->project->glob('database/migrations/*_create_products_table.php'));
        $pivot = $this->project->read((string) $this->project->glob('database/migrations/*_create_product_tag_table.php'));

        // Las columnas son de texto: desde 9.3.1 también una cadena con forma
        // de número —`-0`, `1.50`— va entre comillas. Sin ellas `1.50` era el
        // número 1.5 y la base guardaba `'1.5'`.
        foreach ($this->strings as $i => $value) {
            $this->assertSame($value, $this->argumentAfter($model, "\$table->string('d{$i}')", '->default('), "default de d{$i}: ".json_encode($value));
            $this->assertSame($value, $this->argumentAfter($pivot, "\$table->string('p{$i}')", '->default('), "default de p{$i}: ".json_encode($value));
        }
    }

    public function test_cada_cast_es_el_valor_declarado(): void
    {
        $casts = $this->evaluateArray($this->project->read('src/Models/Product.php'), 'function casts(): array', 'return');

        foreach ($this->strings as $i => $value) {
            $this->assertSame($value, $casts["c{$i}"] ?? null, "cast de c{$i}: ".json_encode($value));
        }
    }

    public function test_cada_regla_es_la_declarada(): void
    {
        $rules = $this->evaluateArray($this->project->read('src/Http/Requests/Product/CreateRequest.php'), 'function rules(): array', 'return');

        foreach ($this->strings as $i => $value) {
            $this->assertSame($value, $rules["d{$i}"] ?? null, "regla de d{$i}: ".json_encode($value));
            $this->assertSame([$value, 'required'], $rules[$value] ?? null, 'reglas del campo '.json_encode($value));
        }
    }

    public function test_el_js_generado_parsea(): void
    {
        foreach (['vue', 'react'] as $ui) {
            foreach (['resources/'.$ui.'/src/models/product/index.js', 'resources/'.$ui.'/src/models/product/forms/index.js'] as $file) {
                if ($this->project->has($file)) {
                    $this->assertJsParses($this->project->read($file), $file);
                }
            }
        }
    }

    /**
     * Cada opción del select, en Vue y en React: el `value` y la etiqueta se
     * recuperan idénticos, y la interpolación de Vue no lleva nada que su
     * compilador corte o decodifique antes de leer el JS.
     */
    public function test_cada_opcion_del_select_vale_lo_declarado(): void
    {
        $forms = [
            'vue' => ['resources/vue/src/models/product/forms/CreateForm.vue', '/<option value="([^"]*)">\{\{ t\(\'(.*?)\'\) \}\}<\/option>/'],
            'react' => ['resources/react/src/models/product/forms/CreateForm.jsx', '/<option value="([^"]*)">\{t\(\'(.*?)\'\)\}<\/option>/'],
        ];

        foreach ($forms as $ui => [$file, $pattern]) {
            preg_match_all($pattern, $this->project->read($file), $matches, PREG_SET_ORDER);

            $this->assertCount(count($this->strings), $matches, "{$file}: no hay una opción por valor del enum.");

            $values = [];
            $labels = [];

            foreach ($matches as [, $value, $label]) {
                $values[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML401, 'UTF-8');
                $labels[] = "'{$label}'";

                if ($ui === 'vue') {
                    $this->assertStringNotContainsString('&', $label, "{$file}: entidad en la interpolación.");
                    $this->assertStringNotContainsString('}', $label, "{$file}: cierre posible en la interpolación.");
                }
            }

            $this->assertSame(array_map('strval', array_keys(array_fill_keys($this->strings, true))), $values, "{$file}: valores");
            $this->assertSame(array_map(fn (string $value): string => $this->label($value), $this->strings), $this->evaluateJs($labels), "{$file}: etiquetas");
        }
    }

    // AYUDAS

    private function label(string $value): string
    {
        return 'L:'.implode('', array_reverse(mb_str_split($value)));
    }

    /**
     * El argumento literal que sigue a `$call` en la línea de `$anchor`,
     * evaluado.
     */
    private function argumentAfter(string $code, string $anchor, string $call): mixed
    {
        $start = strpos($code, $anchor.'->nullable()') === false ? strpos($code, $anchor) : strpos($code, $anchor.'->nullable()');
        $this->assertNotFalse($start, "No está {$anchor}.");

        $open = strpos($code, $call, (int) $start);
        $this->assertNotFalse($open, "{$anchor} no lleva {$call}.");

        return $this->evaluateBalanced($code, (int) $open + strlen($call) - 1, '(', ')');
    }

    /**
     * El array que devuelve el `return` que sigue a `$method`, evaluado.
     *
     * @return array<string|int, mixed>
     */
    private function evaluateArray(string $code, string $method, string $keyword): array
    {
        $at = strpos($code, $method);
        $this->assertNotFalse($at, "No está {$method}.");

        $return = strpos($code, $keyword, (int) $at);
        $open = strpos($code, '[', (int) $return);

        $value = $this->evaluateBalanced($code, (int) $open, '[', ']', true);
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * Evalúa lo que hay entre el delimitador que abre en `$open` y el que lo
     * cierra, contado sobre tokens para que un paréntesis dentro de una
     * cadena no cuente. Antes de evaluarlo se exige que no haya más que
     * literales: si hubiera código, el escape habría fallado.
     */
    private function evaluateBalanced(string $code, int $open, string $opening, string $closing, bool $keep = false): mixed
    {
        $tokens = token_get_all('<?php '.substr($code, $open));
        $depth = 0;
        $inner = '';

        foreach (array_slice($tokens, 1) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === $opening && $depth++ === 0) {
                continue;
            }

            if ($text === $closing && --$depth === 0) {
                break;
            }

            if (is_array($token)) {
                $this->assertContains($token[0], [T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE, T_DOUBLE_ARROW, T_COMMENT],
                    'Código donde sólo debía haber literales: '.json_encode($text));
            } else {
                $this->assertContains($token, ['.', ',', '[', ']'], 'Código donde sólo debía haber literales: '.json_encode($token));
            }

            $inner .= $text;
        }

        return eval('return '.($keep ? "[{$inner}]" : $inner).';');
    }

    /**
     * @param  list<string>  $literals
     * @return list<string>
     */
    private function evaluateJs(array $literals): array
    {
        $file = $this->nodeFile("process.stdout.write(JSON.stringify([\n".implode(",\n", $literals)."\n]));\n");
        exec(escapeshellarg($this->node()).' '.escapeshellarg($file).' 2>&1', $output, $code);
        @unlink($file);

        $this->assertSame(0, $code, "Las etiquetas no son JS válido:\n".implode("\n", array_slice($output, 0, 20)));

        return json_decode(implode("\n", $output), true);
    }

    private function assertJsParses(string $source, string $label): void
    {
        $file = $this->nodeFile($source);
        exec(escapeshellarg($this->node()).' --check '.escapeshellarg($file).' 2>&1', $output, $code);
        @unlink($file);

        $this->assertSame(0, $code, "{$label} no se puede analizar:\n".implode("\n", array_slice($output, 0, 20)));
    }

    private function nodeFile(string $source): string
    {
        $file = sys_get_temp_dir().'/larapack-fuzz-'.bin2hex(random_bytes(6)).'.mjs';
        file_put_contents($file, $source);

        return $file;
    }

    private function node(): string
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        // Sin node no se comprueba nada: que conste como omitido y no como
        // pasado, o el test daría verde según la máquina.
        if ($node === '') {
            $this->markTestSkipped('node no disponible: no se puede comprobar el JS generado.');
        }

        return $node;
    }
}
