<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;

/**
 * La versión 2 del manifiesto: cada archivo dice qué versión del generador lo
 * escribió y si se adoptó en vez de generarse. Lo usan el diagnóstico de
 * `larapack:realign` (para separar un salto de plantilla de una edición) y la
 * adopción. Un manifiesto de la versión 1 —el de school-management, el de
 * expediente— se tiene que seguir leyendo sin cambiar nada.
 */
final class ManifestV2Test extends TestCase
{
    private const V1 = [
        'version' => 1,
        'package' => ['namespace' => 'App\\', 'files' => []],
        'models' => [
            'Post' => [
                'namespace' => 'App\\',
                'files' => [
                    'app/Models/Post.php' => ['stub' => 'Model/ModelTemplate.txt', 'hash' => 'abc'],
                    'database/migrations/2026_01_01_000000_create_posts_table.php' => ['stub' => 'Migration/MigrationTemplate.txt', 'hash' => 'def'],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());
    }

    private function writeV1(): void
    {
        mkdir($this->project->path.'/.larapack');
        file_put_contents($this->project->path.'/.larapack/manifest.json', json_encode(self::V1));
    }

    /**
     * @return array<string, mixed>
     */
    private function onDisk(): array
    {
        return json_decode($this->project->read('.larapack/manifest.json'), true);
    }

    private function put(string $relative, string $content): string
    {
        $file = $this->project->path.'/'.$relative;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);

        return $file;
    }

    public function test_un_manifiesto_v1_se_lee_sin_version_ni_estado(): void
    {
        $this->writeV1();
        $manifest = new Manifest($this->project->path);

        $this->assertSame(1, $manifest->formatVersion());
        $this->assertSame([
            'model' => 'Post',
            'stub' => 'Model/ModelTemplate.txt',
            'hash' => 'abc',
            'generator' => null,
            'state' => Manifest::GENERATED,
        ], $manifest->entryOf('app/Models/Post.php'));
        $this->assertNull($manifest->entryOf('app/Models/Nope.php'));
    }

    public function test_la_primera_escritura_lo_deja_en_v2_sin_tocar_lo_que_habia(): void
    {
        $this->writeV1();
        $file = $this->put('app/Models/Tag.php', '<?php // tag');

        (new Manifest($this->project->path))->record('Tag', 'App\\', $file, '/x/Stubs/Model/ModelTemplate.txt');

        $disk = $this->onDisk();

        $this->assertSame(2, $disk['version']);
        $this->assertSame(['stub' => 'Model/ModelTemplate.txt', 'hash' => 'abc'], $disk['models']['Post']['files']['app/Models/Post.php']);
        $this->assertSame(GeneratorVersion::current(), $disk['models']['Tag']['files']['app/Models/Tag.php']['generator']);
        $this->assertArrayNotHasKey('state', $disk['models']['Tag']['files']['app/Models/Tag.php']);
    }

    public function test_adoptar_anota_el_hash_de_lo_que_hay_y_el_estado(): void
    {
        $file = $this->put('app/Models/Tag.php', "<?php // a mano\r\n");
        $manifest = new Manifest($this->project->path);

        $manifest->adopt('Tag', 'App\\', $file, 'Model/ModelTemplate.txt');

        $entry = $manifest->entryOf($file);

        $this->assertNotNull($entry);
        $this->assertSame(Manifest::ADOPTED, $entry['state']);
        $this->assertSame($manifest->hash($file), $entry['hash']);
        $this->assertSame('Model/ModelTemplate.txt', $entry['stub']);
        $this->assertFalse($manifest->wasCustomised($file));
        $this->assertSame(Manifest::ADOPTED, $this->onDisk()['models']['Tag']['files']['app/Models/Tag.php']['state']);
    }

    public function test_reenlazar_mueve_el_registro_y_conserva_hash_y_version(): void
    {
        $this->writeV1();
        $manifest = new Manifest($this->project->path);

        $this->assertTrue($manifest->relink(
            'database/migrations/2026_01_01_000000_create_posts_table.php',
            'database/migrations/2026_02_02_000000_create_posts_table.php'
        ));

        $this->assertNull($manifest->entryOf('database/migrations/2026_01_01_000000_create_posts_table.php'));
        $this->assertSame('def', $manifest->entryOf('database/migrations/2026_02_02_000000_create_posts_table.php')['hash'] ?? null);
        $this->assertFalse($manifest->relink('no/existe.php', 'otro.php'));
    }

    public function test_olvidar_quita_el_registro_y_no_el_archivo(): void
    {
        $this->writeV1();
        $file = $this->put('app/Models/Post.php', '<?php // post');
        $manifest = new Manifest($this->project->path);

        $this->assertTrue($manifest->drop($file));
        $this->assertFalse($manifest->knows($file));
        $this->assertFileExists($file);
        $this->assertFalse($manifest->drop($file));
    }

    public function test_un_paquete_tambien_se_reenlaza_y_se_olvida(): void
    {
        $file = $this->put('app/Providers/AppServiceProvider.php', '<?php // p');
        $manifest = new Manifest($this->project->path);
        $manifest->record('', 'App\\', $file, 'Providers/AppServiceProviderTemplate.txt');

        $this->assertTrue($manifest->relink($file, 'app/Providers/OtroServiceProvider.php'));
        $this->assertSame('', $manifest->entryOf('app/Providers/OtroServiceProvider.php')['model'] ?? null);
        $this->assertTrue($manifest->drop('app/Providers/OtroServiceProvider.php'));
        $this->assertSame([], $manifest->packageFiles());
    }

    public function test_no_se_reescribe_un_manifiesto_de_una_version_posterior(): void
    {
        mkdir($this->project->path.'/.larapack');
        file_put_contents($this->project->path.'/.larapack/manifest.json', json_encode(['version' => 3, 'models' => [], 'futuro' => true]));
        $file = $this->put('app/Models/Tag.php', '<?php');
        $manifest = new Manifest($this->project->path);

        $this->assertSame(3, $manifest->formatVersion());

        try {
            $manifest->record('Tag', 'App\\', $file, 'Model/ModelTemplate.txt');
            $this->fail('Se reescribió un manifiesto que esta versión no entiende.');
        } catch (MakerException $e) {
            $this->assertStringContainsString('versión 3', $e->getMessage());
        }

        $this->assertTrue($this->onDisk()['futuro']);
    }

    public function test_una_version_que_no_es_un_numero_se_lee_como_la_1(): void
    {
        mkdir($this->project->path.'/.larapack');
        file_put_contents($this->project->path.'/.larapack/manifest.json', json_encode(['version' => 'x', 'models' => []]));

        $this->assertSame(1, (new Manifest($this->project->path))->formatVersion());
    }

    public function test_la_version_del_generador_es_la_de_version(): void
    {
        $this->assertSame(trim((string) file_get_contents(dirname(__DIR__, 2).'/VERSION')), GeneratorVersion::current());
        $this->assertTrue(GeneratorVersion::isOlder(null));
        $this->assertTrue(GeneratorVersion::isOlder(GeneratorVersion::UNKNOWN));
        $this->assertTrue(GeneratorVersion::isOlder('7.10.3'));
        $this->assertFalse(GeneratorVersion::isOlder(GeneratorVersion::current()));
        $this->assertFalse(GeneratorVersion::isOlder('99.0.0'));
    }
}
