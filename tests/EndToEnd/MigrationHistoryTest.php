<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Illuminate\Support\Facades\Schema;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use Orchestra\Testbench\TestCase;

/**
 * Las migraciones de un proyecto que ya existe, leídas todas antes de escribir
 * ninguna: lo que encontró el análisis de viabilidad sobre school-management,
 * expediente y brandai (9.3.1).
 *
 * Cada caso parte de migraciones REALES de esas aplicaciones, copiadas tal cual
 * a tests/Fixtures/migrations, y del laraimport con el que se generaron. Lo que
 * se comprueba es lo que les rompía: que `import --force` no deje una base que
 * `migrate:fresh` no puede levantar, y que el segundo import no escriba nada.
 */
final class MigrationHistoryTest extends TestCase
{
    private const NAMESPACE = 'Acme\\Shop\\';

    private const FIXTURES = __DIR__.'/../Fixtures/migrations';

    private ?FakeProject $project = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = FakeProject::library(self::NAMESPACE);

        mkdir($this->migrations(), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->project?->cleanup();
        $this->project = null;

        parent::tearDown();
    }

    /**
     * D1. school-management: `regulatory_authorities` recibió `state_code` en
     * una alteración. Con el laraimport al día, alter() no tenía nada que
     * escribir y caía en generate(), que con --force reescribía la creación
     * CON `state_code`: la alteración la volvía a añadir y `migrate:fresh`
     * moría con «duplicate column». Pasaba en 50 tablas.
     */
    public function test_la_creacion_de_una_tabla_ya_alterada_no_se_reescribe(): void
    {
        $create = $this->install('school-management/2026_09_18_134352_create_regulatory_authorities_table.php', 'RegulatoryAuthority');
        $this->install('school-management/2026_09_26_063336_alter_regulatory_authorities_table.php', 'RegulatoryAuthority');
        $original = (string) file_get_contents($create);

        $declaration = ['models' => [['name' => 'RegulatoryAuthority', 'props' => [
            ['name' => 'code', 'type' => 'string'],
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'country', 'type' => 'string'],
            ['name' => 'jurisdiction', 'type' => 'string', 'nullable' => true],
            ['name' => 'level', 'type' => 'string', 'default' => 'FEDERAL'],
            ['name' => 'status', 'type' => 'string', 'default' => 'ACTIVE'],
            ['name' => 'state_code', 'type' => 'string', 'nullable' => true],
        ]]]];

        $entries = $this->import($declaration, ['--force' => true]);

        $this->assertSame($original, (string) file_get_contents($create), 'La migración de creación se reescribió con lo que añadió la alteración.');
        $this->assertCount(2, $this->files(), 'Se escribió otra migración sobre un esquema que ya coincide.');
        $this->assertSame('skipped', $this->entryFor($entries, $create)['action'] ?? null);

        $this->fresh();

        $this->assertTrue(Schema::hasColumn('regulatory_authorities', 'state_code'));

