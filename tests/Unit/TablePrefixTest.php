<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\TablePrefix;
use PHPUnit\Framework\TestCase;

/**
 * El prefijo de tablas: qué se prefija, qué no, y que sin la clave no cambia
 * absolutamente nada.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * LO QUE DE VERDAD SE VIGILA AQUÍ.
 *
 * Son tres cosas, y las tres pueden romperse en silencio:
 *
 *   1. Que una clave ajena hacia FUERA del archivo —`users`, la tabla del
 *      anfitrión, la de otro paquete— NO se prefije. Prefijarla genera una
 *      restricción contra una tabla que no existe, y el fallo no aparece al
 *      generar sino al migrar, lejos de su causa.
 *
 *   2. Que sin `table_prefix` todo salga como salía. Es la única forma de que
 *      esto no rompa los proyectos que ya existen.
 *
 *   3. Que el prefijo no se quede pegado entre órdenes. Es estado estático: si
 *      sobrevive a una importación, se cuela en la siguiente.
 * ────────────────────────────────────────────────────────────────────────────
 */
final class TablePrefixTest extends TestCase
{
    protected function setUp(): void
    {
        TablePrefix::reset();
    }

    protected function tearDown(): void
    {
        TablePrefix::reset();
    }

    // ------------------------------------------------------ la normalización

    public function test_the_trailing_underscore_is_optional(): void
    {
        // El esquema documenta la forma con barra baja, pero escribirla sin
        // ella no puede dar `academicsstudents`: sería una trampa silenciosa.
        TablePrefix::set('academics');
        $this->assertSame('academics_', TablePrefix::get());

        TablePrefix::set('academics_');
        $this->assertSame('academics_', TablePrefix::get());
    }

    public function test_an_empty_prefix_stays_empty(): void
    {
        TablePrefix::set('');
        $this->assertSame('', TablePrefix::get());

        TablePrefix::set('   ');
        $this->assertSame('', TablePrefix::get());
    }

    // -------------------------------------------- lo que se prefija y lo que no

    public function test_it_prefixes_the_tables_of_this_file(): void
    {
        TablePrefix::fromDocument([
            'table_prefix' => 'academics_',
            'models' => [['name' => 'Student'], ['name' => 'CurriculumVersion']],
        ]);

        $this->assertSame('academics_students', TablePrefix::table('students'));
        $this->assertSame('academics_students', TablePrefix::constraint('students'));
        $this->assertSame('academics_curriculum_versions', TablePrefix::constraint('curriculum_versions'));
    }

    public function test_it_leaves_alone_a_foreign_key_that_points_outside(): void
    {
        // ES EL CASO QUE ROMPE EL DESPLIEGUE SI SE HACE MAL. `users` y `parties`
        // son del anfitrión: ya existen y ya tienen su nombre.
        TablePrefix::fromDocument([
            'table_prefix' => 'academics_',
            'models' => [['name' => 'Student']],
        ]);

        $this->assertSame('users', TablePrefix::constraint('users'));
        $this->assertSame('parties', TablePrefix::constraint('parties'));
        $this->assertSame('library_loans', TablePrefix::constraint('library_loans'));
    }

    public function test_the_metas_table_of_a_model_counts_as_its_own(): void
    {
        TablePrefix::fromDocument([
            'table_prefix' => 'shop_',
            'models' => [['name' => 'Product']],
        ]);

        $this->assertSame('shop_product_metas', TablePrefix::constraint('product_metas'));
    }

    public function test_a_pivot_declared_in_the_file_counts_as_its_own(): void
    {
        TablePrefix::fromDocument([
            'table_prefix' => 'shop_',
            'models' => [['name' => 'Product']],
            'pivots' => [['name' => 'product_tag']],
        ]);

        $this->assertSame('shop_product_tag', TablePrefix::constraint('product_tag'));
    }

    // -------------------------------------------------- sin la clave, nada cambia

