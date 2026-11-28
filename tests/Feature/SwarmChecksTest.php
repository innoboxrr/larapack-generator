<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\ContractFingerprint;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\MergeDrift;
use Innoboxrr\LarapackGenerator\Support\MergeMarkers;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que `verify` comprueba desde 9.4.0 para que varias ramas a la vez no
 * dejen deriva: que lo generado corresponde al contrato que hay
 * (`contract-outdated`), que el manifiesto se lee (`manifest-unreadable`) y
 * lo que deja un merge mal resuelto (`merge-conflict`, `migration-duplicate`,
 * `route-duplicate`). La prueba con ramas y fusiones de verdad es
 * EndToEnd\SwarmMergeTest; aquí, cada comprobación por separado.
 */
final class SwarmChecksTest extends TestCase
{
    private const CONTRACT = ['models' => [
        ['name' => 'Customer', 'props' => [
            ['name' => 'name', 'type' => 'string', 'datatable' => true],
            ['name' => 'email', 'type' => 'string'],
        ]],
        ['name' => 'Ticket', 'props' => [
            ['name' => 'subject', 'type' => 'string', 'datatable' => true],
            ['name' => 'customer_id', 'type' => 'foreignId', 'constraint' => 'customers'],
        ]],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());
        $this->contract(self::CONTRACT);
        $this->import();
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function contract(array $contract): void
    {
        file_put_contents($this->project->path.'/laraimport.json', json_encode($contract, JSON_PRETTY_PRINT));
    }

    private function import(bool $force = false): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--format' => 'json'] + ($force ? ['--force' => true] : [])), $this->lastOutput);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findings(string ...$checks): array
    {
        $this->runCommand('larapack:verify', ['--format' => 'json', '--strict' => true] + ($checks === [] ? [] : ['--check' => $checks]));

        return array_values(array_filter(
            json_decode($this->lastOutput, true)['findings'],
            fn (array $finding): bool => $finding['level'] !== 'info'
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode($this->project->read('.larapack/manifest.json'), true);
    }

    // ─── La huella del contrato ──────────────────────────────────────────────

    public function test_recien_importado_cada_modelo_dice_con_que_contrato_se_genero(): void
    {
        $manifest = $this->manifest();

        foreach (['Customer', 'Ticket'] as $model) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.[0-9a-f]{64}$/', $manifest['models'][$model]['contract']);
        }

        // Siempre en el mismo orden: dos ramas que llegan a lo mismo escriben
        // el mismo archivo.
        $this->assertSame(['namespace', 'contract', 'files'], array_keys($manifest['models']['Customer']));
        $this->assertSame([], $this->findings());
    }

    public function test_cambiar_el_laraimport_sin_importar_es_un_error(): void
    {
        $contract = self::CONTRACT;
        $contract['models'][0]['props'][1]['type'] = 'text';
        $this->contract($contract);

        $findings = $this->findings();

        $this->assertSame(['contract-outdated'], array_column($findings, 'check'));
        $this->assertSame('error', $findings[0]['level']);
        $this->assertSame('Customer', $findings[0]['model']);
        $this->assertSame('laraimport.json', $findings[0]['file']);
        $this->assertStringContainsString('larapack:import --force', $findings[0]['message']);

        // Sin --force lo que ya existía no se regenera: la huella no se mueve
        // y sigue en rojo. Con --force, sí.
        $this->import();
        $this->assertSame(['contract-outdated'], array_column($this->findings(), 'check'));

        $this->import(force: true);
        $this->assertSame([], $this->findings());
    }

    public function test_escribir_distinto_lo_mismo_no_pide_volver_a_importar(): void
    {
        $contract = self::CONTRACT;
        // El valor por omisión, dicho a mano, y las claves en otro orden.
        $contract['models'][0]['props'][1] = ['nullable' => false, 'type' => 'string', 'name' => 'email'];
        $this->contract($contract);

        $this->assertSame([], $this->findings());
    }

    public function test_un_modelo_declarado_y_sin_generar_es_un_error(): void
    {
        $contract = self::CONTRACT;
        $contract['models'][] = ['name' => 'Order', 'props' => [['name' => 'reference', 'type' => 'string']]];
        $this->contract($contract);

        $findings = $this->findings('contract-outdated');

        $this->assertCount(1, $findings);
        $this->assertSame('Order', $findings[0]['model']);
        $this->assertStringContainsString('no hay nada generado', $findings[0]['message']);

        // Un modelo nuevo se genera y se anota también sin --force.
        $this->import();
        $this->assertSame([], $this->findings());
    }

    public function test_un_modelo_generado_que_el_laraimport_ya_no_declara_es_un_error(): void
    {
        $contract = self::CONTRACT;
        unset($contract['models'][1]);
        $contract['models'] = array_values($contract['models']);
        $this->contract($contract);

        $findings = $this->findings('contract-outdated');

        $this->assertSame(['Ticket'], array_column($findings, 'model'));
        $this->assertStringContainsString('larapack:remove-full-model Ticket', $findings[0]['message']);
    }

    public function test_cambiar_el_modelo_al_que_apunta_una_clave_ajena_desfasa_al_que_la_lleva(): void
    {
        $contract = self::CONTRACT;
        // `customers` deja de ser de un modelo de este laraimport: la factory
        // y la migración de Ticket ya no pueden contar con él.
        $contract['models'][0]['table'] = 'clients';
        $this->contract($contract);

        $this->assertEqualsCanonicalizing(['Customer', 'Ticket'], array_column($this->findings('contract-outdated'), 'model'));
    }

    public function test_lo_que_cambia_para_todos_desfasa_a_todos(): void
    {
        $this->contract(self::CONTRACT + ['table_prefix' => 'crm']);

        $this->assertEqualsCanonicalizing(['Customer', 'Ticket'], array_column($this->findings('contract-outdated'), 'model'));
    }

    public function test_un_manifiesto_sin_huellas_no_se_compara_hasta_que_se_importa(): void
    {
        $manifest = $this->manifest();

        foreach (array_keys($manifest['models']) as $model) {
            unset($manifest['models'][$model]['contract']);
        }

        file_put_contents($this->project->path.'/.larapack/manifest.json', json_encode($manifest));

        $contract = self::CONTRACT;
        $contract['models'][0]['props'][1]['type'] = 'text';
        $this->contract($contract);

        $this->assertSame([], $this->findings('contract-outdated'));

        $this->import(force: true);

        $this->assertNotNull((new Manifest($this->project->path))->contractOf('Customer'));
        $this->assertSame([], $this->findings('contract-outdated'));
    }

    public function test_la_huella_no_depende_del_orden_de_las_claves_y_si_del_de_las_columnas(): void
    {
        $a = ContractFingerprint::of(self::CONTRACT)['models'];

        $reordered = self::CONTRACT;
        $reordered['models'][0] = ['props' => self::CONTRACT['models'][0]['props'], 'name' => 'Customer'];
        $this->assertSame($a, ContractFingerprint::of($reordered)['models']);

        $swapped = self::CONTRACT;
        $swapped['models'][0]['props'] = array_reverse($swapped['models'][0]['props']);
        $this->assertNotSame($a['Customer'], ContractFingerprint::of($swapped)['models']['Customer']);
        // Y sólo cambia la del modelo que cambió: es lo que deja que dos ramas
        // que tocan modelos distintos no choquen en el manifiesto.
        $this->assertSame($a['Ticket'], ContractFingerprint::of($swapped)['models']['Ticket']);
    }

    /**
     * La huella de un modelo recoge lo único que una herramienta lee de OTRO
     * modelo al generar: de quién es la tabla a la que apunta una clave
     * ajena. Si otra herramienta empieza a leer de otros modelos, la huella
     * por modelo deja de ser exacta y hay que ampliarla (ContractFingerprint).
     */
    public function test_solo_la_migracion_y_la_factory_leen_de_otros_modelos(): void
    {
        $readers = [];
        $source = dirname(__DIR__, 2).'/src/Tools';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && $file->getFilename() !== 'Tool.php' && preg_match('/jsonEntries\(|getJsonContent\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $readers[] = $file->getFilename();
            }
        }

        sort($readers);

        $this->assertSame(['FactoryTool.php', 'MigrationTool.php'], $readers);
    }

    // ─── El manifiesto ilegible ──────────────────────────────────────────────

    public function test_un_manifiesto_a_medio_fusionar_es_un_error_y_no_un_manifiesto_vacio(): void
    {
        $path = $this->project->path.'/.larapack/manifest.json';
        $original = (string) file_get_contents($path);
        $conflicted = "<<<<<<< HEAD\n{$original}=======\n{$original}>>>>>>> otra\n";
        file_put_contents($path, $conflicted);

        $findings = $this->findings();

        $this->assertSame(['manifest-unreadable'], array_column($findings, 'check'));
        $this->assertSame('error', $findings[0]['level']);
        $this->assertStringContainsString('marcadores de conflicto', $findings[0]['message']);
        $this->assertStringContainsString('larapack:reconcile', $findings[0]['message']);

        // El generador no lo reescribe.
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:import', ['--force' => true, '--format' => 'json']));
        $this->assertSame($conflicted, file_get_contents($path));

        $this->expectException(MakerException::class);
        (new Manifest($this->project->path))->read();
    }

    public function test_un_manifiesto_vacio_o_ausente_se_sigue_leyendo(): void
    {
        $path = $this->project->path.'/.larapack/manifest.json';

        file_put_contents($path, '');
        $this->assertSame([], (new Manifest($this->project->path))->models());
        $this->assertNull((new Manifest($this->project->path))->unreadable());

        unlink($path);
        $this->assertSame([], (new Manifest($this->project->path))->models());
    }

    // ─── Lo que deja un merge ────────────────────────────────────────────────

    public function test_un_conflicto_sin_resolver_en_un_archivo_generado_es_un_error(): void
    {
        $file = 'app/Http/Controllers/CustomerController.php';
        $content = $this->project->read($file);
        file_put_contents($this->project->path.'/'.$file, "<<<<<<< HEAD\n{$content}=======\n{$content}>>>>>>> otra\n");

        $findings = $this->findings('merge-conflict');

        $this->assertSame([$file], array_column($findings, 'file'));
        $this->assertStringContainsString('línea 1', $findings[0]['message']);
    }

    public function test_un_conflicto_en_codigo_escrito_a_mano_o_en_el_laraimport_tambien(): void
    {
        $markers = "<<<<<<< HEAD\na\n=======\nb\n>>>>>>> otra\n";

        @mkdir($this->project->path.'/app/Services', 0777, true);
        file_put_contents($this->project->path.'/app/Services/Billing.php', "<?php\n\n".$markers);
        file_put_contents($this->project->path.'/laraimport.json', $markers);

        $this->assertEqualsCanonicalizing(['app/Services/Billing.php', 'laraimport.json'], array_column($this->findings('merge-conflict'), 'file'));
    }

    public function test_un_subrayado_o_un_heredoc_no_son_un_conflicto(): void
    {
        $this->assertFalse(MergeMarkers::in("Título\n=======\n\ntexto\n"));
        $this->assertFalse(MergeMarkers::in("\$a = <<<EOT\nx\nEOT;\n"));
        $this->assertFalse(MergeMarkers::in("<<<<<<< sólo el principio\n"));
        $this->assertTrue(MergeMarkers::in("antes\n<<<<<<< HEAD\na\n=======\nb\n>>>>>>> rama\ndespués\n"));
        $this->assertTrue(MergeMarkers::in("<<<<<<< HEAD\r\na\r\n=======\r\nb\r\n>>>>>>> rama\r\n"));
        $this->assertSame(2, MergeMarkers::firstLine("antes\n<<<<<<< HEAD\na\n=======\nb\n>>>>>>> rama\n"));
        $this->assertNull(MergeMarkers::firstLine('nada'));
    }

    public function test_dos_migraciones_que_anaden_la_misma_columna_son_un_error(): void
    {
        $contract = self::CONTRACT;
        $contract['models'][0]['props'][] = ['name' => 'phone', 'type' => 'string', 'nullable' => true];
        $this->contract($contract);
        $this->import(force: true);

        $generated = $this->project->glob('database/migrations/*_alter_customers_table.php');
        $this->assertNotNull($generated);
        $this->assertSame([], $this->findings('migration-duplicate'));

        // La de otra rama, con otra fecha: lo mismo otra vez.
        $copy = 'database/migrations/2031_01_01_000000_alter_customers_table.php';
        copy($this->project->path.'/'.$generated, $this->project->path.'/'.$copy);

        $findings = $this->findings('migration-duplicate');

        $this->assertSame([$copy], array_column($findings, 'file'));
        $this->assertStringContainsString('la columna `phone` a `customers`', $findings[0]['message']);
        $this->assertStringContainsString(basename($generated), $findings[0]['message']);
    }

    public function test_quitar_la_columna_entre_medias_o_anadirla_dentro_de_un_if_no_es_repetirla(): void
    {
        $migration = fn (string $body): string => "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n{$body}\n    }\n};\n";
        $table = fn (string $statement): string => "        Schema::table('customers', function (Blueprint \$table) {\n            {$statement}\n        });";
        $directory = $this->project->path.'/database/migrations/';

        // name la crea la migración generada; se quita y se vuelve a poner.
        file_put_contents($directory.'2031_01_01_000000_drop_name.php', $migration($table("\$table->dropColumn('name');")));
        file_put_contents($directory.'2031_01_02_000000_add_name.php', $migration($table("\$table->string('name');")));
        // Y una que sólo la añade si falta.
        file_put_contents($directory.'2031_01_03_000000_maybe_email.php', $migration("        if (! Schema::hasColumn('customers', 'email')) {\n".$table("\$table->string('email');")."\n        }"));

        $this->assertSame([], $this->findings('migration-duplicate'));
    }

    public function test_dos_migraciones_escritas_a_mano_no_se_juzgan(): void
    {
        $migration = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::table('legacy', function (Blueprint \$table) {\n            \$table->string('code');\n        });\n    }\n};\n";

