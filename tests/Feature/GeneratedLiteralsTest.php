<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Los valores libres del laraimport —un `default`, un `cast`, las etiquetas de
 * un `enum`— llegan al código generado como literales. Se pegaban entre
 * comillas sin escapar: una comilla rompía el archivo y una comilla bien
 * puesta metía código en una migración que `migrate` ejecuta.
 */
final class GeneratedLiteralsTest extends TestCase
{
    private const INJECTION = "x'); throw new \\RuntimeException('inyectado'); //";

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, json_encode([
            'models' => [
                ['name' => 'Product', 'props' => [
                    ['name' => 'title', 'type' => 'string', 'default' => "it's"],
                    ['name' => 'notes', 'type' => 'string', 'default' => self::INJECTION],
                    ['name' => 'path', 'type' => 'string', 'default' => 'C:\\temp\\'],
                    ['name' => 'price', 'type' => 'integer', 'cast' => 'App\\Casts\\Money'],
                    ['name' => 'status', 'type' => 'string', 'datatable' => true, 'form' => true, 'form_component' => 'SelectInputComponent', 'enum' => [
                        'draft' => "Don't publish",
                        'say"hi' => 'Barra \\',
                        'live' => "Dos\nlíneas",
                    ]],
                ]],
            ],
            'pivots' => [
                ['name' => 'product_tag', 'props' => [
                    ['name' => 'label', 'type' => 'string', 'default' => "o'clock"],
                ]],
            ],
        ]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true, '--react' => true]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    public function test_todo_el_php_generado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    public function test_el_default_de_la_migracion_es_el_valor_declarado_y_no_codigo(): void
    {
        $migration = $this->project->read((string) $this->project->glob('database/migrations/*_create_products_table.php'));

        $this->assertSame("it's", $this->defaultOf($migration, 'title'));
        $this->assertSame(self::INJECTION, $this->defaultOf($migration, 'notes'));
        $this->assertSame('C:\\temp\\', $this->defaultOf($migration, 'path'));
        $this->assertStringNotContainsString("throw new \\RuntimeException('inyectado'); //')", $migration);
    }

    public function test_el_default_del_pivote_se_escapa(): void
    {
        $migration = $this->project->read((string) $this->project->glob('database/migrations/*_create_product_tag_table.php'));

        $this->assertSame("o'clock", $this->defaultOf($migration, 'label'));
    }

    /**
     * Lo que ya era correcto sale igual que antes: un cast de clase conserva
     * sus barras simples.
     */
    public function test_un_cast_con_barras_sale_como_siempre(): void
    {
        $this->assertStringContainsString("'price' => 'App\\Casts\\Money'", $this->project->read('src/Models/Product.php'));
    }

    public function test_las_etiquetas_del_enum_son_cadenas_js_validas(): void
    {
        $contract = $this->project->read('resources/vue/src/models/product/index.js');

        $this->assertStringContainsString("t('Don\\'t publish')", $contract);
        $this->assertStringContainsString("t('Barra \\\\')", $contract);
        $this->assertStringContainsString("t('Dos\\nlíneas')", $contract);

        $this->assertJsParses($contract);
    }

    public function test_el_valor_del_enum_se_escapa_en_el_atributo_de_la_opcion(): void
    {
        foreach ([
            'resources/vue/src/models/product/forms/CreateForm.vue',
            'resources/react/src/models/product/forms/CreateForm.jsx',
        ] as $form) {
            $content = $this->project->read($form);

            $this->assertStringContainsString('<option value="say&quot;hi">', $content, $form);
            $this->assertStringNotContainsString('value="say"hi"', $content, $form);
        }
    }

    // AYUDAS

    /**
     * El valor que recibe ->default() de una columna, evaluado como lo haría
     * PHP al ejecutar la migración.
     */
    private function defaultOf(string $migration, string $column): mixed
    {
        $this->assertSame(1, preg_match(
            '/\$table->\w+\(\''.preg_quote($column, '/').'\'\)(?:->nullable\(\))?->default\((.+?)\)(?:->|;\R)/',
            $migration,
            $match
        ), "No se encontró el default de {$column}.");

        $tokens = token_get_all('<?php '.$match[1].';');
        $literal = array_values(array_filter($tokens, fn ($token): bool => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING));

        $this->assertCount(1, $literal, "El default de {$column} no es un único literal: {$match[1]}");

        return eval('return '.$literal[0][1].';');
    }

    private function assertJsParses(string $source): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            $this->addToAssertionCount(1);

            return;
        }

        $file = sys_get_temp_dir().'/larapack-js-'.bin2hex(random_bytes(6)).'.mjs';
        file_put_contents($file, $source);

        exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $output, $code);

        @unlink($file);

        $this->assertSame(0, $code, "El módulo JS generado no se puede analizar:\n".implode("\n", $output));
    }
}
