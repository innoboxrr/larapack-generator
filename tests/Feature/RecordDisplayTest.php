<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * La columna con la que la interfaz nombra a un registro.
 *
 * Las vistas leían siempre `.name`. En el piloto, un producto con `title` salía
 * en la ficha, las migas y la pestaña como "Product" en lugar de "El Quijote".
 */
final class RecordDisplayTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $props
     * @param  array<string, mixed>  $extra
     */
    private function displayFor(array $props, array $extra = []): ?string
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray(['models' => [[
            'name' => 'Item',
            'props' => $props,
            ...$extra,
        ]]]);

        $this->assertNotNull($document, 'El documento no es válido: ' . json_encode($errors));

        return $document->model('Item')['display'];
    }

    public function test_por_defecto_name(): void
    {
        $this->assertSame('name', $this->displayFor([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'name', 'type' => 'string'],
        ]));
    }

    public function test_sin_name_title(): void
    {
        $this->assertSame('title', $this->displayFor([
            ['name' => 'sku', 'type' => 'string', 'datatable' => true],
            ['name' => 'title', 'type' => 'string'],
        ]));
    }

    public function test_sin_name_ni_title_la_primera_columna_de_texto_de_la_tabla(): void
    {
        $this->assertSame('number', $this->displayFor([
            ['name' => 'price', 'type' => 'decimal', 'datatable' => true],
            ['name' => 'notes', 'type' => 'text'],
            ['name' => 'number', 'type' => 'string', 'datatable' => true],
        ]));
    }

    public function test_si_ninguna_esta_en_la_tabla_la_primera_de_texto(): void
    {
        $this->assertSame('notes', $this->displayFor([
            ['name' => 'price', 'type' => 'decimal'],
            ['name' => 'notes', 'type' => 'text'],
        ]));
    }

    /**
     * Un secreto nunca sale por la API: no puede nombrar nada.
     */
    public function test_no_elige_un_secreto_ni_payload(): void
    {
        $this->assertSame('id', $this->displayFor(
            [
                ['name' => 'token', 'type' => 'string', 'secret' => true],
                ['name' => 'price', 'type' => 'decimal'],
            ],
            ['metas' => true]
        ));
    }

    public function test_lo_declarado_manda(): void
    {
        $this->assertSame('sku', $this->displayFor(
            [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'sku', 'type' => 'string'],
            ],
            ['display' => 'sku']
        ));
    }

    public function test_display_tiene_que_ser_una_columna_que_salga_por_la_api(): void
    {
        foreach ([
            ['display' => 'inventada', 'expected' => "no es ninguna de sus columnas"],
            ['display' => 'token', 'expected' => 'es secret'],
        ] as $case) {
            ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray(['models' => [[
                'name' => 'Item',
                'display' => $case['display'],
                'props' => [
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'token', 'type' => 'string', 'secret' => true],
                ],
            ]]]);

            $this->assertNull($document, "display: {$case['display']} no se rechazó.");
            $this->assertStringContainsString($case['expected'], implode("\n", array_column($errors, 'message')));
        }
    }

    public function test_las_vistas_nombran_al_registro_con_su_columna(): void
    {
        $this->useProject(FakeProject::library('Acme\\Tienda\\'));

        file_put_contents($this->project->path . '/laraimport.json', json_encode(['models' => [[
            'name' => 'Product',
            'props' => [['name' => 'title', 'type' => 'string', 'datatable' => true]],
        ]]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $this->project->path . '/laraimport.json', '--vue' => true, '--react' => true]),
            $this->lastOutput
        );

        foreach (['vue' => 'vue', 'react' => 'jsx'] as $ui => $extension) {
            $base = "resources/{$ui}/src/models/product";
            $show = $this->project->read("{$base}/views/ShowView.{$extension}");
            $card = $this->project->read("{$base}/widgets/ModelCard.{$extension}");

            $this->assertStringContainsString('loaded?.title ??', $show);
            $this->assertMatchesRegularExpression('/product(\.value)?\.title \?\?/', $show);
            $this->assertStringContainsString('product?.title ??', $card);
            $this->assertStringNotContainsString('.name ??', $show . $card, "La vista {$ui} sigue leyendo .name.");
        }
    }

    /**
     * Sin laraimport no se conocen las columnas, y lo generado sale como antes.
     */
    public function test_sin_laraimport_se_sigue_usando_name(): void
    {
        $this->useProject(FakeProject::library('Acme\\Tienda\\'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product', '--vue' => true]));

        $this->assertStringContainsString('product?.name ??', $this->project->read('resources/vue/src/models/product/widgets/ModelCard.vue'));
    }
}
