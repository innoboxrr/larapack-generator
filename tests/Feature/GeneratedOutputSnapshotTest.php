<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que el generador produce, archivo por archivo, fijado por hash.
 *
 * Existe para poder cambiar los stubs sin cambiar lo que sale de ellos. Las
 * claves nuevas del laraimport tienen que ser retrocompatibles: un archivo que
 * no las declare debe generar exactamente lo mismo que antes de que existieran,
 * y "exactamente" sólo significa algo si se compara contra una foto tomada
 * antes del cambio.
 *
 * Para rehacer la foto a propósito:
 *
 *     LARAPACK_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter GeneratedOutputSnapshotTest
 *
 * y revisar el diff del JSON antes de confirmarlo: cada línea que cambia es un
 * archivo que un proyecto existente vería distinto al regenerar.
 */
final class GeneratedOutputSnapshotTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        $fixtures = dirname(__DIR__) . '/Fixtures';

        return [
            'paquete importado con vue y react' => ['library', 'larapack:import', [
                'jsonPath' => $fixtures . '/laraimport.json',
                '--vue' => true,
                '--react' => true,
            ]],
            'paquete con relaciones' => ['library', 'larapack:import', [
                'jsonPath' => $fixtures . '/laraimport-relations.json',
                '--vue' => true,
                '--react' => true,
            ]],
            'aplicacion importada' => ['application', 'larapack:import', [
                'jsonPath' => $fixtures . '/laraimport.json',
            ]],
            'modelo suelto sin laraimport' => ['library', 'larapack:full-model', [
                'name' => 'Product',
                '--vue' => true,
                '--react' => true,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('scenarios')]
    public function test_la_salida_coincide_con_la_foto(string $type, string $command, array $arguments): void
    {
        $this->useProject($type === 'library'
            ? FakeProject::library('Acme\\Blog\\')
            : FakeProject::application());

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand($command, $arguments),
            "{$command} terminó con error:\n" . $this->lastOutput
        );

        $actual = $this->hashes();
        $snapshot = $this->snapshotPath($this->dataName());

        if (getenv('LARAPACK_UPDATE_SNAPSHOTS')) {
            if (! is_dir(dirname($snapshot))) {
                mkdir(dirname($snapshot), 0777, true);
            }

            file_put_contents($snapshot, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $this->markTestSkipped("Foto reescrita: {$snapshot}");
        }

        // Sin foto no se compara nada, y un test que pasa sin comparar es
        // peor que no tener test.
        $this->assertFileExists(
            $snapshot,
            'No hay foto para este escenario. Genérala con LARAPACK_UPDATE_SNAPSHOTS=1 y revísala antes de confirmarla.'
        );

        $expected = json_decode((string) file_get_contents($snapshot), true);

        $this->assertSame([], $this->differences($expected, $actual), 'La salida del generador cambió respecto a la foto.');
    }

    /**
     * @return array<string, string>
     */
    private function hashes(): array
    {
        $hashes = [];
        $root = str_replace('\\', '/', $this->project->path);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project->path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            $content = str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname()));

            // El nombre de una migración lleva la hora a la que se generó; lo
            // que se compara es qué migración es y qué contiene.
            $hashes[$this->withoutTimestamps($relative)] = hash('sha256', $this->withoutTimestamps($content));
        }

        ksort($hashes);

        return $hashes;
    }

    private function withoutTimestamps(string $value): string
    {
        return (string) preg_replace('/\d{4}_\d{2}_\d{2}_\d{6}_/', 'TIMESTAMP_', $value);
    }

    /**
     * Una lista legible en lugar de dos arrays enteros: si cambia un archivo
     * de doscientos, el fallo tiene que decir cuál.
     *
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     * @return array<int, string>
     */
    private function differences(array $expected, array $actual): array
    {
        $differences = [];

        foreach (array_diff_key($expected, $actual) as $file => $hash) {
            $differences[] = "ya no se genera: {$file}";
        }

        foreach (array_diff_key($actual, $expected) as $file => $hash) {
            $differences[] = "se genera de nuevas: {$file}";
        }

        foreach (array_intersect_key($actual, $expected) as $file => $hash) {
            if ($expected[$file] !== $hash) {
                $differences[] = "cambió: {$file}";
            }
        }

        return $differences;
    }

    private function snapshotPath(string|int $scenario): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $scenario)), '-');

        return dirname(__DIR__) . "/Fixtures/snapshots/{$slug}.json";
    }
}
