<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class JsonImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport.json',
            ]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_todo_el_php_importado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    public function test_inyecta_las_reglas_de_validacion_del_json(): void
    {
        $create = $this->project->read('src/Http/Requests/Post/CreateRequest.php');

        $this->assertStringContainsString("'title' => 'required|string|max:255'", $create);
        $this->assertStringContainsString("'user_id' => 'required|exists:users,id'", $create);
        $this->assertStringContainsString('public function rules(): array', $create);
    }

    public function test_update_request_conserva_la_regla_del_id_que_su_handle_necesita(): void
    {
        $update = $this->project->read('src/Http/Requests/Post/UpdateRequest.php');

        // authorize() y handle() hacen findOrFail($this->post_id): si la
        // inyección de reglas borra esa clave, findOrFail recibe null.
        $this->assertStringContainsString("'post_id'", $update);
        $this->assertStringContainsString('$this->post_id', $update);
    }

    public function test_no_toca_las_reglas_de_los_demas_requests(): void
    {
        $show = $this->project->read('src/Http/Requests/Post/ShowRequest.php');

        $this->assertStringContainsString("'post_id' => 'required|numeric'", $show);
        $this->assertStringContainsString('Rule::in(Post::$loadable_relations)', $show);
    }

    public function test_traslada_los_casts_al_metodo_casts(): void
    {
        $model = $this->project->read('src/Models/Post.php');

        $this->assertStringContainsString('protected function casts(): array', $model);
        $this->assertMatchesRegularExpression(
            "/casts\(\): array.*'payload' => 'json'.*'published' => 'boolean'/s",
            $model
        );
    }

    public function test_traslada_fillable_y_columnas_de_exportacion(): void
    {
        $model = $this->project->read('src/Models/Post.php');

        $this->assertStringContainsString("'title', 'payload', 'published', 'user_id'", $model);
        // user_id tiene exports_cols false, así que no debe aparecer ahí.
        $this->assertMatchesRegularExpression(
            "/\\\$export_cols = \[\s*'title', 'payload', 'published'\s*\]/",
            $model
        );
    }

    public function test_genera_las_columnas_de_la_migracion(): void
    {
        $migration = $this->project->glob('database/migrations/*_create_posts_table.php');

        $this->assertNotNull($migration, 'No se generó la migración de posts.');

        $contents = $this->project->read($migration);

        $this->assertStringContainsString("\$table->string('title');", $contents);
        $this->assertStringContainsString("\$table->longText('payload')->nullable();", $contents);
        $this->assertStringContainsString("\$table->boolean('published')->default(false);", $contents);
        $this->assertStringContainsString("\$table->foreignId('user_id')->constrained('users')", $contents);
    }

    public function test_genera_el_trait_de_relaciones(): void
    {
        $relations = $this->project->read('src/Models/Traits/Relations/PostRelations.php');

        $this->assertStringContainsString('use App\Models\User;', $relations);
        $this->assertStringContainsString('public function user()', $relations);
        $this->assertStringContainsString('return $this->belongsTo(User::class);', $relations);
    }
}
