<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `metas: true` genera un modelo que guarda y lee sus metas de verdad.
 *
 * Antes se generaban la tabla y el modelo Meta, y nada más: el modelo no tenía
 * la relación metas() que MetaOperations necesita, updateModelMetas y
 * updatePayload salían comentados, `protected_metas` no se rellenaba y
 * `payload` era una columna que el cliente podía escribir. La suite EndToEnd lo
 * ejecuta; aquí se fija lo que se genera.
 */
final class GeneratedMetasTest extends TestCase
{
    private function importFixture(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport.json']),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_el_modelo_tiene_la_relacion_que_usa_meta_operations(): void
    {
        $this->importFixture();

        $relations = $this->project->read('src/Models/Traits/Relations/PostRelations.php');

        $this->assertStringContainsString('public function metas(): HasMany', $relations);
        $this->assertStringContainsString('return $this->hasMany(PostMeta::class);', $relations);
        $this->assertStringContainsString('use Acme\\Blog\\Models\\PostMeta;', $relations);
    }

    public function test_crear_y_actualizar_guardan_las_metas_aplanadas(): void
    {
        $this->importFixture();

        $storage = $this->project->read('src/Models/Traits/Storage/PostStorage.php');

        $this->assertStringContainsString('use Innoboxrr\\Support\\Http\\Requests\\RequestFormater;', $storage);
        $this->assertStringContainsString('$post->updateModelMetas($request);', $storage);
        $this->assertStringContainsString('$this->updateModelMetas($request);', $storage);
        $this->assertStringContainsString("RequestFormater::flatten(\$data), PostMeta::class, 'post_id')->updatePayload();", $storage);
        $this->assertStringNotContainsString("/*\n    public function updateModelMetas", $storage, 'El guardado de metas sigue comentado.');
    }

    public function test_payload_se_construye_a_partir_de_las_metas(): void
    {
        $this->importFixture();

        $operations = $this->project->read('src/Models/Traits/Operations/PostOperations.php');

        $this->assertStringContainsString('public function buildPayload(): array', $operations);
        $this->assertStringContainsString('public function updatePayload(): bool', $operations);
        $this->assertStringContainsString('saveQuietly()', $operations);
    }

    public function test_las_metas_protegidas_y_editables_van_al_modelo(): void
    {
        $this->importFixture();

        $model = $this->project->read('src/Models/Post.php');

        $this->assertMatchesRegularExpression("/protected \\\$protected_metas = \[\s*'views'\s*\];/", $model);
        $this->assertMatchesRegularExpression("/protected \\\$editable_metas = \[\s*'seo_title'\s*\];/", $model);
    }

    /**
     * La fixture declara payload escribible y exportable a propósito.
     */
    public function test_payload_no_se_escribe_desde_la_peticion_ni_se_exporta(): void
    {
        $this->importFixture();

        $model = $this->project->read('src/Models/Post.php');

        foreach (['fillable', 'creatable', 'updatable'] as $list) {
            $this->assertDoesNotMatchRegularExpression("/\\\${$list} = \[[^\]]*'payload'/", $model, "payload sigue en \${$list}.");
        }

        $this->assertDoesNotMatchRegularExpression("/\\\$export_cols = \[[^\]]*'payload'/", $model);
        $this->assertStringNotContainsString("'payload' =>", $this->project->read('database/factories/PostFactory.php'));
    }

    public function test_no_quedan_marcadores_en_lo_generado(): void
    {
        $this->importFixture();

        foreach (['Relations/PostRelations', 'Storage/PostStorage', 'Operations/PostOperations'] as $trait) {
            $this->assertStringNotContainsString('@larapack:', $this->project->read("src/Models/Traits/{$trait}.php"));
        }
    }

    /**
     * Un modelo sin metas sale igual que antes: sin relación, con el guardado
     * y el payload comentados como ejemplo.
     */
    public function test_un_modelo_sin_metas_no_cambia(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product']));

        $this->assertStringNotContainsString('function metas()', $this->project->read('src/Models/Traits/Relations/ProductRelations.php'));
        $this->assertStringContainsString("// use Acme\\Blog\\Models\\ProductMeta;", $this->project->read('src/Models/Traits/Storage/ProductStorage.php'));
        $this->assertStringContainsString("/*\n    public function buildPayload()", $this->project->read('src/Models/Traits/Operations/ProductOperations.php'));
        $this->assertFileDoesNotExist($this->project->path . '/src/Models/ProductMeta.php');
    }

    /**
     * Sin laraimport: `--metas` generaba el modelo Meta y nada lo usaba.
     */
    public function test_full_model_con_metas_lo_deja_conectado(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product', '--metas' => true]));

        $this->assertFileExists($this->project->path . '/src/Models/ProductMeta.php');
        $this->assertStringContainsString('function metas(): HasMany', $this->project->read('src/Models/Traits/Relations/ProductRelations.php'));
        $this->assertStringContainsString('function updateModelMetas($request)', $this->project->read('src/Models/Traits/Storage/ProductStorage.php'));
    }

    /**
     * Se buscaba `ProductModelMetas.php` y la plantilla de borrado en una ruta
     * vacía: el modelo Meta no se borraba nunca.
     */
    public function test_quitar_un_modelo_con_metas_borra_su_meta(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product', '--metas' => true]));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:remove-full-model', ['name' => 'Product']), $this->lastOutput);

