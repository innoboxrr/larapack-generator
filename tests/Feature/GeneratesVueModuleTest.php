<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class GeneratesVueModuleTest extends TestCase
{
    private const MODULE = 'resources/vue/src/models/post';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport.json',
                '--vue' => true,
            ]),
            "larapack:import --vue terminó con error:\n" . $this->lastOutput
        );
    }

    /**
     * Antes ModelViewTool solo corría si el destino era una aplicación, así
     * que en un paquete `--vue` no generaba nada y no avisaba.
     */
    public function test_genera_el_modulo_tambien_en_un_paquete(): void
    {
        foreach ([
            'resources/vue/package.json',
            'resources/vue/vite.config.js',
            'resources/vue/index.js',
            'resources/vue/src/routes/index.js',
            self::MODULE . '/index.js',
            self::MODULE . '/store/index.js',
            self::MODULE . '/routes/index.js',
            self::MODULE . '/forms/CreateForm.vue',
            self::MODULE . '/forms/EditForm.vue',
            self::MODULE . '/forms/FilterForm.vue',
            self::MODULE . '/views/AdminView.vue',
            self::MODULE . '/views/CreateView.vue',
            self::MODULE . '/views/EditView.vue',
            self::MODULE . '/views/ShowView.vue',
            self::MODULE . '/widgets/DataTable.vue',
            self::MODULE . '/widgets/ModelCard.vue',
            self::MODULE . '/widgets/ModelProfile.vue',
        ] as $expected) {
            $this->assertGenerated($expected);
        }
    }

    public function test_todos_los_componentes_usan_composition_api(): void
    {
        foreach (glob($this->project->path . '/' . self::MODULE . '/**/*.vue') as $file) {
            $contents = file_get_contents($file);
            $name = basename($file);

            $this->assertStringContainsString('<script setup>', $contents, "{$name} no usa <script setup>.");
            $this->assertStringNotContainsString('export default {', $contents, "{$name} sigue en Options API.");
            $this->assertStringNotContainsString('this.$', $contents, "{$name} usa `this.\$` (API de instancia).");
        }
    }

    public function test_el_store_es_pinia_y_no_vuex(): void
    {
        $store = $this->project->read(self::MODULE . '/store/index.js');

        $this->assertStringContainsString('defineStore', $store);
        $this->assertStringContainsString('usePostStore', $store);
        $this->assertStringNotContainsString('namespaced', $store);
        $this->assertStringNotContainsString('mutations', $store);

        $this->assertFalse(
            $this->project->has(self::MODULE . '/vuex/postModel.js'),
            'Se sigue generando el módulo Vuex huérfano.'
        );
    }

    /**
     * El prefijo tiene que reconstruir el `->as('api.dotNamespace<archivo>.')`
     * del RouteServiceProvider. El stub anterior emitía 'api.post.', sin el
     * namespace, y había que corregirlo a mano en cada modelo.
     */
    public function test_el_prefijo_de_rutas_coincide_con_el_del_backend(): void
    {
        $module = $this->project->read(self::MODULE . '/index.js');

        $this->assertStringContainsString(
            "export const API_ROUTE_PREFIX = 'api.acme.blog.post.'",
            $module
        );
    }

    public function test_no_deja_marcadores_sin_sustituir(): void
    {
        foreach ($this->generatedModuleFiles() as $relative) {
            $contents = $this->project->read($relative);

            foreach ([
                '//import_more_components//',
                '//form_fields//',
                '//submit_data//',
                '//props//',
                '//DATA_TABLE_COLUMNS//',
                '//DATA_TABLE_SORT//',
                '<!-- Add more inputs -->',
            ] as $marker) {
                $this->assertStringNotContainsString($marker, $contents, "{$relative} conserva el marcador {$marker}.");
            }
        }
    }

    public function test_construye_los_inputs_declarados_en_el_json(): void
    {
        $create = $this->project->read(self::MODULE . '/forms/CreateForm.vue');

        // title -> TextInputComponent
        $this->assertStringContainsString('<TextInputComponent', $create);
        $this->assertStringContainsString('v-model="form.title"', $create);

        // published -> SelectInputComponent, importado porque no viene por defecto
        $this->assertStringContainsString('<SelectInputComponent', $create);
        $this->assertStringContainsString('        SelectInputComponent,', $create);
        $this->assertStringContainsString('v-model="form.published"', $create);

        // user_id: form false + form_submit true -> prop real, no cadena vacía
        $this->assertMatchesRegularExpression(
            "/user_id: \{\s*type: \[String, Number\],\s*default: null,\s*\}/",
            $create
        );
        $this->assertStringContainsString('user_id: props.user_id,', $create);
        $this->assertStringContainsString('title: form.title,', $create);
    }

    /**
     * Un form_component que no existe en innoboxrr-form-elements es una errata
     * del JSON. Emitir el import igualmente rompia el build del modulo entero.
     */
    public function test_un_componente_inexistente_no_se_importa(): void
    {
        $create = $this->project->read(self::MODULE . '/forms/CreateForm.vue');

        $this->assertStringNotContainsString('NoExisteComponent', $create);
        $this->assertStringContainsString(
            '<!-- status: declara form_component en el JSON de importacion -->',
            $create
        );
    }

    public function test_la_tabla_ordena_por_una_columna_que_muestra(): void
    {
        $module = $this->project->read(self::MODULE . '/index.js');

        $this->assertStringContainsString("id: 'title',", $module);
        $this->assertStringContainsString("    title: 'asc',", $module);
        // payload no es columna de tabla en el fixture.
        $this->assertStringNotContainsString("id: 'payload',", $module);
    }

    public function test_el_package_json_del_modulo_es_valido(): void
    {
        $package = json_decode($this->project->read('resources/vue/package.json'), true);

        $this->assertIsArray($package, 'El package.json generado no es JSON válido.');
        $this->assertSame('acme-blog', $package['name']);
        $this->assertArrayHasKey('pinia', $package['peerDependencies']);
        $this->assertArrayHasKey('vue', $package['peerDependencies']);
    }

    /**
     * @return array<int, string>
     */
    private function generatedModuleFiles(): array
    {
        $files = [];
        $prefix = strlen($this->project->path) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->project->path . '/resources/vue',
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['vue', 'js'], true)) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), $prefix));
            }
        }

        return $files;
    }
}
