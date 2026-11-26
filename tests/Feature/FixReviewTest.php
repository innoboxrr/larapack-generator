<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\AppendableProposals;
use Innoboxrr\LarapackGenerator\Support\LegacyFix;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que `larapack:fix` añade a su reporte sin arreglar nada —los
 * ManagedFilter sin camino para el administrador y la propuesta de
 * `$appendable` leída del front—, `--package`, y que la lógica propia de un
 * IndexRequest sobrevive al arreglo.
 */
final class FixReviewTest extends TestCase
{
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
     * @param  list<string>  $checks
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
     * El IndexRequest de la plantilla de antes de 9.0.0, pasado por Pint.
     */
    private static function index(string $model, string $namespace = 'App', string $options = ''): string
    {
        $options = $options === '' ? '' : ", {$options}";

        return <<<PHP
<?php

namespace {$namespace}\\Http\\Requests\\{$model};

use {$namespace}\\Http\\Resources\\Models\\{$model}Resource;
use {$namespace}\\Models\\{$model};
use Illuminate\\Foundation\\Http\\FormRequest;
use Innoboxrr\\SearchSurge\\Search\\Builder;

class IndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        //
    }

    public function handle()
    {
        \$builder = new Builder;

        \$query = \$builder->get({$model}::class, \$this->all(){$options});

        return {$model}Resource::collection(\$query);
    }
}
PHP;
    }

    private static function filter(string $model, string $body, string $namespace = 'App\\Models\\Filters', string $uses = ''): string
    {
        return <<<PHP
<?php

namespace {$namespace}\\{$model};

use Innoboxrr\\SearchSurge\\Search\\Utils\\Managed;
{$uses}
class ManagedFilter extends Managed
{
    public static function canView(\$query, \$user, array \$args = [])
    {
{$body}
    }
}
PHP;
    }

    private static function model(string $model, string $body = '', string $namespace = 'App'): string
    {
        return <<<PHP
<?php

namespace {$namespace}\\Models;

use Illuminate\\Database\\Eloquent\\Casts\\Attribute;
use Illuminate\\Database\\Eloquent\\Model;

class {$model} extends Model
{
{$body}
}
PHP;
    }

    private static function resource(string $model, string $namespace = 'App'): string
    {
        return <<<PHP
<?php

namespace {$namespace}\\Http\\Resources\\Models;

use {$namespace}\\Models\\{$model};
use Illuminate\\Http\\Request;
use Illuminate\\Http\\Resources\\Json\\JsonResource;

/**
 * @mixin {$model}
 */
class {$model}Resource extends JsonResource
{
    public function toArray(Request \$request): array
    {
        if (\$request->has('appends') && is_array(\$request->get('appends'))) {
            \$this->resource->setAppends(\$request->get('appends'));
        }

        return parent::toArray(\$request);
    }
}
PHP;
    }

    // ADMIN-PATH-MISSING

    public function test_reporta_los_managed_filter_sin_camino_para_el_administrador(): void
    {
        // La plantilla: sólo `return $query;`. No restringe a nadie.
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::index('Post'));
        $this->write('app/Models/Post.php', self::model('Post'));
        $this->write('app/Models/Filters/Post/ManagedFilter.php', self::filter('Post', "        // Añadir restricciones de visibilidad\n\n        return \$query;"));

        // Acota siempre.
        $this->write('app/Http/Requests/Tag/IndexRequest.php', self::index('Tag'));
        $this->write('app/Models/Tag.php', self::model('Tag'));
        $this->write('app/Models/Filters/Tag/ManagedFilter.php', self::filter('Tag', "        return \$query->where('user_id', \$user->id);"));

        // Un ternario con `: $query` no es la rama del administrador.
        $this->write('app/Http/Requests/Book/IndexRequest.php', self::index('Book'));
        $this->write('app/Models/Book.php', self::model('Book'));
        $this->write('app/Models/Filters/Book/ManagedFilter.php', self::filter('Book', "        return \$query instanceof \\Illuminate\\Database\\Eloquent\\Builder\n            ? \$query->where('public', true)\n            : \$query;"));

        // can('viewAny', ...) con `return $query;` en su rama.
        $this->write('app/Http/Requests/Note/IndexRequest.php', self::index('Note'));
        $this->write('app/Models/Note.php', self::model('Note'));
        $this->write('app/Models/Filters/Note/ManagedFilter.php', self::filter('Note', "        if (\$user->can('viewAny', \\App\\Models\\Note::class)) {\n            return \$query;\n        }\n\n        return \$query->where('user_id', \$user->id);"));

        // Delega en un soporte del proyecto, que mira isAdmin() dos saltos más allá.
        $this->write('app/Support/Scope.php', <<<'PHP'
<?php

namespace App\Support;

class Scope
{
    public static function apply($query, $user, string $model)
    {
        if (self::bypasses($user)) {
            return $query->whereRaw('1 = 1');
        }

        return $query->where('scope_id', $user->scope_id);
    }

    public static function bypasses($user): bool
    {
        return $user->isAdmin();
    }
}
PHP);
        $this->write('app/Http/Requests/Comment/IndexRequest.php', self::index('Comment'));
        $this->write('app/Models/Comment.php', self::model('Comment'));
        $this->write('app/Models/Filters/Comment/ManagedFilter.php', self::filter('Comment', '        return Scope::apply($query, $user, \\App\\Models\\Comment::class);', uses: "use App\\Support\\Scope;\n"));

        // Un soporte que acota siempre: se sigue y se dice.
        $this->write('app/Support/Narrow.php', "<?php\n\nnamespace App\\Support;\n\nclass Narrow\n{\n    public static function apply(\$query, \$user)\n    {\n        return \$query->where('owner_id', \$user->id);\n    }\n}\n");
        $this->write('app/Http/Requests/Invoice/IndexRequest.php', self::index('Invoice'));
        $this->write('app/Models/Invoice.php', self::model('Invoice'));
        $this->write('app/Models/Filters/Invoice/ManagedFilter.php', self::filter('Invoice', '        return Narrow::apply($query, $user);', uses: "use App\\Support\\Narrow;\n"));

        // Otro con el mismo soporte: la respuesta sale de la memoria y lo dice igual.
        $this->write('app/Http/Requests/Reply/IndexRequest.php', self::index('Reply'));
        $this->write('app/Models/Reply.php', self::model('Reply'));
        $this->write('app/Models/Filters/Reply/ManagedFilter.php', self::filter('Reply', '        return Narrow::apply($query, $user);', uses: "use App\\Support\\Narrow;\n"));

        // Las opciones del Builder dicen dónde están los filtros.
        $this->write('app/Http/Requests/Item/IndexRequest.php', self::index('Item', options: "['filtersNamespace' => 'App\\\\Search\\\\Filters']"));
        $this->write('app/Models/Item.php', self::model('Item'));
        $this->write('app/Search/Filters/Item/ManagedFilter.php', self::filter('Item', "        return \$query->whereNull('archived_at');", 'App\\Search\\Filters'));

        // Sin ManagedFilter en ningún sitio.
        $this->write('app/Http/Requests/Orphan/IndexRequest.php', self::index('Orphan'));

        [$code, $report] = $this->fix(['index-managed'], ['--dry-run' => true]);

        // Sólo se reporta: no cambia el código de salida.
        $this->assertSame(Command::SUCCESS, $code);
        $entry = $report['checks']['index-managed'];
        $this->assertCount(9, $entry['fixed']);

        $missing = array_column($entry['admin_path_missing'], null, 'file');
        $this->assertSame([
            'app/Models/Filters/Book/ManagedFilter.php',
            'app/Models/Filters/Invoice/ManagedFilter.php',
            'app/Models/Filters/Reply/ManagedFilter.php',
            'app/Models/Filters/Tag/ManagedFilter.php',
            'app/Search/Filters/Item/ManagedFilter.php',
        ], array_keys($missing));
        $this->assertSame('App\Models\Tag', $missing['app/Models/Filters/Tag/ManagedFilter.php']['model']);
        $this->assertSame(['app/Http/Requests/Tag/IndexRequest.php'], $missing['app/Models/Filters/Tag/ManagedFilter.php']['requests']);
        $this->assertStringContainsString('`return $query;`', $missing['app/Models/Filters/Tag/ManagedFilter.php']['reason']);
        $this->assertStringContainsString('Narrow::apply()', $missing['app/Models/Filters/Invoice/ManagedFilter.php']['reason']);
        $this->assertStringContainsString('Narrow::apply()', $missing['app/Models/Filters/Reply/ManagedFilter.php']['reason']);

        $this->assertSame([[
            'file' => 'app/Http/Requests/Orphan/IndexRequest.php',
            'model' => 'App\Models\Orphan',
            'reason' => 'No se encontró su ManagedFilter en el proyecto. Se buscó: App\Models\Filters\Orphan\ManagedFilter.',
        ]], $entry['managed_filter_unresolved']);

        // Y en texto.
        $this->runCommand('larapack:fix', ['checks' => ['index-managed'], '--dry-run' => true]);
        $this->assertStringContainsString('5 ManagedFilter sin camino para el administrador', $this->lastOutput);
        $this->assertStringContainsString('app/Models/Filters/Tag/ManagedFilter.php (App\Models\Tag)', $this->lastOutput);
        $this->assertStringContainsString('sin ManagedFilter localizado  app/Http/Requests/Orphan/IndexRequest.php', $this->lastOutput);
    }

    public function test_tambien_mira_los_que_ya_estaban_bien(): void
    {
        $this->write('app/Http/Requests/Tag/IndexRequest.php', str_replace("        //\n", "        \$this->merge(['managed' => true]);\n", self::index('Tag')));
        $this->write('app/Models/Tag.php', self::model('Tag'));
        $this->write('app/Models/Filters/Tag/ManagedFilter.php', self::filter('Tag', "        return \$query->where('user_id', \$user->id);"));

        [, $report] = $this->fix(['index-managed']);

        $this->assertSame(1, $report['checks']['index-managed']['unchanged']);
        $this->assertSame('app/Models/Filters/Tag/ManagedFilter.php', $report['checks']['index-managed']['admin_path_missing'][0]['file']);
    }

    // APPENDABLE-PROPOSALS

    public function test_reconoce_las_formas_de_pedir_appends(): void
    {
        $text = <<<'JS'
axios.get(`/api/post/index?appends[]=full_name&appends[]=avatar_url`)
const a = { params: { appends: ['excerpt', "word_count"] } }
params.appends = ['reading_time']
fetch('/x?appends=one,two')
const b = { 'appends[]': 'cover' }
params.appends.push('late', 'later')
const c = { appends: someVariable }
JS;

        $this->assertSame([
            ['avatar_url', 1], ['full_name', 1],
            ['excerpt', 2], ['word_count', 2],
            ['reading_time', 3],
            ['one', 4], ['two', 4],
            ['cover', 5],
            ['late', 6], ['later', 6],
        ], AppendableProposals::requested($text));
    }

    public function test_propone_appendable_por_resource_sin_escribirlo(): void
    {
        $this->write('app/Models/Post.php', self::model('Post',
            "    public function getFullNameAttribute(): string\n    {\n        return '';\n    }\n\n    use \\App\\Models\\Traits\\HasAvatar;"));
        $this->write('app/Models/Traits/HasAvatar.php', "<?php\n\nnamespace App\\Models\\Traits;\n\nuse Illuminate\\Database\\Eloquent\\Casts\\Attribute;\n\ntrait HasAvatar\n{\n    protected function avatarUrl(): Attribute\n    {\n        return Attribute::get(fn () => '');\n    }\n}\n");
        $this->write('app/Http/Resources/Models/PostResource.php', self::resource('Post'));
        $this->write('app/Models/Tag.php', self::model('Tag'));
        $this->write('app/Http/Resources/Models/TagResource.php', self::resource('Tag'));

        $this->write('resources/react/posts/List.jsx', <<<'JS'
import axios from 'axios'

export function load() {
    return axios.get(route('api.app.post.index'), { params: { appends: ['full_name', 'avatar_url', 'typo_name'] } })
}
JS);
        $this->write('resources/js/tags.js', "const url = '/api/tags/index?appends[]=usage_count'\n");
        $this->write('resources/js/both.js', "get(route('api.app.post.index'))\nget(route('api.app.tag.index'), { params: { appends: ['mixed'] } })\n");
        $this->write('resources/js/nothing.js', "const x = { appends: ['lonely'] }\n");

        $before = $this->project->read('app/Http/Resources/Models/PostResource.php');

        [$code, $report] = $this->fix(['resource-appends'], ['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame($before, $this->project->read('app/Http/Resources/Models/PostResource.php'));

        $proposals = $report['checks']['resource-appends']['appendable_proposals'];
        $this->assertFalse($proposals['confirmed']);
        $this->assertSame(AppendableProposals::WARNING, $proposals['warning']);
        $this->assertSame([
            [
                'file' => 'app/Http/Resources/Models/PostResource.php',
                'model' => 'App\Models\Post',
                'accessors' => [
                    ['name' => 'avatar_url', 'exists' => true, 'sources' => ['resources/react/posts/List.jsx:4']],
                    ['name' => 'full_name', 'exists' => true, 'sources' => ['resources/react/posts/List.jsx:4']],
                    ['name' => 'typo_name', 'exists' => false, 'sources' => ['resources/react/posts/List.jsx:4']],
                ],
            ],
            [
                'file' => 'app/Http/Resources/Models/TagResource.php',
                'model' => 'App\Models\Tag',
                'accessors' => [
                    ['name' => 'usage_count', 'exists' => false, 'sources' => ['resources/js/tags.js:1']],
                ],
            ],
        ], $proposals['resources']);

        $loose = array_column($proposals['unassociated'], null, 'name');
        $this->assertSame(['mixed', 'lonely'], array_keys($loose));
        $this->assertSame('resources/js/both.js:2', $loose['mixed']['source']);
        $this->assertStringContainsString('varios modelos', $loose['mixed']['reason']);
        $this->assertSame('resources/js/nothing.js:1', $loose['lonely']['source']);

        // Con el arreglo escrito, $appendable sigue vacío.
        $this->fix(['resource-appends']);
        $this->assertStringContainsString('protected array $appendable = [];', $this->project->read('app/Http/Resources/Models/PostResource.php'));

        // Y en texto.
        $this->runCommand('larapack:fix', ['checks' => ['resource-appends'], '--dry-run' => true]);
        $this->assertStringContainsString('Propuestas para $appendable: Propuestas sin confirmar', $this->lastOutput);
        $this->assertStringContainsString('typo_name  no existe en el modelo  (resources/react/posts/List.jsx:4)', $this->lastOutput);
        $this->assertStringContainsString('sin asociar  lonely  resources/js/nothing.js:1', $this->lastOutput);
    }

    public function test_el_front_de_un_paquete_desempata_entre_resources_del_mismo_modelo(): void
    {
        $this->write('app/Models/Book.php', self::model('Book'));
        $this->write('app/Http/Resources/Models/BookResource.php', self::resource('Book'));
        $this->libraryPackage();
        $this->write('packages/library/src/Models/Book.php', self::model('Book', "    public function getTitleCaseAttribute(): string\n    {\n        return '';\n    }", 'Acme\Library'));
        $this->write('packages/library/src/Http/Resources/Models/BookResource.php', self::resource('Book', 'Acme\Library'));
        $this->write('packages/library/resources/react/books.jsx', "get(route('api.library.book.index'), { params: { appends: ['title_case'] } })\n");
        $this->write('resources/js/books.js', "get('/api/books', { params: { appends: ['root_only'] } })\n");

        [, $report] = $this->fix(['resource-appends'], ['--dry-run' => true]);
        $proposals = $report['checks']['resource-appends']['appendable_proposals'];

        $this->assertSame([], $proposals['unassociated']);
        $this->assertSame([
            [
                'file' => 'app/Http/Resources/Models/BookResource.php',
                'model' => 'App\Models\Book',
                'accessors' => [['name' => 'root_only', 'exists' => false, 'sources' => ['resources/js/books.js:1']]],
            ],
            [
                'file' => 'packages/library/src/Http/Resources/Models/BookResource.php',
                'model' => 'Acme\Library\Models\Book',
                'accessors' => [['name' => 'title_case', 'exists' => true, 'sources' => ['packages/library/resources/react/books.jsx:1']]],
            ],
        ], $proposals['resources']);
    }

    // --PACKAGE

    private function libraryPackage(): void
    {
        $this->write('packages/library/composer.json', (string) json_encode([
            'name' => 'acme/school-library',
            'autoload' => ['psr-4' => ['Acme\\Library\\' => 'src/']],
        ]));
    }

    public function test_package_acota_por_directorio_por_nombre_composer_o_al_nucleo(): void
    {
        $this->libraryPackage();
        $this->write('app/Http/Requests/Post/IndexRequest.php', self::index('Post'));
        $this->write('packages/library/src/Http/Requests/Book/IndexRequest.php', self::index('Book', 'Acme\Library'));
        $this->write('app/Http/Resources/Models/PostResource.php', self::resource('Post'));
        $this->write('packages/library/src/Http/Resources/Models/BookResource.php', self::resource('Book', 'Acme\Library'));

        $book = [['file' => 'packages/library/src/Http/Requests/Book/IndexRequest.php']];
        $post = [['file' => 'app/Http/Requests/Post/IndexRequest.php']];

        foreach (['library', 'acme/school-library', 'packages/library'] as $name) {
            [, $report] = $this->fix(['index-managed', 'resource-appends'], ['--package' => [$name], '--dry-run' => true]);

            $this->assertSame($book, array_map(fn (array $fixed): array => ['file' => $fixed['file']], $report['checks']['index-managed']['fixed']), $name);
            $this->assertSame(['packages/library/src/Http/Resources/Models/BookResource.php'], array_column($report['checks']['resource-appends']['fixed'], 'file'), $name);
        }

        [, $report] = $this->fix(['index-managed', 'resource-appends'], ['--package' => ['app'], '--dry-run' => true]);
        $this->assertSame($post, array_map(fn (array $fixed): array => ['file' => $fixed['file']], $report['checks']['index-managed']['fixed']));
        $this->assertSame(['app/Http/Resources/Models/PostResource.php'], array_column($report['checks']['resource-appends']['fixed'], 'file'));

        // Repetible: la unión.
        [, $report] = $this->fix(['index-managed'], ['--package' => ['app', 'library'], '--dry-run' => true]);
        $this->assertCount(2, $report['checks']['index-managed']['fixed']);

        // Con --path: la intersección.
        [, $report] = $this->fix(['index-managed'], ['--package' => ['app'], '--path' => 'packages/library', '--dry-run' => true]);
        $this->assertSame([], $report['checks']['index-managed']['fixed']);

        // Y no escribió nada.
        $this->assertSame(self::index('Post'), $this->project->read('app/Http/Requests/Post/IndexRequest.php'));
    }

    public function test_un_paquete_que_no_existe_falla_con_un_error_claro(): void
    {
        $this->libraryPackage();

        [$code, $report] = $this->fix(['index-managed'], ['--package' => ['nope']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('No hay un paquete local nope', $report['error']);
        $this->assertStringContainsString('library', $this->lastOutput);
    }

    public function test_con_package_correrlo_dos_veces_no_cambia_nada_la_segunda(): void
    {
        $this->libraryPackage();
        $this->write('packages/library/src/Http/Requests/Book/IndexRequest.php', self::index('Book', 'Acme\Library'));
        $this->write('packages/library/src/Http/Resources/Models/BookResource.php', self::resource('Book', 'Acme\Library'));
        $options = ['--package' => ['acme/school-library']];

        [, $first] = $this->fix(['index-managed', 'resource-appends'], $options);
        $files = [
            $this->project->read('packages/library/src/Http/Requests/Book/IndexRequest.php'),
            $this->project->read('packages/library/src/Http/Resources/Models/BookResource.php'),
        ];
        $this->assertCount(1, $first['checks']['index-managed']['fixed']);

        [$code, $second] = $this->fix(['index-managed', 'resource-appends'], $options);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame([], $second['checks']['index-managed']['fixed']);
        $this->assertSame(1, $second['checks']['index-managed']['unchanged']);
        $this->assertSame([], $second['checks']['resource-appends']['fixed']);
        $this->assertSame(1, $second['checks']['resource-appends']['unchanged']);
        $this->assertSame($files, [
            $this->project->read('packages/library/src/Http/Requests/Book/IndexRequest.php'),
            $this->project->read('packages/library/src/Http/Resources/Models/BookResource.php'),
        ]);
    }

    // SÓLO LO QUE VIENE DEL GENERADOR

    public function test_un_index_request_con_logica_propia_alrededor_no_pierde_nada(): void
    {
        $custom = <<<'PHP'
<?php

namespace App\Http\Requests\Post;

use App\Http\Resources\Models\PostResource;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Innoboxrr\SearchSurge\Search\Builder;

/**
 * Lista los posts de la escuela actual.
 */
class IndexRequest extends FormRequest
{
    private const PER_PAGE = 25;

    protected function prepareForValidation(): void
    {
        // Lo nuestro: siempre paginado y en la escuela de la sesión.
        $this->merge(['paginate' => self::PER_PAGE]);
        $this->mergeIfMissing(['school_id' => session('school_id')]);
    }

    public function authorize(): bool
    {
        return $this->user()->can('index', Post::class) && $this->schoolIsOpen();
    }

    public function rules(): array
    {
        return ['school_id' => ['required', 'integer']];
    }

    public function handle()
    {
        $builder = new Builder;

        $query = $builder->get(Post::class, $this->all(), ['with' => ['author']]);

        event('posts.listed', [$this->user()]);

        return PostResource::collection($query);
    }

    private function schoolIsOpen(): bool
    {
        return true;
    }
}
PHP;
        $this->write('app/Http/Requests/Post/IndexRequest.php', $custom);

        [$code, $report] = $this->fix(['index-managed']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertCount(1, $report['checks']['index-managed']['fixed']);

        $fixed = $this->project->read('app/Http/Requests/Post/IndexRequest.php');

        // Lo único nuevo es el bloque de la plantilla al principio del método.
        $snippet = implode("\n", array_map(fn (string $line): string => '        '.$line, LegacyFix::MANAGED_LINES));
        $this->assertSame(
            str_replace("    {\n        // Lo nuestro:", "    {\n".$snippet."\n\n        // Lo nuestro:", $custom),
            $fixed
        );
    }
}
