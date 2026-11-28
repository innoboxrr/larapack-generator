<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\FileComparison;
use Innoboxrr\LarapackGenerator\Support\Realign\PintPass;
use PHPUnit\Framework\TestCase;

/**
 * La pasada de Pint de varios ámbitos a la vez y en varios procesos da lo
 * mismo que ámbito a ámbito y en uno: el reparto es sólo para tardar menos.
 */
final class RealignPintPassTest extends TestCase
{
    private const REGENERATED = "<?php\n\nreturn [\n    'value' => %d,\n];\n";

    /** `array(...)` es otro código por tokens; tras Pint, el mismo. */
    private const FORMAT = "<?php\n\nreturn array(\n    'value' => %d,\n);\n";

    private const EDITED = "<?php\n\nreturn [\n    'value' => %d,\n    'extra' => true,\n];\n";

    private string $directory = '';

    private string|false $previous = false;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/larapack-pint-pass-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/first', 0777, true);
        mkdir($this->directory.'/second', 0777, true);

        $this->previous = getenv('LARAPACK_PINT');
        putenv('LARAPACK_PINT='.dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'LARAPACK_PINT' : 'LARAPACK_PINT='.$this->previous);

        foreach (array_reverse(glob($this->directory.'{,/*,/*/*}', GLOB_BRACE) ?: []) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
    }

    public function test_varios_ambitos_en_varios_procesos_dan_lo_mismo_que_uno_a_uno(): void
    {
        // Más de los que caben en un proceso, para que se repartan.
        $first = $this->pairs('first', 20);
        $second = $this->pairs('second', 3);
        // Otra configuración es otra Pint que lanzar: aquí deja `array()`.
        file_put_contents($this->directory.'/pint.json', (string) json_encode(['preset' => 'empty']));

        $batches = [
            'first' => ['pairs' => $first, 'root' => $this->directory.'/first', 'config' => null],
            'empty' => ['pairs' => [], 'root' => $this->directory.'/first', 'config' => null],
            'second' => ['pairs' => $second, 'root' => $this->directory.'/second', 'config' => null],
            'configured' => ['pairs' => $second, 'root' => $this->directory.'/second', 'config' => $this->directory.'/pint.json'],
        ];

        $together = PintPass::equivalentMany($batches, 4);

        $this->assertSame(['first', 'empty', 'second', 'configured'], array_keys($together));
        $this->assertSame([], $together['empty']);
        $this->assertSame([], $together['configured']);
        $this->assertSame(['f0' => FileComparison::IDENTICAL, 'f2' => FileComparison::IDENTICAL], $together['second']);
        $this->assertCount(10, $together['first']);
        $this->assertArrayHasKey('f18', $together['first']);
        $this->assertArrayNotHasKey('f19', $together['first']);

        foreach ($batches as $key => $batch) {
            $alone = PintPass::equivalent($batch['pairs'], $batch['root'], $batch['config']);
            ksort($alone);
            $expected = $together[$key];
            ksort($expected);

            $this->assertSame($alone, $expected, "El ámbito {$key} no da lo mismo solo que con los demás.");
        }

        // Los archivos del proyecto no se tocan: se formatea una copia.
        $this->assertSame(sprintf(self::FORMAT, 0), file_get_contents($first['f0'][0]));
    }

    public function test_sin_pint_no_se_compara_nada(): void
    {
        putenv('LARAPACK_PINT=off');

        $this->assertSame(['only' => []], PintPass::equivalentMany(['only' => ['pairs' => $this->pairs('first', 2), 'root' => $this->directory.'/first', 'config' => null]], 4));
    }

    /**
     * Los pares, formato y edición alternados.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function pairs(string $scope, int $count): array
    {
        $pairs = [];

        for ($index = 0; $index < $count; $index++) {
            $current = $this->directory.'/'.$scope.'/current'.$index.'.php';
            $regenerated = $this->directory.'/'.$scope.'/regenerated'.$index.'.php';
            file_put_contents($current, sprintf($index % 2 === 0 ? self::FORMAT : self::EDITED, $index));
            file_put_contents($regenerated, sprintf(self::REGENERATED, $index));
            $pairs['f'.$index] = [$current, $regenerated];
        }

        return $pairs;
    }
}
