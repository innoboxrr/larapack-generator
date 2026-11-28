<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Composer\Autoload\ClassLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Generation;
use Innoboxrr\LarapackGenerator\Support\MigrationTimestamp;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Tests\EndToEnd\Fixtures\AuditPolicy;
use Innoboxrr\LarapackGenerator\Tests\EndToEnd\Fixtures\User;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\Larapack;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * La auditoría de seguridad de 9.4.0 contra una aplicación que arranca: se
 * genera un paquete, se instala y se le ataca por HTTP con el usuario al que
 * su dueño le abrió una sola habilidad en la política.
 *
 * Nada de lo generado se retoca. La política sí se sustituye en cada prueba
 * (Gate::policy), porque escribirla es justo el trabajo del dueño: lo que se
 * prueba es que abrir una habilidad no abre nada más.
 */
final class GeneratedSecurityAuditTest extends TestCase
{
    private const NS = 'Acme\\Vault\\';

    private const LARAIMPORT = ['models' => [
        [
            'name' => 'Folder',
            'props' => [
                ['name' => 'name', 'type' => 'string', 'datatable' => true],
            ],
            'load_relations' => [
                ['type' => 'hasMany', 'related' => 'Note', 'name' => 'notes'],
            ],
        ],
        [
            'name' => 'Note',
            'metas' => true,
            'editable_metas' => ['color'],
            'protected_metas' => ['views'],
            'props' => [
                ['name' => 'title', 'type' => 'string', 'datatable' => true],
                ['name' => 'token_hash', 'type' => 'string', 'secret' => true, 'nullable' => true],
                ['name' => 'locked_code', 'type' => 'string', 'updatable' => false, 'nullable' => true],
                ['name' => 'folder_id', 'type' => 'foreignId', 'constraint' => 'folders'],
                ['name' => 'next_id', 'type' => 'foreignId', 'constraint' => 'notes', 'nullable' => true],
            ],
            // `next` se llama como una función de PHP que acepta un objeto:
            // es el nombre que convierte el truco de las claves en una fuga.
            'load_relations' => [
                ['type' => 'belongsTo', 'related' => 'Folder', 'name' => 'folder'],
                ['type' => 'belongsTo', 'related' => 'Note', 'name' => 'next'],
            ],
        ],
        [
            // El usuario que inicia sesión, declarado como en la guía.
            'name' => 'Member',
            'authenticatable' => true,
            'props' => [
                ['name' => 'name', 'type' => 'string', 'datatable' => true],
                ['name' => 'email', 'type' => 'string', 'datatable' => true],
                ['name' => 'email_verified_at', 'type' => 'timestamp', 'nullable' => true, 'fillable' => false],
                ['name' => 'password', 'type' => 'string'],
            ],
        ],
    ]];

    private static ?FakeProject $project = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$project = FakeProject::empty();
        $laraimport = self::$project->path.'/laraimport.json';

        try {
            foreach ([
                ['larapack:new', ['name' => 'acme/vault', 'directory' => self::$project->path]],
                ['larapack:import', ['jsonPath' => $laraimport]],
            ] as [$command, $arguments]) {
                if ($command === 'larapack:import') {
                    file_put_contents($laraimport, (string) json_encode(self::LARAIMPORT, JSON_PRETTY_PRINT));
                    ProjectRoot::set(self::$project->path);
                }

                [$code, $output] = Larapack::run($command, $arguments);

                if ($code !== 0) {
                    throw new RuntimeException("{$command} terminó con {$code}:\n{$output}");
                }
            }
        } finally {
            ProjectRoot::set(null);
            Generation::reset();
            Declaration::reset();
            MigrationTimestamp::reset();
        }

        $loader = array_values(ClassLoader::getRegisteredLoaders())[0];
        $composer = self::composer();

        foreach (($composer['autoload']['psr-4'] ?? []) + ($composer['autoload-dev']['psr-4'] ?? []) as $prefix => $directory) {
            $loader->addPsr4($prefix, self::$project->path.'/'.$directory);
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

        JsonResource::wrap('data');
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            ExcelServiceProvider::class,
            ...(self::composer()['extra']['laravel']['providers'] ?? []),
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        JsonResource::withoutWrapping();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app->make('migrator')->path(\Orchestra\Testbench\default_migration_path());

        $this->artisan('migrate')->assertSuccessful()->run();
    }