        $this->assertImportIsIdempotent($declaration);
    }

    /**
     * D2. school-site: `site_media.php` es una migración escrita a mano, con
     * otro nombre, que añade `cover_file` y ensancha `cover_url`. El generador
     * sólo leía `*_alter_<tabla>_table.php` y volvía a añadir `cover_file` en
     * una alteración propia: duplicada en una base nueva.
     */
    public function test_lee_las_migraciones_escritas_a_mano_con_cualquier_nombre(): void
    {
        $create = $this->install('school-site/2026_10_11_060713_create_site_site_posts_table.php', 'SitePost');
        $this->install('school-site/2026_10_11_120000_site_media.php', null);
        $original = (string) file_get_contents($create);

        $declaration = ['table_prefix' => 'site_', 'models' => [['name' => 'SitePost', 'props' => [
            ['name' => 'uuid', 'type' => 'uuid'],
            ['name' => 'site_id', 'type' => 'foreignId', 'constraint' => 'site_sites', 'on_update' => 'restrict', 'on_delete' => 'restrict'],
            ['name' => 'kind', 'type' => 'string', 'default' => 'NEWS'],
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'slug', 'type' => 'string'],
            ['name' => 'summary', 'type' => 'string', 'nullable' => true],
            ['name' => 'body', 'type' => 'text', 'nullable' => true],
            ['name' => 'cover_url', 'type' => 'string', 'nullable' => true],
            ['name' => 'cover_file', 'type' => 'string', 'nullable' => true],
            ['name' => 'starts_at', 'type' => 'dateTime', 'nullable' => true],
            ['name' => 'ends_at', 'type' => 'dateTime', 'nullable' => true],
            ['name' => 'location', 'type' => 'string', 'nullable' => true],
            ['name' => 'status', 'type' => 'string', 'default' => 'DRAFT'],
            ['name' => 'published_at', 'type' => 'dateTime', 'nullable' => true],
        ]]]];

        $this->import($declaration, ['--force' => true]);

        $this->assertSame($original, (string) file_get_contents($create));
        $this->assertCount(2, $this->files(), 'Se volvió a añadir lo que ya añade la migración escrita a mano.');

        $this->fresh();

        $this->assertTrue(Schema::hasColumn('site_site_posts', 'cover_file'));

        $this->assertImportIsIdempotent($declaration);
    }

    /**
     * D2. expediente: `invitaciones_al_equipo.php` añade las columnas de la
     * invitación en un bucle, con `Schema::hasColumn` delante de cada una. Eso
     * no se lee con seguridad, así que no se adivina: no se escribe ninguna
     * alteración y se dice qué archivo lo impide.
     */
    public function test_lo_que_no_se_puede_leer_no_se_adivina(): void
    {
        // La creación tal como la generó LaraPack antes de las invitaciones: la
        // real ya las trae, porque una importación posterior la reescribió
        // (justo el defecto D1).
        $create = $this->install('expediente/2026_09_17_001428_create_memberships_table.php', 'Membership', fn (string $code): string => (string) preg_replace(
            '/^\s*\$table->(unsignedBigInteger\(\'invited_by_id\'|timestamp\(\'invit|timestamp\(\'accepted_at|string\(\'invitation_|boolean\(\'invitation_).*\n/m',
            '',
            $code
        ));
        $this->install('expediente/2026_09_17_170120_invitaciones_al_equipo.php', null);
        $original = (string) file_get_contents($create);

        $this->assertStringNotContainsString('invited_at', $original);

        $declaration = ['models' => [['name' => 'Membership', 'props' => [
            ['name' => 'clinic_id', 'type' => 'foreignId', 'constraint' => 'clinics', 'on_update' => 'cascade', 'on_delete' => 'cascade'],
            ['name' => 'user_id', 'type' => 'foreignId', 'constraint' => 'users', 'on_update' => 'cascade', 'on_delete' => 'cascade'],
            ['name' => 'role', 'type' => 'string', 'default' => 'receptionist'],
            ['name' => 'abilities', 'type' => 'json', 'nullable' => true],
            ['name' => 'status', 'type' => 'string', 'default' => 'active'],
            ['name' => 'invited_by_id', 'type' => 'unsignedBigInteger', 'nullable' => true],
            ['name' => 'invited_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'accepted_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'invitation_token_hash', 'type' => 'string', 'nullable' => true],
            ['name' => 'invitation_expires_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'invitation_created_user', 'type' => 'boolean', 'default' => false],
        ]]]];

        $entries = $this->import($declaration, ['--force' => true]);

        $this->assertSame($original, (string) file_get_contents($create));
        $this->assertCount(2, $this->files(), 'Se escribió una alteración adivinando lo que hace un bucle.');

        $entry = $this->entryFor($entries, $create);

        $this->assertSame('skipped', $entry['action'] ?? null);
        $this->assertStringContainsString('2026_09_17_170120_invitaciones_al_equipo.php', (string) ($entry['reason'] ?? ''));

        $this->fresh();

        $this->assertTrue(Schema::hasColumn('memberships', 'invited_at'));
    }

    /**
     * D4. expediente y school-management: las llaves de 7.x dicen `cascade` y
     * desde 8.0 el generador escribe `restrict`. Comparar el texto dejaba 33 y
     * 89 alteraciones vacías, sólo con un comentario. Lo que el laraimport no
     * declara no lo pide cambiar: se conserva y no se escribe nada.
     *
     * Y la llave escrita en dos pasos —`unsignedBigInteger` y luego
     * `foreign()->references()->on()`— es la misma que `foreignId()->constrained()`.
     */
    public function test_la_accion_de_una_llave_que_el_laraimport_no_declara_se_conserva(): void
    {
        $create = $this->install('expediente/2026_09_17_001428_create_memberships_table.php', 'Membership');
        $original = (string) file_get_contents($create);

        $declaration = $this->memberships();

        $entries = $this->import($declaration, ['--force' => true]);

        $this->assertSame($original, (string) file_get_contents($create));
        $this->assertCount(1, $this->files(), 'Se escribió una alteración por una acción que el laraimport no declara.');
        $this->assertStringContainsString('on_delete', (string) ($this->entryFor($entries, $create)['reason'] ?? ''), 'No se sugiere declarar on_delete.');

        $this->fresh();

        $this->assertSame('cascade', $this->foreignKey('memberships', 'clinic_id')['on_delete'] ?? null);

        $this->assertImportIsIdempotent($declaration);
    }

    /**
     * D4. Si el laraimport SÍ declara otra acción, eso es un cambio de verdad:
     * se quita la llave y se vuelve a crear, no se deja un comentario.
     */
    public function test_una_accion_declarada_distinta_cambia_la_llave_de_verdad(): void
    {
        $this->install('expediente/2026_09_17_001428_create_memberships_table.php', 'Membership');

        $declaration = $this->memberships();
        $declaration['models'][0]['props'][0]['on_delete'] = 'restrict';

        $this->import($declaration, ['--force' => true]);

        $alters = $this->files('*_alter_memberships_table.php');

        $this->assertCount(1, $alters);

        $code = (string) file_get_contents($alters[0]);

        $this->assertStringNotContainsString('a mano', $code);
        $this->assertStringContainsString("dropForeign(['clinic_id'])", $code);
        $this->assertStringContainsString("->onDelete('restrict')", $code);
        $this->assertStringNotContainsString("'user_id'", $code, 'Sólo cambia la llave que declara otra acción.');

        $this->fresh();

        $this->assertSame('restrict', $this->foreignKey('memberships', 'clinic_id')['on_delete'] ?? null);
        $this->assertSame('cascade', $this->foreignKey('memberships', 'user_id')['on_delete'] ?? null);

        $this->artisan('migrate:rollback', ['--path' => $this->migrations(), '--realpath' => true, '--step' => 1])->assertSuccessful()->run();

        $this->assertSame('cascade', $this->foreignKey('memberships', 'clinic_id')['on_delete'] ?? null);

        $this->assertImportIsIdempotent($declaration);
    }

    /**
     * D5. expediente: `practitioners.specialty_id` se queda sin llave porque
     * `create_practitioners_table` corre antes que `create_specialties_table`
     * («1824 Failed to open the referenced table»). Al declararla en el
     * laraimport, la llave va en una alteración fechada después de las dos
     * tablas, nunca dentro de la creación.
     */
    public function test_la_llave_hacia_una_tabla_posterior_va_en_una_alteracion_posterior(): void
    {
        $practitioners = $this->install('expediente/2026_09_16_064547_create_practitioners_table.php', 'Practitioner');
        $specialties = $this->install('expediente/2026_09_16_132439_create_specialties_table.php', 'Specialty');
        $original = (string) file_get_contents($practitioners);

        $declaration = ['models' => [
            ['name' => 'Specialty', 'props' => [
                ['name' => 'code', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'parent_id', 'type' => 'foreignId', 'constraint' => 'specialties', 'nullable' => true, 'on_update' => 'cascade', 'on_delete' => 'cascade'],
                ['name' => 'position', 'type' => 'unsignedInteger', 'default' => 0],
                ['name' => 'is_active', 'type' => 'boolean', 'default' => true],
            ]],
            ['name' => 'Practitioner', 'props' => [
                ['name' => 'clinic_id', 'type' => 'foreignId', 'constraint' => 'clinics', 'on_update' => 'cascade', 'on_delete' => 'cascade'],
                ['name' => 'user_id', 'type' => 'foreignId', 'constraint' => 'users', 'on_update' => 'cascade', 'on_delete' => 'cascade'],
                ['name' => 'license_number', 'type' => 'string'],
                ['name' => 'curp', 'type' => 'string', 'nullable' => true],
                ['name' => 'education_code', 'type' => 'string', 'nullable' => true],
                ['name' => 'given_name', 'type' => 'string', 'nullable' => true],
                ['name' => 'paternal_name', 'type' => 'string', 'nullable' => true],
                ['name' => 'maternal_name', 'type' => 'string', 'nullable' => true],
                ['name' => 'birth_country_code', 'type' => 'string', 'nullable' => true],
                ['name' => 'sis_staff_type', 'type' => 'unsignedSmallInteger', 'nullable' => true],
                ['name' => 'education_catalog_release_id', 'type' => 'unsignedBigInteger', 'nullable' => true],
                ['name' => 'specialty_id', 'type' => 'foreignId', 'constraint' => 'specialties', 'nullable' => true, 'on_delete' => 'set null'],
                ['name' => 'specialty', 'type' => 'string', 'default' => 'orthopedics'],
                ['name' => 'subspecialty', 'type' => 'string', 'nullable' => true],
                ['name' => 'title', 'type' => 'string', 'nullable' => true, 'default' => 'Dr.'],
                ['name' => 'public_slug', 'type' => 'string'],
                ['name' => 'bio', 'type' => 'text', 'nullable' => true],
                ['name' => 'status', 'type' => 'string', 'default' => 'active'],
                ['name' => 'verified_at', 'type' => 'timestamp', 'nullable' => true],
            ], 'metas' => true],
        ]];

        $this->import($declaration, ['--force' => true]);

        $this->assertSame($original, (string) file_get_contents($practitioners), 'La llave se escribió dentro de la creación, que corre antes que specialties.');

        $alters = $this->files('*_alter_practitioners_table.php');

        $this->assertCount(1, $alters);
        $this->assertGreaterThan(basename($specialties), basename($alters[0]), 'La alteración corre antes que la tabla a la que apunta.');

        $code = (string) file_get_contents($alters[0]);

        $this->assertStringNotContainsString('a mano', $code);
        $this->assertStringContainsString("foreign('specialty_id')", $code);
        $this->assertStringNotContainsString('->change()', $code, 'El tipo no cambió: foreignId y unsignedBigInteger son la misma columna.');

        $this->fresh();

        $this->assertSame('specialties', $this->foreignKey('practitioners', 'specialty_id')['foreign_table'] ?? null);

        $this->assertImportIsIdempotent($declaration);
    }

    /**
     * D5. La tabla a la que apunta la llave se crea en una migración fechada
     * DESPUÉS de hoy —una fecha escrita a mano, o un reloj atrasado—: la
     * alteración va después de ella, no con la hora del reloj.
     */
    /**
     * `migrate` ordena por el nombre entero: dos migraciones del mismo segundo
     * se ordenan por lo que sigue a la fecha, y `create_categories` corre
     * antes que `create_posts`. Comparar sólo la fecha las daba por iguales y
     * la llave iba dentro de una creación que corre antes que su tabla.
     */
    public function test_la_llave_hacia_una_tabla_del_mismo_segundo_que_corre_despues_se_difiere(): void
    {
        $moment = gmdate('Y_m_d_His', time() + 3600);
        $posts = $this->migrations().'/'.$moment.'_create_posts_table.php';
        file_put_contents($posts, <<<'PHP'
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
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
PHP);
        (new Manifest($this->project->path))->record('Post', self::NAMESPACE, $posts, 'Migration/MigrationTemplate.txt');

        // La creación nueva cae en el mismo segundo que la de `posts`.
        (new \ReflectionProperty(MigrationTimestamp::class, 'last'))->setValue(null, (int) gmmktime(...array_map('intval', [
            substr($moment, 11, 2), substr($moment, 13, 2), substr($moment, 15, 2), substr($moment, 5, 2), substr($moment, 8, 2), substr($moment, 0, 4),
        ])) - 1);

        $this->import(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
            ['name' => 'Category', 'props' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'post_id', 'type' => 'foreignId', 'constraint' => 'posts'],
            ]],
        ]]);

        $categories = glob($this->migrations().'/*_create_categories_table.php') ?: [];
        $this->assertCount(1, $categories);
        $this->assertStringStartsWith($moment, basename($categories[0]), 'La prueba no consiguió el mismo segundo.');
        $this->assertStringNotContainsString("constrained('posts')", (string) file_get_contents($categories[0]));
        $this->assertNotSame([], glob($this->migrations().'/*_add_foreign_keys_to_categories_table.php') ?: []);
    }

    public function test_la_llave_va_despues_de_la_tabla_aunque_esta_este_fechada_despues_de_hoy(): void
    {
        $this->install('expediente/2026_09_16_064547_create_practitioners_table.php', 'Practitioner');

        $future = $this->migrations().'/2099_01_01_000000_create_specialties_table.php';
        file_put_contents($future, (string) file_get_contents(self::FIXTURES.'/expediente/2026_09_16_132439_create_specialties_table.php'));
        (new Manifest($this->project->path))->record('Specialty', self::NAMESPACE, $future, 'Migration/MigrationTemplate.txt');

        $declaration = ['models' => [
            ['name' => 'Specialty', 'props' => [
                ['name' => 'code', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'parent_id', 'type' => 'foreignId', 'constraint' => 'specialties', 'nullable' => true, 'on_update' => 'cascade', 'on_delete' => 'cascade'],
                ['name' => 'position', 'type' => 'unsignedInteger', 'default' => 0],
                ['name' => 'is_active', 'type' => 'boolean', 'default' => true],
            ]],
            ['name' => 'Practitioner', 'metas' => true, 'props' => [
                ['name' => 'clinic_id', 'type' => 'foreignId', 'constraint' => 'clinics', 'on_update' => 'cascade', 'on_delete' => 'cascade'],
                ['name' => 'user_id', 'type' => 'foreignId', 'constraint' => 'users', 'on_update' => 'cascade', 'on_delete' => 'cascade'],
                ['name' => 'license_number', 'type' => 'string'],
                ['name' => 'curp', 'type' => 'string', 'nullable' => true],
                ['name' => 'education_code', 'type' => 'string', 'nullable' => true],
                ['name' => 'given_name', 'type' => 'string', 'nullable' => true],
                ['name' => 'paternal_name', 'type' => 'string', 'nullable' => true],
                ['name' => 'maternal_name', 'type' => 'string', 'nullable' => true],
                ['name' => 'birth_country_code', 'type' => 'string', 'nullable' => true],
                ['name' => 'sis_staff_type', 'type' => 'unsignedSmallInteger', 'nullable' => true],
                ['name' => 'education_catalog_release_id', 'type' => 'unsignedBigInteger', 'nullable' => true],
                ['name' => 'specialty_id', 'type' => 'foreignId', 'constraint' => 'specialties', 'nullable' => true, 'on_delete' => 'set null'],
                ['name' => 'specialty', 'type' => 'string', 'default' => 'orthopedics'],
                ['name' => 'subspecialty', 'type' => 'string', 'nullable' => true],
                ['name' => 'title', 'type' => 'string', 'nullable' => true, 'default' => 'Dr.'],
                ['name' => 'public_slug', 'type' => 'string'],
                ['name' => 'bio', 'type' => 'text', 'nullable' => true],
                ['name' => 'status', 'type' => 'string', 'default' => 'active'],
                ['name' => 'verified_at', 'type' => 'timestamp', 'nullable' => true],
            ]],
        ]];

        $this->import($declaration, ['--force' => true]);

        $alters = $this->files('*_alter_practitioners_table.php');

        $this->assertCount(1, $alters);
        $this->assertGreaterThan(basename($future), basename($alters[0]));

        $this->fresh();

        $this->assertSame('specialties', $this->foreignKey('practitioners', 'specialty_id')['foreign_table'] ?? null);
    }

    /**
     * D5. brandai: `sites` apunta a `registered_domains` y `registered_domains`
     * a `sites`. No había orden posible y el validador lo rechazaba. Con una de
     * las dos llaves nullable sí lo hay: las tablas se crean sin ella y la
     * llave va en una migración aparte, después de las dos.
     */
    public function test_un_ciclo_con_una_llave_nullable_se_resuelve_difiriendola(): void
    {
        $declaration = ['models' => [
            ['name' => 'Site', 'props' => [
                ['name' => 'subdomain', 'type' => 'string'],
                ['name' => 'domain', 'type' => 'string', 'nullable' => true],
                ['name' => 'registered_domain_id', 'type' => 'foreignId', 'constraint' => 'registered_domains', 'nullable' => true, 'on_delete' => 'set null'],
                ['name' => 'is_active', 'type' => 'boolean', 'default' => true],
            ]],
            ['name' => 'RegisteredDomain', 'props' => [
                ['name' => 'site_id', 'type' => 'foreignId', 'constraint' => 'sites', 'nullable' => true, 'on_delete' => 'set null'],
                ['name' => 'domain', 'type' => 'string'],
                ['name' => 'registrar', 'type' => 'string', 'default' => 'porkbun'],
            ]],
        ]];

        $this->import($declaration);

        $this->fresh();

        $this->assertSame('registered_domains', $this->foreignKey('sites', 'registered_domain_id')['foreign_table'] ?? null);
        $this->assertSame('sites', $this->foreignKey('registered_domains', 'site_id')['foreign_table'] ?? null);

        $this->assertImportIsIdempotent($declaration);

        $this->fresh();
    }

    /**
     * D3. school-payroll: la 9.0 escribía `->default(01)` para el `'01'` del
     * laraimport, y `01` es el octal 1: la columna valía `'1'`. La alteración
     * que lo corrige la deshacía copiando ese texto, sin comillas: un `down()`
     * que se lee como el número 1 sobre una columna de texto. Se deshace al
     * valor que de verdad tenía la columna, escrito como lo que es: `'1'`.
     */
    public function test_el_default_que_se_deshace_conserva_lo_que_valia_la_columna(): void
    {
        $this->install('school-payroll/2026_10_11_052126_create_payroll_payroll_fiscal_profiles_table.php', 'PayrollFiscalProfile');

        $this->import($this->fiscalProfiles(), ['--force' => true]);

        $alters = $this->files('*_alter_payroll_payroll_fiscal_profiles_table.php');

        $this->assertCount(1, $alters);

        $code = (string) file_get_contents($alters[0]);
        [$up, $down] = explode('function down', $code);

        $this->assertStringContainsString("\$table->string('contract_type')->default('01')->change();", $up);
        $this->assertStringContainsString("\$table->string('regime_type')->default('02')->change();", $up);
        $this->assertStringContainsString("\$table->string('contract_type')->default('1')->change();", $down);
        $this->assertStringContainsString("\$table->string('regime_type')->default('2')->change();", $down);
        $this->assertStringNotContainsString('default(0', $code);

        $this->fresh();

        $this->assertSame("'01'", $this->column('payroll_payroll_fiscal_profiles', 'contract_type')['default'] ?? null);

        $this->artisan('migrate:rollback', ['--path' => $this->migrations(), '--realpath' => true, '--step' => 1])->assertSuccessful()->run();

        $this->assertSame("'1'", $this->column('payroll_payroll_fiscal_profiles', 'contract_type')['default'] ?? null);

        $this->assertImportIsIdempotent($this->fiscalProfiles());
    }

    /**
     * D3. Una cadena con forma de número decimal salía sin comillas en
     * cualquier columna: el `'1.50'` de una columna de texto llegaba como el
     * número 1.5 y la base guardaba `'1.5'`.
     */
    public function test_una_columna_de_texto_conserva_el_default_tal_como_se_declara(): void
    {
        $this->import(['models' => [['name' => 'Rate', 'props' => [
            ['name' => 'label', 'type' => 'string', 'default' => '1.50'],
            ['name' => 'code', 'type' => 'char', 'default' => '10'],
            ['name' => 'amount', 'type' => 'decimal', 'default' => '1.50'],
            ['name' => 'active', 'type' => 'boolean', 'default' => true],
        ]]]]);

        $create = $this->files('*_create_rates_table.php')[0];
        $code = (string) file_get_contents($create);

        $this->assertStringContainsString("\$table->string('label')->default('1.50');", $code);
        $this->assertStringContainsString("\$table->char('code')->default('10');", $code);
        $this->assertStringContainsString("\$table->decimal('amount')->default(1.50);", $code);
        $this->assertStringContainsString("\$table->boolean('active')->default(true);", $code);

        $this->fresh();

        $this->assertSame("'1.50'", $this->column('rates', 'label')['default'] ?? null);
    }

    /**
     * D2. La migración de invariantes de school-payroll cambia columnas con
     * SQL crudo (`MODIFY contract_type … DEFAULT '01'`). Lo que cambia no se
     * compara con el laraimport: no se escribe nada sobre esas columnas y se
     * nombra el archivo.
     */
    public function test_el_sql_crudo_sobre_la_tabla_no_se_adivina(): void
    {
        $create = $this->install('school-payroll/2026_10_11_052126_create_payroll_payroll_fiscal_profiles_table.php', 'PayrollFiscalProfile');
        $this->install('school-payroll/2026_10_11_053000_payroll_imss_and_stamping_invariants.php', null);

        $entries = $this->import($this->fiscalProfiles(), ['--force' => true]);

        $this->assertCount(2, $this->files(), 'Se escribió una alteración sobre una tabla que cambia con SQL crudo.');

        $entry = $this->entryFor($entries, $create);

        $this->assertSame('skipped', $entry['action'] ?? null);
        $this->assertStringContainsString('2026_10_11_053000_payroll_imss_and_stamping_invariants.php', (string) ($entry['reason'] ?? ''));
    }

    /**
     * D2. Una tabla que ya crea una migración escrita a mano, con otro
     * nombre, no recibe una segunda creación: `migrate` fallaría con «table
     * already exists».
     */
    public function test_no_crea_una_tabla_que_ya_crea_otra_migracion(): void
    {
        $hand = $this->migrations().'/2026_01_01_000000_catalogo_base.php';

        file_put_contents($hand, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('products', function (Blueprint \$table) {\n            \$table->id();\n            \$table->string('title', 80);\n            \$table->timestamps();\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('products');\n    }\n};\n");

        $entries = $this->import(['models' => [['name' => 'Product', 'props' => [['name' => 'title', 'type' => 'string']]]]], ['--force' => true]);

        $this->assertCount(1, $this->files(), 'Se escribió una segunda creación de products.');
        $this->assertSame('skipped', $this->entryFor($entries, $hand)['action'] ?? null);

        $this->fresh();

        $this->assertTrue(Schema::hasColumn('products', 'title'));
    }

    /**
     * D1, fuera de la importación: `larapack:migration --force` tampoco
     * reescribe la creación de una tabla que ya tiene alteraciones.
     */
    public function test_regenerar_la_migracion_suelta_no_reescribe_una_tabla_alterada(): void
    {
        $create = $this->install('school-management/2026_09_18_134352_create_regulatory_authorities_table.php', 'RegulatoryAuthority');
        $this->install('school-management/2026_09_26_063336_alter_regulatory_authorities_table.php', 'RegulatoryAuthority');
        $original = (string) file_get_contents($create);

        ProjectRoot::set($this->project->path);

        try {
            [$code, $output] = Larapack::run('larapack:migration', ['name' => 'RegulatoryAuthority', '--force' => true]);
        } finally {
            ProjectRoot::set(null);
            Generation::reset();
            MigrationTimestamp::reset();
        }

        $this->assertSame(0, $code, $output);
        $this->assertSame($original, (string) file_get_contents($create));
    }

    // AYUDAS

    /**
     * @return array<string, mixed>
     */
    private function memberships(): array
    {
        return ['models' => [['name' => 'Membership', 'props' => [
            ['name' => 'clinic_id', 'type' => 'foreignId', 'constraint' => 'clinics'],
            ['name' => 'user_id', 'type' => 'foreignId', 'constraint' => 'users'],
            ['name' => 'role', 'type' => 'string', 'default' => 'receptionist'],
            ['name' => 'abilities', 'type' => 'json', 'nullable' => true],
            ['name' => 'status', 'type' => 'string', 'default' => 'active'],
            ['name' => 'invited_by_id', 'type' => 'unsignedBigInteger', 'nullable' => true],
            ['name' => 'invited_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'accepted_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'invitation_token_hash', 'type' => 'string', 'nullable' => true],
            ['name' => 'invitation_expires_at', 'type' => 'timestamp', 'nullable' => true],
            ['name' => 'invitation_created_user', 'type' => 'boolean', 'default' => false],
        ]]]];
    }

    /**
     * El perfil fiscal de school-payroll, como lo declara su laraimport.
     *
     * @return array<string, mixed>
     */
    private function fiscalProfiles(): array
    {
        return ['table_prefix' => 'payroll_', 'models' => [['name' => 'PayrollFiscalProfile', 'props' => [
            ['name' => 'scope_id', 'type' => 'foreignId', 'constraint' => 'scopes'],
            ['name' => 'party_id', 'type' => 'foreignId', 'constraint' => 'parties'],
            ['name' => 'rfc', 'type' => 'string'],
            ['name' => 'curp', 'type' => 'string'],
            ['name' => 'nss', 'type' => 'string', 'nullable' => true],
            ['name' => 'postal_code', 'type' => 'string'],
            ['name' => 'state_key', 'type' => 'string'],
            ['name' => 'contract_type', 'type' => 'string', 'default' => '01'],
            ['name' => 'regime_type', 'type' => 'string', 'default' => '02'],
            ['name' => 'risk_class', 'type' => 'string', 'nullable' => true],
            ['name' => 'sbc_daily_amount', 'type' => 'decimal', 'nullable' => true],
            ['name' => 'recorded_by_user_id', 'type' => 'foreignId', 'constraint' => 'users'],
        ]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $table, string $name): array
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        return [];
    }

    /**
     * Copia una migración real al proyecto. Con modelo, se anota en el
     * manifiesto como generada por LaraPack y sin tocar; sin modelo, es una
     * migración escrita a mano.
     *
     * @param  (callable(string): string)|null  $edit
     */
    private function install(string $fixture, ?string $model, ?callable $edit = null): string
    {
        $destination = $this->migrations().'/'.basename($fixture);
        $code = (string) file_get_contents(self::FIXTURES.'/'.$fixture);

        file_put_contents($destination, $edit === null ? $code : $edit($code));

        if ($model !== null) {
            (new Manifest($this->project->path))->record($model, self::NAMESPACE, $destination, 'Migration/MigrationTemplate.txt');
        }

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function import(array $declaration, array $options = []): array
    {
        $laraimport = $this->project->path.'/laraimport.json';

        file_put_contents($laraimport, (string) json_encode($declaration));

        ProjectRoot::set($this->project->path);

        try {
            [$code, $output] = Larapack::run('larapack:import', ['jsonPath' => $laraimport, '--format' => 'json', ...$options]);
        } finally {
            ProjectRoot::set(null);
            Generation::reset();
            Declaration::reset();
            MigrationTimestamp::reset();
        }

        $this->assertSame(0, $code, "larapack:import terminó con {$code}:\n{$output}");

        $report = json_decode(substr($output, (int) strpos($output, '{')), true);

        return is_array($report) && is_array($report['files'] ?? null) ? array_values($report['files']) : [];
    }

    /**
     * El segundo `import --force` no escribe ninguna migración: ni nueva ni
     * distinta.
     *
     * @param  array<string, mixed>  $declaration
     */
    private function assertImportIsIdempotent(array $declaration): void
    {
        $before = $this->snapshot();

        $this->import($declaration, ['--force' => true]);

        $this->assertSame($before, $this->snapshot(), 'El segundo import cambió las migraciones.');
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->files() as $file) {
            $snapshot[basename($file)] = (string) sha1_file($file);
        }

        return $snapshot;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function entryFor(array $entries, string $file): array
    {
        $relative = substr($file, strlen($this->project->path) + 1);

        foreach ($entries as $entry) {
            if (($entry['file'] ?? null) === $relative) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function files(string $pattern = '*.php'): array
    {
        $files = glob($this->migrations().'/'.$pattern) ?: [];

        sort($files);

        return $files;
    }

    private function fresh(): void
    {
        $this->artisan('migrate:fresh', ['--path' => $this->migrations(), '--realpath' => true])->assertSuccessful()->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function foreignKey(string $table, string $column): array
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return $key;
            }
        }

        return [];
    }

    private function migrations(): string
    {
        return $this->project->path.'/database/migrations';
    }
}
