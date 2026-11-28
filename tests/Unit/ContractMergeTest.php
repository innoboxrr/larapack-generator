<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Merge\ContractMerge;
use PHPUnit\Framework\TestCase;

/**
 * El laraimport se fusiona por lo que significa: por nombres, no por líneas.
 * Es lo que deja que dos ramas añadan cada una su modelo o su columna sin
 * que una persona tenga que resolver un conflicto que no lo es, y lo que
 * impide que dos que declaran lo mismo lo dejen declarado dos veces.
 */
final class ContractMergeTest extends TestCase
{
    private const BASE = ['models' => [
        ['name' => 'Customer', 'props' => [['name' => 'name', 'type' => 'string'], ['name' => 'email', 'type' => 'string']]],
        ['name' => 'Ticket', 'props' => [['name' => 'subject', 'type' => 'string']]],
    ]];

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @return array{0: array<string, mixed>|null, 1: list<array{path: string, ours: mixed, theirs: mixed}>}
     */
    private function merge(?array $base, array $ours, array $theirs): array
    {
        $result = ContractMerge::mergeJson($base === null ? null : (string) json_encode($base), (string) json_encode($ours), (string) json_encode($theirs));

        return [$result['json'] === null ? null : json_decode($result['json'], true), $result['conflicts']];
    }