    // SHOW: EL CLIENTE NO ELIGE QUÉ RELACIONES SE CARGAN

    /**
     * `Rule::in` valida los valores de `load_relations`, no sus claves, y
     * `with()` toma una clave de texto por el nombre de la relación y su valor
     * por la restricción, a la que llama. Con `load_relations[folder.notes]=next`
     * el valor pasa la regla (`next` es cargable), `next()` es una función de
     * PHP que acepta el objeto, y la respuesta trae `folder.notes`: todas las
     * notas de la carpeta, también las que la política no deja ver.
     */
    public function test_show_no_carga_una_relacion_que_llega_como_clave(): void
    {
        [$mine, $secret] = $this->notes();

        $this->actingAsOwnerOf($mine);

        $response = $this->getJson($this->route('note', 'show').'?'.http_build_query([
            'note_id' => $mine->getKey(),
            'load_relations' => ['folder.notes' => 'next'],
        ]));

        $this->assertStringNotContainsString(
            'Ajena',
            (string) $response->getContent(),
            'show cargó folder.notes, que no está en $loadable_relations, y con ella la nota ajena.'
        );
        $response->assertStatus(422);
    }

    /**
     * Con un valor que no es una función, el mismo truco acababa en un 500:
     * Laravel llamaba a `folder()`.
     */
    public function test_show_responde_422_y_no_500_a_una_relacion_como_clave(): void
    {
        [$mine] = $this->notes();

        $this->actingAsOwnerOf($mine);

        foreach (['load_relations', 'load_counts'] as $key) {
            $this->getJson($this->route('note', 'show').'?'.http_build_query([
                'note_id' => $mine->getKey(),
                $key => ['folder.notes' => 'folder'],
            ]))->assertStatus(422);
        }
    }

    public function test_show_sigue_cargando_lo_declarado(): void
    {
        [$mine] = $this->notes();

        $this->actingAsOwnerOf($mine);

        $this->getJson($this->route('note', 'show').'?'.http_build_query([
            'note_id' => $mine->getKey(),
            'load_relations' => ['folder'],
        ]))->assertOk()->assertJsonPath('folder.name', 'Carpeta');
    }

    // INDEX: EL CLIENTE NO PIDE LA TABLA ENTERA

    /**
     * SearchSurge acota `paginate` a `max_per_page`, pero `paginate=0` es
     * «todo»: una petición traía la tabla entera, hidratada y serializada.
     */
    public function test_index_no_devuelve_la_tabla_entera_con_paginate_cero(): void
    {
        $folder = $this->model('Folder')::create(['name' => 'Carpeta']);

        foreach (range(1, 30) as $n) {
            $this->model('Note')::create(['title' => "Nota {$n}", 'folder_id' => $folder->getKey()]);
        }

        $this->actingWith(['index' => true]);

        config(['search-surge.pagination.max_per_page' => 20]);

        foreach ([0, '0', 'false'] as $paginate) {
            $response = $this->getJson($this->route('note', 'index').'?'.http_build_query(['paginate' => $paginate]))->assertOk();

            $rows = $response->json('data') ?? $response->json();

            $this->assertCount(20, $rows, 'index no acotó a max_per_page con paginate='.json_encode($paginate));
            $this->assertSame(30, $response->json('meta.total'), 'index dejó de paginar con paginate='.json_encode($paginate));
        }

        // Quien pide una página la sigue recibiendo, y la exportación sigue
        // recorriendo la tabla entera.
        $this->assertCount(5, $this->getJson($this->route('note', 'index').'?paginate=5')->assertOk()->json('data'));
    }

