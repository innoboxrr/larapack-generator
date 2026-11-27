<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\ColumnDefinition;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\MigrationReader;
use Innoboxrr\LarapackGenerator\Tools\Migration\Schema\SchemaHistory;
use PHPUnit\Framework\TestCase;

/**
 * La lectura de migraciones reales: lo que se lee, lo que se lee igual
 * aunque se escriba distinto, y lo que no se puede leer y se dice.
 *
 * Los archivos son copias literales de school-management, school-site,
 * school-payroll, expediente y brandai.
 */
final class MigrationReaderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/migrations';

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            array_map('unlink', glob($this->directory.'/*') ?: []);
            rmdir($this->directory);
        }

        MigrationReader::reset();

        parent::tearDown();
    }

    /**
     * brandai escribe la llave en cinco líneas, con `->after()` en medio y
     * `->nullOnDelete()`: es la misma llave que la de una línea.
     */
    public function test_una_llave_repartida_en_varias_lineas_es_la_misma_llave(): void
    {
        $users = $this->history('brandai/2026_04_06_000001_add_current_workspace_id_to_users_table.php')->table('users');
        $column = $users->columns['current_workspace_id'] ?? null;

        $this->assertInstanceOf(ColumnDefinition::class, $column);
        $this->assertTrue($column->nullable);
        $this->assertSame('workspaces', $column->foreign?->table);
        $this->assertSame('set null', $column->foreign->onDelete);
        $this->assertSame([], $users->opaque);
    }

    /**
     * `constrained()` sin argumentos apunta a la tabla que Laravel deduce del
     * nombre: `registered_domain_id` → `registered_domains`.
     */
    public function test_constrained_sin_tabla_apunta_a_la_que_deduce_laravel(): void
    {
        $sites = $this->history(
            'brandai/2026_02_24_000016_create_sites_table.php',
            'brandai/2026_06_25_000002_add_registered_domain_id_to_sites.php',
        )->table('sites');

        $this->assertSame('registered_domains', $sites->columns['registered_domain_id']->foreign?->table);
        $this->assertSame(['255'], array_map(fn ($v) => $v->export(), $sites->columns['subdomain']->args));
        $this->assertCount(2, $sites->files);
    }

    public function test_foreignid_y_unsignedbiginteger_con_foreign_son_la_misma_columna(): void
    {
        $twoSteps = MigrationReader::columns("\$table->unsignedBigInteger('clinic_id');")[0];
        $oneStep = MigrationReader::columns("\$table->foreignId('clinic_id')->constrained('clinics')->onUpdate('restrict')->onDelete('cascade');")[0];

        $this->assertTrue($twoSteps->sameColumnAs($oneStep));
        $this->assertTrue(MigrationReader::columns("\$table->integer('n')->unsigned()->nullable();")[0]
            ->sameColumnAs(MigrationReader::columns("\$table->unsignedInteger('n')->nullable();")[0]));
    }

    /**
     * Un default se compara por lo que guarda la base: `5` y `'5'` son lo
     * mismo; `01` es el 1 y no `'01'`.
     */
    public function test_el_default_se_compara_por_lo_que_guarda_la_base(): void
    {
        $column = fn (string $code): ColumnDefinition => MigrationReader::columns($code)[0];

        $this->assertTrue($column("\$table->string('a')->default(5);")->sameColumnAs($column("\$table->string('a')->default('5');")));
        $this->assertFalse($column("\$table->string('a')->default(01);")->sameColumnAs($column("\$table->string('a')->default('01');")));
        $this->assertTrue($column("\$table->decimal('a')->default(10.00);")->sameColumnAs($column("\$table->decimal('a')->default('10');")));
        $this->assertTrue($column("\$table->boolean('a')->default(true);")->sameColumnAs($column("\$table->boolean('a')->default(1);")));
        $this->assertTrue($column("\$table->string('a')->nullable()->default(null);")->sameColumnAs($column("\$table->string('a')->nullable();")));
        $this->assertFalse($column("\$table->string('a')->default('it\\'s');")->sameColumnAs($column("\$table->string('a')->default('its');")));
    }

    /**
     * El `down()` no cuenta: lo que deshace no es lo que hay.
     */
    public function test_lo_que_hace_down_no_cuenta(): void
    {
        $posts = $this->history(
            'school-site/2026_10_11_060713_create_site_site_posts_table.php',
            'school-site/2026_10_11_120000_site_media.php',
        )->table('site_site_posts');

        $this->assertArrayHasKey('cover_file', $posts->columns);
        $this->assertSame('char', $posts->columns['cover_file']->method);
        $this->assertSame('500', $posts->columns['cover_url']->args[0]->export());
        $this->assertStringEndsWith('2026_10_11_120000_site_media.php', $posts->origin['cover_file']);
    }

    public function test_un_bucle_deja_la_tabla_sin_leer_y_dice_el_archivo(): void
    {
        $memberships = $this->history(
            'expediente/2026_09_17_001428_create_memberships_table.php',
            'expediente/2026_09_17_170120_invitaciones_al_equipo.php',
        )->table('memberships');

        $this->assertNotSame([], $memberships->opaque);
        $this->assertStringContainsString('2026_09_17_170120_invitaciones_al_equipo.php', implode(' ', $memberships->opaque));
    }

    /**
     * expediente rehace las llaves en un método privado, con SQL armado con la
     * tabla en un parámetro: no se sabe qué llave de qué tabla deja, pero el
     * archivo nombra `practitioners` y `specialties`. Sus llaves no se pueden
     * leer; sus columnas sí, porque ese SQL sólo pone y quita llaves.
     */
    public function test_el_sql_que_rehace_llaves_deja_sin_leer_las_llaves_de_las_tablas_que_nombra(): void
    {
        $history = $this->history(
            'expediente/2026_09_16_064547_create_practitioners_table.php',
            'expediente/2026_09_16_132439_create_specialties_table.php',
            'expediente/2026_09_19_110000_el_medico_tiene_especialidad.php',
        );

        foreach (['practitioners', 'specialties'] as $table) {
            $state = $history->table($table);

            $this->assertStringContainsString('el_medico_tiene_especialidad', implode(' ', $state->foreignOpaque), $table);
            $this->assertSame([], $state->opaque, $table);
        }

        $this->assertArrayHasKey('specialty_id', $history->table('practitioners')->columns);
    }

    /**
     * Lo que dice un SQL: un CHECK o un índice no cambia columnas; un
     * MODIFY, un DROP COLUMN o un ADD sin más sí.
     */
    public function test_el_sql_se_clasifica_por_lo_que_le_hace_a_las_columnas(): void
    {
        $this->assertSame('none', MigrationReader::sqlEffect('ALTER TABLE `t` ADD CONSTRAINT `ck` CHECK (`status` IN (1))'));
        $this->assertSame('none', MigrationReader::sqlEffect('ALTER TABLE ? ADD UNIQUE INDEX `u` (`a`, `b`)'));
        $this->assertSame('none', MigrationReader::sqlEffect('ALTER TABLE `t` DROP CHECK `ck`'));
        $this->assertSame('none', MigrationReader::sqlEffect('UPDATE `t` SET `a` = 1'));
        $this->assertSame('foreign', MigrationReader::sqlEffect('ALTER TABLE ? DROP FOREIGN KEY ?'));
        $this->assertSame('columns', MigrationReader::sqlEffect('ALTER TABLE `t` MODIFY `a` VARCHAR(2) NOT NULL'));
        $this->assertSame('columns', MigrationReader::sqlEffect('ALTER TABLE ? ADD COLUMN ? ? GENERATED ALWAYS AS (?) STORED'));
        $this->assertSame('columns', MigrationReader::sqlEffect('ALTER TABLE `t` ADD `a` INT'));
        $this->assertSame('columns', MigrationReader::sqlEffect('ALTER TABLE `t` DROP `a`'));
        $this->assertSame('unknown', MigrationReader::sqlEffect('RENAME TABLE `a` TO `b`'));
    }

    /**
     * El SQL crudo de school-payroll: las columnas que cambia con MODIFY
     * quedan inciertas.
     */
    public function test_el_sql_crudo_vuelve_inciertas_las_columnas_que_nombra(): void
    {
        $profiles = $this->history(
            'school-payroll/2026_10_11_052126_create_payroll_payroll_fiscal_profiles_table.php',
            'school-payroll/2026_10_11_053000_payroll_imss_and_stamping_invariants.php',
        )->table('payroll_payroll_fiscal_profiles');

        $this->assertNotNull($profiles->doubtAbout('contract_type'));
        $this->assertNotNull($profiles->doubtAbout('regime_type'));
        $this->assertNull($profiles->doubtAbout('nss_number'));

        // El bucle con `sprintf` sólo añade CHECK: no deja la tabla sin leer.
        $this->assertSame([], $profiles->opaque);
    }

    public function test_una_tabla_en_otra_conexion_no_se_suma_a_la_de_la_aplicacion(): void
    {
        $this->directory = sys_get_temp_dir().'/larapack-reader-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory.'/2026_01_01_000000_create_posts_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
    }
};
PHP);
        file_put_contents($this->directory.'/2026_01_02_000000_tenant_posts.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('posts', function (Blueprint $table) {
            $table->string('slug');
        });
    }
};
PHP);

        $posts = (new SchemaHistory($this->directory))->table('posts');

        $this->assertArrayNotHasKey('slug', $posts->columns);
        $this->assertStringContainsString('2026_01_02_000000_tenant_posts.php', implode(' ', $posts->opaque));
    }

    private function history(string ...$fixtures): SchemaHistory
    {
        $this->directory = sys_get_temp_dir().'/larapack-reader-'.bin2hex(random_bytes(6));
        mkdir($this->directory);

        foreach ($fixtures as $fixture) {
            copy(self::FIXTURES.'/'.$fixture, $this->directory.'/'.basename($fixture));
        }

        return new SchemaHistory($this->directory);
    }
}
