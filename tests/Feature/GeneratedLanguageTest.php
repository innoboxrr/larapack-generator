<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Translations;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo generado habla un solo idioma: el de la aplicación.
 *
 * Las vistas usaban claves en inglés que nadie traducía, las rutas y las acciones
 * de cada fila venían en español fijo, los datatables también, y la notificación
 * de exportación mezclaba los dos. Con la aplicación en español la pantalla salía
 * mezclada; en inglés, también.
 *
 * Ahora todo texto visible es una clave en inglés, LaraPack escribe su
 * traducción al español, y lo que no puede saber —el nombre del modelo, sus
 * campos— queda como clave pendiente.
 */
final class GeneratedLanguageTest extends TestCase
{
    private const TOKENS = ['SingularModelLabel', 'PluralModelLabel'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->import();
    }

    /**
     * La prueba que impide que un texto nuevo de los stubs llegue a la
     * pantalla en inglés: cada clave que escriben tiene que estar traducida.
     */
    public function test_cada_texto_de_los_stubs_tiene_traduccion_al_espanol(): void
    {
        $dictionary = json_decode((string) file_get_contents(stubs_path('Locales/es.json')), true);

        $stubs = stubs_path('');
        $keys = [
            ...Translations::keysIn(Translations::sourcesIn($stubs.'ModelView', ['js', 'jsx', 'vue']), Translations::FRONTEND),
            ...Translations::keysIn(Translations::sourcesIn($stubs.'ReactView', ['js', 'jsx', 'vue']), Translations::FRONTEND),
            ...Translations::keysIn(Translations::sourcesIn($stubs, ['txt']), Translations::BACKEND),
        ];

        $missing = array_values(array_unique(array_filter(
            array_diff($keys, self::TOKENS),
            fn (string $key): bool => ($dictionary[$key] ?? '') === ''
        )));

        $this->assertNotSame([], $keys, 'No se encontró ninguna clave en los stubs: la extracción no está leyendo nada.');
        $this->assertSame([], $missing, "Claves sin traducir en Stubs/Locales/es.json:\n".implode("\n", $missing));
    }

    public function test_el_modulo_trae_sus_traducciones(): void
    {
        foreach (['vue', 'react'] as $ui) {
            $es = $this->json("resources/{$ui}/src/locales/es.json");
            $en = $this->json("resources/{$ui}/src/locales/en.json");

            $this->assertSame('Crear :name', $es['Create :name'] ?? null);
            $this->assertSame('Acciones del registro', $es['Record actions'] ?? null);
            $this->assertSame('Create :name', $en['Create :name'] ?? null);

            // Lo del dominio no lo puede saber LaraPack: queda pendiente, y
            // mientras tanto innoboxrr-i18n enseña la clave.
            foreach (['Post', 'Posts', 'Title'] as $domain) {
                $this->assertSame('', $es[$domain] ?? null, "{$ui}: '{$domain}' debería quedar pendiente en es.json.");
                $this->assertSame($domain, $en[$domain] ?? null);
            }

            $this->assertStringContainsString(
                "export { translations } from './src/i18n.js'",
                $this->read("resources/{$ui}/index.js")
            );
        }
    }

