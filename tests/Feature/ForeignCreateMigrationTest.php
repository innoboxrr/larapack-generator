<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * La migración de creación de una tabla que ya existe cuando se importa.
 *
 * Si la generó LaraPack y cambió el laraimport, se escribe la alteración; si
 * además se editó a mano, se conserva y se pide escribirla. Una migración que
 * LaraPack no generó —la de `users` que trae Laravel— no es ninguna de las dos:
 * el piloto de la aplicación base la veía en cada importación como "editada a
 * mano: escribe la alteración", y seguir el consejo era quitar `remember_token`
 * o el índice único de `email`, que el laraimport no sabe expresar.
 */
final class ForeignCreateMigrationTest extends TestCase
{
    private const USERS_MIGRATION = 'database/migrations/0001_01_01_000000_create_users_table.php';

    private const USER = [
        'name' => 'User',
        'authenticatable' => true,
        'props' => [
            ['name' => 'name', 'type' => 'string', 'datatable' => true],
            ['name' => 'email', 'type' => 'string', 'datatable' => true],
            ['name' => 'email_verified_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'password', 'type' => 'string'],
        ],
    ];

    public function test_la_migracion_de_usuarios_de_laravel_no_se_da_por_editada_a_mano(): void
    {
        $project = $this->useProject(FakeProject::application());

        mkdir($project->path.'/database/migrations', 0777, true);
        copy(
            dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000000_testbench_create_users_table.php',
            $project->path.'/'.self::USERS_MIGRATION
        );
        $laravel = $project->read(self::USERS_MIGRATION);

        // Cada importación, también con --force.
        foreach ([[], [], ['--force' => true]] as $options) {
            $entry = $this->importEntry([self::USER], self::USERS_MIGRATION, $options);

            $this->assertSame('skipped', $entry['action'], 'La migración de Laravel se informa como conservada por editada a mano.');
            $this->assertStringContainsString('no la generó LaraPack', (string) $entry['reason']);
            $this->assertStringNotContainsString('escribe la alteración', (string) $entry['reason']);

            $this->assertSame($laravel, $project->read(self::USERS_MIGRATION));
            $this->assertNull($project->glob('database/migrations/*_alter_users_table.php'), 'Se escribió una alteración contra la migración de Laravel.');
        }
    }

    public function test_una_migracion_generada_y_editada_a_mano_sigue_pidiendo_la_alteracion(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->importEntry([$this->product([['name' => 'title', 'type' => 'string']])], null);

        $create = (string) $project->glob('database/migrations/*_create_products_table.php');
        file_put_contents($project->path.'/'.$create, $project->read($create)."\n// índice añadido a mano\n");

        $entry = $this->importEntry([$this->product([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'sku', 'type' => 'string', 'nullable' => true],
        ])], $create);

        $this->assertSame('preserved', $entry['action']);
        $this->assertStringContainsString('escribe la alteración', (string) $entry['reason']);
        $this->assertNull($project->glob('database/migrations/*_alter_products_table.php'));
    }

    public function test_una_migracion_generada_sin_tocar_recibe_su_alteracion(): void
    {
        $project = $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $this->importEntry([$this->product([['name' => 'title', 'type' => 'string']])], null);

        $this->importEntry([$this->product([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'sku', 'type' => 'string', 'nullable' => true],
        ])], null);

        $alter = $project->glob('database/migrations/*_alter_products_table.php');

        $this->assertNotNull($alter, 'Cambió el laraimport y no se escribió la alteración.');
        $this->assertStringContainsString("\$table->string('sku')->nullable();", $project->read($alter));
    }

    // AYUDAS

    /**
     * @param  array<int, array<string, mixed>>  $props
     * @return array<string, mixed>
     */
    private function product(array $props): array
    {
        return ['name' => 'Product', 'props' => $props];
    }

    /**
     * Importa y devuelve lo que el informe dice del archivo.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<string, mixed>  $options
     * @return array{action: string, file: string, stub: string, reason: string|null}|null
     */
    private function importEntry(array $models, ?string $file, array $options = []): ?array
    {
        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => $models]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--format' => 'json'] + $options),
            "larapack:import terminó con error:\n".$this->lastOutput
        );

        if ($file === null) {
            return null;
        }

        $report = json_decode($this->lastOutput, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report, "La salida no era JSON:\n".$this->lastOutput);

        $entries = array_values(array_filter(
            $report['files'],
            fn (array $entry): bool => str_replace('\\', '/', $entry['file']) === $file
        ));

        $this->assertCount(1, $entries, "El informe no dice nada, o lo dice varias veces, de {$file}:\n".$this->lastOutput);

        return $entries[0];
    }
}