    /**
     * Lo que llega en `orderBy`, `orderMode`, `ids` o los filtros de fecha no
     * llega al SQL como identificador, ni tumba el listado.
     */
    public function test_index_no_lleva_al_sql_lo_que_llega_en_la_peticion(): void
    {
        [$mine] = $this->notes();

        $this->actingWith(['index' => true]);

        foreach ([
            ['orderBy' => 'id; DROP TABLE notes'],
            ['orderBy' => 'token_hash', 'orderMode' => 'desc, (select 1)'],
            ['orderBy' => ['id' => 'x']],
            ['ids' => [[1, 2], 'x']],
            ['id' => ['a' => 'b']],
            ['created_at' => "' OR 1=1 --", 'created_after' => ['x'], 'created_before' => '9999-99-99'],
            ['paginator' => 'cursor', 'cursor' => 'no-es-un-cursor'],
            ['page' => -5, 'paginate' => 999999999],
        ] as $query) {
            $response = $this->getJson($this->route('note', 'index').'?'.http_build_query($query));

            $this->assertContains($response->getStatusCode(), [200, 422], 'index respondió '.$response->getStatusCode().' a '.json_encode($query));
        }

        $this->assertNotNull($this->model('Note')::find($mine->getKey()));
    }

    // ESCRITURA: SÓLO LO DECLARADO

    public function test_update_no_escribe_lo_que_no_es_updatable(): void
    {
        [$mine] = $this->notes();
        $mine->forceFill(['locked_code' => 'original'])->save();
        $mine->setMeta('views', 10)->updatePayload();
        $created = $mine->created_at;

        $this->actingAsOwnerOf($mine);

        $this->putJson($this->route('note', 'update'), [
            'note_id' => $mine->getKey(),
            'title' => 'Cambiado',
            'locked_code' => 'forzado',
            'id' => 999,
            'created_at' => '2001-01-01 00:00:00',
            'deleted_at' => '2001-01-01 00:00:00',
            'payload' => ['views' => 1],
            'views' => 99999,
            'color' => 'rojo',
        ])->assertOk();

        $fresh = $this->model('Note')::findOrFail($mine->getKey());

        $this->assertSame('Cambiado', $fresh->title);
        $this->assertSame('original', $fresh->locked_code);
        $this->assertTrue($created->equalTo($fresh->created_at));
        $this->assertNull($fresh->deleted_at);
        $this->assertEquals(10, $fresh->meta('views'));
        $this->assertSame('rojo', $fresh->meta('color'));
        $this->assertEquals(['views' => 10, 'color' => 'rojo'], $fresh->payload);
    }

    public function test_una_masiva_no_toca_el_registro_ajeno(): void
    {
        [$mine, $secret] = $this->notes();

        $this->actingAsOwnerOf($mine);

        $this->putJson($this->route('note', 'bulk.update'), [
            'ids' => [$mine->getKey(), $secret->getKey()],
            'data' => ['title' => 'Tomada'],
        ])->assertForbidden();

        $this->deleteJson($this->route('note', 'bulk.delete'), [
            'ids' => [$mine->getKey(), $secret->getKey()],
        ])->assertForbidden();

        $this->assertSame('Ajena', $this->model('Note')::findOrFail($secret->getKey())->title);
        $this->assertSame('Mía', $this->model('Note')::findOrFail($mine->getKey())->title);
    }

    public function test_el_registro_ajeno_no_se_lee_ni_se_escribe_por_ningun_camino(): void
    {
        [$mine, $secret] = $this->notes();
        $id = $secret->getKey();

        $this->actingAsOwnerOf($mine);

        $this->getJson($this->route('note', 'show', ['note_id' => $id]))->assertForbidden();
        $this->getJson($this->route('note', 'show').'?'.http_build_query(['note_id' => [$id]]))->assertForbidden();
        $this->putJson($this->route('note', 'update'), ['note_id' => $id, 'title' => 'x'])->assertForbidden();
        $this->putJson($this->route('note', 'update'), ['note_id' => [$mine->getKey(), $id], 'title' => 'x'])->assertForbidden();
        $this->deleteJson($this->route('note', 'delete'), ['note_id' => $id])->assertForbidden();
        $this->postJson($this->route('note', 'restore'), ['note_id' => $id])->assertForbidden();
        $this->deleteJson($this->route('note', 'force.delete'), ['note_id' => $id])->assertForbidden();
        $this->postJson($this->route('note', 'export'))->assertForbidden();
        $this->getJson($this->route('note', 'index'))->assertForbidden();

        $this->assertSame('Ajena', $this->model('Note')::findOrFail($id)->title);
    }