        file_put_contents($this->project->path.'/database/migrations/2031_01_01_000000_a.php', $migration);
        file_put_contents($this->project->path.'/database/migrations/2031_01_02_000000_b.php', $migration);

        $this->assertSame([], $this->findings('migration-duplicate'));
    }

    public function test_dos_migraciones_que_crean_la_misma_tabla_son_un_error(): void
    {
        $generated = (string) $this->project->glob('database/migrations/*_create_tickets_table.php');
        $copy = 'database/migrations/2031_01_01_000000_create_tickets_table.php';
        copy($this->project->path.'/'.$generated, $this->project->path.'/'.$copy);

        $findings = $this->findings('migration-duplicate');

        $this->assertSame([$copy], array_column($findings, 'file'));
        $this->assertStringContainsString('la tabla `tickets`', $findings[0]['message']);
    }

    public function test_un_archivo_de_rutas_generado_con_la_misma_ruta_dos_veces_es_un_error(): void
    {
        $file = 'routes/api/models/ticket.php';
        $routes = $this->project->read($file);
        file_put_contents($this->project->path.'/'.$file, $routes."\nRoute::get('index', [TicketController::class, 'index'])\n    ->name('index.again');\n");

        $findings = $this->findings('route-duplicate');

        $this->assertSame([$file], array_column($findings, 'file'));
        $this->assertStringContainsString('GET index', $findings[0]['message']);

        // El mismo camino con otro verbo no es la misma ruta.
        $this->assertSame([], MergeDrift::repeatedRoutes("Route::get('close', X);\nRoute::post('close', X);\n"));
        $this->assertSame(['POST close'], MergeDrift::repeatedRoutes("Route::post('close', X);\nRoute::post(\"close\", Y);\n"));
    }
}
