<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Un `laraimport` describe un conjunto de modelos que viven juntos. Hasta
 * ahora el trait de relaciones importaba siempre desde `App\Models`, así que
 * en modo paquete el `use` apuntaba a una clase que no existía y el modelo
 * generado reventaba en el primer acceso a la relación.
 */
final class ResolvesRelationNamespacesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport-relations.json',
            ]),
            "larapack:import terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_todo_el_php_importado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    public function test_un_modelo_del_mismo_archivo_se_importa_desde_el_paquete(): void
    {
        $relations = $this->project->read('src/Models/Traits/Relations/PostRelations.php');

        $this->assertStringContainsString('use Acme\Blog\Models\Category;', $relations);
        $this->assertStringNotContainsString('use App\Models\Category;', $relations);
    }

    public function test_un_modelo_ajeno_sigue_resolviendo_a_la_aplicacion(): void
    {
        $relations = $this->project->read('src/Models/Traits/Relations/PostRelations.php');

        $this->assertStringContainsString('use App\Models\User;', $relations);
    }

    public function test_respeta_el_namespace_declarado_a_mano(): void
    {
        $relations = $this->project->read('src/Models/Traits/Relations/PostRelations.php');

        $this->assertStringContainsString('use Acme\Tenancy\Tenant;', $relations);
    }

    /**
     * Importarse a sí mismo es un error de compilación en PHP, y una relación
     * recursiva (`parent`) es de lo más normal.
     */
    public function test_no_se_importa_a_si_mismo(): void
    {
        $relations = $this->project->read('src/Models/Traits/Relations/PostRelations.php');

        $this->assertStringNotContainsString('use Acme\Blog\Models\Post;', $relations);
        $this->assertStringContainsString('public function parent()', $relations);
        $this->assertStringContainsString('return $this->belongsTo(Post::class);', $relations);
    }

    /**
     * La relación inversa se resuelve igual: Category vive en el paquete, así
     * que su `use Post` también.
     */
    public function test_la_relacion_inversa_resuelve_igual(): void
    {
        $relations = $this->project->read('src/Models/Traits/Relations/CategoryRelations.php');

        $this->assertStringContainsString('use Acme\Blog\Models\Post;', $relations);
        $this->assertStringContainsString('public function posts()', $relations);
    }
}
