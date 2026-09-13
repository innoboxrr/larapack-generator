<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Las vistas generadas se usan como una aplicación de escritorio, y lo hacen
 * igual en Vue y en React.
 *
 * Antes cada alta o edición era una página: se perdía la tabla de vista, al
 * volver se remontaba desde la primera página y sin filtros, y nada le decía
 * al usuario que lo que hizo había salido bien. Estas pruebas fijan el nuevo
 * reparto en las dos ramas a la vez, para que una no se quede atrás.
 */
final class GeneratedDesktopUiTest extends TestCase
{
    private const VUE = 'resources/vue/src/models/post';

    private const REACT = 'resources/react/src/models/post';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport.json',
                '--vue' => true,
                '--react' => true,
            ]),
            "larapack:import --vue --react terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_el_alta_se_abre_en_un_drawer_sobre_la_tabla(): void
    {
        foreach ([self::VUE . '/views/AdminView.vue', self::REACT . '/views/AdminView.jsx'] as $file) {
            $view = $this->read($file);

            $this->assertStringContainsString('<DrawerComponent', $view, "{$file} no abre el alta en un drawer.");
            $this->assertStringContainsString("'AdminCreatePost'", $view);
            $this->assertStringContainsString('refresh()', $view, "{$file} no recarga la tabla tras el alta.");
            $this->assertStringNotContainsString('crudKey', $view, "{$file} sigue remontando la tabla para recargarla.");
        }
    }

    public function test_la_edicion_se_abre_en_un_drawer_sobre_la_ficha(): void
    {
        foreach ([self::VUE . '/views/ShowView.vue', self::REACT . '/views/ShowView.jsx'] as $file) {
            $view = $this->read($file);

            $this->assertStringContainsString('<DrawerComponent', $view, "{$file} no abre la edición en un drawer.");
            $this->assertStringContainsString("'AdminEditPost'", $view);
        }
    }

    /**
     * Quien abre el drawer decide qué pasa al guardar. Si el formulario
     * navegara por su cuenta, el drawer se quedaría abierto sobre otra página.
     */
    public function test_el_alta_y_la_edicion_avisan_en_vez_de_navegar(): void
    {
        foreach (['CreateView', 'EditView'] as $view) {
            $vue = $this->read(self::VUE . "/views/{$view}.vue");
            $react = $this->read(self::REACT . "/views/{$view}.jsx");

            $this->assertStringContainsString("emit('updateData'", $vue);
            $this->assertStringNotContainsString('useRouter', $vue, "La vista Vue {$view} sigue navegando.");

            $this->assertStringContainsString('onUpdateData?.(', $react);
            $this->assertStringNotContainsString('useNavigate', $react, "La vista React {$view} sigue navegando.");
        }
    }

    public function test_el_detalle_ensena_la_forma_del_registro_mientras_llega(): void
    {
        foreach ([self::VUE . '/views/ShowView.vue', self::REACT . '/views/ShowView.jsx'] as $file) {
            $view = $this->read($file);

            $this->assertStringContainsString('SkeletonComponent', $view, "{$file} se queda en blanco mientras carga.");
            $this->assertStringContainsString('aria-busy', $view);
        }
    }

    public function test_la_tabla_se_puede_recargar_desde_fuera(): void
    {
        $this->assertStringContainsString('defineExpose', $this->read(self::VUE . '/widgets/DataTable.vue'));
        $this->assertStringContainsString('ref={ref}', $this->read(self::REACT . '/widgets/DataTable.jsx'));
    }

    public function test_el_indice_tiene_paleta_de_comandos(): void
    {
        $this->assertStringContainsString('<CommandPaletteComponent', $this->read(self::VUE . '/views/AdminView.vue'));
        $this->assertStringContainsString('<CommandPaletteComponent', $this->read(self::REACT . '/views/AdminView.jsx'));
    }

    public function test_las_acciones_del_registro_son_un_menu_de_verdad(): void
    {
        $this->assertStringContainsString('<MenuComponent', $this->read('resources/vue/src/components/ActionMenu.vue'));
        $this->assertStringContainsString('<MenuComponent', $this->read('resources/react/src/components/ActionMenu.jsx'));
    }

    /**
     * Crear, guardar y borrar dicen que salió bien. Cancelar una confirmación
     * no es un error, y no se avisa como tal.
     */
    public function test_crear_guardar_y_borrar_avisan_al_usuario(): void
    {
        foreach (['vue' => 'vue', 'react' => 'jsx'] as $ui => $extension) {
            $base = "resources/{$ui}/src/models/post";

            $this->assertStringContainsString("notifySuccess(t('Record created'))", $this->read("{$base}/views/AdminView.{$extension}"));
            $this->assertStringContainsString("notifySuccess(t('Changes saved'))", $this->read("{$base}/views/ShowView.{$extension}"));

            $card = $this->read("{$base}/widgets/ModelCard.{$extension}");

            $this->assertStringContainsString("notifySuccess(t('Record deleted'))", $card);
            $this->assertStringContainsString("'RequestCancelledError'", $card);
        }
    }

    private function read(string $relative): string
    {
        $path = $this->project->path . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