    public function test_regenerar_no_pisa_una_traduccion_escrita_a_mano(): void
    {
        $path = 'resources/vue/src/locales/es.json';

        $translations = $this->json($path);
        $translations['Post'] = 'Entrada';
        $translations['Create'] = 'Nueva';

        file_put_contents($this->project->path.'/'.$path, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->import();

        $after = $this->json($path);

        $this->assertSame('Entrada', $after['Post']);
        $this->assertSame('Nueva', $after['Create']);
    }

    /**
     * Los títulos se leen cuando el router los pide, no al importar las rutas:
     * el módulo puede cargarse antes de que la aplicación elija idioma.
     */
    public function test_rutas_migas_y_paleta_se_traducen(): void
    {
        $this->assertStringContainsString(
            "get title() { return t('Create :name', { name: t('Post') }) }",
            $this->read('resources/vue/src/models/post/routes/index.js')
        );
        $this->assertStringContainsString(
            "get title() { return t('Posts') }",
            $this->read('resources/react/src/models/post/routes/index.js')
        );

        foreach (['vue' => 'vue', 'react' => 'jsx'] as $ui => $extension) {
            $admin = $this->read("resources/{$ui}/src/models/post/views/AdminView.{$extension}");

            $this->assertStringContainsString("group: t('Posts')", $admin);
            $this->assertStringContainsString("title: t('Posts')", $admin);
            $this->assertStringContainsString("t('Close')", $admin);
            $this->assertStringContainsString("t('No results')", $admin);
        }
    }

    public function test_la_tabla_recibe_sus_textos_traducidos(): void
    {
        $this->assertStringContainsString(':labels="tableLabels()"', $this->read('resources/vue/src/models/post/widgets/DataTable.vue'));
        $this->assertStringContainsString('labels={tableLabels()}', $this->read('resources/react/src/models/post/widgets/DataTable.jsx'));

        $this->assertStringContainsString("value: t('Title')", $this->read('resources/vue/src/models/post/index.js'));
    }

    public function test_no_quedan_textos_fijos_en_espanol_ni_nombres_de_clase(): void
    {
        $offenders = [];

        foreach (['vue', 'react'] as $ui) {
            foreach (Translations::sourcesIn($this->project->path."/resources/{$ui}/src", ['js', 'jsx', 'vue']) as $file) {
                $contents = (string) file_get_contents($file);

                foreach (["'Crear ", "'Ver ", "'Editar ", "'Acciones'", 'aria-label="Breadcrumb"', "title: 'Posts'", "group: 'Posts'", "?? 'Post'"] as $literal) {
                    if (str_contains($contents, $literal)) {
                        $offenders[] = basename($file).": {$literal}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_el_backend_traduce_acciones_y_notificacion(): void
    {
        $this->assertStringContainsString("__('Show')", $this->read('src/Http/Resources/Models/PostResource.php'));
        $this->assertStringContainsString("__('Hello')", $this->read('src/Notifications/Post/ExportNotification.php'));
        // El proveedor lo escribe larapack:providers, no el importador.
        $this->assertStringContainsString(
            "loadJsonTranslationsFrom(__DIR__ . '/../../lang')",
            (string) file_get_contents(stubs_path('Providers/AppServiceProviderTemplate.txt'))
        );

        $es = $this->json('lang/es.json');

        $this->assertSame('Ver', $es['Show'] ?? null);
        $this->assertSame('Descargar', $es['Download'] ?? null);

        // El traductor de Laravel pintaría una cadena vacía: lo que LaraPack no
        // sabe traducir no se escribe.
        $this->assertNotContains('', $es);
    }

    public function test_los_eventos_no_cambian_el_idioma_de_la_peticion(): void
    {
        foreach (['CreateEvent', 'UpdateEvent', 'DeleteEvent', 'ExportEvent'] as $event) {
            $php = $this->read("src/Http/Events/Post/Events/{$event}.php");

            $this->assertStringNotContainsString('App::setLocale(', $php, "{$event} cambia el idioma de toda la petición.");
            $this->assertStringContainsString('App::getLocale()', $php);
        }
    }

    private function import(): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__).'/Fixtures/laraimport.json',
                '--vue' => true,
                '--react' => true,
            ]),
            "larapack:import --vue --react terminó con error:\n".$this->lastOutput
        );
    }

    /**
     * @return array<string, string>
     */
    private function json(string $relative): array
    {
        $decoded = json_decode($this->read($relative), true);

        $this->assertIsArray($decoded, "{$relative} no es JSON válido.");

        return $decoded;
    }

    private function read(string $relative): string
    {
        $path = $this->project->path.'/'.$relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