    /**
     * `policies` y `policy` no piden permiso —la interfaz las llama para saber
     * qué ofrecer—, así que sólo pueden decir sí o no: ni datos del registro,
     * ni un 500 con lo que llegue.
     */
    public function test_la_api_de_politicas_solo_responde_booleanos(): void
    {
        [$mine, $secret] = $this->notes();
        $secret->forceFill(['token_hash' => 'sha256:nunca'])->save();

        $this->actingAsOwnerOf($mine);

        foreach ([
            ['policies', ['id' => $secret->getKey()]],
            ['policies', ['id' => [$secret->getKey()]]],
            ['policies', ['id' => '1 OR 1=1']],
            ['policy', ['policy' => 'view', 'id' => $secret->getKey()]],
            ['policy', ['policy' => 'view', 'id' => ['x' => 'y']]],
            ['policy', ['policy' => ['view'], 'id' => $mine->getKey()]],
            ['policy', ['policy' => 'before', 'id' => $mine->getKey()]],
            ['policy', ['policy' => '__construct']],
        ] as [$action, $query]) {
            $response = $this->getJson($this->route('note', $action).'?'.http_build_query($query));

            $this->assertContains($response->getStatusCode(), [200, 422], "{$action} respondió ".$response->getStatusCode().' a '.json_encode($query));
            $this->assertStringNotContainsString('Ajena', (string) $response->getContent());
            $this->assertStringNotContainsString('sha256', (string) $response->getContent());

            if ($response->getStatusCode() === 200) {
                foreach ($response->json() as $ability => $allowed) {
                    $this->assertFalse($allowed, "{$action} dice que el registro ajeno admite {$ability}.");
                }
            }
        }

        $this->getJson($this->route('note', 'policy', ['policy' => 'update', 'id' => $mine->getKey()]))->assertOk()->assertExactJson(['update' => true]);
    }

    /**
     * Un registro borrado sólo vuelve por `restore`, con su habilidad.
     */
    public function test_un_registro_borrado_no_se_lee_ni_se_edita(): void
    {
        [$mine] = $this->notes();
        $id = $mine->getKey();

        $this->actingWith(['view' => $id, 'update' => $id, 'delete' => $id]);

        $this->deleteJson($this->route('note', 'delete'), ['note_id' => $id])->assertOk();

        $this->getJson($this->route('note', 'show', ['note_id' => $id]))->assertNotFound();
        $this->putJson($this->route('note', 'update'), ['note_id' => $id, 'title' => 'x'])->assertNotFound();
        $this->postJson($this->route('note', 'restore'), ['note_id' => $id])->assertForbidden();
        $this->deleteJson($this->route('note', 'force.delete'), ['note_id' => $id])->assertForbidden();

        $this->assertSoftDeleted('notes', ['id' => $id, 'title' => 'Mía']);
    }

    // EVENTOS: UN SECRETO NO VIAJA EN SU CARGA

    /**
     * El evento llevaba `$request->all()`: la columna `secret` —o la
     * contraseña de un `authenticatable`— en claro, a cualquier listener. Un
     * listener en cola la deja escrita en `jobs` y en `failed_jobs`.
     */
    public function test_los_eventos_no_llevan_las_columnas_secretas(): void
    {
        $folder = $this->model('Folder')::create(['name' => 'Carpeta']);

        Sanctum::actingAs($this->user(admin: true));

        $seen = [];

        Event::listen(self::NS.'Http\\Events\\Note\\Events\\*', function (string $name, array $payload) use (&$seen): void {
            $seen[class_basename($name)][] = serialize($payload[0]->data);
        });

        $id = $this->postJson($this->route('note', 'create'), [
            'title' => 'Con secreto', 'folder_id' => $folder->getKey(), 'token_hash' => 'sha256:crear',
        ])->assertCreated()->json('id');

        $this->putJson($this->route('note', 'update'), ['note_id' => $id, 'token_hash' => 'sha256:editar'])->assertOk();

        $this->putJson($this->route('note', 'bulk.update'), ['ids' => [$id], 'data' => ['token_hash' => 'sha256:masiva']])->assertOk();

        // Se escribió: lo que no hace es viajar en el evento.
        $this->assertDatabaseHas('notes', ['id' => $id, 'token_hash' => 'sha256:masiva']);

        $this->assertSame(['CreateEvent', 'UpdateEvent'], array_keys($seen));
        $this->assertCount(2, $seen['UpdateEvent']);

        foreach ($seen as $event => $payloads) {
            foreach ($payloads as $payload) {
                $this->assertStringNotContainsString('sha256:', $payload, "{$event} lleva la columna secreta en su carga.");
            }
        }
    }