        $this->assertFileDoesNotExist($this->project->path . '/src/Models/ProductMeta.php');
        $this->assertNotNull($this->project->glob('database/migrations/*_drop_product_metas_table.php'));
    }

    public function test_quitar_un_modelo_sin_metas_no_deja_una_migracion_de_metas(): void
    {
        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product']));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:remove-full-model', ['name' => 'Product']), $this->lastOutput);

        $this->assertNull($this->project->glob('database/migrations/*_drop_product_metas_table.php'));
    }

    // CONTRATO

    public function test_con_metas_se_anade_payload_si_no_se_declara(): void
    {
        ['document' => $document] = ImportDocument::fromArray(['models' => [[
            'name' => 'Deal',
            'metas' => true,
            'props' => [['name' => 'title', 'type' => 'string']],
        ]]]);

        $payload = collect($document->model('Deal')['props'])->firstWhere('name', 'payload');

        $this->assertNotNull($payload, 'Un modelo con metas no tiene dónde guardar la copia.');
        $this->assertSame('longText', $payload['type']);
        $this->assertSame('array', $payload['cast']);
        $this->assertTrue($payload['nullable']);
        $this->assertFalse($payload['fillable']);
        $this->assertFalse($payload['exports_cols']);
    }

    public function test_sin_metas_no_se_anade_payload(): void
    {
        ['document' => $document] = ImportDocument::fromArray(['models' => [[
            'name' => 'Deal',
            'props' => [['name' => 'title', 'type' => 'string']],
        ]]]);

        $this->assertNull(collect($document->model('Deal')['props'])->firstWhere('name', 'payload'));
    }

    public function test_avisa_de_lo_que_se_declara_a_medias(): void
    {
        ['errors' => $findings] = ImportDocument::fromArray(['models' => [
            [
                'name' => 'Deal',
                'metas' => true,
                'editable_metas' => ['seo_title', 'views'],
                'protected_metas' => ['views'],
                'props' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'payload', 'type' => 'longText', 'creatable' => true],
                ],
            ],
            [
                'name' => 'Note',
                'editable_metas' => ['seo_title'],
                'props' => [['name' => 'title', 'type' => 'string']],
            ],
        ]]);

        $messages = implode("\n", array_column($findings, 'message'));

        $this->assertSame([SemanticValidator::WARNING], array_values(array_unique(array_column($findings, 'level'))));
        $this->assertStringContainsString("'views' está en editable_metas y en protected_metas", $messages);
        $this->assertStringContainsString("'payload' lo rehace updatePayload()", $messages);
        $this->assertStringContainsString("'Note' declara editable_metas pero no metas: true", $messages);
    }
}