    public function test_dos_ramas_que_anaden_cada_una_un_modelo_se_quedan_con_los_dos(): void
    {
        $ours = self::BASE;
        $ours['models'][] = ['name' => 'Order', 'props' => [['name' => 'reference', 'type' => 'string']]];
        $theirs = self::BASE;
        $theirs['models'][] = ['name' => 'Note', 'props' => [['name' => 'body', 'type' => 'text']]];

        [$merged, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertSame([], $conflicts);
        // En el orden nuestro, con lo de la otra rama detrás.
        $this->assertSame(['Customer', 'Ticket', 'Order', 'Note'], array_column($merged['models'], 'name'));
    }

    public function test_dos_columnas_distintas_en_el_mismo_modelo_se_quedan_las_dos(): void
    {
        $ours = self::BASE;
        $ours['models'][0]['props'][] = ['name' => 'phone', 'type' => 'string'];
        $theirs = self::BASE;
        $theirs['models'][0]['props'][] = ['name' => 'vip', 'type' => 'boolean'];

        [$merged, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertSame([], $conflicts);
        $this->assertSame(['name', 'email', 'phone', 'vip'], array_column($merged['models'][0]['props'], 'name'));
    }

    public function test_la_misma_columna_anadida_por_las_dos_queda_una_vez_con_lo_de_ambas(): void
    {
        $ours = self::BASE;
        $ours['models'][0]['props'][] = ['name' => 'phone', 'type' => 'string', 'nullable' => true];
        $theirs = self::BASE;
        // En otro sitio de la lista: git las fusionaría «limpias» y duplicadas.
        array_unshift($theirs['models'][0]['props'], ['name' => 'phone', 'type' => 'string', 'nullable' => true, 'datatable' => true]);

        [$merged, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertSame([], $conflicts);
        $this->assertSame(['name', 'email', 'phone'], array_column($merged['models'][0]['props'], 'name'));
        $this->assertSame(['name' => 'phone', 'type' => 'string', 'nullable' => true, 'datatable' => true], $merged['models'][0]['props'][2]);
    }

    public function test_lo_que_las_dos_declaran_distinto_es_un_conflicto_con_su_ruta_y_no_se_decide(): void
    {
        $ours = self::BASE;
        $ours['models'][1]['endpoints'] = [['name' => 'close', 'method' => 'post']];
        $theirs = self::BASE;
        $theirs['models'][1]['endpoints'] = [['name' => 'close', 'method' => 'put']];

        [, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertSame([['path' => '/models/Ticket/endpoints/close/method', 'ours' => 'post', 'theirs' => 'put']], $conflicts);
    }

    public function test_un_cambio_de_una_sola_rama_gana_al_valor_que_la_otra_no_toco(): void
    {
        $ours = self::BASE;
        $ours['models'][0]['props'][1]['type'] = 'text';
        $theirs = self::BASE;
        $theirs['models'][0]['props'][0]['datatable'] = true;
        $theirs['table_prefix'] = 'crm';

        [$merged, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertSame([], $conflicts);
        $this->assertSame('text', $merged['models'][0]['props'][1]['type']);
        $this->assertTrue($merged['models'][0]['props'][0]['datatable']);
        $this->assertSame('crm', $merged['table_prefix']);
    }

    public function test_lo_que_una_rama_quita_se_va_si_la_otra_no_lo_toco(): void
    {
        $ours = self::BASE;
        unset($ours['models'][1]);
        $ours['models'] = array_values($ours['models']);
        $theirs = self::BASE;
        $theirs['models'][0]['props'][] = ['name' => 'phone', 'type' => 'string'];

        [$merged, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertSame([], $conflicts);
        $this->assertSame(['Customer'], array_column($merged['models'], 'name'));
    }

    public function test_quitar_lo_que_la_otra_rama_cambio_es_un_conflicto_y_no_se_pierde(): void
    {
        $ours = self::BASE;
        unset($ours['models'][1]);
        $ours['models'] = array_values($ours['models']);
        $theirs = self::BASE;
        $theirs['models'][1]['props'][] = ['name' => 'priority', 'type' => 'integer'];

        [$merged, $conflicts] = $this->merge(self::BASE, $ours, $theirs);

        $this->assertCount(1, $conflicts);
        $this->assertSame('/models/Ticket', $conflicts[0]['path']);
        $this->assertSame('(quitado)', $conflicts[0]['ours']);
        $this->assertContains('Ticket', array_column($merged['models'], 'name'));
    }

    public function test_una_lista_de_valores_se_fusiona_como_conjunto(): void
    {
        $base = self::BASE;
        $base['models'][0]['routes'] = ['except' => ['create', 'export']];
        $ours = $base;
        $ours['models'][0]['routes']['except'] = ['create', 'export', 'restore'];
        $theirs = $base;
        $theirs['models'][0]['routes']['except'] = ['export', 'forceDelete'];

        [$merged, $conflicts] = $this->merge($base, $ours, $theirs);

        $this->assertSame([], $conflicts);
        $this->assertSame(['export', 'restore', 'forceDelete'], $merged['models'][0]['routes']['except']);
    }

    public function test_sin_ancestro_comun_lo_distinto_es_conflicto_y_lo_igual_no(): void
    {
        $ours = ['models' => [['name' => 'A', 'props' => [['name' => 'x', 'type' => 'string']]]]];
        $theirs = ['models' => [['name' => 'A', 'props' => [['name' => 'x', 'type' => 'text']]], ['name' => 'B', 'props' => []]]];

        [$merged, $conflicts] = $this->merge(null, $ours, $theirs);

        $this->assertSame(['/models/A/props/x/type'], array_column($conflicts, 'path'));
        $this->assertSame(['A', 'B'], array_column($merged['models'], 'name'));
    }

    public function test_el_orden_de_las_claves_no_es_un_cambio_y_un_objeto_vacio_sigue_siendo_objeto(): void
    {
        $result = ContractMerge::mergeJson(
            '{"models":[{"name":"A","routes":{},"props":[{"name":"x","type":"string"}]}]}',
            '{"models":[{"props":[{"type":"string","name":"x"}],"routes":{},"name":"A"}]}',
            '{"models":[{"name":"A","routes":{},"props":[{"name":"x","type":"string"},{"name":"y","type":"text"}]}]}',
        );

        $this->assertSame([], $result['conflicts']);
        $this->assertStringContainsString('"routes": {}', (string) $result['json']);
        $this->assertStringContainsString('"name": "y"', (string) $result['json']);
    }

    public function test_el_resultado_se_escribe_siempre_igual(): void
    {
        $json = (string) ContractMerge::mergeJson(null, '{"models":[],"título":"año/1"}', '{"models":[],"título":"año/1"}')['json'];

        $this->assertSame("{\n    \"models\": [],\n    \"título\": \"año/1\"\n}\n", $json);
    }

    public function test_una_version_que_no_es_json_no_se_fusiona(): void
    {
        $result = ContractMerge::mergeJson('{}', "<<<<<<< HEAD\n{}\n", '{}');

        $this->assertNull($result['json']);
        $this->assertStringContainsString('ours', (string) $result['error']);
    }
}
