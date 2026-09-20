<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use PHPUnit\Framework\TestCase;

/**
 * `on_delete` y `on_update`: qué sale por omisión y qué se puede pedir.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * LO QUE ESTO VIGILA, Y POR QUÉ NO ES UNA PREFERENCIA DE ESTILO.
 *
 * Hasta la 8.0.0 el generador emitía `onDelete('cascade')` en TODA clave ajena,
 * sin forma de declarar otra cosa. Eso no es sólo agresivo: es incompatible con
 * la mitad de la integridad que se puede expresar en MySQL, porque una columna
 * con acción referencial no admite un CHECK (error 3823) ni puede sostener una
 * columna generada STORED (error 1215).
 *
 * En un proyecto real costó SIETE migraciones escritas sólo para deshacerlo, y
 * 259 claves ajenas reconstruidas a mano. Si alguien vuelve a poner `cascade`
 * por omisión en un modelo, esta prueba lo caza.
 * ────────────────────────────────────────────────────────────────────────────
 */
final class ReferentialActionsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function prop(array $extra = []): array
    {
        return array_merge([
            'name' => 'category_id',
            'type' => 'foreignId',
            'constraint' => 'categories',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $prop
     * @return array<string, mixed>
     */
    private function resolved(array $prop): array
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray([
            'models' => [
                ['name' => 'Category', 'props' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Product', 'props' => [$prop]],
            ],
        ]);

        $this->assertNotNull($document, 'No validó: '.json_encode($errors));

        return $document->model('Product')['props'][0];
    }

    // ------------------------------------------------------- lo que sale solo

    public function test_a_model_foreign_key_restricts_by_default(): void
    {
        // ES LA PRUEBA QUE DA NOMBRE AL CAMBIO. Borrar la fila padre tiene que
        // FALLAR, no llevarse en silencio lo que cuelga de ella.
        $prop = $this->resolved($this->prop());

        $this->assertSame('restrict', $prop['on_delete']);
        $this->assertSame('restrict', $prop['on_update']);
    }

    public function test_a_pivot_foreign_key_cascades_by_default(): void
    {
        // La única excepción, y es correcta: una fila pivote no significa nada
        // sin sus dos lados.
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray([
            'models' => [['name' => 'Product', 'props' => [['name' => 'name', 'type' => 'string']]]],
            'pivots' => [[
                'name' => 'product_tag',
                'props' => [
                    ['name' => 'product_id', 'type' => 'foreignId', 'constraint' => 'products'],
                    ['name' => 'tag_id', 'type' => 'foreignId', 'constraint' => 'tags'],
                ],
            ]],
        ]);

        $this->assertNotNull($document, 'No validó: '.json_encode($errors));

        foreach ($document->pivots()[0]['props'] as $prop) {
            $this->assertSame('cascade', $prop['on_delete'], $prop['name']);
            $this->assertSame('cascade', $prop['on_update'], $prop['name']);
        }
    }

    // ------------------------------------------------------- lo que se pide

    public function test_cascade_can_be_asked_for_explicitly(): void
    {
        $prop = $this->resolved($this->prop(['on_delete' => 'cascade']));

        $this->assertSame('cascade', $prop['on_delete']);
        // Y sólo lo que se pidió: `on_update` sigue en lo suyo.
        $this->assertSame('restrict', $prop['on_update']);
    }

    public function test_the_schema_only_accepts_the_four_actions_sql_has(): void
    {
        foreach (['cascade', 'restrict', 'set null', 'no action'] as $valid) {
            ['document' => $document] = ImportDocument::fromArray([
                'models' => [
                    ['name' => 'Category', 'props' => [['name' => 'name', 'type' => 'string']]],
                    ['name' => 'Product', 'props' => [
                        $this->prop(['on_delete' => $valid, 'nullable' => true]),
                    ]],
                ],
            ]);

            $this->assertNotNull($document, "El esquema rechazó «{$valid}», que es SQL válido.");
        }

        ['document' => $invalid] = ImportDocument::fromArray([
            'models' => [['name' => 'Product', 'props' => [
                $this->prop(['on_delete' => 'CASCADE']),
            ]]],
        ]);

        $this->assertNull($invalid, 'El esquema aceptó «CASCADE» en mayúsculas.');
    }

    // ------------------------------------------- lo que no puede pasar callado

    public function test_set_null_on_a_column_that_cannot_be_null_is_an_error(): void
    {
        // MySQL lo rechaza al crear la tabla con un 1215 pelado, que no dice
        // cuál de las claves es ni por qué. Aquí se dice antes de escribir nada.
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray([
            'models' => [['name' => 'Product', 'props' => [
                $this->prop(['on_delete' => 'set null']),
            ]]],
        ]);

        $this->assertNull($document, 'Dejó pasar un set null sobre una columna NOT NULL.');

        $messages = implode("\n", array_column($errors, 'message'));

        $this->assertStringContainsString('category_id', $messages);
        $this->assertStringContainsString('nullable', $messages);
    }

    public function test_set_null_is_fine_when_the_column_is_nullable(): void
    {
        $prop = $this->resolved($this->prop(['on_delete' => 'set null', 'nullable' => true]));

        $this->assertSame('set null', $prop['on_delete']);
    }
}
