<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `.github/scripts/mutation-floors.php`: el suelo de cada pieza en el trabajo
 * «Mutación». El umbral de Infection es de todas juntas, y una podía bajar
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
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: string}>  $mutants  [clase, archivo, mutador, línea, diff]
     */
    private function report(array $counts, array $mutants = []): string
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

        foreach ($mutants as [$kind, $file, $mutator, $line, $diff]) {
            $report[$kind][] = ['mutator' => ['mutatorName' => $mutator, 'originalFilePath' => $file, 'originalStartLine' => $line], 'diff' => $diff, 'processOutput' => ''];
        }

        $path = $this->root.'/infection.json';
        file_put_contents($path, json_encode($report));

        return $path;
    }

    /**
     * @param  array<string, int|float>  $floors
     * @param  array<string, mixed>|null  $catalog
     * @return array{0: int, 1: string, 2: string}
     */
    private function floors(string $report, array $floors, ?array $catalog = null): array
    {
        $path = $this->root.'/floors.json';
        file_put_contents($path, json_encode($floors));
        $arguments = [$report, $path];

        if ($catalog !== null) {
            file_put_contents($this->root.'/survivors.json', json_encode($catalog));
            $arguments[] = $this->root.'/survivors.json';
        }

        return $this->script($arguments, $path);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function script(array $arguments, string $floors = 'floors.json'): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/mutation-floors.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $replace = [$floors => 'floors.json', $this->root.'/survivors.json' => 'survivors.json'];

        return [proc_close($process), strtr($stdout, $replace), strtr($stderr, $replace)];
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

        // La holgura: cuántos mutantes más pueden escapar sin bajar del
        // suelo. LegacyFix, con uno más, quedaría en 25 %, justo su suelo.
        [$code, $stdout, $stderr] = $this->floors($report, ['Guard' => 87.5, 'CiGate' => 100, 'LegacyFix' => 25]);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame(
            "Pieza               Mutantes      MSI   Suelo  Holgura\n"
            ."CiGate                     5  100.00%    100%        0\n"
            ."Guard                      8   87.50%   87.5%        0\n"
            ."LegacyFix                  4   50.00%     25%        1\n"
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
        $this->assertSame("\nGuard: MSI 30.00 % por debajo de su suelo, 30.01 %: 0.01 puntos, 1 mutante detectado más.\n", $stderr);
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

    /**
     * Un diff como los de Infection: cabecera, contexto, la línea que quita y
     * la que pone.
     */
    private function diff(string $removed, string $added, string $indent = '        '): string
    {
        return "--- Original\n+++ New\n@@ @@\n     {\n-{$indent}{$removed}\n+{$indent}{$added}\n     }";
    }

    public function test_un_pr_rojo_dice_que_mutantes_nuevos_escaparon(): void
    {
        $known = $this->diff('if ($a && $b) {', 'if ($a || $b) {');
        $report = $this->report(['/repo/src/Support/Guard.php' => ['killed' => 6]], [
            // Conocido aunque se haya movido de línea y de sangría.
            ['escaped', '/repo/src/Support/Guard.php', 'LogicalAnd', 120, $this->diff('if ($a  &&  $b) {', 'if ($a || $b) {', "\t\t")],
            // El mismo cambio dos veces: el catálogo sólo tiene uno.
            ['escaped', '/repo/src/Support/Guard.php', 'LogicalAnd', 300, $known],
            ['uncovered', '/repo/src/Support/Guard.php', 'Coalesce', 41, $this->diff('return $x ?? 0;', 'return 0 ?? $x;')],
        ]);
        $catalog = ['Guard' => [
            ['line' => 98, 'mutator' => 'LogicalAnd', 'kind' => 'escaped', 'removed' => 'if ($a && $b) {', 'added' => 'if ($a || $b) {'],
            // Ya no escapa: no falla, pero el catálogo se queda viejo.
            ['line' => 7, 'mutator' => 'TrueValue', 'kind' => 'escaped', 'removed' => '$v = true;', 'added' => '$v = false;'],
        ]];

        // 6 de 9: 66,67 %. Con un suelo de 80 le faltan dos: 8 de 9, 88,89 %.
        [$code, $stdout, $stderr] = $this->floors($report, ['Guard' => 80], $catalog);

        $this->assertSame(1, $code);
        $this->assertSame(
            "Pieza               Mutantes      MSI   Suelo  Holgura\n"
            ."Guard                      9   66.67%     80%       -2\n"
            ."\n1 del catálogo ya no escapan: un test los mata o su código cambió. Regenéralo con --write-catalog.\n",
            $stdout
        );
        $this->assertSame(
            "\nGuard: MSI 66.67 % por debajo de su suelo, 80 %: 13.33 puntos, 2 mutantes detectados más.\n"
            ."  Escapan y no están en survivors.json:\n"
            ."    /repo/src/Support/Guard.php:300 [LogicalAnd, escapa]\n"
            ."      - if (\$a && \$b) {\n"
            ."      + if (\$a || \$b) {\n"
            ."    /repo/src/Support/Guard.php:41 [Coalesce, sin recorrer]\n"
            ."      - return \$x ?? 0;\n"
            ."      + return 0 ?? \$x;\n",
            $stderr
        );
    }

    public function test_los_nuevos_se_avisan_aunque_la_pieza_no_baje(): void
    {
        $report = $this->report(['/repo/src/Support/CiGate.php' => ['killed' => 99]], [
            ['escaped', '/repo/src/Support/CiGate.php', 'Minus', 12, $this->diff('$i + 1', '$i - 1')],
        ]);

        [$code, $stdout, $stderr] = $this->floors($report, ['CiGate' => 98], []);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame(
            "Pieza               Mutantes      MSI   Suelo  Holgura\n"
            ."CiGate                   100   99.00%     98%        1\n"
            ."\nCiGate: no baja de su suelo, pero escapan y no están en survivors.json:\n"
            ."    /repo/src/Support/CiGate.php:12 [Minus, escapa]\n"
            ."      - \$i + 1\n"
            ."      + \$i - 1\n"
            ."\nNinguna pieza baja de su suelo.\n",
            $stdout
        );
    }

    public function test_el_catalogo_se_escribe_desde_la_corrida(): void
    {
        $report = $this->report(['/repo/src/Support/Guard.php' => ['killed' => 3]], [
            ['uncovered', '/repo/src/Support/Guard.php', 'Coalesce', 41, $this->diff('return $x ?? 0;', 'return 0 ?? $x;')],
            ['escaped', '/repo/src/Support/Guard.php', 'LogicalAnd', 9, $this->diff('if ($a && $b) {', 'if ($a || $b) {')],
            ['escaped', 'C:\\repo\\src\\Support\\CiGate.php', 'Minus', 12, $this->diff('$i + 1', '$i - 1')],
        ]);
        $catalog = $this->root.'/survivors.json';

        [$code, $stdout] = $this->script(['--write-catalog', $report, $catalog]);

        $this->assertSame(0, $code);
        $this->assertSame("3 supervivientes de 2 piezas en survivors.json.\n", $stdout);
        $this->assertSame([
            'CiGate' => [
                ['line' => 12, 'mutator' => 'Minus', 'kind' => 'escaped', 'removed' => '$i + 1', 'added' => '$i - 1'],
            ],
            'Guard' => [
                ['line' => 9, 'mutator' => 'LogicalAnd', 'kind' => 'escaped', 'removed' => 'if ($a && $b) {', 'added' => 'if ($a || $b) {'],
                ['line' => 41, 'mutator' => 'Coalesce', 'kind' => 'uncovered', 'removed' => 'return $x ?? 0;', 'added' => 'return 0 ?? $x;'],
            ],
        ], json_decode((string) file_get_contents($catalog), true));

        // Con el catálogo recién escrito no hay nada nuevo ni nada viejo.
        $path = $this->root.'/floors.json';
        file_put_contents($path, json_encode(['Guard' => 60, 'CiGate' => 0]));
        [$code, $stdout, $stderr] = $this->script([$report, $path, $catalog], $path);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame(
            "Pieza               Mutantes      MSI   Suelo  Holgura\n"
            ."CiGate                     1    0.00%      0%        0\n"
            ."Guard                      5   60.00%     60%        0\n"
            ."\nNinguna pieza baja de su suelo.\n",
            $stdout
        );
    }

    public function test_sin_informe_no_pasa(): void
    {
        [$code, , $stderr] = $this->floors($this->root.'/no-existe.json', ['Guard' => 90]);

        $this->assertSame(2, $code);
        $this->assertSame($this->root."/no-existe.json: no existe o no es JSON.\n", $stderr);
    }

    /**
     * Los suelos, el catálogo y el workflow van juntos: las piezas de
     * infection.json5, cada una con su suelo; el catálogo, de esas piezas; y
     * el paso de la CI, con los dos.
     */
    public function test_los_archivos_de_la_ci_van_juntos(): void
    {
        $root = dirname(__DIR__, 2);
        $floors = json_decode((string) file_get_contents($root.'/.github/mutation-floors.json'), true);
        $catalog = json_decode((string) file_get_contents($root.'/.github/mutation-survivors.json'), true);
        $workflow = (string) file_get_contents($root.'/.github/workflows/mutation.yml');

        preg_match('/\(\?:([A-Za-z|]+)\)/', (string) file_get_contents($root.'/infection.json5'), $pieces);
        $measured = explode('|', $pieces[1] ?? '');
        sort($measured);

        $this->assertIsArray($floors);
        $this->assertSame($measured, array_keys($floors));

        // La política: lo logrado a la décima, menos un punto. A la décima.
        foreach ($floors as $piece => $floor) {
            $this->assertSame(round($floor, 1), (float) $floor, $piece);
        }

        $this->assertIsArray($catalog);
        $this->assertSame([], array_diff(array_keys($catalog), $measured));

        foreach ($catalog as $piece => $entries) {
            foreach ($entries as $entry) {
                $this->assertIsString($entry['mutator'], $piece);
                $this->assertIsString($entry['removed'], $piece);
                $this->assertIsString($entry['added'], $piece);
            }
        }

        $this->assertStringContainsString(
            'mutation-floors.php .infection/infection.json .github/mutation-floors.json .github/mutation-survivors.json',
            $workflow
        );
    }

    /**
     * Una prueba que regenera el contrato (`larapack:realign diagnose` sobre
     * un paquete con laraimport) no puede estar en el `--filter` de
     * infection.json5. Regeneration lanza un proceso hijo con descriptores
     * `file`, y bajo Infection `file://` es su interceptor: `posix_spawn`
     * falla, la prueba da error con cualquier mutante y todo lo que recorre
     * sale «muerto». RealignExceptionsTest estuvo en el filtro así, y
     * `HandRolledLock` y `ControllerShape` pasaban su suelo con mutantes que
     * ninguna prueba mataba de verdad (docs/mutacion.md).
     *
     * Las que regeneran son las que montan un paquete generado: el trait
     * RealignProject, o `generated()` de SmallRealignProject.
     */
    public function test_ninguna_prueba_del_filtro_regenera_el_contrato(): void
    {
        $root = dirname(__DIR__, 2);
        preg_match("/--filter='([^']+)'/", (string) file_get_contents($root.'/infection.json5'), $filter);
        $files = [...(glob($root.'/tests/Unit/*Test.php') ?: []), ...(glob($root.'/tests/Feature/*Test.php') ?: [])];
        $matched = 0;

        foreach (explode('|', $filter[1] ?? '') as $pattern) {
            foreach ($files as $file) {
                // Como lo compara PHPUnit: con el nombre completo de la clase.
                $class = 'Innoboxrr\\LarapackGenerator\\Tests\\'.basename(dirname($file)).'\\'.basename($file, '.php');

                if (preg_match('/'.$pattern.'/', $class.'::test') !== 1) {
                    continue;
                }

                $matched++;
                $this->assertDoesNotMatchRegularExpression(
                    '/\buse RealignProject;|->generated\(\)|->generatedPackage\(\)/',
                    (string) file_get_contents($file),
                    basename($file).' regenera el contrato y está en el --filter de infection.json5 (como '.$pattern.'): bajo Infection daría por muerto todo lo que recorre.'
                );
            }
        }

        $this->assertGreaterThan(30, $matched);
    }
}
