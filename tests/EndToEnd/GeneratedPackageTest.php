<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Composer\Autoload\ClassLoader;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\EndToEnd\Fixtures\User;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Lo que obtiene quien sigue la guía al pie de la letra desde cero: crea el
 * paquete con larapack:new, escribe un laraimport, lo valida, genera la API y
 * la interfaz, e instala el paquete en una aplicación Laravel.
 *
 * Los demás tests leen lo generado. Este lo ejecuta: migra, llama a las rutas
 * con el nombre que usa el front, autentica, autoriza, crea, lee, modifica y
 * borra. Un stub que produce PHP válido pero no arranca, o que arranca y no
 * funciona, pasa todos los demás tests y falla aquí.
 *
 * Nada de lo generado se retoca antes de probarlo: si hace falta tocar un
 * archivo para que esto pase, el defecto está en el generador. La aplicación,
 * en cambio, es la que describe la guía, ni más ni menos.
 */
final class GeneratedPackageTest extends TestCase
{
    private const NS = 'Acme\\Shop\\';

    private static ?FakeProject $project = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$project = FakeProject::empty();
        $laraimport = self::$project->path . '/laraimport.json';

        // Quien sigue la guía tiene Pint en el vendor del paquete, y el
        // generador formatea con él. Aquí el paquete no tiene vendor: se usa
        // el de LaraPack.
        putenv('LARAPACK_PINT=' . dirname(__DIR__, 2) . '/vendor/laravel/pint/builds/pint');

        try {
            foreach ([
                ['larapack:new', ['name' => 'acme/shop', 'directory' => self::$project->path]],
                ['larapack:validate', ['jsonPath' => $laraimport, '--vue' => true, '--react' => true]],
                ['larapack:import', ['jsonPath' => $laraimport, '--vue' => true, '--react' => true]],
            ] as [$command, $arguments]) {
                // El laraimport se escribe una vez creado el paquete, como lo
                // haría quien lo usa.
                if ($command === 'larapack:validate') {
                    copy(__DIR__ . '/Fixtures/laraimport.json', $laraimport);
                    ProjectRoot::set(self::$project->path);
                }

                [$code, $output] = Larapack::run($command, $arguments);

                if ($code !== 0) {
                    throw new RuntimeException("{$command} terminó con {$code}:\n{$output}");
                }
            }
        } finally {
            putenv('LARAPACK_PINT');
            ProjectRoot::set(null);
            Generation::reset();
            Declaration::reset();
            MigrationTimestamp::reset();
        }

        // Lo que haría `composer dump-autoload` en el paquete: se lee de su
        // composer.json, así que también se prueba que el generador lo declare.
        $loader = array_values(ClassLoader::getRegisteredLoaders())[0];

        foreach (self::autoload() as $prefix => $directory) {
            $loader->addPsr4($prefix, self::$project->path . '/' . $directory);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$project?->cleanup();
        self::$project = null;

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Es estático: sin devolverlo, el resto de la suite heredaría la
        // aplicación de este test.
        JsonResource::wrap('data');
    }

    /**
     * Los del anfitrión (Sanctum, Excel) y los que el paquete declara para el
     * descubrimiento de Laravel. Testbench no descubre paquetes solo.
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \Maatwebsite\Excel\ExcelServiceProvider::class,
            ...(self::composer()['extra']['laravel']['providers'] ?? []),
        ];
    }

    /**
     * La aplicación que describe la guía: su usuario, y las respuestas sin el
     * envoltorio `data` que el datatable no espera.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        JsonResource::withoutWrapping();
    }

    /**
     * La base es SQLite en memoria y nace vacía con cada test, así que basta
     * migrar: las tablas de Laravel y las que el paquete registre.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app->make('migrator')->path(\Orchestra\Testbench\default_migration_path());

        $this->artisan('migrate')->assertSuccessful()->run();
    }

    // INSTALACIÓN

    public function test_el_paquete_declara_sus_proveedores_para_el_descubrimiento(): void
    {
        $providers = self::composer()['extra']['laravel']['providers'] ?? [];

        $this->assertNotEmpty($providers, 'composer.json no declara extra.laravel.providers: una aplicación que instale el paquete no lo arranca.');

        foreach ($providers as $provider) {
            $this->assertTrue(class_exists($provider), "{$provider} está declarado y no existe.");
        }
    }

    /**
     * larapack:new sale de la línea base; generar modelos encima no puede
     * sacarlo de ella.
     */
    public function test_con_sus_modelos_generados_sigue_pasando_la_auditoria(): void
    {
        $this->assertSame([], (new \Innoboxrr\LarapackGenerator\Support\Ecosystem())->audit(self::$project->path));
    }

