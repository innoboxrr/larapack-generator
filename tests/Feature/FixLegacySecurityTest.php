<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\LegacyFix;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:fix` sobre las formas que hay en los proyectos de verdad: lo que
 * generaban las versiones anteriores tal cual, y lo mismo pasado por Pint como
 * está en school-management. Lo que no reconoce no lo toca y dice por qué.
 */
final class FixLegacySecurityTest extends TestCase
{
    /**
     * El IndexRequest de antes de 9.0.0, tal como salía de la plantilla.
     */
    private const OLD_INDEX = <<<'PHP'
<?php

namespace App\Http\Requests\Post;

use App\Models\Post;
use App\Http\Resources\Models\PostResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Innoboxrr\SearchSurge\Search\Builder;

class IndexRequest extends FormRequest
{

    protected function prepareForValidation()
    {
        //
    }

    public function authorize()
    {
        return $this->user()->can('index', Post::class);
    }

    public function handle()
    {

        $builder = new Builder();

        $query = $builder->get(Post::class, $this->all());

        return PostResource::collection($query);

    }
}
PHP;

    /**
     * El mismo, pasado por Pint: es la forma de school-management.
     */
    private const PINT_INDEX = <<<'PHP'
<?php

namespace App\Http\Requests\Tag;

use App\Http\Resources\Models\TagResource;
use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Innoboxrr\SearchSurge\Search\Builder;

class IndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        //
    }

    public function authorize(): bool
    {
        return $this->user()->can('index', Tag::class);
    }

    public function handle()
    {

        $builder = new Builder;

        $query = $builder->get(Tag::class, $this->all());

        return TagResource::collection($query);

    }
}
PHP;

    private const OLD_RESOURCE = <<<'PHP'
<?php

namespace App\Http\Resources\Models;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Post;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        if ($request->has('appends') && is_array($request->get('appends'))) {
            $this->resource->setAppends($request->get('appends'));
        }

        return parent::toArray($request) + [
            'actions' => $this->actions($request)
        ];

    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actions(Request $request): array
    {
        return [];
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());
    }

    private function write(string $relative, string $code): void
    {
        $file = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $code);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function fix(array $checks = ['index-managed', 'resource-appends'], array $options = []): array
    {
        $code = $this->runCommand('larapack:fix', ['checks' => $checks, '--format' => 'json'] + $options);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return [$code, $report];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyFindings(): array
    {
        $this->runCommand('larapack:verify', ['--format' => 'json', '--check' => ['index-managed', 'resource-appends']]);

        return json_decode($this->lastOutput, true)['findings'];
    }

    private function lint(string $relative): void
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->project->path.'/'.$relative).' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));
    }

    private static function indented(array $lines, string $indent): string
    {
        return implode("\n", array_map(fn (string $line): string => $line === '' ? '' : $indent.$line, $lines));
    }

    public function test_los_fragmentos_son_los_de_la_plantilla_actual(): void
    {
        $stubs = dirname(__DIR__, 2).'/src/Stubs';
        $index = (string) file_get_contents($stubs.'/Requests/IndexRequest.txt');
        $resource = (string) file_get_contents($stubs.'/Resource/ResourceTemplate.txt');

        $this->assertStringContainsString(self::indented(LegacyFix::MANAGED_LINES, '        '), $index);
        $this->assertStringContainsString(self::indented(LegacyFix::APPENDABLE_LINES, '    '), $resource);

        $appends = LegacyFix::APPENDS_LINES;
        $appends[0] = sprintf($appends[0], '$request');
        $this->assertStringContainsString(self::indented($appends, '        '), $resource);
    }

    public function test_arregla_lo_generado_antes_y_verify_queda_limpio(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);
        $this->write('app/Http/Requests/Tag/IndexRequest.php', self::PINT_INDEX);
        $this->write('app/Http/Resources/Models/PostResource.php', self::OLD_RESOURCE);

        $this->assertCount(3, $this->legacyFindings());

        [$code, $report] = $this->fix();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertTrue($report['ok']);
        $this->assertFalse($report['dry_run']);
        $this->assertSame(
            [['file' => 'app/Http/Requests/Post/IndexRequest.php'], ['file' => 'app/Http/Requests/Tag/IndexRequest.php']],
            $report['checks']['index-managed']['fixed']
        );
        $this->assertSame([['file' => 'app/Http/Resources/Models/PostResource.php']], $report['checks']['resource-appends']['fixed']);
        $this->assertSame([], $report['checks']['index-managed']['skipped']);
        $this->assertSame([], $this->legacyFindings());

        $tag = $this->project->read('app/Http/Requests/Tag/IndexRequest.php');
        $this->assertStringContainsString(
            "    protected function prepareForValidation(): void\n    {\n".self::indented(LegacyFix::MANAGED_LINES, '        ')."\n    }\n\n    public function authorize",
            $tag
        );
        $this->assertStringNotContainsString("        //\n    }", $tag);

        $resource = $this->project->read('app/Http/Resources/Models/PostResource.php');
        $this->assertStringContainsString("{\n\n".self::indented(LegacyFix::APPENDABLE_LINES, '    ')."\n\n    /**\n     * @return array<string, mixed>", $resource);
        $this->assertStringContainsString("        \$appends = \$request->input('appends');\n\n        if (is_array(\$appends) && \$this->appendable !== []) {", $resource);
        $this->assertStringNotContainsString("\$request->get('appends')", $resource);
        $this->assertStringContainsString("        }\n\n        return parent::toArray(\$request)", $resource);

        foreach (['app/Http/Requests/Post/IndexRequest.php', 'app/Http/Requests/Tag/IndexRequest.php', 'app/Http/Resources/Models/PostResource.php'] as $file) {
            $this->lint($file);
        }
    }

    public function test_correrlo_dos_veces_no_cambia_nada_la_segunda(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);
        $this->write('app/Http/Resources/Models/PostResource.php', self::OLD_RESOURCE);

        $this->fix();
        $first = [$this->project->read('app/Http/Requests/Post/IndexRequest.php'), $this->project->read('app/Http/Resources/Models/PostResource.php')];

        [$code, $report] = $this->fix();

        $this->assertSame(Command::SUCCESS, $code);
        $only = fn (array $entry): array => array_intersect_key($entry, array_flip(['fixed', 'skipped', 'unchanged']));
        $this->assertSame(['fixed' => [], 'skipped' => [], 'unchanged' => 1], $only($report['checks']['index-managed']));
        $this->assertSame(['fixed' => [], 'skipped' => [], 'unchanged' => 1], $only($report['checks']['resource-appends']));
        $this->assertSame($first, [$this->project->read('app/Http/Requests/Post/IndexRequest.php'), $this->project->read('app/Http/Resources/Models/PostResource.php')]);
    }

    public function test_un_prepare_for_validation_con_codigo_lo_conserva_detras(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', str_replace(
            "        //\n",
            "        parent::prepareForValidation();\n\n        \$this->merge(['paginate' => 25]);\n",
            self::PINT_INDEX
        ));

        $this->fix(['index-managed']);

        $this->assertStringContainsString(
            "    {\n".self::indented(LegacyFix::MANAGED_LINES, '        ')."\n\n        parent::prepareForValidation();\n\n        \$this->merge(['paginate' => 25]);\n    }",
            $this->project->read('app/Http/Requests/Post/IndexRequest.php')
        );
        $this->assertSame([], $this->legacyFindings());
    }

    public function test_si_no_declara_el_metodo_lo_crea_delante_del_primero(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', str_replace(
            "    protected function prepareForValidation(): void\n    {\n        //\n    }\n\n",
            '',
            self::PINT_INDEX
        ));

        [, $report] = $this->fix(['index-managed']);

        $this->assertCount(1, $report['checks']['index-managed']['fixed']);
        $this->assertStringContainsString(
            "{\n    protected function prepareForValidation(): void\n    {\n".self::indented(LegacyFix::MANAGED_LINES, '        ')."\n    }\n\n    public function authorize(): bool",
            $this->project->read('app/Http/Requests/Post/IndexRequest.php')
        );
        $this->lint('app/Http/Requests/Post/IndexRequest.php');
    }

    public function test_si_hereda_uno_del_proyecto_lo_llama_con_su_visibilidad(): void
    {
        $this->write('app/Http/Requests/BaseRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseRequest extends FormRequest
{
    public function prepareForValidation()
    {
        $this->merge(['paginate' => 25]);
    }
}
PHP);
        $this->write('app/Http/Requests/Post/IndexRequest.php', str_replace(
            ['use Illuminate\Foundation\Http\FormRequest;', 'extends FormRequest', "    protected function prepareForValidation(): void\n    {\n        //\n    }\n\n"],
            ['use App\Http\Requests\BaseRequest;', 'extends BaseRequest', ''],
            self::PINT_INDEX
        ));

        $this->fix(['index-managed']);

        $this->assertStringContainsString(
            "    public function prepareForValidation(): void\n    {\n        parent::prepareForValidation();\n\n".self::indented(LegacyFix::MANAGED_LINES, '        ')."\n    }",
            $this->project->read('app/Http/Requests/Post/IndexRequest.php')
        );
        $this->assertSame([], $this->legacyFindings());
    }

    public function test_lo_que_no_reconoce_lo_salta_y_dice_por_que(): void
    {
        // Un trait podría declarar prepareForValidation().
        $this->write('app/Http/Requests/Post/IndexRequest.php', str_replace(
            ["{\n    protected function prepareForValidation(): void\n    {\n        //\n    }\n", 'use Innoboxrr\SearchSurge\Search\Builder;'],
            ["{\n    use \\App\\Http\\Requests\\Concerns\\Prepares;\n", "use Innoboxrr\\SearchSurge\\Search\\Builder;\n"],
            self::PINT_INDEX
        ));
        // Hereda de una request de fuera del proyecto: verify no la marca.
        // Un Resource con else.
        $this->write('app/Http/Resources/Models/PostResource.php', str_replace(
            "            \$this->resource->setAppends(\$request->get('appends'));\n        }",
            "            \$this->resource->setAppends(\$request->get('appends'));\n        } else {\n            \$this->resource->makeHidden(['secret']);\n        }",
            self::OLD_RESOURCE
        ));
        // Uno que pasa por una variable.
        $this->write('app/Http/Resources/Models/TagResource.php', str_replace(
            ['class PostResource', "        if (\$request->has('appends') && is_array(\$request->get('appends'))) {\n            \$this->resource->setAppends(\$request->get('appends'));\n        }"],
            ['class TagResource', "        \$appends = \$request->input('appends', []);\n        \$this->resource->setAppends(\$appends);"],
            self::OLD_RESOURCE
        ));
        // Uno que hereda de otro Resource.
        $this->write('app/Http/Resources/Models/NoteResource.php', str_replace(
            ['class PostResource extends JsonResource', 'use Illuminate\Http\Resources\Json\JsonResource;'],
            ['class NoteResource extends BaseResource', 'use App\Http\Resources\BaseResource;'],
            self::OLD_RESOURCE
        ));

        $before = [
            'app/Http/Requests/Post/IndexRequest.php' => $this->project->read('app/Http/Requests/Post/IndexRequest.php'),
            'app/Http/Resources/Models/PostResource.php' => $this->project->read('app/Http/Resources/Models/PostResource.php'),
            'app/Http/Resources/Models/TagResource.php' => $this->project->read('app/Http/Resources/Models/TagResource.php'),
            'app/Http/Resources/Models/NoteResource.php' => $this->project->read('app/Http/Resources/Models/NoteResource.php'),
        ];

        [$code, $report] = $this->fix();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFalse($report['ok']);

        $reasons = [];

        foreach ($report['checks'] as $entry) {
            $this->assertSame([], $entry['fixed']);

            foreach ($entry['skipped'] as $skip) {
                $reasons[$skip['file']] = $skip['reason'];
            }
        }

        ksort($reasons);
        $expected = array_keys($before);
        sort($expected);
        $this->assertSame($expected, array_keys($reasons));
        $this->assertStringContainsString('usa traits', $reasons['app/Http/Requests/Post/IndexRequest.php']);
        $this->assertStringContainsString('else', $reasons['app/Http/Resources/Models/PostResource.php']);
        $this->assertStringContainsString('no es la lectura directa de `appends`', $reasons['app/Http/Resources/Models/TagResource.php']);
        $this->assertStringContainsString('BaseResource', $reasons['app/Http/Resources/Models/NoteResource.php']);

        foreach ($before as $file => $code) {
            $this->assertSame($code, $this->project->read($file), "{$file} no debía tocarse.");
        }
    }

    public function test_el_helper_request_y_la_sangria_con_tabuladores(): void
    {
        $this->write('app/Http/Resources/Models/PostResource.php', str_replace(
            ['    ', "\$this->resource->setAppends(\$request->get('appends'));"],
            ["\t", "\$this->resource->setAppends(request('appends'));"],
            self::OLD_RESOURCE
        ));

        [, $report] = $this->fix(['resource-appends']);

        $this->assertCount(1, $report['checks']['resource-appends']['fixed']);

        $resource = $this->project->read('app/Http/Resources/Models/PostResource.php');
        $this->assertStringContainsString("\t\t\$appends = request()->input('appends');\n\n\t\tif (is_array(\$appends)", $resource);
        $this->assertStringContainsString("\tprotected array \$appendable = [];", $resource);
        $this->assertSame([], $this->legacyFindings());
    }

    public function test_dry_run_muestra_el_diff_y_no_escribe(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::PINT_INDEX);

        [$code, $report] = $this->fix(['index-managed'], ['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(self::PINT_INDEX, $this->project->read('app/Http/Requests/Post/IndexRequest.php'));

        $diff = $report['checks']['index-managed']['fixed'][0]['diff'];
        $this->assertStringStartsWith("--- a/app/Http/Requests/Post/IndexRequest.php\n+++ b/app/Http/Requests/Post/IndexRequest.php\n@@ -11,7 +11,23 @@\n", $diff);
        $this->assertStringContainsString("+        \$this->merge([\n+            'managed' => true,\n+        ]);\n", $diff);

        // Y en texto.
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:fix', ['checks' => ['index-managed'], '--dry-run' => true]));
        $this->assertStringContainsString('arreglaría  app/Http/Requests/Post/IndexRequest.php', $this->lastOutput);
        $this->assertStringContainsString('+        $this->merge([', $this->lastOutput);
        $this->assertStringContainsString('1 por arreglar, 0 saltados, 0 ya estaban bien', $this->lastOutput);
    }

    public function test_path_acota_a_un_subdirectorio(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);
        $this->write('packages/library/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Library\\' => 'src/']]]));
        $this->write('packages/library/src/Http/Requests/Book/IndexRequest.php', str_replace('App\Http\Requests\Post', 'Acme\Library\Http\Requests\Book', self::OLD_INDEX));

        [, $report] = $this->fix(['index-managed'], ['--path' => 'packages/library']);

        $this->assertSame([['file' => 'packages/library/src/Http/Requests/Book/IndexRequest.php']], $report['checks']['index-managed']['fixed']);
        $this->assertSame(self::OLD_INDEX, $this->project->read('app/Http/Requests/Post/IndexRequest.php'));

        [$code, $report] = $this->fix(['index-managed'], ['--path' => 'no/existe']);
        $this->assertSame(Command::FAILURE, $code);
        $this->assertFalse($report['ok']);
    }

    public function test_una_correccion_desconocida_falla(): void
    {
        [$code, $report] = $this->fix(['index-managed', 'controller-shape']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('controller-shape', $report['error']);
    }

    public function test_lo_que_el_manifiesto_conocia_sin_tocar_sigue_sin_tocar(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Post']));

        // Lo que habría generado una versión anterior, anotado como generado.
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::PINT_INDEX);
        $manifest = new Manifest($this->project->path);
        $manifest->rehash([$this->project->path.'/app/Http/Requests/Post/IndexRequest.php']);
        $this->assertFalse($manifest->wasCustomised($this->project->path.'/app/Http/Requests/Post/IndexRequest.php'));

        [, $report] = $this->fix(['index-managed']);

        $this->assertCount(1, $report['checks']['index-managed']['fixed']);
        $this->assertFalse((new Manifest($this->project->path))->wasCustomised($this->project->path.'/app/Http/Requests/Post/IndexRequest.php'));
    }

    public function test_pasa_el_pint_del_proyecto_si_lo_tiene(): void
    {
        putenv('LARAPACK_PINT='.dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint');

        try {
            $this->write('app/Http/Resources/Models/PostResource.php', self::OLD_RESOURCE);

            [, $report] = $this->fix(['resource-appends']);
        } finally {
            putenv('LARAPACK_PINT');
        }

        $this->assertTrue($report['formatted']);
        $this->assertCount(1, $report['checks']['resource-appends']['fixed']);

        // Pint quita la línea en blanco tras la llave de la clase y ordena los use.
        $resource = $this->project->read('app/Http/Resources/Models/PostResource.php');
        $this->assertStringContainsString("{\n    /**\n     * Los accessors", $resource);
        $this->lint('app/Http/Resources/Models/PostResource.php');
        $this->assertSame([], $this->legacyFindings());
    }

    /**
     * Si lo formateado no parsea, se devuelve el original y el arreglo pasa de
     * `fixed` a `skipped`. Un Pint de mentira que rompe lo que toca fuerza ese
     * camino, que ninguna prueba recorría.
     */
    public function test_lo_que_no_parsea_tras_formatear_se_devuelve_y_se_salta(): void
    {
        $pint = $this->project->path.'/broken-pint.php';
        file_put_contents($pint, "<?php\nforeach (array_slice(\$argv, 1) as \$file) {\n    file_put_contents(\$file, \"<?php\\nfunction (\\n\");\n}\n");

        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);
        $this->write('app/Http/Resources/Models/PostResource.php', self::OLD_RESOURCE);

        putenv('LARAPACK_PINT='.$pint);

        try {
            [$code, $report] = $this->fix();
        } finally {
            putenv('LARAPACK_PINT');
        }

        $this->assertSame(Command::FAILURE, $code);
        $reason = 'Después de formatearlo no parseaba, y se devolvió el original.';

        foreach (['index-managed' => 'app/Http/Requests/Post/IndexRequest.php', 'resource-appends' => 'app/Http/Resources/Models/PostResource.php'] as $check => $file) {
            $this->assertSame([], $report['checks'][$check]['fixed'], $check);
            $this->assertSame([['file' => $file, 'reason' => $reason]], $report['checks'][$check]['skipped'], $check);
        }

        $this->assertSame(self::OLD_INDEX, $this->project->read('app/Http/Requests/Post/IndexRequest.php'));
        $this->assertSame(self::OLD_RESOURCE, $this->project->read('app/Http/Resources/Models/PostResource.php'));
    }

    /**
     * Dos arreglos del mismo archivo en una comprobación, y el archivo hay que
     * devolverlo: los dos salen de `fixed` y los dos acaban en `skipped`.
     * Quitando por índice mientras se reindexaba, el segundo se quedaba en
     * `fixed` aunque el archivo ya fuera el original.
     *
     * plan() hoy anota cada archivo una vez, así que se prepara el informe a
     * mano: lo que se fija es que revert() no dependa de esa casualidad.
     */
    public function test_revertir_quita_todos_los_arreglos_del_archivo(): void
    {
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);
        $this->write('app/Http/Requests/Tag/IndexRequest.php', self::PINT_INDEX);

        $fix = new LegacyFix($this->project->path, ['index-managed']);
        $planned = $fix->plan()['index-managed']['fixed'];

        $this->assertSame([['file' => 'app/Http/Requests/Post/IndexRequest.php'], ['file' => 'app/Http/Requests/Tag/IndexRequest.php']], $planned);

        $report = new \ReflectionProperty(LegacyFix::class, 'report');
        $state = $report->getValue($fix);
        $post = ['file' => 'app/Http/Requests/Post/IndexRequest.php'];
        $state['index-managed']['fixed'] = [$post, $post, ['file' => 'app/Http/Requests/Tag/IndexRequest.php']];
        $report->setValue($fix, $state);

        (new \ReflectionMethod(LegacyFix::class, 'revert'))->invoke($fix, $this->project->path.'/app/Http/Requests/Post/IndexRequest.php', 'motivo');

        $after = $report->getValue($fix)['index-managed'];

        $this->assertSame([['file' => 'app/Http/Requests/Tag/IndexRequest.php']], $after['fixed']);
        $this->assertSame([$post + ['reason' => 'motivo'], $post + ['reason' => 'motivo']], $after['skipped']);
    }
}