    /**
     * Lo mismo con la contraseña de quien inicia sesión y su confirmación, que
     * no es una columna pero lleva el mismo texto.
     */
    public function test_los_eventos_no_llevan_la_contrasena_y_se_guarda_cifrada(): void
    {
        Sanctum::actingAs($this->user(admin: true));

        $seen = [];

        Event::listen(self::NS.'Http\\Events\\Member\\Events\\*', function (string $name, array $payload) use (&$seen): void {
            $seen[] = serialize($payload[0]->data);
        });

        $response = $this->postJson($this->route('member', 'create'), [
            'name' => 'Eva', 'email' => 'eva@example.test', 'password' => 'en-claro-123', 'password_confirmation' => 'en-claro-123',
            'email_verified_at' => '2020-01-01 00:00:00', 'remember_token' => 'fijado',
        ])->assertCreated();

        $this->assertStringNotContainsString('en-claro-123', (string) $response->getContent());
        $response->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');

        $member = $this->model('Member')::findOrFail($response->json('id'));

        $this->assertNotSame('en-claro-123', $member->getRawOriginal('password'));
        $this->assertTrue(password_verify('en-claro-123', $member->getRawOriginal('password')));
        $this->assertNull($member->email_verified_at, 'email_verified_at se escribió desde la petición con fillable: false.');
        $this->assertNull($member->getRawOriginal('remember_token'));

        $this->assertCount(1, $seen);
        $this->assertStringNotContainsString('en-claro-123', $seen[0], 'CreateEvent lleva la contraseña en claro.');
        $this->assertStringContainsString('eva@example.test', $seen[0]);
    }

    // QUIEN ADMINISTRA

    /**
     * isAdmin() decide por el correo. Sin índice único, quien podía escribir
     * el suyo tomaba el de un administrador; con él, en una base que distingue
     * mayúsculas bastaba con cambiar una letra, porque se comparaba en
     * minúsculas.
     */
    public function test_cambiar_el_correo_no_convierte_a_nadie_en_administrador(): void
    {
        config(['auth.admins' => ['root@corp.test']]);

        $member = $this->model('Member');

        $root = $member::create(['name' => 'Root', 'email' => 'root@corp.test', 'password' => 'x']);
        $eve = $member::create(['name' => 'Eve', 'email' => 'eve@example.test', 'password' => 'x']);

        $this->assertTrue($root->isAdmin());
        $this->assertFalse($eve->isAdmin());

        // La política que escribiría el dueño: cada uno edita su perfil.
        AuditPolicy::$abilities = ['update' => $eve->getKey()];
        Gate::policy($member, AuditPolicy::class);
        Sanctum::actingAs($eve);

        foreach (['root@corp.test', 'ROOT@corp.test', 'Root@Corp.Test'] as $email) {
            $this->withoutExceptionHandling(); // la base responde con su excepción

            try {
                $this->putJson($this->route('member', 'update'), ['member_id' => $eve->getKey(), 'email' => $email]);
            } catch (QueryException) {
                // El índice único: no hay dos usuarios con el mismo correo.
            }

            $this->assertFalse($eve->fresh()->isAdmin(), "Con el correo {$email} cualquiera administra.");
        }

        $this->assertSame(1, $member::where('email', 'root@corp.test')->count());
    }

    // EXPORTACIÓN

    /**
     * El correo decía que el archivo se borraba a las 24 horas y nadie lo
     * borra: lo que caduca es el enlace firmado. En un disco que no firma, ni
     * eso, y el correo no lo promete.
     */
    public function test_el_correo_de_la_exportacion_solo_promete_lo_que_pasa(): void
    {
        Storage::fake('local'); // firma, como el `local` de una aplicación de Laravel

        $notification = self::NS.'Notifications\\Note\\ExportNotification';
        $admin = $this->user(admin: true);
        Sanctum::actingAs($admin);

        $mail = (new $notification(['managed' => true]))->toMail($admin);
        $lines = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

        $this->assertStringContainsString('expires in 24 hours', $lines);
        $this->assertStringNotContainsString('deleted', $lines);
        $this->assertStringContainsString('expiration=', $mail->actionUrl);

        // Un disco que no firma, como `public`.
        config([
            'filesystems.disks.plain' => ['driver' => 'local', 'root' => self::$project->path.'/storage-plain', 'url' => 'https://app.test/storage'],
            'acmevault.export_disk' => 'plain',
        ]);

        $mail = (new $notification(['managed' => true]))->toMail($admin);
        $lines = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

        $this->assertStringStartsWith('https://app.test/storage/exports/', $mail->actionUrl);
        $this->assertStringNotContainsString('24 hours', $lines, 'El correo promete que el enlace caduca en un disco que no firma.');
        $this->assertStringNotContainsString('deleted', $lines);
    }