    public function test_las_migraciones_crean_las_tablas_declaradas(): void
    {
        foreach (['products', 'categories', 'audit_entries', 'api_keys'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "No se creó la tabla {$table}.");
        }

        $this->assertTrue(Schema::hasColumn('products', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('audit_entries', 'deleted_at'), 'Un modelo inmutable no se borra, no necesita deleted_at.');
    }

    public function test_cada_factory_crea_una_fila_que_la_base_acepta(): void
    {
        foreach (['Category', 'Product', 'AuditEntry', 'ApiKey'] as $name) {
            $model = $this->model($name)::factory()->create();

            $this->assertTrue($model->exists, "La factory de {$name} no creó la fila.");
        }
    }

    public function test_cada_ruta_que_llama_el_front_existe(): void
    {
        foreach (['vue', 'react'] as $ui) {
            foreach (glob(self::$project->path . "/resources/{$ui}/src/models/*/index.js") as $contract) {
                $source = (string) file_get_contents($contract);

                $this->assertMatchesRegularExpression("/API_ROUTE_PREFIX = '([^']+)'/", $source);
                preg_match("/API_ROUTE_PREFIX = '([^']+)'/", $source, $prefix);
                preg_match_all("/API_ROUTE_PREFIX \\+ '([^']+)'/", $source, $actions);

                $this->assertNotEmpty($actions[1], "{$contract} no llama a ninguna ruta.");

                foreach ($actions[1] as $action) {
                    $this->assertTrue(
                        Route::has($prefix[1] . $action),
                        "El contrato {$ui} llama a {$prefix[1]}{$action}, que no existe."
                    );
                }
            }
        }
    }

    // AUTORIZACIÓN

    public function test_un_invitado_no_entra(): void
    {
        $this->getJson($this->route('product', 'index'))->assertUnauthorized();
    }

    /**
     * Las políticas nacen cerradas: hasta que alguien escribe quién puede
     * qué, sólo el administrador del anfitrión pasa.
     */
    public function test_sin_politica_escrita_un_usuario_normal_no_puede_nada(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson($this->route('product', 'index'))->assertForbidden();
        $this->postJson($this->route('product', 'create'), [])->assertForbidden();
    }

    public function test_la_api_de_politicas_responde_lo_que_decide_la_politica(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson($this->route('product', 'policies'))
            ->assertOk()
            ->assertJson(['index' => false, 'create' => false]);

        Sanctum::actingAs($this->user(admin: true));

        $this->getJson($this->route('product', 'policies'))
            ->assertOk()
            ->assertJson(['index' => true, 'create' => true]);
    }

    // COMPORTAMIENTO

    public function test_un_administrador_recorre_el_ciclo_completo_de_un_modelo(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        $payload = $this->model('Product')::factory()->make()->getAttributes();

        $id = $this->postJson($this->route('product', 'create'), $payload)
            ->assertCreated()
            ->json('id');

        $this->assertNotNull($id, 'create no devolvió el id de lo creado.');

        // La forma que espera el datatable: filas, paginación y enlaces.
        $this->getJson($this->route('product', 'index'))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links'])
            ->assertJsonFragment(['id' => $id]);

        $this->getJson($this->route('product', 'show', ['product_id' => $id, 'load_relations' => ['category']]))
            ->assertOk()
            ->assertJsonPath('category.id', $payload['category_id']);

        $this->putJson($this->route('product', 'update'), ['product_id' => $id, 'title' => 'Otro título'])
            ->assertOk()
            ->assertJsonPath('title', 'Otro título');

        $this->deleteJson($this->route('product', 'delete'), ['product_id' => $id])->assertOk();
        $this->assertSoftDeleted('products', ['id' => $id]);

        $this->postJson($this->route('product', 'restore'), ['product_id' => $id])->assertOk();
        $this->assertNotSoftDeleted('products', ['id' => $id]);
    }

    /**
     * El formulario manda grupos anidados; se guardan como metas planas, lo
     * protegido no se toca desde la petición, un valor vacío borra, y payload
     * queda con la copia. Nada de esto funcionaba: el modelo no tenía la
     * relación metas() y el guardado estaba comentado.
     */
    public function test_las_metas_se_guardan_desde_el_formulario_y_payload_las_refleja(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        $id = $this->postJson($this->route('category', 'create'), [
            'name' => 'Libros',
            'seo' => ['title' => 'Libros baratos', 'og' => ['image' => 'libros.png']],
            'views' => 999,
            'payload' => ['inventado' => true],
        ])->assertCreated()->json('id');

        $category = $this->model('Category')::findOrFail($id);

        $this->assertSame('Libros baratos', $category->meta('seo_title'));
        $this->assertSame('libros.png', $category->meta('seo_og_image'));
        $this->assertNull($category->meta('views'), 'Una meta protegida se escribió desde la petición.');
        $this->assertSame(['seo_title' => 'Libros baratos', 'seo_og_image' => 'libros.png'], $category->payload);

        // La protegida la escribe el sistema.
        $category->setMeta('views', 10)->updatePayload();

        $this->putJson($this->route('category', 'update'), [
            'category_id' => $id,
            'seo' => ['title' => '', 'og' => ['image' => 'otra.png']],
            'views' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('payload.seo_og_image', 'otra.png');

        $category->refresh();

        $this->assertNull($category->meta('seo_title'), 'Vaciar una meta en el formulario no la borró.');
        $this->assertEquals(10, $category->meta('views'), 'El formulario tocó una meta protegida.');
        $this->assertSame('otra.png', $category->getPayload('seo_og_image'));
        $this->assertArrayNotHasKey('seo_title', $category->payload);
    }

    /**
     * El borrado permanente nace apagado incluso para el administrador, y lo
     * dice la política: el front no ofrece un botón que luego falla.
     * Encenderlo es cosa de la política, no de retocar el modelo.
     */
    public function test_el_borrado_permanente_nace_apagado_y_se_enciende_en_la_politica(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        $product = $this->model('Product')::factory()->create();

        $this->getJson($this->route('product', 'policies', ['id' => $product->id]))
            ->assertOk()
            ->assertJson(['forceDelete' => false, 'delete' => true]);

        $this->deleteJson($this->route('product', 'force.delete'), ['product_id' => $product->id])->assertForbidden();

        Gate::before(fn ($user, string $ability) => $ability === 'forceDelete' ? true : null);

        $this->deleteJson($this->route('product', 'force.delete'), ['product_id' => $product->id])->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    /**
     * Lo que se hace desde la selección de la tabla. Sólo viajan los campos que
     * cambian —editar una celda manda la suya—, un id que no existe no deja
     * nada a medias y cada registro pasa por la política.
     */
    public function test_un_administrador_cambia_y_borra_varios_registros_de_una_vez(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        $ids = $this->model('Product')::factory()->count(3)->create()->modelKeys();

        $this->putJson($this->route('product', 'bulk.update'), ['ids' => $ids, 'data' => ['title' => 'En lote']])
            ->assertOk();

        $this->assertSame(3, $this->model('Product')::whereKey($ids)->where('title', 'En lote')->count());

        $this->deleteJson($this->route('product', 'bulk.delete'), ['ids' => [...$ids, 999999]])->assertNotFound();
        $this->assertSame(3, $this->model('Product')::whereKey($ids)->count(), 'Un id inexistente borró los demás.');

        $this->deleteJson($this->route('product', 'bulk.delete'), ['ids' => []])->assertUnprocessable();
        $this->putJson($this->route('product', 'bulk.update'), ['ids' => $ids, 'data' => []])->assertUnprocessable();

        $this->deleteJson($this->route('product', 'bulk.delete'), ['ids' => $ids])->assertOk();

        foreach ($ids as $id) {
            $this->assertSoftDeleted('products', ['id' => $id]);
        }
    }

    public function test_una_accion_masiva_pasa_por_la_politica_de_cada_registro(): void
    {
        $ids = $this->model('Product')::factory()->count(2)->create()->modelKeys();

        Sanctum::actingAs($this->user());

        $this->putJson($this->route('product', 'bulk.update'), ['ids' => $ids, 'data' => ['title' => 'No']])->assertForbidden();
        $this->deleteJson($this->route('product', 'bulk.delete'), ['ids' => $ids])->assertForbidden();

        $this->getJson($this->route('product', 'policies'))
            ->assertOk()
            ->assertJson(['bulkUpdate' => false, 'bulkDelete' => false]);

        Sanctum::actingAs($this->user(admin: true));

        $this->getJson($this->route('product', 'policies'))
            ->assertOk()
            ->assertJson(['bulkUpdate' => true, 'bulkDelete' => true]);
    }

    public function test_un_modelo_inmutable_se_crea_pero_no_se_modifica(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        foreach (['update', 'delete', 'restore', 'force.delete', 'bulk.update', 'bulk.delete'] as $action) {
            $this->assertFalse(Route::has($this->prefix('audit-entry') . $action), "AuditEntry es inmutable y expone {$action}.");
        }

        $id = $this->postJson($this->route('audit-entry', 'create'), ['message' => 'alta'])
            ->assertCreated()
            ->json('id');

        $entry = $this->model('AuditEntry')::findOrFail($id);

        $this->expectException(LogicException::class);

        $entry->update(['message' => 'cambio']);
    }

    public function test_un_secreto_se_escribe_pero_nunca_sale_por_la_api(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        $created = $this->postJson($this->route('api-key', 'create'), ['label' => 'ci', 'token_hash' => 'sha256:abc'])
            ->assertCreated()
            ->assertJsonMissingPath('token_hash');

        $id = $created->json('id');

        $this->assertDatabaseHas('api_keys', ['id' => $id, 'token_hash' => 'sha256:abc']);

        $this->getJson($this->route('api-key', 'show', ['api_key_id' => $id]))
            ->assertOk()
            ->assertJsonMissingPath('token_hash');

        $this->getJson($this->route('api-key', 'index'))
            ->assertOk()
            ->assertDontSee('sha256:abc');
    }

    /**
     * Sin simular la notificación: el archivo se genera de verdad en el disco
     * por defecto. En una aplicación nueva exportar fallaba, porque Excel no
     * era dependencia del paquete y el disco era S3.
     */
    public function test_la_exportacion_genera_el_archivo_en_el_disco_por_defecto(): void
    {
        Storage::fake('local');
        config(['mail.default' => 'array']);

        Sanctum::actingAs($this->user(admin: true));

        $this->model('Category')::factory()->count(2)->create();

        $this->postJson($this->route('category', 'export'))->assertOk();

        $this->assertCount(1, Storage::disk('local')->files('exports'), 'La exportación no dejó el archivo en el disco local.');
    }

    public function test_la_exportacion_avisa_al_usuario_que_la_pidio(): void
    {
        Notification::fake();

        $admin = $this->user(admin: true);
        Sanctum::actingAs($admin);

        $this->model('Category')::factory()->count(2)->create();

        $this->postJson($this->route('category', 'export'))->assertOk();

        Notification::assertSentTo($admin, self::NS . 'Notifications\\Category\\ExportNotification');
    }

    // LA GUÍA

    public function test_verify_no_encuentra_desviaciones_en_lo_recien_generado(): void
    {
        [$code, $output] = Larapack::run('larapack:verify', ['--root' => self::$project->path]);

        ProjectRoot::set(null);

        $this->assertSame(0, $code, "larapack:verify encontró desviaciones en código recién generado:\n{$output}");
    }

    /**
     * La guía le dice al agente que los tests generados describen el
     * comportamiento. Si no pasan recién generados, el agente no puede
     * distinguir lo que rompió él de lo que ya venía roto.
     */
    public function test_los_tests_que_genera_el_paquete_pasan_sin_tocarlos(): void
    {
        $bootstrap = $this->autoloadBootstrap();

        $process = new Process([
            PHP_BINARY,
            ...$this->inheritedExtensions(),
            dirname(__DIR__, 2) . '/vendor/phpunit/phpunit/phpunit',
            '--bootstrap', $bootstrap,
            '--configuration', self::$project->path . (is_file(self::$project->path . '/phpunit.xml.dist') ? '/phpunit.xml.dist' : '/phpunit.xml'),
            '--do-not-cache-result',
            '--colors=never',
        ], self::$project->path, null, null, 300);

        $process->run();

        $this->assertTrue($process->isSuccessful(), "Los tests generados fallan:\n" . $process->getOutput() . $process->getErrorOutput());
    }

    // CALIDAD

    /**
     * La CI del paquete corre `pint --test`. Si lo recién generado no pasara,
     * el primer push de quien lo usa saldría en rojo por algo que no escribió.
     */
    public function test_lo_generado_tiene_el_formato_que_exige_su_ci(): void
    {
        $paths = array_values(array_filter(
            ['src', 'database', 'routes', 'config', 'tests'],
            fn (string $directory): bool => is_dir(self::$project->path . '/' . $directory)
        ));

        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/vendor/laravel/pint/builds/pint',
            '--test',
            '--config', self::$project->path . '/pint.json',
            ...$paths,
        ], self::$project->path, null, null, 300);

        $process->run();

        $this->assertTrue($process->isSuccessful(), "Pint encuentra código sin formato en lo generado:\n" . $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Y `phpstan analyse` con el nivel y las rutas del phpstan.neon.dist que
     * deja larapack:new.
     */
    public function test_lo_generado_pasa_el_analisis_de_su_ci(): void
    {
        $dist = (string) file_get_contents(self::$project->path . '/phpstan.neon.dist');

        $this->assertMatchesRegularExpression('/level:\s*(\d+)/', $dist);
        preg_match('/level:\s*(\d+)/', $dist, $level);
        preg_match_all('/^\s*-\s*(src|database|routes|config|tests)\s*$/m', $dist, $paths);

        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $project = str_replace('\\', '/', self::$project->path);

        $config = self::$project->path . '/phpstan.test.neon';

        file_put_contents($config, implode("\n", [
            'includes:',
            "    - {$root}/vendor/larastan/larastan/extension.neon",
            'parameters:',
            "    level: {$level[1]}",
            '    tmpDir: ' . $project . '/.phpstan',
            '    paths:',
            ...array_map(fn (string $path): string => "        - {$project}/{$path}", $paths[1]),
            '    databaseMigrationsPath:',
            "        - {$project}/database/migrations",
            ...(str_contains($dist, 'parseModelCastsMethod: true') ? ['    parseModelCastsMethod: true'] : []),
            '    bootstrapFiles:',
            '        - ' . str_replace('\\', '/', $this->autoloadBootstrap()),
            '',
        ]));

        // Desde la raíz de LaraPack: su vendor es el que tiene Larastan y
        // Testbench, con los que Larastan arranca una aplicación.
        $process = new Process([
            PHP_BINARY,
            ...$this->inheritedExtensions(),
            dirname(__DIR__, 2) . '/vendor/phpstan/phpstan/phpstan',
            'analyse',
            '-c', $config,
            '--no-progress',
            '--error-format=raw',
            '--memory-limit=1G',
        ], dirname(__DIR__, 2), null, null, 600);

        $process->run();

        $this->assertTrue($process->isSuccessful(), "Larastan encuentra errores en lo generado:\n" . $process->getOutput() . $process->getErrorOutput());
    }

    // AYUDAS

    /**
     * Lo que haría `composer dump-autoload` en el paquete, para un proceso
     * aparte: el autoloader de LaraPack más los namespaces del paquete.
     */
    private function autoloadBootstrap(): string
    {
        $bootstrap = self::$project->path . '/vendor-autoload.php';
        $lines = ['<?php', '$loader = require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'];

        foreach (self::autoload() as $prefix => $directory) {
            $lines[] = '$loader->addPsr4(' . var_export($prefix, true) . ', ' . var_export(self::$project->path . '/' . $directory, true) . ');';
        }

        file_put_contents($bootstrap, implode(PHP_EOL, $lines) . PHP_EOL);

        return $bootstrap;
    }

    private function user(bool $admin = false): User
    {
        static $count = 0;
        $count++;

        return User::forceCreate([
            'name' => $admin ? 'Ana' : 'Luis',
            'email' => ($admin ? 'ana' : 'luis') . $count . ($admin ? '@admin.test' : '@example.test'),
            'password' => 'secret',
        ]);
    }

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    private function model(string $name): string
    {
        return self::NS . 'Models\\' . $name;
    }

    /**
     * El prefijo que usa el front, leído del contrato generado.
     */
    private function prefix(string $kebab): string
    {
        $contract = (string) file_get_contents(self::$project->path . "/resources/vue/src/models/{$kebab}/index.js");

        if (! preg_match("/API_ROUTE_PREFIX = '([^']+)'/", $contract, $match)) {
            $this->fail("El contrato de {$kebab} no declara API_ROUTE_PREFIX.");
        }

        return $match[1];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function route(string $kebab, string $action, array $query = []): string
    {
        return route($this->prefix($kebab) . $action, $query);
    }

    /**
     * @return array<string, string>
     */
    private static function autoload(): array
    {
        $composer = self::composer();

        return ($composer['autoload']['psr-4'] ?? []) + ($composer['autoload-dev']['psr-4'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(): array
    {
        return json_decode((string) file_get_contents(self::$project->path . '/composer.json'), true);
    }

    /**
     * El PHP de este proceso puede tener extensiones cargadas por -d que el
     * ini no trae (pdo_sqlite en Windows); el proceso hijo las necesita igual.
     *
     * @return array<int, string>
     */
    private function inheritedExtensions(): array
    {
        $ini = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -m');
        $arguments = [];

        foreach (['pdo_sqlite', 'sqlite3'] as $extension) {
            if (extension_loaded($extension) && ! preg_match('/^' . $extension . '$/mi', $ini)) {
                $arguments[] = '-d';
                $arguments[] = "extension={$extension}";
            }
        }

        return $arguments;
    }
}
