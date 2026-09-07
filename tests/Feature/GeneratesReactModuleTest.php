<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class GeneratesReactModuleTest extends TestCase
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

    public function test_genera_el_modulo_react_completo(): void
    {
        foreach ([
            'resources/react/package.json',
            'resources/react/vite.config.js',
            'resources/react/index.js',
            'resources/react/src/routes/index.js',
            'resources/react/src/theme.js',
            self::REACT . '/index.js',
            self::REACT . '/store/index.js',
            self::REACT . '/routes/index.js',
            self::REACT . '/forms/CreateForm.jsx',
            self::REACT . '/forms/EditForm.jsx',
            self::REACT . '/forms/FilterForm.jsx',
            self::REACT . '/views/AdminView.jsx',
            self::REACT . '/views/CreateView.jsx',
            self::REACT . '/views/EditView.jsx',
            self::REACT . '/views/ShowView.jsx',
            self::REACT . '/widgets/DataTable.jsx',
            self::REACT . '/widgets/ModelCard.jsx',
            self::REACT . '/widgets/ModelProfile.jsx',
        ] as $expected) {
            $this->assertGenerated($expected);
        }
    }

    /**
     * Es el punto entero de la paridad: `models/<entidad>/index.js` son
     * funciones puras y llamadas HTTP, sin nada de un framework de UI. Si cada
     * framework tuviera su copia, no habria un contrato: habria dos.
     */
    public function test_el_contrato_es_literalmente_el_mismo_archivo(): void
    {
        $this->assertSame(
            $this->project->read(self::VUE . '/index.js'),
            $this->project->read(self::REACT . '/index.js'),
            'El contrato del modelo difiere entre Vue y React.'
        );
    }

    public function test_los_dos_modulos_tienen_la_misma_estructura(): void
    {
        $this->assertSame(
            ['forms/CreateForm', 'forms/EditForm', 'forms/FilterForm', 'index', 'routes/index',
                'store/index', 'views/AdminView', 'views/CreateView', 'views/EditView', 'views/ShowView',
                'widgets/DataTable', 'widgets/ModelCard', 'widgets/ModelProfile'],
            $this->moduleShape(self::VUE)
        );

        $this->assertSame($this->moduleShape(self::VUE), $this->moduleShape(self::REACT));
    }

    public function test_el_store_es_zustand_y_expone_lo_mismo_que_el_de_pinia(): void
    {
        $store = $this->project->read(self::REACT . '/store/index.js');

        $this->assertStringContainsString("import { create } from 'zustand'", $store);
        $this->assertStringContainsString('export const usePostStore', $store);
        $this->assertStringNotContainsString('defineStore', $store);

        // La misma superficie que el store de Vue del mismo modelo.
        foreach (['fetchIndex', 'fetchOne', 'fetchPolicies', 'create', 'update', 'remove', 'reset', 'can'] as $action) {
            $this->assertStringContainsString("{$action}:", $store, "El store React no expone {$action}.");
        }
    }

    public function test_no_deja_marcadores_sin_sustituir(): void
    {
        foreach ($this->generatedReactFiles() as $relative) {
            $contents = $this->project->read($relative);

            foreach ([
                '//import_more_components//',
                '//form_fields//',
                '//submit_data//',
                '//props//',
                '//DATA_TABLE_COLUMNS//',
                '//DATA_TABLE_SORT//',
                '{/* Add more inputs */}',
            ] as $marker) {
                $this->assertStringNotContainsString($marker, $contents, "{$relative} conserva el marcador {$marker}.");
            }
        }
    }

    public function test_construye_los_inputs_declarados_en_el_json(): void
    {
        $create = $this->project->read(self::REACT . '/forms/CreateForm.jsx');

        // title -> TextInputComponent, con el contrato value/onChange.
        $this->assertStringContainsString('<TextInputComponent', $create);
        $this->assertStringContainsString("value={form.title ?? ''}", $create);
        $this->assertStringContainsString("onChange={(value) => setField('title', value)}", $create);

        // published -> SelectInputComponent, importado porque no viene por defecto.
        $this->assertStringContainsString('<SelectInputComponent', $create);
        $this->assertStringContainsString('    SelectInputComponent,', $create);

        // user_id: form false + form_submit true -> parametro con valor por
        // defecto, que es como React declara una prop.
        $this->assertStringContainsString('    user_id = null,', $create);
        $this->assertStringContainsString('user_id: user_id,', $create);
        $this->assertStringContainsString('title: form.title,', $create);
    }

    public function test_no_queda_nada_de_vue_en_el_modulo_react(): void
    {
        foreach ($this->generatedReactFiles() as $relative) {
            $contents = $this->project->read($relative);

            foreach (['<script setup>', 'v-model', 'defineProps', "from 'vue'", 'innoboxrr-form-elements'] as $vueism) {
                $this->assertStringNotContainsString(
                    $vueism,
                    $contents,
                    "{$relative} arrastra algo de Vue: {$vueism}."
                );
            }
        }
    }

    /**
     * El prefijo tiene que reconstruir el `->as('api.dotNamespace<archivo>.')`
     * del RouteServiceProvider, igual que en Vue: es el mismo archivo.
     */
    public function test_el_prefijo_de_rutas_coincide_con_el_del_backend(): void
    {
        $this->assertStringContainsString(
            "export const API_ROUTE_PREFIX = 'api.acme.blog.post.'",
            $this->project->read(self::REACT . '/index.js')
        );
    }

    /**
     * Las vistas resuelven a donde navegar por el nombre de la ruta, que es lo
     * que declara el contrato. Un mapa nombre->ruta escrito a mano mentiria en
     * cuanto el anfitrion montara el modulo en otro prefijo.
     */
    public function test_navega_por_nombre_de_ruta_y_no_por_una_ruta_escrita_a_mano(): void
    {
        $create = $this->project->read(self::REACT . '/views/CreateView.jsx');

        $this->assertStringContainsString("import { buildPath } from 'innoboxrr-react-datatable'", $create);
        $this->assertStringContainsString("buildPath('AdminShowPost', { id: post.id })", $create);

        $routes = $this->project->read(self::REACT . '/routes/index.js');

        $this->assertStringContainsString("id: 'AdminShowPost',", $routes);
        $this->assertStringNotContainsString('routeNames', $routes);
    }

    public function test_el_agregador_registra_los_nombres_que_declara_el_arbol(): void
    {
        $aggregator = $this->project->read('resources/react/src/routes/index.js');

        $this->assertStringContainsString("import { registerRoutes } from 'innoboxrr-react-datatable'", $aggregator);
        $this->assertStringContainsString('export const routeNamesOf', $aggregator);
        $this->assertStringContainsString('export const registerModuleRoutes', $aggregator);
    }

    public function test_no_espera_ninguna_clase_de_un_global(): void
    {
        foreach ($this->generatedReactFiles() as $relative) {
            $contents = $this->project->read($relative);

            foreach (['inputClass', 'buttonClass'] as $global) {
                $this->assertStringNotContainsString(
                    $global,
                    $contents,
                    "{$relative} sigue esperando {$global} de un global."
                );
            }
        }
    }

    public function test_el_tema_del_paquete_se_declara_una_vez(): void
    {
        $theme = $this->project->read('resources/react/src/theme.js');

        $this->assertStringContainsString("import { setTheme } from 'innoboxrr-form-core'", $theme);
        $this->assertStringContainsString('setTheme({', $theme);
    }

    public function test_los_inputs_generados_no_llevan_clases(): void
    {
        $create = $this->project->read(self::REACT . '/forms/CreateForm.jsx');

        $this->assertStringNotContainsString('customClass', $create);
        $this->assertStringContainsString('<TextInputComponent', $create);
    }

    public function test_el_boton_de_reiniciar_filtros_usa_la_variante_secundaria(): void
    {
        $filter = $this->project->read(self::REACT . '/forms/FilterForm.jsx');

        $this->assertStringContainsString('variant="secondary"', $filter);
        $this->assertStringNotContainsString('bg-gray-400', $filter);
    }

    public function test_el_package_json_del_modulo_es_valido(): void
    {
        $package = json_decode($this->project->read('resources/react/package.json'), true);

        $this->assertIsArray($package, 'El package.json generado no es JSON válido.');
        $this->assertSame('acme-blog-react', $package['name']);

        foreach (['react', 'react-dom', 'react-router-dom', 'zustand'] as $peer) {
            $this->assertArrayHasKey($peer, $package['peerDependencies']);
        }

        $this->assertArrayHasKey('innoboxrr-react-form-elements', $package['dependencies']);
        $this->assertArrayHasKey('innoboxrr-react-datatable', $package['dependencies']);
    }

    /**
     * Los stubs son texto plano: un error de sintaxis solo aparece al abrir el
     * archivo en el proyecto destino. Esto lo comprueba de verdad cuando hay
     * con que; si no, se marca como omitido en lugar de fingir que paso.
     */
    public function test_todo_el_jsx_generado_compila(): void
    {
        $esbuild = $this->esbuild();

        if ($esbuild === null) {
            $this->markTestSkipped('esbuild no está disponible; no se puede comprobar la sintaxis del JSX.');
        }

        $errors = [];

        foreach ($this->generatedReactFiles() as $relative) {
            $command = escapeshellarg($esbuild)
                . ' --loader:.jsx=jsx --loader:.js=jsx '
                . escapeshellarg($this->project->path . '/' . $relative)
                . ' --outfile=' . escapeshellarg($this->nullDevice());

            exec($command . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                $errors[] = "{$relative}: " . trim(implode(' ', $output));
            }
        }

        $this->assertSame([], $errors, "JSX generado con errores de sintaxis:\n" . implode("\n", $errors));
    }

    private function esbuild(): ?string
    {
        $candidates = [
            dirname(__DIR__, 4) . '/npm/react-form-elements/node_modules/.bin/esbuild.cmd',
            dirname(__DIR__, 4) . '/npm/react-form-elements/node_modules/.bin/esbuild',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }

    /**
     * Los archivos del modulo, sin extension, para poder comparar la forma de
     * los dos modulos aunque uno acabe en .vue y el otro en .jsx.
     *
     * @return array<int, string>
     */
    private function moduleShape(string $module): array
    {
        $files = [];
        $prefix = strlen($this->project->path . '/' . $module) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->project->path . '/' . $module,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), $prefix));

            $files[] = preg_replace('/\.(vue|jsx|js)$/', '', $relative);
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function generatedReactFiles(): array
    {
        $files = [];
        $prefix = strlen($this->project->path) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->project->path . '/resources/react',
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['jsx', 'js'], true)) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), $prefix));
            }
        }

        sort($files);

        return $files;
    }
}
