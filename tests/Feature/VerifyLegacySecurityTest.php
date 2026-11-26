<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que las plantillas ya no generan, buscado en lo que generaron antes: un
 * IndexRequest que no fuerza `managed` y un Resource que pasa `appends` del
 * cliente a setAppends() sin lista blanca.
 *
 * Los archivos de las pruebas son copia literal de lo que generaban las
 * versiones anteriores, que es lo que hay en los proyectos de verdad.
 */
final class VerifyLegacySecurityTest extends TestCase
{
    private const OLD_INDEX = <<<'PHP'
<?php

namespace App\Http\Requests\Post;

use App\Models\Post;
use App\Http\Resources\Models\PostResource;
use Illuminate\Foundation\Http\FormRequest;
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

    private const OLD_RESOURCE = <<<'PHP'
<?php

namespace App\Http\Resources\Models;

use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{

    public function toArray($request)
    {

        if ($request->has('appends') && is_array($request->get('appends'))) {
            $this->resource->setAppends($request->get('appends'));
        }

        return parent::toArray($request);

    }
}
PHP;

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(array $options = []): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json'] + $options);

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function findingsOf(array $report, string $check): array
    {
        return array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === $check));
    }

    private function write(string $relative, string $code): void
    {
        $file = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $code);
    }

    public function test_lo_que_se_genera_hoy_no_tiene_hallazgos(): void
    {
        $this->useProject(FakeProject::application());

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Post']));

        [, $report] = $this->verify();

        $this->assertSame([], $this->findingsOf($report, 'index-managed'));
        $this->assertSame([], $this->findingsOf($report, 'resource-appends'));
    }

    public function test_avisa_de_lo_generado_con_versiones_anteriores_sin_manifiesto(): void
    {
        $this->useProject(FakeProject::empty());

        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);
        $this->write('app/Http/Resources/Models/PostResource.php', self::OLD_RESOURCE);

        [, $report] = $this->verify();

        $index = $this->findingsOf($report, 'index-managed');
        $appends = $this->findingsOf($report, 'resource-appends');

        $this->assertCount(1, $index);
        $this->assertSame('warning', $index[0]['level']);
        $this->assertSame('app/Http/Requests/Post/IndexRequest.php', $index[0]['file']);
        $this->assertStringContainsString("\$this->merge(['managed' => true]);", $index[0]['message']);

        $this->assertCount(1, $appends);
        $this->assertSame('warning', $appends[0]['level']);
        $this->assertSame('app/Http/Resources/Models/PostResource.php', $appends[0]['file']);
        $this->assertStringContainsString('PostResource::toArray() (línea 14)', $appends[0]['message']);
    }

    public function test_es_aviso_y_con_strict_falla(): void
    {
        $this->useProject(FakeProject::empty());

        $this->write('app/Http/Requests/Post/IndexRequest.php', self::OLD_INDEX);

        [$code] = $this->verify(['--check' => ['index-managed']]);
        $this->assertSame(Command::SUCCESS, $code);

        [$code] = $this->verify(['--check' => ['index-managed'], '--strict' => true]);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function test_forzar_managed_en_una_request_base_cuenta(): void
    {
        $this->useProject(FakeProject::empty());

        $this->write('app/Http/Requests/ManagedRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ManagedRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['managed' => true, 'paginate' => 25]);
    }
}
PHP);

        // Hereda prepareForValidation() sin tocarlo.
        $this->write('app/Http/Requests/Post/IndexRequest.php', str_replace(
            ['use Illuminate\Foundation\Http\FormRequest;', 'extends FormRequest', "    protected function prepareForValidation()\n    {\n        //\n    }\n"],
            ['use App\Http\Requests\ManagedRequest;', 'extends ManagedRequest', ''],
            self::OLD_INDEX
        ));

        // Lo sobrescribe y llama al padre.
        $this->write('app/Http/Requests/Tag/IndexRequest.php', str_replace(
            ['namespace App\Http\Requests\Post;', 'use Illuminate\Foundation\Http\FormRequest;', 'extends FormRequest', '        //'],
            ['namespace App\Http\Requests\Tag;', 'use App\Http\Requests\ManagedRequest;', 'extends ManagedRequest', "        parent::prepareForValidation();\n        \$this->merge(['tag' => 1]);"],
            self::OLD_INDEX
        ));

        // Lo sobrescribe y no llama al padre: pierde la protección.
        $this->write('app/Http/Requests/Note/IndexRequest.php', str_replace(
            ['namespace App\Http\Requests\Post;', 'use Illuminate\Foundation\Http\FormRequest;', 'extends FormRequest', '        //'],
            ['namespace App\Http\Requests\Note;', 'use App\Http\Requests\ManagedRequest;', 'extends ManagedRequest', "        \$this->merge(['note' => 1]);"],
            self::OLD_INDEX
        ));

        [, $report] = $this->verify(['--check' => ['index-managed']]);

        $this->assertSame(['app/Http/Requests/Note/IndexRequest.php'], array_column($this->findingsOf($report, 'index-managed'), 'file'));
    }

    public function test_merge_if_missing_no_fuerza_nada(): void
    {
        $this->useProject(FakeProject::empty());

        $this->write('app/Http/Requests/Post/IndexRequest.php', str_replace('        //', "        \$this->mergeIfMissing(['managed' => true]);", self::OLD_INDEX));
        $this->write('app/Http/Requests/Tag/IndexRequest.php', str_replace(
            ['namespace App\Http\Requests\Post;', '        //'],
            ['namespace App\Http\Requests\Tag;', "        \$this->merge([\n            'managed' => 1,\n        ]);"],
            self::OLD_INDEX
        ));

        [, $report] = $this->verify(['--check' => ['index-managed']]);

        $this->assertSame(['app/Http/Requests/Post/IndexRequest.php'], array_column($this->findingsOf($report, 'index-managed'), 'file'));
    }

    public function test_un_index_que_no_usa_search_surge_no_se_mira(): void
    {
        $this->useProject(FakeProject::empty());

        $this->write('app/Http/Requests/Post/IndexRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests\Post;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function handle()
    {
        return Post::query()->where('user_id', $this->user()->id)->get();
    }
}
PHP);

        [, $report] = $this->verify(['--check' => ['index-managed']]);

        $this->assertSame([], $report['findings']);
    }

    public function test_appends_por_una_variable_o_por_el_helper_tambien_avisa(): void
    {
        $this->useProject(FakeProject::empty());

        $this->write('app/Http/Resources/Models/PostResource.php', str_replace(
            "        if (\$request->has('appends') && is_array(\$request->get('appends'))) {\n            \$this->resource->setAppends(\$request->get('appends'));\n        }",
            "        \$appends = \$request->input('appends', []);\n        \$this->resource->setAppends(\$appends);",
            self::OLD_RESOURCE
        ));
        $this->write('app/Http/Resources/Models/TagResource.php', str_replace(
            ['class PostResource', "\$this->resource->setAppends(\$request->get('appends'));"],
            ['class TagResource', "\$this->resource->setAppends(request('appends'));"],
            self::OLD_RESOURCE
        ));
        // Con lista blanca no avisa.
        $this->write('app/Http/Resources/Models/NoteResource.php', str_replace(
            ['class PostResource', "\$this->resource->setAppends(\$request->get('appends'));"],
            ['class NoteResource', "\$wanted = array_intersect(['full_name'], \$request->get('appends'));\n            \$this->resource->setAppends(\$wanted);"],
            self::OLD_RESOURCE
        ));

        [, $report] = $this->verify(['--check' => ['resource-appends']]);

        $this->assertSame(
            ['app/Http/Resources/Models/PostResource.php', 'app/Http/Resources/Models/TagResource.php'],
            array_column($this->findingsOf($report, 'resource-appends'), 'file')
        );
    }
}
