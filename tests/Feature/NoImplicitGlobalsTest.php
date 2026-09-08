<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El codigo generado no puede depender de cosas que nadie declara.
 *
 * Durante mucho tiempo los stubs Vue usaron <BreadcrumbsComponent> y
 * <DropdownButtonComponent>, que no estaban definidos en ningun paquete del
 * ecosistema: el modulo solo compilaba si la aplicacion anfitriona los habia
 * registrado globalmente por su cuenta, y nada —ni un import, ni una
 * peerDependency, ni la documentacion— lo decia. La rama React ni siquiera
 * tenia migas de pan, asi que ademas las dos ramas divergian.
 *
 * Estos tests fijan las dos condiciones: que los componentes existan dentro
 * del modulo, y que ninguna plantilla vuelva a invocar un global fantasma.
 */
final class NoImplicitGlobalsTest extends TestCase
{
    /**
     * Componentes que en algun momento se usaron sin definirlos en ninguna
     * parte. Si uno reaparece, es que hemos vuelto al patron implicito.
     */
    private const PHANTOM_COMPONENTS = [
        'BreadcrumbsComponent',
        'DropdownButtonComponent',
    ];

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

    public function test_ningun_stub_invoca_un_componente_global_fantasma(): void
    {
        $offenders = [];

        foreach ($this->stubFiles() as $file) {
            $contents = $this->withoutComments((string) file_get_contents($file));

            foreach (self::PHANTOM_COMPONENTS as $component) {
                if (str_contains($contents, "<{$component}")) {
                    $offenders[] = basename(dirname($file)) . '/' . basename($file) . " usa <{$component}>";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_ningun_stub_depende_de_uikit(): void
    {
        // UIkit no lo declaraba ningún package.json del ecosistema: se
        // consumía como CSS global del anfitrión, así que un módulo generado
        // solo se veía bien dentro de una aplicación que ya lo trajera. La
        // maquetación sale ahora del sistema de diseño de form-core.
        $offenders = [];

        foreach ($this->stubFiles() as $file) {
            $contents = $this->withoutComments((string) file_get_contents($file));

            if (preg_match_all('/\buk-[a-z0-9@-]+/', $contents, $matches)) {
                $offenders[] = basename(dirname($file)) . '/' . basename($file)
                    . ': ' . implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_ningun_stub_usa_clases_de_font_awesome(): void
    {
        // Font Awesome tampoco lo declaraba ningún package.json: llegaba
        // porque `uikit-custom-icons` lo exigía y la app lo cargaba por su
        // cuenta. Los iconos salen ahora del mapa de innoboxrr-form-core, que
        // resuelve un nombre semántico contra la colección que elija el
        // proyecto.
        $offenders = [];

        foreach ($this->stubFiles() as $file) {
            $contents = $this->withoutComments((string) file_get_contents($file));

            if (preg_match_all('/\bfa[srlbd]?-[a-z0-9-]+/', $contents, $matches)) {
                $offenders[] = basename(dirname($file)) . '/' . basename($file)
                    . ': ' . implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_los_iconos_se_piden_por_nombre_semantico(): void
    {
        // El contrato del modelo es el mismo archivo para Vue y para React, y
        // es donde se declaran las acciones del CRUD.
        $contract = $this->read('resources/vue/src/models/post/index.js');

        $this->assertStringContainsString("icon: 'plus'", $contract);
        $this->assertStringContainsString("icon: 'download'", $contract);

        // Los de SweetAlert no son nuestros: 'warning' es su propio
        // vocabulario y tiene que seguir llegándole tal cual.
        $this->assertStringContainsString("icon: 'warning'", $contract);
    }

    public function test_las_dos_ramas_generan_los_componentes_compartidos(): void
    {
        foreach ([
            'resources/vue/src/components/Breadcrumbs.vue',
            'resources/vue/src/components/ActionMenu.vue',
            'resources/react/src/components/Breadcrumbs.jsx',
            'resources/react/src/components/ActionMenu.jsx',
        ] as $file) {
            $this->assertFileExists(
                $this->project->path . '/' . $file,
                "Falta {$file}: sin el, el modulo generado vuelve a depender de un componente que la app tiene que registrar por su cuenta."
            );
        }
    }

    public function test_las_migas_estan_en_las_mismas_vistas_en_vue_y_react(): void
    {
        $vistas = ['AdminView', 'CreateView', 'ShowView'];

        foreach ($vistas as $vista) {
            $vue = $this->read("resources/vue/src/models/post/views/{$vista}.vue");
            $react = $this->read("resources/react/src/models/post/views/{$vista}.jsx");

            $this->assertStringContainsString('<Breadcrumbs', $vue, "La vista Vue {$vista} no pinta migas.");
            $this->assertStringContainsString('<Breadcrumbs', $react, "La vista React {$vista} no pinta migas, y la de Vue si.");
        }
    }

    public function test_las_acciones_del_registro_usan_el_mismo_componente_en_ambas_ramas(): void
    {
        $this->assertStringContainsString(
            '<ActionMenu',
            $this->read('resources/vue/src/models/post/widgets/ModelCard.vue')
        );

        $this->assertStringContainsString(
            '<ActionMenu',
            $this->read('resources/react/src/models/post/widgets/ModelCard.jsx')
        );
    }

    public function test_los_componentes_compartidos_se_estilizan_desde_el_tema(): void
    {
        // Si volvieran a llevar las clases incrustadas, cambiar el aspecto del
        // ecosistema dejaria de ser un cambio en el tema.
        foreach ([
            'resources/vue/src/components/Breadcrumbs.vue',
            'resources/vue/src/components/ActionMenu.vue',
            'resources/react/src/components/Breadcrumbs.jsx',
            'resources/react/src/components/ActionMenu.jsx',
        ] as $file) {
            $contents = $this->read($file);

            $this->assertStringContainsString('innoboxrr-form-core', $contents, "{$file} no lee el tema.");
            $this->assertStringContainsString('classFor(', $contents, "{$file} no usa classFor.");
        }
    }

    /**
     * Los componentes nuevos explican en su cabecera *por que* sustituyen a
     * los fantasmas, asi que los nombran. Mencionarlos en un comentario no es
     * usarlos: lo que se busca es la invocacion real.
     */
    private function withoutComments(string $contents): string
    {
        return (string) preg_replace(
            ['/<!--.*?-->/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'],
            '',
            $contents
        );
    }

    /**
     * @return array<int, string>
     */
    private function stubFiles(): array
    {
        $stubs = dirname(__DIR__, 2) . '/src/Stubs';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stubs, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            // .txt entra a proposito: las plantillas de PHP tambien emiten
            // nombres de icono, y mirar solo .vue y .jsx dejo pasar que el
            // Resource siguiera emitiendo 'fa-eye' despues de la migracion.
            if (in_array($file->getExtension(), ['vue', 'jsx', 'js', 'txt'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function read(string $relative): string
    {
        $path = $this->project->path . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
