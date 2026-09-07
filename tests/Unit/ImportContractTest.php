<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use PHPUnit\Framework\TestCase;

final class ImportContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function model(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Post',
            'props' => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array{document: ImportDocument|null, errors: array<int, array<string, mixed>>}
     */
    private function parse(array $models, array $pivots = []): array
    {
        return ImportDocument::fromArray(['models' => $models, 'pivots' => $pivots]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<int, string>
     */
    private function messages(array $errors, string $level): array
    {
        return array_map(
            fn (array $e): string => $e['path'] . ' ' . $e['message'],
            array_values(array_filter($errors, fn (array $e): bool => $e['level'] === $level))
        );
    }

    // FORMA

    /**
     * El objetivo del esquema es que el archivo declare solo lo que decide de
     * verdad. Antes habia catorce claves obligatorias por propiedad y omitir
     * cualquiera daba un TypeError a mitad de la generacion.
     */
    public function test_un_documento_minimo_es_valido(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([$this->model()]);

        $this->assertSame([], $errors);
        $this->assertNotNull($document);
    }

    public function test_aplica_los_valores_por_defecto_del_esquema(): void
    {
        ['document' => $document] = $this->parse([$this->model()]);

        $model = $document->model('Post');

        $this->assertFalse($model['metas']);
        $this->assertSame([], $model['load_relations']);
        $this->assertSame([], $model['load_counts']);
        $this->assertSame([], $model['editable_metas']);
        $this->assertSame([], $model['requests']);

        $prop = $model['props'][0];

        $this->assertFalse($prop['nullable']);
        $this->assertTrue($prop['fillable']);
        $this->assertTrue($prop['creatable']);
        $this->assertTrue($prop['updatable']);
        $this->assertTrue($prop['exports_cols']);
        $this->assertNull($prop['cast']);
        $this->assertNull($prop['constraint']);
        $this->assertFalse($prop['form']);
        $this->assertFalse($prop['form_submit']);
        $this->assertFalse($prop['datatable']);
    }

    public function test_rechaza_un_documento_sin_modelos(): void
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray([]);

        $this->assertNull($document);
        $this->assertNotEmpty($errors);
    }

    public function test_rechaza_un_tipo_de_columna_inexistente(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['props' => [['name' => 'title', 'type' => 'stringg']]]),
        ]);

        $this->assertStringContainsString(
            '/models/0/props/0/type',
            implode("\n", $this->messages($errors, 'error'))
        );
    }

    public function test_exige_componente_cuando_la_propiedad_va_en_el_formulario(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['props' => [['name' => 'title', 'type' => 'string', 'form' => true]]]),
        ]);

        $this->assertNotEmpty($this->messages($errors, 'error'));
    }

    public function test_exige_tabla_en_una_clave_foranea(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['props' => [['name' => 'user_id', 'type' => 'foreignId']]]),
        ]);

        $this->assertNotEmpty($this->messages($errors, 'error'));
    }

    public function test_rechaza_claves_desconocidas(): void
    {
        ['errors' => $errors] = $this->parse([$this->model(['inventada' => true])]);

        $this->assertNotEmpty($this->messages($errors, 'error'));
    }

    // REFERENCIAS CRUZADAS

    public function test_detecta_modelos_repetidos(): void
    {
        ['errors' => $errors] = $this->parse([$this->model(), $this->model()]);

        $this->assertStringContainsString(
            "'Post' está declarado dos veces",
            implode("\n", $this->messages($errors, 'error'))
        );
    }

    public function test_detecta_columnas_repetidas(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['props' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'title', 'type' => 'text'],
            ]]),
        ]);

        $this->assertStringContainsString(
            "'title' está declarada dos veces",
            implode("\n", $this->messages($errors, 'error'))
        );
    }

    /**
     * Un ciclo de claves foraneas no tiene orden de migracion posible, asi que
     * `php artisan migrate` fallaria siempre.
     */
    public function test_detecta_un_ciclo_de_claves_foraneas(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse([
            ['name' => 'Post', 'props' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'category_id', 'type' => 'foreignId', 'constraint' => 'categories'],
            ]],
            ['name' => 'Category', 'props' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'post_id', 'type' => 'foreignId', 'constraint' => 'posts'],
            ]],
        ]);

        $this->assertNull($document);
        $this->assertStringContainsString(
            'Ciclo de claves foráneas',
            implode("\n", $this->messages($errors, 'error'))
        );
    }

    public function test_exige_que_update_valide_el_identificador(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['requests' => [
                ['name' => 'Update', 'rules' => ['title' => 'nullable']],
            ]]),
        ]);

        $this->assertStringContainsString(
            "Update debe validar 'post_id'",
            implode("\n", $this->messages($errors, 'error'))
        );
    }

    public function test_avisa_de_una_regla_sobre_un_campo_inexistente(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['requests' => [
                ['name' => 'Create', 'rules' => ['titulo' => 'required']],
            ]]),
        ]);

        $this->assertStringContainsString(
            "'titulo' no es columna de Post",
            implode("\n", $this->messages($errors, 'warning'))
        );
    }

    public function test_avisa_de_un_enum_que_no_se_va_a_pintar(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['props' => [[
                'name' => 'estado',
                'type' => 'string',
                'form' => true,
                'form_component' => 'TextInputComponent',
                'enum' => ['a' => 'A'],
            ]]]),
        ]);

        $this->assertStringContainsString(
            'las opciones se ignorarán',
            implode("\n", $this->messages($errors, 'warning'))
        );
    }

    public function test_avisa_de_una_relacion_que_no_resuelve(): void
    {
        ['errors' => $errors] = $this->parse([
            $this->model(['load_relations' => [
                ['type' => 'belongsTo', 'related' => 'Autor', 'name' => 'autor'],
            ]]),
        ]);

        $this->assertStringContainsString(
            'se resolverá contra App\\Models',
            implode("\n", $this->messages($errors, 'warning'))
        );
    }

    // RESOLUCION

    /**
     * El orden del array decidia el de las migraciones: una clave foranea
     * hacia un modelo declarado mas abajo hacia fallar `migrate`.
     */
    public function test_ordena_los_modelos_por_sus_dependencias(): void
    {
        ['document' => $document] = $this->parse([
            ['name' => 'Post', 'props' => [
                ['name' => 'category_id', 'type' => 'foreignId', 'constraint' => 'categories'],
            ]],
            ['name' => 'Category', 'props' => [
                ['name' => 'name', 'type' => 'string'],
            ]],
        ]);

        $this->assertSame(['Category', 'Post'], array_column($document->models(), 'name'));
    }

    /**
     * Un modelo declarado en el mismo archivo vive en el paquete; antes se
     * asumia siempre App\Models y el `use` generado apuntaba a una clase que
     * no existia.
     */
    public function test_resuelve_el_namespace_de_las_relaciones(): void
    {
        ['document' => $document] = $this->parse([
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']], 'load_relations' => [
                ['type' => 'belongsTo', 'related' => 'Category', 'name' => 'category'],
                ['type' => 'belongsTo', 'related' => 'User', 'name' => 'user'],
            ]],
            ['name' => 'Category', 'props' => [['name' => 'name', 'type' => 'string']]],
        ]);

        $relations = $document->model('Post')['load_relations'];

        $this->assertNull($relations[0]['namespace'], 'Category está en el archivo: es del paquete.');
        $this->assertSame('App\\Models', $relations[1]['namespace']);
    }

    public function test_respeta_el_namespace_declarado_explicitamente(): void
    {
        ['document' => $document] = $this->parse([
            $this->model(['load_relations' => [
                ['type' => 'belongsTo', 'related' => 'Tenant', 'name' => 'tenant', 'namespace' => 'Acme\\Tenancy'],
            ]]),
        ]);

        $this->assertSame('Acme\\Tenancy', $document->model('Post')['load_relations'][0]['namespace']);
    }
}
