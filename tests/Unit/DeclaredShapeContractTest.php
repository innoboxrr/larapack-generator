<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use PHPUnit\Framework\TestCase;

/**
 * `routes`, `immutable` y `secret`: lo que el contrato acepta, lo que rechaza
 * y lo que deja una vez resuelto.
 */
final class DeclaredShapeContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, array<string, mixed>>|null  $props
     * @return array<string, mixed>
     */
    private function model(array $overrides = [], ?array $props = null): array
    {
        return array_merge([
            'name' => 'AuditEvent',
            'props' => $props ?? [['name' => 'title', 'type' => 'string']],
        ], $overrides);
    }

    /**
     * @return array{document: ImportDocument|null, errors: array<int, array<string, mixed>>}
     */
    private function parse(array ...$models): array
    {
        return ImportDocument::fromArray(['models' => $models]);
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

    // RETROCOMPATIBILIDAD

    public function test_un_modelo_sin_las_claves_nuevas_tiene_las_diez_acciones(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model());

        $this->assertSame([], $errors);

        $model = $document->model('AuditEvent');

        $this->assertSame(Actions::ALL, $model['actions']);
        $this->assertFalse($model['immutable']);
        $this->assertFalse($model['props'][0]['secret']);
        $this->assertTrue($model['props'][0]['exports_cols']);
    }

    // ROUTES

    public function test_only_deja_solo_esas_acciones_en_el_orden_de_siempre(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => ['only' => ['show', 'export', 'index']]]));

        $this->assertSame(['index', 'show', 'export'], $document->model('AuditEvent')['actions']);
    }

    public function test_except_quita_esas_acciones(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => ['except' => ['update', 'restore', 'forceDelete']]]));

        $this->assertSame(
            ['policies', 'policy', 'index', 'show', 'create', 'delete', 'export'],
            $document->model('AuditEvent')['actions']
        );
    }

    public function test_only_y_except_juntos_fallan_con_un_mensaje_que_dice_que_hacer(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model([
            'routes' => ['only' => ['index'], 'except' => ['update']],
        ]));

        $this->assertNull($document);

        $errores = $this->messages($errors, SemanticValidator::ERROR);

        $this->assertContains(
            '/models/0/routes Declara `only` o `except`, no los dos: juntos no tienen una lectura única. Usa `only` si la lista de lo que queda es más corta.',
            $errores
        );

        // El genérico del esquema sobre `not` no aparece además del propio.
        $this->assertCount(1, $errores);
    }

    public function test_rechaza_una_accion_que_no_existe(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => ['only' => ['index', 'destroy']]]));

        $this->assertNull($document);
    }

    public function test_rechaza_routes_vacio(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => []]));

        $this->assertNull($document);
    }

    public function test_restore_sin_delete_es_un_aviso_y_no_un_error(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model([
            'routes' => ['only' => ['index', 'show', 'restore']],
        ]));

        $this->assertNotNull($document);
        $this->assertNotEmpty($this->messages($errors, SemanticValidator::WARNING));
        $this->assertSame([], $this->messages($errors, SemanticValidator::ERROR));
    }

    public function test_export_sin_index_avisa(): void
    {
        ['errors' => $errors] = $this->parse($this->model(['routes' => ['only' => ['show', 'export']]]));

        $this->assertStringContainsString('export sin index', implode("\n", $this->messages($errors, SemanticValidator::WARNING)));
    }

    public function test_un_campo_de_formulario_sin_create_ni_update_es_un_error(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model(
            ['routes' => ['only' => ['index', 'show']]],
            [['name' => 'title', 'type' => 'string', 'form' => true, 'form_component' => 'TextInputComponent']]
        ));

        $this->assertNull($document);
        $this->assertStringContainsString('no tiene dónde vivir', implode("\n", $this->messages($errors, SemanticValidator::ERROR)));
    }

    /**
     * Sin update no se genera UpdateRequest, así que no hay handle() que
     * necesite el identificador; exigirlo sería un error sobre un archivo que
     * no existe.
     */
    public function test_reglas_de_update_sin_update_avisan_en_lugar_de_exigir_el_identificador(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model([
            'routes' => ['only' => ['index', 'show', 'create']],
            'requests' => [['name' => 'Update', 'rules' => ['title' => 'required']]],
        ]));

        $this->assertNotNull($document);
        $this->assertStringContainsString('no se genera ese request', implode("\n", $this->messages($errors, SemanticValidator::WARNING)));
    }

    // IMMUTABLE

    /**
     * Una fila inmutable nace. Lo que no hace es cambiar.
     */
    public function test_immutable_quita_lo_que_modifica_pero_deja_crear(): void
    {
        ['document' => $document] = $this->parse($this->model(['immutable' => true]));

        $actions = $document->model('AuditEvent')['actions'];

        $this->assertContains('create', $actions);

        foreach (Actions::MODIFY as $modify) {
            $this->assertNotContains($modify, $actions);
        }
    }

    public function test_immutable_con_una_escritura_en_only_es_una_contradiccion(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model([
            'immutable' => true,
            'routes' => ['only' => ['index', 'update']],
        ]));

        $this->assertNull($document);
        $this->assertStringContainsString('es immutable y declara update', implode("\n", $this->messages($errors, SemanticValidator::ERROR)));
    }

    public function test_immutable_con_except_no_es_contradiccion(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model([
            'immutable' => true,
            'routes' => ['except' => ['create', 'export']],
        ]));

        $this->assertNotNull($document);
        $this->assertSame(['policies', 'policy', 'index', 'show'], $document->model('AuditEvent')['actions']);
        $this->assertSame([], $this->messages($errors, SemanticValidator::ERROR));
    }

    // SECRET

    public function test_un_secreto_no_sale_ni_en_la_exportacion_ni_en_la_tabla(): void
    {
        ['document' => $document] = $this->parse($this->model([], [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'token_hash', 'type' => 'string', 'secret' => true],
        ]));

        $secret = $document->model('AuditEvent')['props'][1];

        $this->assertTrue($secret['secret']);
        $this->assertFalse($secret['exports_cols']);
        $this->assertFalse($secret['datatable']);
        // Escribirlo sí se puede.
        $this->assertTrue($secret['fillable']);
    }

    public function test_un_secreto_que_pide_salir_en_la_tabla_es_un_error(): void
    {
        ['document' => $document, 'errors' => $errors] = $this->parse($this->model([], [
            ['name' => 'token_hash', 'type' => 'string', 'secret' => true, 'datatable' => true],
        ]));

        $this->assertNull($document);
        $this->assertStringContainsString('es secret y a la vez pide salir en la tabla', implode("\n", $this->messages($errors, SemanticValidator::ERROR)));
    }

    public function test_un_secreto_que_pide_salir_en_la_exportacion_es_un_error(): void
    {
        ['document' => $document] = $this->parse($this->model([], [
            ['name' => 'token_hash', 'type' => 'string', 'secret' => true, 'exports_cols' => true],
        ]));

        $this->assertNull($document);
    }

    // INTERFAZ

    public function test_los_avisos_de_interfaz_no_aparecen_al_validar_solo_la_api(): void
    {
        ['errors' => $errors] = $this->parse($this->model(['routes' => ['only' => ['show']]]));

        $this->assertSame([], $errors);
    }

    public function test_sin_index_la_interfaz_avisa_de_que_solo_trae_el_contrato(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => ['only' => ['show']]]));

        $avisos = implode("\n", $this->messages(SemanticValidator::interface($document->toArray()), SemanticValidator::WARNING));

        $this->assertStringContainsString('sólo trae el contrato y el store', $avisos);
    }

    public function test_sin_policies_la_interfaz_avisa_de_que_la_tabla_las_necesita(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => ['except' => ['policies']]]));

        $avisos = implode("\n", $this->messages(SemanticValidator::interface($document->toArray()), SemanticValidator::WARNING));

        $this->assertStringContainsString('consulta las políticas', $avisos);
    }

    public function test_update_sin_show_avisa_de_que_no_hay_formulario_de_edicion(): void
    {
        ['document' => $document] = $this->parse($this->model(['routes' => ['except' => ['show']]]));

        $avisos = implode("\n", $this->messages(SemanticValidator::interface($document->toArray()), SemanticValidator::WARNING));

        $this->assertStringContainsString('no trae formulario de edición', $avisos);
    }

    public function test_la_forma_de_siempre_no_produce_avisos_de_interfaz(): void
    {
        ['document' => $document] = $this->parse($this->model());

        $this->assertSame([], SemanticValidator::interface($document->toArray()));
    }
}