    /**
     * El archivo, abierto: ni la columna secreta, ni una celda que la hoja de
     * cálculo vaya a evaluar. En un .xlsx sólo es fórmula lo que empieza por
     * `=`; `+`, `-` y `@` lo son al teclearlos o en un CSV, que no se genera.
     */
    public function test_la_exportacion_no_trae_secretos_ni_formulas(): void
    {
        Storage::fake('local');
        config(['mail.default' => 'array']);

        $folder = $this->model('Folder')::create(['name' => 'Carpeta']);

        foreach (['=1+1', '+1+1', '-1+1', '@SUM(1)', "\t=1+1", "\r=1+1", ' =1+1', '=cmd|\' /C calc\'!A0'] as $title) {
            $this->model('Note')::create(['title' => $title, 'folder_id' => $folder->getKey(), 'token_hash' => 'sha256:nunca']);
        }

        Sanctum::actingAs($this->user(admin: true));

        $this->postJson($this->route('note', 'export'))->assertOk();

        $files = Storage::disk('local')->files('exports');

        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^exports/[0-9a-f-]{36}\.xlsx$#', $files[0]);

        $sheet = IOFactory::load(Storage::disk('local')->path($files[0]))->getActiveSheet();

        $this->assertSame(9, $sheet->getHighestRow());

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType(), 'La celda '.$cell->getCoordinate().' es una fórmula: '.$cell->getValue());
                $this->assertStringNotContainsString('sha256:nunca', (string) $cell->getValue());
            }
        }

        $this->assertNotContains('token_hash', $sheet->rangeToArray('A1:Z1')[0]);
    }

    // AYUDAS

    /**
     * Dos notas en la misma carpeta: la del usuario y la que no puede ver.
     *
     * @return array{0: Model, 1: Model}
     */
    private function notes(): array
    {
        $folder = $this->model('Folder')::create(['name' => 'Carpeta']);

        $secret = $this->model('Note')::create(['title' => 'Ajena', 'folder_id' => $folder->getKey()]);
        $mine = $this->model('Note')::create(['title' => 'Mía', 'folder_id' => $folder->getKey(), 'next_id' => null]);

        return [$mine, $secret];
    }

    /**
     * La política que escribiría el dueño: quien entra ve, cambia y borra su
     * nota, y nada más.
     */
    private function actingAsOwnerOf(Model $note): void
    {
        $this->actingWith(array_fill_keys(['view', 'update', 'delete', 'restore', 'forceDelete'], $note->getKey()));
    }

    /**
     * @param  array<string, true|int|string>  $abilities  la habilidad y, si es de registro, el id sobre el que vale
     */
    private function actingWith(array $abilities): void
    {
        AuditPolicy::$abilities = $abilities;

        Gate::policy($this->model('Note'), AuditPolicy::class);

        Sanctum::actingAs($this->user());
    }

    private function user(bool $admin = false): User
    {
        static $count = 0;
        $count++;

        return User::forceCreate([
            'name' => $admin ? 'Ana' : 'Luis',
            'email' => ($admin ? 'ana' : 'luis').$count.($admin ? '@admin.test' : '@example.test'),
            'password' => 'secret',
        ]);
    }

    /**
     * @return class-string<Model>
     */
    private function model(string $name): string
    {
        return self::NS.'Models\\'.$name;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function route(string $kebab, string $action, array $query = []): string
    {
        return route('api.acme.vault.'.$kebab.'.'.$action, $query);
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(): array
    {
        return json_decode((string) file_get_contents(self::$project->path.'/composer.json'), true);
    }
}
