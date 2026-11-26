<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Cómo se escribe el `default` de una columna en la migración, y que cambiarlo
 * no reescriba columnas que no cambiaron.
 *
 * MigrationTool::alter() compara cada definición de columna como texto. Por
 * eso la forma del literal es parte del contrato con los proyectos ya
 * generados: una 9.1 que escribía `->default('10.00')` donde la 9.0 escribía
 * `->default(10.00)` habría hecho que el primer import tras actualizar
 * escribiera un `->change()` por cada columna de dinero, sin que nada hubiera
 * cambiado.
 */
final class MigrationDefaultsTest extends TestCase
{
    /**
     * Cada default, con lo que la 9.0 escribía para él: el texto que ya está
     * en las migraciones de los proyectos que actualizan.
     */
    private const UNCHANGED_SINCE_9_0 = [
        'price' => ['decimal', '10.00', '10.00'],
        'balance' => ['decimal', '0.00', '0.00'],
        'rate' => ['decimal', '1.10', '1.10'],
        'stock' => ['integer', '5', '5'],
        'offset' => ['integer', '-3', '-3'],
        'quantity' => ['integer', 2, '2'],
        'weight' => ['float', 1.5, '1.5'],
        'active' => ['boolean', true, 'true'],
        'archived' => ['boolean', false, 'false'],
        'label' => ['string', "it's", "'it\\'s'"],
    ];

    public function test_reimportar_un_proyecto_de_9_0_no_altera_los_defaults_que_no_cambiaron(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $props = [];

        foreach (self::UNCHANGED_SINCE_9_0 as $name => [$type, $default]) {
            $props[] = ['name' => $name, 'type' => $type, 'default' => $default];
        }

        $this->import(['models' => [['name' => 'Product', 'props' => $props]]]);

        $create = (string) $project->glob('database/migrations/*_create_products_table.php');
        $code = $project->read($create);

        foreach (self::UNCHANGED_SINCE_9_0 as $name => [$type, , $literal]) {
            $this->assertStringContainsString("\$table->{$type}('{$name}')->default({$literal});", $code);
        }

        $this->import(['models' => [['name' => 'Product', 'props' => $props]]]);

        $this->assertNull(
            $project->glob('database/migrations/*_alter_products_table.php'),
            'Reimportar sin cambios escribió una alteración.'
        );
    }

    /**
     * La 9.0 dejaba `"01"` sin comillas, y en PHP `01` es el octal 1: la
     * columna valía 1. Ahora sale `'01'`, y en un proyecto ya generado el
     * siguiente import escribe la alteración que lo corrige. Es el único
     * cambio de forma a propósito, y lo dice el CHANGELOG.
     */
    public function test_un_default_con_ceros_a_la_izquierda_va_entre_comillas_y_se_altera(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $declaration = ['models' => [['name' => 'Profile', 'props' => [
            ['name' => 'contract_type', 'type' => 'string', 'default' => '01'],
            ['name' => 'code', 'type' => 'string', 'default' => '007'],
            ['name' => 'zone', 'type' => 'string', 'default' => '08'],
            ['name' => 'factor', 'type' => 'string', 'default' => '1e3'],
            ['name' => 'padded', 'type' => 'string', 'default' => ' 1'],
        ]]]];

        $this->import($declaration);

        $create = (string) $project->glob('database/migrations/*_create_profiles_table.php');
        $code = $project->read($create);

        foreach (["contract_type')->default('01')", "code')->default('007')", "zone')->default('08')", "factor')->default('1e3')", "padded')->default(' 1')"] as $expected) {
            $this->assertStringContainsString($expected, $code);
        }

        // La migración como la dejó la 9.0, anotada en el manifiesto como
        // generada: lo que tiene un proyecto que actualiza sin haberla tocado.
        file_put_contents($project->path.'/'.$create, str_replace("->default('01')", '->default(01)', $code));
        (new Manifest($project->path))->rehash([$project->path.'/'.$create]);

        $this->import($declaration);

        $alter = $project->glob('database/migrations/*_alter_profiles_table.php');

        $this->assertNotNull($alter, 'El default octal de la 9.0 no se corrigió.');
        $this->assertStringContainsString("\$table->string('contract_type')->default('01')->change();", $project->read($alter));
        $this->assertStringNotContainsString("'code'", $project->read($alter), 'Sólo cambia la columna que la 9.0 escribía distinto.');
    }

    /**
     * El pivote escribía todo default entre comillas: `true` salía `'1'`,
     * `false` salía `''` —inválido para un boolean en MySQL estricto— y `0`
     * salía `'0'`. Ahora usa el mismo literal que las migraciones de modelo.
     */
    public function test_el_pivote_escribe_el_default_como_el_modelo(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->import(['models' => [['name' => 'Product', 'props' => [['name' => 'name', 'type' => 'string']]]], 'pivots' => [[
            'name' => 'product_tag',
            'props' => [
                ['name' => 'primary', 'type' => 'boolean', 'default' => true],
                ['name' => 'hidden', 'type' => 'boolean', 'default' => false],
                ['name' => 'position', 'type' => 'integer', 'default' => 0],
                ['name' => 'weight', 'type' => 'decimal', 'default' => '1.50'],
                ['name' => 'code', 'type' => 'string', 'default' => '007'],
                ['name' => 'note', 'type' => 'string', 'default' => "it's"],
            ],
        ]]]);

        $code = $project->read((string) $project->glob('database/migrations/*_create_product_tag_table.php'));

        foreach ([
            "\$table->boolean('primary')->default(true);",
            "\$table->boolean('hidden')->default(false);",
            "\$table->integer('position')->default(0);",
            "\$table->decimal('weight')->default(1.50);",
            "\$table->string('code')->default('007');",
            "\$table->string('note')->default('it\\'s');",
        ] as $expected) {
            $this->assertStringContainsString($expected, $code);
        }
    }

    /**
     * @param  array<string, mixed>  $declaration
     */
    private function import(array $declaration): void
    {
        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode($declaration));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }
}
