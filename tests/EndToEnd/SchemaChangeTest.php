<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Illuminate\Support\Facades\Schema;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use Orchestra\Testbench\TestCase;

/**
 * Cambiar el laraimport de una tabla que ya se migró.
 *
 * Reimportar dejaba la migración de creación como estaba —o la reescribía con
 * --force—, y ninguna de las dos cosas llega a una base ya migrada: quien la
 * tenía no la vuelve a crear. Ahora lo que cambió va en una migración de
 * alteración, y aquí se migra de verdad, se deshace y se vuelve a cambiar.
 */
final class SchemaChangeTest extends TestCase
{
    private ?FakeProject $project = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = FakeProject::library('Acme\\Shop\\');
    }

    protected function tearDown(): void
    {
        $this->project?->cleanup();
        $this->project = null;

        parent::tearDown();
    }

    public function test_lo_que_cambia_en_el_laraimport_llega_a_una_base_ya_migrada(): void
    {
        $this->import([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'decimal'],
            ['name' => 'notes', 'type' => 'text', 'nullable' => true],
        ]);

        $create = $this->project->path . '/' . $this->project->glob('database/migrations/*_create_products_table.php');
        $original = (string) file_get_contents($create);

        $this->migrate();

        $this->assertTrue(Schema::hasColumn('products', 'notes'));
        $this->assertFalse($this->column('price')['nullable']);

        // Se añade sku, price admite nulos y notes desaparece.
        $this->import([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => true],
            ['name' => 'sku', 'type' => 'string', 'nullable' => true],
        ]);

        $this->assertSame($original, (string) file_get_contents($create), 'La migración de creación cambió: quien ya la migró no vería el cambio.');
        $this->assertCount(1, $this->alters());

        $this->migrate();

        $this->assertTrue(Schema::hasColumn('products', 'sku'));
        $this->assertFalse(Schema::hasColumn('products', 'notes'));
        $this->assertTrue($this->column('price')['nullable']);

        // Sin cambios no hay nada que alterar.
        $this->import([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => true],
            ['name' => 'sku', 'type' => 'string', 'nullable' => true],
        ]);

        $this->assertCount(1, $this->alters(), 'Reimportar lo mismo escribió otra alteración.');

        // La alteración se deshace.
        $this->artisan('migrate:rollback', ['--path' => $this->migrations(), '--realpath' => true, '--step' => 1])->assertSuccessful()->run();

        $this->assertFalse(Schema::hasColumn('products', 'sku'));
        $this->assertTrue(Schema::hasColumn('products', 'notes'));
        $this->assertFalse($this->column('price')['nullable']);

        $this->migrate();

        // La siguiente parte de lo que ya dejaron las anteriores: sku no se
        // vuelve a añadir.
        $simulated = [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => true],
            ['name' => 'sku', 'type' => 'string', 'nullable' => true],
            ['name' => 'stock', 'type' => 'integer', 'default' => 0],
        ];

        $this->import($simulated, ['--dry-run' => true]);

        $this->assertCount(1, $this->alters(), 'La simulación escribió la alteración.');

        $this->import($simulated);

        $this->assertCount(2, $this->alters());
        $this->assertStringNotContainsString("'sku'", (string) file_get_contents($this->alters()[1]));

        $this->migrate();

        $this->assertTrue(Schema::hasColumn('products', 'stock'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $props
     * @param  array<string, mixed>  $options
     */
    private function import(array $props, array $options = []): void
    {
        $laraimport = $this->project->path . '/laraimport.json';

        file_put_contents($laraimport, (string) json_encode(['models' => [['name' => 'Product', 'props' => $props]]]));

        ProjectRoot::set($this->project->path);

        try {
            [$code, $output] = Larapack::run('larapack:import', ['jsonPath' => $laraimport, ...$options]);
        } finally {
            ProjectRoot::set(null);
            Generation::reset();
            Declaration::reset();
            MigrationTimestamp::reset();
        }

        $this->assertSame(0, $code, "larapack:import terminó con {$code}:\n{$output}");
    }

    private function migrate(): void
    {
        $this->artisan('migrate', ['--path' => $this->migrations(), '--realpath' => true])->assertSuccessful()->run();
    }

    private function migrations(): string
    {
        return $this->project->path . '/database/migrations';
    }

    /**
     * @return array<int, string>
     */
    private function alters(): array
    {
        $files = glob($this->migrations() . '/*_alter_products_table.php') ?: [];

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $name): array
    {
        return collect(Schema::getColumns('products'))->firstWhere('name', $name) ?? [];
    }
}
