<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\Changes;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\Regeneration;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Throwable;

/**
 * El generador delante de un repositorio que no es de fiar: lo que trae —un
 * enlace simbólico plantado, un composer.json, un manifiesto o un plan
 * editados— no puede hacer que escriba, borre o meta código fuera de lo que
 * el contrato declara.
 *
 * Cada prueba es un ataque que funcionaba: `larapack:import` escribía a través
 * de un enlace, el namespace de composer.json salía como código en cada clase,
 * `larapack:fix` reescribía un trait de fuera del proyecto por una ruta del
 * manifiesto y la caché del realineamiento vaciaba el directorio al que la
 * llevara un enlace.
 */
final class GeneratorInjectionAuditTest extends TestCase
{
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outside = (string) realpath(sys_get_temp_dir()).'/larapack-outside-'.bin2hex(random_bytes(6));
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->outside);

        parent::tearDown();
    }

    // ENLACES SIMBÓLICOS

    public function test_import_no_escribe_a_traves_de_un_directorio_enlazado_hacia_fuera(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        mkdir($this->project->path.'/src');
        symlink($this->outside, $this->project->path.'/src/Models');

        $error = $this->importExpectingFailure();

        $this->assertStringContainsString('lleva fuera del proyecto', $error);
        $this->assertSame([], self::filesIn($this->outside), 'Se generó fuera del proyecto, a través de src/Models.');
    }

    public function test_import_no_escribe_a_traves_de_un_enlace_roto(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        mkdir($this->project->path.'/src/Models', 0777, true);

        // «No existe», así que el generador lo crearía: copy() sigue el enlace
        // y el modelo acaba en el archivo de fuera.
        symlink($this->outside.'/pwned.php', $this->project->path.'/src/Models/Product.php');

        $error = $this->importExpectingFailure();

        $this->assertStringContainsString('enlace simbólico roto', $error);
        $this->assertFileDoesNotExist($this->outside.'/pwned.php');
    }

    public function test_el_manifiesto_no_se_escribe_a_traves_de_un_larapack_enlazado(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        symlink($this->outside, $this->project->path.'/.larapack');

        $this->importExpectingFailure();

        $this->assertSame([], self::filesIn($this->outside), 'El manifiesto se escribió fuera del proyecto.');
    }

    public function test_un_enlace_que_se_queda_dentro_del_proyecto_vale(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        mkdir($this->project->path.'/src');
        mkdir($this->project->path.'/modelos');
        symlink($this->project->path.'/modelos', $this->project->path.'/src/Models');

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => $this->contract()]), $this->lastOutput);
        $this->assertFileExists($this->project->path.'/modelos/Product.php');
    }

    public function test_remove_full_model_no_borra_a_traves_de_un_directorio_enlazado(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => $this->contract()]), $this->lastOutput);

        // El directorio de requests, cambiado por un enlace a uno de fuera
        // que tiene una carpeta con el nombre del modelo.
        self::remove($this->project->path.'/src/Http/Requests');
        mkdir($this->outside.'/Product');
        file_put_contents($this->outside.'/Product/keep.txt', 'de otro');
        symlink($this->outside, $this->project->path.'/src/Http/Requests');

        try {
            $this->runCommand('larapack:remove-full-model', ['name' => 'Product']);
        } catch (MakerException) {
            // Lo que importa es lo que queda en disco.
        }

        $this->assertFileExists($this->outside.'/Product/keep.txt', 'remove-full-model borró la carpeta de un directorio de fuera del proyecto.');
    }

    // composer.json

    public function test_un_namespace_de_composer_json_que_no_es_un_namespace_no_llega_a_lo_generado(): void
    {
        $this->useProject(FakeProject::library("Acme\\Shop;\n} namespace X { function pwn(){} echo 'PWN'; \\"));

        $error = $this->importExpectingFailure();

        $this->assertStringContainsString('no es un namespace de PHP', $error);
        $this->assertSame([], $this->project->phpFiles(), 'Se generó código con el namespace inyectado.');
    }

    public function test_los_namespaces_normales_siguen_valiendo(): void
    {
        foreach (['Acme\\', 'Acme\\Shop\\', 'Acme_2\\Shop_Catalog\\V2\\'] as $namespace) {
            $this->useProject(FakeProject::library($namespace));
            $this->assertSame($namespace, get_namespace());
            $this->project->cleanup();
        }
    }

    // EL MANIFIESTO

    public function test_fix_no_reescribe_un_archivo_de_fuera_por_una_ruta_del_manifiesto(): void
    {
        $this->projectWithMissingRelation();

        // El modelo y su trait, copiados fuera, y el manifiesto apuntando a la
        // copia con `..`: es lo que haría quien prepara el repositorio.
        $name = basename($this->outside);
        mkdir($this->outside.'/Models/Traits/Relations', 0777, true);
        copy($this->project->path.'/src/Models/Post.php', $this->outside.'/Models/Post.php');
        copy($this->project->path.'/src/Models/Traits/Relations/PostRelations.php', $this->outside.'/Models/Traits/Relations/PostRelations.php');
        $before = (string) file_get_contents($this->outside.'/Models/Traits/Relations/PostRelations.php');

        $this->tamperManifest(function (array $manifest) use ($name): array {
            $entry = $manifest['models']['Post']['files']['src/Models/Post.php'];
            unset($manifest['models']['Post']['files']['src/Models/Post.php']);
            $manifest['models']['Post']['files']["../{$name}/Models/Post.php"] = $entry;

            return $manifest;
        });

        $error = $this->runExpectingFailure('larapack:fix', ['checks' => ['relation-missing']]);

        $this->assertStringContainsString('sale del proyecto', $error);
        $this->assertSame($before, file_get_contents($this->outside.'/Models/Traits/Relations/PostRelations.php'), 'larapack:fix escribió en un trait de fuera del proyecto.');
    }

    public function test_fix_no_mete_en_el_trait_el_codigo_de_un_namespace_del_manifiesto(): void
    {
        $this->projectWithMissingRelation();
        $trait = $this->project->path.'/src/Models/Traits/Relations/PostRelations.php';
        $before = (string) file_get_contents($trait);

        $this->tamperManifest(function (array $manifest): array {
            $manifest['models']['Post']['namespace'] = "Acme\\Shop;\nfile_put_contents(__DIR__.'/pwned', 'x'); //";

            return $manifest;
        });

        $error = $this->runExpectingFailure('larapack:fix', ['checks' => ['relation-missing']]);

        $this->assertStringContainsString('namespace que no es de PHP', $error);
        $this->assertSame($before, file_get_contents($trait));
        $this->assertStringNotContainsString('file_put_contents', (string) file_get_contents($trait));
    }

    public function test_un_manifiesto_con_una_ruta_absoluta_no_se_interpreta(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => $this->contract()]), $this->lastOutput);

        $this->tamperManifest(function (array $manifest): array {
            $manifest['package']['files'][$this->outside.'/x.php'] = ['stub' => 'Config/ConfigTemplate.txt', 'hash' => 'x'];

            return $manifest;
        });

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('sale del proyecto');

        (new Manifest($this->project->path))->models();
    }

    // larapack:fix

    /**
     * @return array<string, array{0: string}>
     */
    public static function waysOut(): array
    {
        return [
            'app/ es un enlace hacia fuera' => ['symlink'],
            'un repositorio path de composer.json con ruta absoluta' => ['repository'],
        ];
    }

    #[DataProvider('waysOut')]
    public function test_fix_no_reescribe_el_codigo_de_otro_proyecto(string $way): void
    {
        $this->useProject(FakeProject::application());

        // El IndexRequest de antes de 9.0.0: `larapack:fix index-managed` lo
        // reescribe. Aquí es de otro proyecto.
        $request = "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse App\\Models\\Post;\nuse App\\Http\\Resources\\Models\\PostResource;\n"
            ."use Illuminate\\Foundation\\Http\\FormRequest;\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\nclass IndexRequest extends FormRequest\n{\n\n"
            ."    protected function prepareForValidation()\n    {\n        //\n    }\n\n    public function authorize()\n    {\n        return \$this->user()->can('index', Post::class);\n    }\n\n"
            ."    public function handle()\n    {\n\n        \$builder = new Builder();\n\n        \$query = \$builder->get(Post::class, \$this->all());\n\n        return PostResource::collection(\$query);\n\n    }\n}\n";

        $victim = $this->outside.'/app/Http/Requests/Post/IndexRequest.php';
        mkdir(dirname($victim), 0777, true);
        file_put_contents($victim, $request);

        if ($way === 'symlink') {
            symlink($this->outside.'/app', $this->project->path.'/app');
        } else {
            file_put_contents($this->outside.'/composer.json', json_encode(['name' => 'otro/proyecto', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
            $composer = json_decode((string) file_get_contents($this->project->path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $composer['repositories'] = [['type' => 'path', 'url' => $this->outside]];
            file_put_contents($this->project->path.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        }

        $this->runCommand('larapack:fix', ['checks' => ['index-managed'], '--format' => 'json']);

        $this->assertSame($request, file_get_contents($victim), 'larapack:fix reescribió un archivo de fuera del proyecto.');
        $this->assertStringContainsString('fuera de la ra', $this->lastOutput);
    }

    // EL REALINEAMIENTO

    public function test_la_cache_del_realineamiento_no_vacia_el_directorio_al_que_lleva_un_enlace(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $this->contract();

        // `.larapack/realign/cache` → fuera, donde hay un `root/` que no es
        // una caché: con el enlace hacia `/`, el directorio personal de root.
        mkdir($this->project->path.'/.larapack/realign', 0777, true);
        mkdir($this->outside.'/root');
        file_put_contents($this->outside.'/root/keep.txt', 'de otro');
        symlink($this->outside, $this->project->path.'/.larapack/realign/cache');

        try {
            (new Regeneration(new RealignState($this->project->path), 1, false))->run((new Scopes($this->project->path))->all());
            $this->fail('La regeneración siguió adelante a través del enlace.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('lleva fuera del proyecto', $e->getMessage());
        }

        $this->assertFileExists($this->outside.'/root/keep.txt', 'La regeneración vació un directorio de fuera del proyecto.');
    }

    public function test_un_cambio_del_realineamiento_no_escribe_en_una_ruta_que_sale_de_la_raiz(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        $name = basename($this->outside);

        // La ruta de un paso del plan (.larapack/realign/plan.json), que es un
        // archivo del proyecto: `../<fuera>/x.php`.
        try {
            $changes = new Changes($this->project->path);
            $changes->put($this->project->path."/../{$name}/x.php", "<?php\n");
            $changes->commit();
            $this->fail('El cambio se escribió.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('lleva fuera del proyecto', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->outside.'/x.php');
    }

    public function test_un_cambio_del_realineamiento_no_escribe_ni_borra_a_traves_de_un_enlace(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        file_put_contents($this->outside.'/victim.php', "<?php\n// de otro\n");
        symlink($this->outside, $this->project->path.'/src');

        foreach (["<?php\n// pisado\n", null] as $content) {
            try {
                $changes = new Changes($this->project->path);
                $content === null
                    ? $changes->delete($this->project->path.'/src/victim.php')
                    : $changes->put($this->project->path.'/src/victim.php', $content);
                $changes->commit();
                $this->fail('El cambio se escribió a través del enlace.');
            } catch (MakerException $e) {
                $this->assertStringContainsString('lleva fuera del proyecto', $e->getMessage());
            }

            $this->assertSame("<?php\n// de otro\n", file_get_contents($this->outside.'/victim.php'));
        }
    }

    // larapack:ci

    public function test_ci_no_escribe_el_workflow_a_traves_de_un_enlace(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));
        mkdir($this->project->path.'/.github');
        symlink($this->outside, $this->project->path.'/.github/workflows');

        $this->runExpectingFailure('larapack:ci', ['--owner' => ['@acme']]);

        $this->assertSame([], self::filesIn($this->outside), 'El workflow se escribió fuera del proyecto.');
    }

    // larapack:new

    public function test_new_no_acepta_un_nombre_ni_un_namespace_con_un_salto_de_linea_final(): void
    {
        $this->useProject(FakeProject::empty());

        foreach ([['name' => "acme/shop\n"], ['name' => 'acme/shop', '--namespace' => "Acme\\Shop\n"]] as $arguments) {
            $this->assertSame(Command::FAILURE, $this->runCommand('larapack:new', $arguments + ['directory' => $this->project->path.'/pkg']), $this->lastOutput);
            $this->assertFileDoesNotExist($this->project->path.'/pkg/composer.json');
        }
    }

    // AYUDAS

    private function contract(): string
    {
        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, json_encode(['models' => [[
            'name' => 'Product',
            'props' => [['name' => 'title', 'type' => 'string']],
        ]]], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Un paquete con Author y Post ya generados, y una relación que se
     * declara después: `larapack:fix relation-missing` la añade al trait.
     */
    private function projectWithMissingRelation(): void
    {
        $this->useProject(FakeProject::library('Acme\\Shop\\'));

        $contract = ['models' => [
            ['name' => 'Author', 'props' => [['name' => 'name', 'type' => 'string']]],
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
        ]];

        $path = $this->project->path.'/laraimport.json';
        file_put_contents($path, json_encode($contract, JSON_THROW_ON_ERROR));
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['jsonPath' => $path]), $this->lastOutput);

        $contract['models'][1]['load_relations'] = [['type' => 'belongsTo', 'name' => 'author', 'related' => 'Author']];
        file_put_contents($path, json_encode($contract, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function tamperManifest(callable $change): void
    {
        $file = $this->project->path.'/.larapack/manifest.json';

        file_put_contents($file, json_encode($change(json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function importExpectingFailure(): string
    {
        return $this->runExpectingFailure('larapack:import', ['jsonPath' => $this->contract()]);
    }

    /**
     * El comando tiene que fallar: con una excepción (así llega a la terminal
     * de quien lo corre) o con su código de salida. Devuelve el porqué.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function runExpectingFailure(string $command, array $arguments): string
    {
        try {
            $code = $this->runCommand($command, $arguments);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        $this->assertNotSame(Command::SUCCESS, $code, "{$command} terminó bien:\n".$this->lastOutput);

        return $this->lastOutput;
    }

    /**
     * @return list<string>
     */
    private static function filesIn(string $directory): array
    {
        $files = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            $files = is_dir($path) && ! is_link($path) ? [...$files, ...self::filesIn($path)] : [...$files, $path];
        }

        return $files;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
