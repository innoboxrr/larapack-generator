<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Merge\ManifestMerge;
use PHPUnit\Framework\TestCase;

/**
 * El manifiesto de una fusión se recompone de los de las dos ramas: la unión
 * de lo que añadió cada una y, de lo que las dos regeneraron, saber si lleva
 * trabajo de alguien (se conserva) o es todo del generador (se regenera).
 */
final class ManifestMergeTest extends TestCase
{
    /**
     * @param  array<string, string>  $files  ruta => contenido que se generó
     * @return array<string, mixed>
     */
    private function manifest(array $files, array $models = []): array
    {
        $entries = [];

        foreach ($files as $path => $content) {
            $entries[$path] = ['stub' => 'Model/ModelTemplate.txt', 'hash' => Manifest::hashOf($content), 'generator' => '9.4.0'];
        }

        return ['version' => 2, 'package' => ['namespace' => '', 'files' => []], 'models' => ['Customer' => ['namespace' => 'App\\', 'contract' => 'c1', 'files' => $entries]] + $models];
    }

    /**
     * @param  array<string, array<string, string>>  $trees  rama => ruta => contenido en esa rama
     */
    private function contentAt(array $trees): \Closure
    {
        return fn (string $side, string $file): ?string => $trees[$side][$file] ?? null;
    }

    public function test_los_modelos_que_anade_cada_rama_quedan_los_dos(): void
    {
        $base = $this->manifest(['a.php' => 'A']);
        $ours = $this->manifest(['a.php' => 'A'], ['Order' => ['namespace' => 'App\\', 'contract' => 'o', 'files' => ['order.php' => ['stub' => 's', 'hash' => 'h1']]]]);
        $theirs = $this->manifest(['a.php' => 'A'], ['Note' => ['namespace' => 'App\\', 'contract' => 'n', 'files' => ['note.php' => ['stub' => 's', 'hash' => 'h2']]]]);

        $result = ManifestMerge::merge($base, $ours, $theirs, $this->contentAt([]));

        $this->assertSame(['Customer', 'Order', 'Note'], array_keys($result['manifest']['models']));
        $this->assertSame([], $result['regenerate']);
        $this->assertSame([], $result['review']);
    }

    public function test_lo_que_regenero_una_sola_rama_es_de_esa_rama(): void
    {
        $base = $this->manifest(['a.php' => 'A', 'b.php' => 'B']);
        $ours = $this->manifest(['a.php' => 'A2', 'b.php' => 'B']);
        $theirs = $this->manifest(['a.php' => 'A', 'b.php' => 'B2']);

        $merged = ManifestMerge::merge($base, $ours, $theirs, $this->contentAt([]))['manifest'];

        $this->assertSame(Manifest::hashOf('A2'), $merged['models']['Customer']['files']['a.php']['hash']);
        $this->assertSame(Manifest::hashOf('B2'), $merged['models']['Customer']['files']['b.php']['hash']);
    }

    public function test_lo_que_las_dos_regeneraron_sin_editar_se_marca_para_regenerar(): void
    {
        $base = $this->manifest(['a.php' => 'A']);
        $ours = $this->manifest(['a.php' => 'A-ours']);
        $theirs = $this->manifest(['a.php' => 'A-theirs']);

        $result = ManifestMerge::merge($base, $ours, $theirs, $this->contentAt(['ours' => ['a.php' => 'A-ours'], 'theirs' => ['a.php' => 'A-theirs']]));

        $this->assertSame(['a.php'], $result['regenerate']);
        $this->assertSame([], $result['review']);
    }

    public function test_si_una_rama_ademas_lo_edito_se_conserva_como_editado_y_se_avisa(): void
    {
        $base = $this->manifest(['a.php' => 'A']);
        $ours = $this->manifest(['a.php' => 'A-ours']);
        $theirs = $this->manifest(['a.php' => 'A-theirs']);

        // En la otra rama el archivo ya no es lo que su manifiesto registró.
        $result = ManifestMerge::merge($base, $ours, $theirs, $this->contentAt(['ours' => ['a.php' => 'A-ours'], 'theirs' => ['a.php' => "A-theirs\n// a mano"]]));

        $this->assertSame([], $result['regenerate']);
        $this->assertSame('a.php', $result['review'][0]['file']);
        // El registro de quien lo editó: el archivo fusionado no tendrá ese
        // hash, seguirá contando como editado y --force no lo pisará.
        $this->assertSame(Manifest::hashOf('A-theirs'), $result['manifest']['models']['Customer']['files']['a.php']['hash']);
    }

