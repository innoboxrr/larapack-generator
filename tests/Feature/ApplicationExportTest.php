<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * La exportación que se genera dentro de una aplicación.
 *
 * Un paquete tiene su propia configuración (`config/<paquete>.php`) y registra
 * sus vistas como `<paquete>::`. Una aplicación no: su namespace sin separación
 * es `app`, así que lo generado leía `config('app.excel_view')`, renderizaba
 * `app::excel.<modelo>`, que nadie registra, y guardaba su configuración en
 * `config/app.php`, la de Laravel. El piloto de la aplicación base lo encontró:
 * cada exportación fallaba con "No hint path defined for [app]".
 */
final class ApplicationExportTest extends TestCase
{
    private function import(FakeProject $project): void
    {
        $this->useProject($project);

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, (string) json_encode(['models' => [[
            'name' => 'OrderLine',
            'props' => [['name' => 'quantity', 'type' => 'integer', 'datatable' => true]],
        ]]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    // LA VISTA

    public function test_en_una_aplicacion_la_exportacion_renderiza_la_vista_que_genera(): void
    {
        $this->import(FakeProject::application());

        [$key, $prefix] = $this->excelView('app/Exports/OrderLinesExports.php');

        $this->assertSame('larapack.excel_view', $key, 'La exportación lee la configuración de Laravel en lugar de la de LaraPack.');
        $this->assertSame('excel.', $prefix, 'Una aplicación no registra vistas con namespace: la exportación tiene que usar las suyas.');

        $this->assertTrue(
            $this->project->has('resources/views/'.str_replace('.', '/', $prefix).'order_line.blade.php'),
            "La exportación renderiza {$prefix}order_line, y esa vista no es la que se generó."
        );
    }

    public function test_en_un_paquete_la_exportacion_sigue_usando_las_vistas_del_paquete(): void
    {
        $this->import(FakeProject::library('Acme\\Shop\\'));

        $this->assertSame(
            ['acmeshop.excel_view', 'acmeshop::excel.'],
            $this->excelView('src/Exports/OrderLinesExports.php')
        );
    }

    // AYUDAS

    /**
     * La clave de configuración y el prefijo por defecto con que la exportación
     * elige su vista.
     *
     * @return array{0: string, 1: string}
     */
    private function excelView(string $export): array
    {
        $source = $this->project->read($export);

        $this->assertSame(
            1,
            preg_match("/config\\(\\s*'([^']+)',\\s*'([^']*)'\\s*\\)\\s*\\.\\s*'order_line'/", $source, $match),
            "{$export} no elige su vista con config(clave, prefijo).'order_line':\n{$source}"
        );

        return [$match[1], $match[2]];
    }
}
