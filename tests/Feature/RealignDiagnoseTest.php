<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign diagnose`: regenera el contrato aparte y clasifica cada
 * archivo como lo encontró el análisis de school-management, expediente y
 * brandai. Cada clase de desviación con un proyecto generado de verdad y
 * desviado como se desvían ellos: el formato de Pint encima de un hash
 * viejo, una migración renombrada a mano, una request con authorize()
 * reescrito, una plantilla de una versión anterior, controladores a mano.
 */
final class RealignDiagnoseTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    private const POLICY = 'src/Policies/PostPolicy.php';

    private const SHOW_REQUEST = 'src/Http/Requests/Post/ShowRequest.php';

    private const CREATE_REQUEST = 'src/Http/Requests/Post/CreateRequest.php';

    private const ID_FILTER = 'src/Models/Filters/Post/IdFilter.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
    }

    public function test_un_proyecto_recien_generado_cumple_entero(): void
    {
        $diagnosis = $this->realign('diagnose');

        $this->assertSame(RealignState::FORMAT_VERSION, $diagnosis['format_version']);
        $this->assertTrue($diagnosis['ok']);
        $this->assertGreaterThan(50, $diagnosis['totals']['files']);
        $this->assertSame($diagnosis['totals']['files'], $diagnosis['totals']['classes'][FileClass::IDENTICAL]);
        $this->assertSame($diagnosis['totals']['files'], $diagnosis['totals']['conforming']);
        $this->assertSame(array_fill_keys(Category::ALL, 0), $diagnosis['totals']['categories']);
        $this->assertSame([], $this->report()['files']);
    }

    public function test_diagnosticar_no_escribe_codigo(): void
    {
        $before = $this->snapshot();

        $this->realign('diagnose');

        $this->assertSame($before, $this->snapshot(), 'diagnose cambió algo fuera de .larapack/realign/.');
        $this->assertFileExists($this->project->path.'/.larapack/realign/report.json');
        $this->assertStringContainsString('cache/', $this->project->read('.larapack/realign/.gitignore'));
    }

    public function test_un_archivo_igual_salvo_formato_con_el_hash_viejo_se_adopta(): void
    {
        // Lo que pasó en 3 306 archivos de los paquetes de school-management:
        // Pint después de generar, y el manifiesto con el hash de antes.
        file_put_contents($this->project->path.'/'.self::CONTROLLER, str_replace('    ', "\t", $this->project->read(self::CONTROLLER)));

        $this->realign('diagnose');
        $entry = $this->entry(self::CONTROLLER);

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::FORMAT_ONLY, $entry['class']);
        $this->assertSame(Category::DETERMINISTIC, $entry['category']);
        $this->assertSame('adopt', $entry['type']);
        $this->assertFalse($entry['hash_current']);
    }

    public function test_lo_editado_en_los_huecos_cumple(): void
    {
        file_put_contents($this->project->path.'/'.self::POLICY, $this->project->read(self::POLICY)."\n// abierto a mano\n");
        $this->edit(self::CREATE_REQUEST, "public function rules(): array\n    {\n        return [", "public function rules(): array\n    {\n        return [\n            'extra' => 'nullable',");

        $diagnosis = $this->realign('diagnose');

        $this->assertNull($this->entry(self::POLICY));
        $this->assertNull($this->entry(self::CREATE_REQUEST));
        $this->assertSame(2, $diagnosis['totals']['classes'][FileClass::HOLE_EDITED]);
        $this->assertSame($diagnosis['totals']['files'], $diagnosis['totals']['conforming']);
    }

    public function test_un_hueco_con_sus_imports_sigue_en_el_hueco(): void
    {
        // Los 605 canView() de school-management traían su `use`.
        $filter = 'src/Models/Filters/Post/ManagedFilter.php';
        $content = $this->project->read($filter);
        $content = (string) preg_replace('/^use /m', "use Acme\\Blog\\Support\\Scope;\nuse ", $content, 1);
        $content = (string) preg_replace('/(function canView\([^)]*\)[^{]*\{)/', "\$1\n        Scope::apply(\$query);\n", $content, 1);
        file_put_contents($this->project->path.'/'.$filter, $content);

        $diagnosis = $this->realign('diagnose');

        $this->assertNull($this->entry($filter));
        $this->assertSame(1, $diagnosis['totals']['classes'][FileClass::HOLE_EDITED]);
    }

    public function test_solo_comentarios_es_formato_y_se_marca(): void
    {
        // 158 IndexRequest de school-management: el arreglo de `managed`
        // hecho a mano con sus propios comentarios.
        $index = 'src/Http/Requests/Post/IndexRequest.php';
        $this->edit($index, 'protected function prepareForValidation', "// Lo dejamos así a propósito.\n    protected function prepareForValidation");

        $this->realign('diagnose');
        $entry = $this->entry($index);

        $this->assertSame(FileClass::FORMAT_ONLY, $entry['class'] ?? null);
        $this->assertTrue($entry['comments'] ?? null);
        $this->assertSame('adopt', $entry['type'] ?? null);
    }

    public function test_una_migracion_registrada_dos_veces_se_olvida_la_que_falta(): void
    {
        // school-xapi: la misma creación generada dos veces con dos fechas;
        // una se borró y el manifiesto conserva las dos.
        $original = (string) $this->project->glob('database/migrations/*_create_post_metas_table.php');
        $copy = 'database/migrations/2030_01_01_000000_create_post_metas_table.php';
        rename($this->project->path.'/'.$original, $this->project->path.'/'.$copy);
        $this->changeManifest(function (array $manifest) use ($original, $copy): array {
            $files = &$manifest['models']['Post']['files'];
            $files[$copy] = $files[$original];

            return $manifest;
        });

        $this->realign('diagnose');
        $entry = $this->entry($original);

        $this->assertSame(FileClass::MISSING, $entry['class'] ?? null);
        $this->assertSame('superseded', $entry['cause'] ?? null);
        $this->assertSame('prune', $entry['type'] ?? null);
        $this->assertSame($copy, $entry['to'] ?? null);
    }

    public function test_lo_que_solo_reconoce_pint_es_formato(): void
    {
        // `array(...)` frente a `[...]`: por tokens es otro código; pasado por
        // la misma Pint que formateó lo regenerado, es lo mismo.
        $previous = getenv('LARAPACK_PINT');
        putenv('LARAPACK_PINT='.dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint');

        try {
            $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--react' => true, '--force' => true, '--format' => 'json']), $this->lastOutput);
            $index = 'src/Http/Requests/Post/IndexRequest.php';
            $this->assertStringContainsString('$this->merge([', $this->project->read($index));
            $this->edit($index, '$this->merge([', '$this->merge(array(');
            $this->edit($index, ']);', '));');

            $this->realign('diagnose');
            $entry = $this->entry($index);
        } finally {
            putenv($previous === false ? 'LARAPACK_PINT' : 'LARAPACK_PINT='.$previous);
        }

        $this->assertSame(FileClass::FORMAT_ONLY, $entry['class'] ?? null);
        $this->assertSame('igual tras pasarlo por la Pint del proyecto', $entry['reason'] ?? null);
    }

    public function test_lo_editado_fuera_de_los_huecos_es_guiado_y_dice_que_pieza(): void
    {
        $this->edit(self::SHOW_REQUEST, "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (true) {\n            return true;\n        }");

        $this->realign('diagnose');
        $entry = $this->entry(self::SHOW_REQUEST);

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::EDITED_OUTSIDE, $entry['class']);
        $this->assertSame(Category::GUIDED, $entry['category']);
        $this->assertSame(['method:authorize'], $entry['members']);
        $this->assertStringContainsString('method:authorize', $entry['reason']);
    }

    public function test_un_archivo_sin_editar_de_otra_version_es_un_salto_de_version(): void
    {
        // Lo que escribió la plantilla de una versión anterior: el hash del
        // manifiesto es el de lo que hay, y difiere de la plantilla actual.
        file_put_contents($this->project->path.'/'.self::ID_FILTER, $this->project->read(self::ID_FILTER)."// plantilla vieja\n");
        $this->changeManifest(function (array $manifest): array {
            $entry = &$manifest['models']['Post']['files'][self::ID_FILTER];
            $entry['hash'] = hash('sha256', $this->project->read(self::ID_FILTER));
            $entry['generator'] = '8.0.0';

            return $manifest;
        });

        $this->realign('diagnose');
        $entry = $this->entry(self::ID_FILTER);

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::VERSION_DELTA, $entry['class']);
        $this->assertSame('regenerate', $entry['type']);
        $this->assertSame('generator', $entry['cause']);
        $this->assertSame('8.0.0', $entry['generator']);
    }

    public function test_sin_version_en_el_manifiesto_tambien_es_de_otra_version(): void
    {
        file_put_contents($this->project->path.'/'.self::ID_FILTER, $this->project->read(self::ID_FILTER)."// v1\n");
        $this->changeManifest(function (array $manifest): array {
            $entry = &$manifest['models']['Post']['files'][self::ID_FILTER];
            $entry['hash'] = hash('sha256', $this->project->read(self::ID_FILTER));
            unset($entry['generator']);

            return $manifest;
        });

        $this->realign('diagnose');

        $this->assertSame('generator', $this->entry(self::ID_FILTER)['cause'] ?? null);
    }

    public function test_una_migracion_renombrada_a_mano_se_vuelve_a_enlazar(): void
    {
        // school-xapi y school-whatsapp: dos migraciones renombradas, y verify
        // dando missing-file sobre algo que sí existe.
        $original = (string) $this->project->glob('database/migrations/*_create_post_metas_table.php');
        rename($this->project->path.'/'.$original, $this->project->path.'/database/migrations/2020_01_01_000000_create_post_metas_table.php');

        $this->realign('diagnose');
        $entry = $this->entry($original);

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::RENAMED, $entry['class']);
        $this->assertSame('relink', $entry['type']);
        $this->assertSame('database/migrations/2020_01_01_000000_create_post_metas_table.php', $entry['to']);
    }

    public function test_una_migracion_que_difiere_no_se_reescribe_nunca(): void
    {
        $migration = (string) $this->project->glob('database/migrations/*_create_posts_table.php');
        $this->edit($migration, '$table->id();', "\$table->id();\n            \$table->string('extra');");

        $this->realign('diagnose');
        $entry = $this->entry($migration);

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::MIGRATION_HISTORY, $entry['class']);
        $this->assertSame(Category::OUT_OF_SCOPE, $entry['category']);
        $this->assertNull($entry['type']);
    }

    public function test_un_archivo_borrado_que_el_contrato_pide_es_una_decision(): void
    {
        $card = 'resources/react/src/models/post/widgets/ModelCard.jsx';
        unlink($this->project->path.'/'.$card);

        $this->realign('diagnose');
        $entry = $this->entry($card);

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::MISSING, $entry['class']);
        $this->assertSame(Category::DECISION, $entry['category']);
        $this->assertSame('missing', $entry['type']);
    }

    public function test_lo_de_un_modelo_retirado_del_contrato_se_olvida(): void
    {
        // StaffMember en expediente: borrado del contrato, 53 missing-file.
        $this->changeManifest(function (array $manifest): array {
            $manifest['models']['StaffMember'] = ['namespace' => 'Acme\\Blog\\', 'files' => [
                'src/Models/StaffMember.php' => ['stub' => 'Model/ModelTemplate.txt', 'hash' => 'x'],
            ]];

            return $manifest;
        });

        $this->realign('diagnose');
        $entry = $this->entry('src/Models/StaffMember.php');

        $this->assertNotNull($entry);
        $this->assertSame(FileClass::MISSING, $entry['class']);
        $this->assertSame('retired', $entry['cause']);
        $this->assertSame('prune', $entry['type']);
        $this->assertSame(Category::DETERMINISTIC, $entry['category']);
    }

    public function test_un_archivo_que_el_manifiesto_no_conoce_en_una_ruta_generada(): void
    {
        $model = 'src/Models/Post.php';
        $show = 'resources/react/src/models/post/views/ShowView.jsx';
        $this->changeManifest(function (array $manifest) use ($model, $show): array {
            unset($manifest['models']['Post']['files'][$model], $manifest['models']['Post']['files'][$show]);

            return $manifest;
        });
        file_put_contents($this->project->path.'/'.$show, "// escrito a mano\n".$this->project->read($show));

        $this->realign('diagnose');

        // Igual a lo regenerado: se adopta solo.
        $this->assertSame(FileClass::IDENTICAL, $this->entry($model)['class'] ?? null);
        $this->assertSame('adopt', $this->entry($model)['type'] ?? null);
        $this->assertFalse($this->entry($model)['tracked'] ?? null);
        // Distinto: adoptarlo como editado o dejarlo fuera lo decide alguien.
        $this->assertSame(FileClass::UNTRACKED, $this->entry($show)['class'] ?? null);
        $this->assertSame(Category::DECISION, $this->entry($show)['category'] ?? null);
    }

    public function test_lo_que_import_no_regenera_queda_fuera_de_alcance(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:providers', ['--root' => $this->project->path]), $this->lastOutput);

        $this->realign('diagnose');
        $providers = array_filter($this->report()['files'], fn (array $entry): bool => str_starts_with($entry['file'], 'src/Providers/'));

        $this->assertNotSame([], $providers);

        foreach ($providers as $entry) {
            $this->assertSame(FileClass::NOT_REGENERATED, $entry['class']);
            $this->assertSame(Category::OUT_OF_SCOPE, $entry['category']);
        }
    }

    public function test_el_codigo_escrito_a_mano_se_lista_y_verify_se_clasifica(): void
    {
        @mkdir($this->project->path.'/src/Http/Controllers/Admin', 0777, true);
        file_put_contents($this->project->path.'/src/Http/Controllers/Admin/ReportController.php', <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Controllers\Controller;
            use Illuminate\Http\Request;

            class ReportController extends Controller
            {
                public function index(Request $request)
                {
                    $request->validate(['from' => 'required|date']);

                    return response()->json(['ok' => true]);
                }
            }
            PHP);
        @mkdir($this->project->path.'/src/Services', 0777, true);
        file_put_contents($this->project->path.'/src/Services/Billing.php', "<?php\n\nnamespace Acme\\Blog\\Services;\n\nclass Billing {}\n");

        $diagnosis = $this->realign('diagnose');
        $report = $this->report();

        $this->assertSame([[
            'scope' => '.', 'package' => null, 'file' => 'src/Http/Controllers/Admin/ReportController.php', 'kind' => 'controller',
            'category' => Category::DECISION, 'type' => 'unmanaged-http',
        ]], $report['unmanaged']);

        $shape = array_values(array_filter($report['findings'], fn (array $finding): bool => $finding['check'] === 'controller-shape'));
        $this->assertCount(1, $shape);
        $this->assertSame('controller-shape', $shape[0]['type']);
        $this->assertSame(Category::GUIDED, $shape[0]['category']);
        $this->assertSame('.', $shape[0]['scope']);
        $this->assertSame(1, $diagnosis['verify']['errors']);

        // Lo demás que no es de LaraPack se cuenta y no se lista; los traits
        // del modelo, huecos que el generador no registra, aparte.
        $this->assertSame(['Models/Traits' => 5, 'Services' => 1], $report['out_of_scope']['.']['other_code']);
        $this->assertNotContains('customised', array_column($report['findings'], 'check'));
    }

    public function test_una_excepcion_aprobada_deja_de_proponerse(): void
    {
        @mkdir($this->project->path.'/src/Http/Controllers/Admin', 0777, true);
        file_put_contents($this->project->path.'/src/Http/Controllers/Admin/HookController.php', "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers\\Admin;\n\nclass HookController {}\n");
        @mkdir($this->project->path.'/.larapack/realign', 0777, true);
        file_put_contents($this->project->path.'/.larapack/realign/exceptions.json', json_encode(['format_version' => 1, 'exceptions' => [
            ['type' => 'unmanaged-http', 'file' => 'src/Http/Controllers/Admin/HookController.php', 'reason' => 'Firma sobre el cuerpo en bruto.'],
            // Sin razón no cuenta.
            ['type' => 'unmanaged-models', 'file' => 'src/Models/Post.php'],
        ]]));

        $this->realign('diagnose');

        $this->assertSame([], $this->report()['unmanaged']);
    }

    public function test_la_segunda_vez_usa_lo_regenerado_si_nada_cambio(): void
    {
        $first = $this->realign('diagnose');
        $second = $this->realign('diagnose');
        $forced = $this->realign('diagnose', ['--no-cache' => true]);

        $this->assertFalse($first['scopes'][0]['regeneration']['cached']);
        $this->assertTrue($second['scopes'][0]['regeneration']['cached']);
        $this->assertFalse($forced['scopes'][0]['regeneration']['cached']);
        $this->assertSame($first['scopes'][0]['regeneration']['key'], $second['scopes'][0]['regeneration']['key']);

        // Cambiar el contrato cambia la clave y no deja la vieja.
        $this->edit('laraimport.json', '"name": "Post"', '"name": "Post", "metas": true');
        $changed = $this->realign('diagnose');

        $this->assertFalse($changed['scopes'][0]['regeneration']['cached']);
        $this->assertNotSame($first['scopes'][0]['regeneration']['key'], $changed['scopes'][0]['regeneration']['key']);
        $this->assertCount(1, glob($this->project->path.'/.larapack/realign/cache/root/*.done') ?: []);
    }

    public function test_un_contrato_que_no_valida_no_se_compara_y_se_dice(): void
    {
        file_put_contents($this->project->path.'/laraimport.json', '{"models": "no es una lista"}');

        $diagnosis = $this->realign('diagnose');

        $this->assertFalse($diagnosis['scopes'][0]['regeneration']['ok']);
        $this->assertNotEmpty($diagnosis['scopes'][0]['regeneration']['error']);
        $this->assertSame([], $this->report()['files']);
    }

    public function test_un_contrato_sin_modelos_se_compara_contra_una_referencia_vacia(): void
    {
        // Los 12 paquetes de integración de school-management (`models: []`):
        // se regenera sin manifiesto, y lo que había deja de ser del contrato.
        file_put_contents($this->project->path.'/laraimport.json', '{"models": []}');

        $diagnosis = $this->realign('diagnose');

        $this->assertTrue($diagnosis['scopes'][0]['regeneration']['ok']);
        $this->assertSame(FileClass::NOT_REGENERATED, $this->entry(self::CONTROLLER)['class'] ?? null);
        $this->assertStringContainsString('ya no está en el contrato', $this->entry(self::CONTROLLER)['reason'] ?? '');
    }

    public function test_jobs_tiene_que_ser_positivo(): void
    {
        $document = $this->realign('diagnose', ['--jobs' => '0'], Command::FAILURE);

        $this->assertFalse($document['ok']);
        $this->assertStringContainsString('--jobs', $document['error']);
    }

    /**
     * @return array<string, string> ruta => hash, fuera de .larapack/realign
     */
    private function snapshot(): array
    {
        $hashes = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project->path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($this->project->path) + 1);

            if (! str_starts_with($relative, '.larapack/realign')) {
                $hashes[$relative] = (string) hash_file('sha256', $file->getPathname());
            }
        }

        ksort($hashes);

        return $hashes;
    }
}