    public function test_el_mismo_hash_en_las_dos_no_es_un_conflicto(): void
    {
        $base = $this->manifest(['a.php' => 'A']);
        $both = $this->manifest(['a.php' => 'A2']);

        $result = ManifestMerge::merge($base, $both, $both, $this->contentAt([]));

        $this->assertSame([], $result['regenerate']);
        $this->assertSame($both['models'], $result['manifest']['models']);
    }

    public function test_un_modelo_retirado_en_una_rama_se_retira_si_la_otra_no_lo_toco_y_se_conserva_si_lo_cambio(): void
    {
        $extra = ['Old' => ['namespace' => 'App\\', 'files' => ['old.php' => ['stub' => 's', 'hash' => 'h']]]];
        $base = $this->manifest(['a.php' => 'A'], $extra);
        $ours = $this->manifest(['a.php' => 'A']);

        $untouched = ManifestMerge::merge($base, $ours, $base, $this->contentAt([]))['manifest'];
        $this->assertArrayNotHasKey('Old', $untouched['models']);

        $changed = $this->manifest(['a.php' => 'A'], ['Old' => ['namespace' => 'App\\', 'files' => ['old.php' => ['stub' => 's', 'hash' => 'h2']]]]);
        $kept = ManifestMerge::merge($base, $ours, $changed, $this->contentAt([]))['manifest'];
        $this->assertSame('h2', $kept['models']['Old']['files']['old.php']['hash']);
    }

    public function test_un_registro_que_una_rama_quito_se_quita(): void
    {
        $base = $this->manifest(['a.php' => 'A', 'migration_1.php' => 'M']);
        $ours = $this->manifest(['a.php' => 'A']);

        $merged = ManifestMerge::merge($base, $ours, $base, $this->contentAt([]))['manifest'];

        $this->assertSame(['a.php'], array_keys($merged['models']['Customer']['files']));
    }

    public function test_la_huella_del_contrato_que_cambiaron_las_dos_se_queda_con_la_nuestra_para_que_verify_lo_diga(): void
    {
        $base = $this->manifest([]);
        $ours = $base;
        $ours['models']['Customer']['contract'] = 'ours';
        $theirs = $base;
        $theirs['models']['Customer']['contract'] = 'theirs';

        $merged = ManifestMerge::merge($base, $ours, $theirs, $this->contentAt([]))['manifest'];

        $this->assertSame('ours', $merged['models']['Customer']['contract']);
    }

    public function test_dos_hashes_que_php_daria_por_iguales_como_numeros_no_lo_son(): void
    {
        $entry = fn (string $hash): array => ['version' => 2, 'package' => ['namespace' => '', 'files' => []], 'models' => ['M' => ['namespace' => '', 'files' => ['a.php' => ['stub' => 's', 'hash' => $hash]]]]];

        // '0e1' == '0e2' con comparación laxa: son dos hashes distintos.
        $result = ManifestMerge::merge($entry('0e0'), $entry('0e1'), $entry('0e2'), $this->contentAt([]));

        $this->assertSame('a.php', $result['review'][0]['file']);
    }

    public function test_sin_el_manifiesto_de_una_rama_vale_el_de_la_otra(): void
    {
        $ours = $this->manifest(['a.php' => 'A']);

        $this->assertSame($ours, ManifestMerge::merge(null, $ours, null, $this->contentAt([]))['manifest']);
        $this->assertSame($ours, ManifestMerge::merge(null, null, $ours, $this->contentAt([]))['manifest']);
        $this->assertNull(ManifestMerge::merge(null, null, null, $this->contentAt([]))['manifest']);
    }
}
