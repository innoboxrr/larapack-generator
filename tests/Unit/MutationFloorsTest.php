<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `.github/scripts/mutation-floors.php`: el suelo de cada pieza en el trabajo
 * «Mutación». El umbral de Infection es de las ocho juntas, y una podía bajar
 * mientras las demás la compensaban; esto es lo que lo impide, así que se
 * prueba como cualquier otra barrera.
 */
final class MutationFloorsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-floors-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root);
    }

    /**
     * Un informe como el de Infection, con `$counts` mutantes de cada clase
     * por archivo.
     *
     * @param  array<string, array<string, int>>  $counts  archivo => [clase => cuántos]
     */
    private function report(array $counts): string
    {
        $report = ['stats' => [], 'escaped' => [], 'timeouted' => [], 'killed' => [], 'killedByStaticAnalysis' => [],
            'errored' => [], 'syntaxErrors' => [], 'uncovered' => [], 'ignored' => []];

        foreach ($counts as $file => $kinds) {
            foreach ($kinds as $kind => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $report[$kind][] = ['mutator' => ['mutatorName' => 'X', 'originalFilePath' => $file, 'originalStartLine' => 1], 'diff' => '', 'processOutput' => ''];
                }
            }
        }

        $path = $this->root.'/infection.json';
        file_put_contents($path, json_encode($report));

        return $path;
    }

    /**
     * @param  array<string, int|float>  $floors
     * @return array{0: int, 1: string, 2: string}
     */
    private function floors(string $report, array $floors): array
    {
        $path = $this->root.'/floors.json';
        file_put_contents($path, json_encode($floors));

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/mutation-floors.php', $report, $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, str_replace($path, 'floors.json', $stderr)];
    }

    public function test_cada_pieza_contra_su_suelo(): void
    {
        $report = $this->report([
            // 7 de 8 detectados: 87,5 %. Con barras de Windows, también.
            '/repo/src/Support/Guard.php' => ['killed' => 5, 'timeouted' => 1, 'errored' => 1, 'escaped' => 1],
            'C:\\repo\\src\\Support\\CiGate.php' => ['killed' => 3, 'syntaxErrors' => 1, 'killedByStaticAnalysis' => 1],
            // Lo que ningún test recorre cuenta en contra; lo ignorado, no.
            '/repo/src/Support/LegacyFix.php' => ['killed' => 2, 'uncovered' => 2, 'ignored' => 5],
        ]);

        [$code, $stdout, $stderr] = $this->floors($report, ['Guard' => 87.5, 'CiGate' => 100, 'LegacyFix' => 50]);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame(
            "Pieza               Mutantes      MSI   Suelo\n"
            ."CiGate                     5  100.00%    100%\n"
            ."Guard                      8   87.50%   87.5%\n"
            ."LegacyFix                  4   50.00%     50%\n"
            ."\nNinguna pieza baja de su suelo.\n",
            $stdout
        );
        $this->assertSame('', $stderr);
    }

    public function test_una_pieza_que_baja_falla_aunque_las_demas_compensen(): void
    {
        // Juntas: 103 de 110, un 93,6 %. Guard sola, 3 de 10.
        $report = $this->report([
            '/repo/src/Support/Guard.php' => ['killed' => 3, 'escaped' => 7],
            '/repo/src/Support/CiGate.php' => ['killed' => 100],
        ]);

        [$code, , $stderr] = $this->floors($report, ['Guard' => 30.01, 'CiGate' => 100]);

        $this->assertSame(1, $code);
        $this->assertSame("\nGuard: MSI 30.00 % por debajo de su suelo, 30.01 %.\n", $stderr);
    }

    public function test_un_suelo_sin_mutantes_y_un_archivo_sin_suelo(): void
    {
        $report = $this->report(['/repo/src/Support/Guardia.php' => ['killed' => 1]]);

        [$code, , $stderr] = $this->floors($report, ['Guard' => 90]);

        $this->assertSame(1, $code);
        $this->assertSame(
            "\nGuard: tiene suelo y ningún mutante. Si salió de infection.json5 o cambió de nombre, quítala o renómbrala en floors.json.\n"
            ."Guardia: se muta (1 mutantes) y no tiene suelo en floors.json.\n",
            $stderr
        );
    }

    public function test_sin_informe_no_pasa(): void
    {
        [$code, , $stderr] = $this->floors($this->root.'/no-existe.json', ['Guard' => 90]);

        $this->assertSame(2, $code);
        $this->assertSame($this->root."/no-existe.json: no existe o no es JSON.\n", $stderr);
    }
}