    public function test_without_the_key_nothing_is_touched(): void
    {
        TablePrefix::fromDocument([
            'models' => [['name' => 'Student']],
        ]);

        $this->assertSame('', TablePrefix::get());
        $this->assertSame('students', TablePrefix::table('students'));
        $this->assertSame('students', TablePrefix::constraint('students'));
        $this->assertSame('users', TablePrefix::constraint('users'));
    }

    // ------------------------------------------------------------- el estado

    public function test_reset_clears_it(): void
    {
        TablePrefix::fromDocument([
            'table_prefix' => 'academics_',
            'models' => [['name' => 'Student']],
        ]);

        TablePrefix::reset();

        $this->assertSame('', TablePrefix::get());
        // Y también la lista de tablas propias: si quedara, la orden siguiente
        // prefijaría una clave ajena que ya no es suya.
        $this->assertSame('students', TablePrefix::constraint('students'));
    }

    // ---------------------------------------------- lo que dice el contrato

    public function test_the_schema_accepts_the_key_and_defaults_it_to_empty(): void
    {
        ['document' => $document, 'errors' => $errors] = ImportDocument::fromArray([
            'table_prefix' => 'academics_',
            'models' => [['name' => 'Student', 'props' => [['name' => 'code', 'type' => 'string']]]],
        ]);

        $this->assertNotNull($document, 'El esquema rechazó table_prefix: '.json_encode($errors));
        $this->assertSame('academics_', $document->toArray()['table_prefix']);

        ['document' => $without] = ImportDocument::fromArray([
            'models' => [['name' => 'Student', 'props' => [['name' => 'code', 'type' => 'string']]]],
        ]);

        $this->assertNotNull($without);
        $this->assertSame('', $without->toArray()['table_prefix']);
    }

    public function test_it_warns_about_a_validation_rule_that_names_a_table_without_the_prefix(): void
    {
        // ES EL UNICO SITIO QUE EL PREFIJO NO PUEDE RESOLVER SOLO: la regla es
        // una cadena libre, y reescribirla sería adivinar. Sin el aviso, la
        // validación consultaría una tabla que no existe y el error saldría en
        // producción con un SQLSTATE.
        ['errors' => $errors] = ImportDocument::fromArray([
            'table_prefix' => 'academics_',
            'models' => [[
                'name' => 'Student',
                'props' => [['name' => 'code', 'type' => 'string']],
                'requests' => [[
                    'name' => 'Create',
                    'rules' => ['code' => 'required|string|unique:students,code'],
                ]],
            ]],
        ]);

        $messages = array_column($errors, 'message');

        $this->assertNotEmpty(array_filter(
            $messages,
            fn (string $m): bool => str_contains($m, 'unique:students') && str_contains($m, 'academics_')
        ), 'No avisó de la regla sin prefijo: '.json_encode($messages));
    }

    public function test_it_does_not_warn_about_a_rule_that_names_a_table_of_the_host(): void
    {
        ['errors' => $errors] = ImportDocument::fromArray([
            'table_prefix' => 'academics_',
            'models' => [[
                'name' => 'Student',
                'props' => [['name' => 'code', 'type' => 'string']],
                'requests' => [[
                    'name' => 'Create',
                    'rules' => ['code' => 'required|string|exists:users,email'],
                ]],
            ]],
        ]);

        $messages = array_column($errors, 'message');

        $this->assertEmpty(array_filter(
            $messages,
            fn (string $m): bool => str_contains($m, 'exists:users')
        ), 'Avisó de una tabla del anfitrión: '.json_encode($messages));
    }

    public function test_the_schema_rejects_a_prefix_that_is_not_a_table_name(): void
    {
        foreach (['Academics_', 'academics-', '1academics', 'academics prefix'] as $invalid) {
            ['document' => $document] = ImportDocument::fromArray([
                'table_prefix' => $invalid,
                'models' => [['name' => 'Student', 'props' => [['name' => 'code', 'type' => 'string']]]],
            ]);

            $this->assertNull($document, "El esquema aceptó «{$invalid}», que no puede ser un prefijo de tabla.");
        }
    }
}
