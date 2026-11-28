<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\ScopeClassifier;
use Innoboxrr\LarapackGenerator\Support\SpaFallback;
use PHPUnit\Framework\TestCase;

/**
 * La clase de cada archivo de un ámbito decide qué se hace con él: se adopta
 * solo, se regenera solo, lo devuelve un agente a sus huecos o lo decide el
 * dueño. Aquí el ámbito se monta a mano —el archivo, su registro en el
 * manifiesto y lo que generaría hoy el contrato— para llegar a cada clase y
 * a cada causa sin generar un proyecto.
 *
 * El riesgo es asimétrico, como en FileComparison: dar por idéntico o por
 * sin editar lo que alguien escribió lo adopta o lo regenera, y se pierde.
 */
final class ScopeClassifierTest extends TestCase
{
    private const REQUEST = <<<'PHP'
        <?php

        namespace Acme\Blog\Http\Requests\Post;

        use Illuminate\Foundation\Http\FormRequest;

        class CreateRequest extends FormRequest
        {
            public function authorize(): bool
            {
                return $this->user()->can('create');
            }

            public function rules(): array
            {
                return [];
            }
        }

        PHP;

    private const REQUEST_STUB = 'Requests/CreateRequest.txt';

    private const CONTROLLER_STUB = 'Controller/ControllerTemplate.txt';

    private string $root;

    private string $reference;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-classifier-'.bin2hex(random_bytes(4));
        $this->reference = $this->root.'/.larapack/realign/cache/root/abc';
        mkdir($this->reference, 0777, true);
        file_put_contents($this->root.'/composer.json', (string) json_encode(['name' => 'acme/blog', 'autoload' => ['psr-4' => ['Acme\\Blog\\' => 'src/']]]));
        file_put_contents($this->root.'/laraimport.json', (string) json_encode(['models' => [['name' => 'Post'], 'suelto', ['sin' => 'nombre']], 'controllers' => [['name' => 'Health']]]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function write(string $base, string $relative, string $content): string
    {
        $path = $base.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Un archivo del proyecto que el manifiesto registra tal como está.
     */
    private function tracked(string $relative, string $content, string $stub, string $model = 'Post'): void
    {
        (new Manifest($this->root))->record($model, 'Acme\\Blog', $this->write($this->root, $relative, $content), $stub);
    }

    /**
     * Lo que generaría hoy el contrato en esa ruta.
     */
    private function produced(string $relative, string $content, string $stub, string $model = 'Post'): void
    {
        (new Manifest($this->reference))->record($model, 'Acme\\Blog', $this->write($this->reference, $relative, $content), $stub);
    }

    /**
     * Editado después de generarlo: el hash del manifiesto ya no es el suyo.
     */
    private function edit(string $relative, string $content): void
    {
        $this->write($this->root, $relative, $content);
    }

    /**
     * Cambia la versión con la que el manifiesto dice que se escribió.
     */
    private function generator(string $relative, ?string $version, string $model = 'Post'): void
    {
        $path = $this->root.'/.larapack/manifest.json';
        $manifest = json_decode((string) file_get_contents($path), true);

        if ($version === null) {
            unset($manifest['models'][$model]['files'][$relative]['generator']);
        } else {
            $manifest['models'][$model]['files'][$relative]['generator'] = $version;
        }

        file_put_contents($path, (string) json_encode($manifest));
    }

    private function classifier(bool $reference = true, bool $exceptions = false): ScopeClassifier
    {
        return new ScopeClassifier(
            ['package' => null, 'path' => '.', 'root' => $this->root, 'contract' => true, 'manifest' => true],
            $this->root,
            $reference ? $this->reference : null,
            $exceptions ? Exceptions::forRoot($this->root) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(string $file): array
    {
        return $this->classifier()->classify($file);
    }

    /**
     * Lo que decide de un archivo: clase, categoría y tipo de paso.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function verdict(string $file): array
    {
        $entry = $this->classify($file);

        return [$entry['class'], $entry['category'], $entry['type']];
    }

    public function test_lo_identico_y_registrado_cumple_y_lo_que_el_manifiesto_no_conoce_se_adopta(): void
    {
        $this->tracked('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->write($this->root, 'src/B.php', self::REQUEST);
        $this->produced('src/B.php', self::REQUEST, self::REQUEST_STUB);

        $entry = $this->classify('src/A.php');

        $this->assertSame([
            'scope' => '.', 'package' => null, 'file' => 'src/A.php', 'path' => 'src/A.php', 'model' => 'Post', 'stub' => self::REQUEST_STUB,
            'class' => FileClass::IDENTICAL, 'category' => null, 'type' => null, 'reason' => 'idéntico a lo regenerado',
            'tracked' => true, 'hash_current' => true, 'generator' => $entry['generator'],
        ], $entry);
        $this->assertIsString($entry['generator']);

        // Igual, pero sin registrar: hasta adoptarlo, --force no lo toca. El
        // stub es el de lo regenerado.
        $untracked = $this->classify('src/B.php');
        $this->assertSame([FileClass::IDENTICAL, Category::DETERMINISTIC, 'adopt'], [$untracked['class'], $untracked['category'], $untracked['type']]);
        $this->assertFalse($untracked['tracked']);
        $this->assertNull($untracked['hash_current']);
        $this->assertNull($untracked['model']);
        $this->assertNull($untracked['generator']);
        $this->assertSame(self::REQUEST_STUB, $untracked['stub']);
    }

    public function test_igual_salvo_formato_se_adopta_si_el_hash_del_manifiesto_es_de_antes(): void
    {
        $this->tracked('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->tracked('src/B.php', str_replace('    ', "\t", self::REQUEST), self::REQUEST_STUB);
        $this->produced('src/B.php', self::REQUEST, self::REQUEST_STUB);

        // Reformateado después de generarlo: el manifiesto lo ve editado.
        $this->edit('src/A.php', str_replace('    ', "\t", self::REQUEST));
        $entry = $this->classify('src/A.php');

        $this->assertSame([FileClass::FORMAT_ONLY, Category::DETERMINISTIC, 'adopt'], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame('igual salvo espacios u orden de imports', $entry['reason']);
        $this->assertFalse($entry['hash_current']);
        $this->assertTrue($entry['tracked']);

        // Con el hash de hoy ya está adoptado: cumple.
        $this->assertSame([FileClass::FORMAT_ONLY, null, null], $this->verdict('src/B.php'));
    }

    public function test_lo_que_nadie_edito_y_difiere_se_regenera_y_dice_por_que(): void
    {
        $changed = str_replace("can('create')", "can('create', Post::class)", self::REQUEST);
        $this->tracked('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/A.php', $changed, self::REQUEST_STUB);

        // Escrito con esta versión: lo que cambió es el contrato.
        $entry = $this->classify('src/A.php');
        $this->assertSame([FileClass::VERSION_DELTA, Category::DETERMINISTIC, 'regenerate'], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame('contract', $entry['cause']);
        $this->assertSame('sin editar, pero el contrato cambió desde que se generó', $entry['reason']);
        $this->assertTrue($entry['hash_current']);
        $this->assertArrayNotHasKey('members', $entry);

        // Con una anterior: el salto de plantilla.
        $this->generator('src/A.php', '1.2.3');
        $entry = $this->classify('src/A.php');
        $this->assertSame(FileClass::VERSION_DELTA, $entry['class']);
        $this->assertSame('generator', $entry['cause']);
        $this->assertSame('sin editar, escrito con una versión anterior del generador (1.2.3)', $entry['reason']);

        // Un manifiesto v1 no dice con cuál.
        $this->generator('src/A.php', null);
        $entry = $this->classify('src/A.php');
        $this->assertSame('generator', $entry['cause']);
        $this->assertSame('sin editar, escrito con una versión anterior del generador (sin versión: manifiesto v1)', $entry['reason']);
    }

    public function test_editado_solo_en_un_hueco_cumple_y_fuera_de_el_lo_devuelve_un_agente(): void
    {
        $this->tracked('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->tracked('src/B.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/B.php', self::REQUEST, self::REQUEST_STUB);

        $this->edit('src/A.php', str_replace('return [];', "return ['title' => 'required'];", self::REQUEST));
        $hole = $this->classify('src/A.php');

        $this->assertSame([FileClass::HOLE_EDITED, null, null], [$hole['class'], $hole['category'], $hole['type']]);
        $this->assertSame('editado sólo en sus huecos', $hole['reason']);
        $this->assertSame(['method:rules'], $hole['members']);
        $this->assertFalse($hole['hash_current']);
        // Dónde está lo regenerado, relativo al proyecto: el playbook lo da.
        $this->assertSame('.larapack/realign/cache/root/abc/src/A.php', $hole['regenerated']);

        $this->edit('src/B.php', str_replace(["can('create')", 'return [];'], ["can('create') || true", "return ['title' => 'required'];"], self::REQUEST));
        $outside = $this->classify('src/B.php');

        $this->assertSame([FileClass::EDITED_OUTSIDE, Category::GUIDED, 'edited-outside'], [$outside['class'], $outside['category'], $outside['type']]);
        // La razón nombra lo que está fuera, no el hueco.
        $this->assertSame('editado fuera de los huecos: method:authorize', $outside['reason']);
        $this->assertSame(['method:authorize', 'method:rules'], $outside['members']);
        $this->assertArrayNotHasKey('cause', $outside);

        // Escrito con una versión anterior, parte puede ser la plantilla nueva.
        $this->generator('src/B.php', '1.2.3');
        $this->assertSame('edited_or_generator', $this->classify('src/B.php')['cause']);
        $this->generator('src/A.php', '1.2.3');
        $this->assertArrayNotHasKey('cause', $this->classify('src/A.php'));
    }

    public function test_lo_que_no_es_php_y_difiere_esta_editado_fuera(): void
    {
        $this->tracked('resources/react/src/models/post/index.jsx', "export default 1\n", 'ReactView/Index.txt');
        $this->produced('resources/react/src/models/post/index.jsx', "export default 1\n", 'ReactView/Index.txt');
        $this->edit('resources/react/src/models/post/index.jsx', "export default 2\n");

        $entry = $this->classify('resources/react/src/models/post/index.jsx');

        $this->assertSame([FileClass::EDITED_OUTSIDE, Category::GUIDED, 'edited-outside'], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame(['outside'], $entry['members']);
        $this->assertSame('editado fuera de los huecos: outside', $entry['reason']);
    }

    public function test_el_mismo_codigo_con_otros_comentarios_se_conserva_sin_adoptar(): void
    {
        $commented = str_replace('return [];', "// lo decidió producto\n        return [];", self::REQUEST);

        // Editado por alguien: los comentarios son suyos. Cumple, y no se
        // adopta (adoptarlo dejaría que --force los reemplazara).
        $this->tracked('src/A.php', self::REQUEST, self::CONTROLLER_STUB);
        $this->produced('src/A.php', self::REQUEST, self::CONTROLLER_STUB);
        $this->edit('src/A.php', $commented);
        $entry = $this->classify('src/A.php');
        $this->assertSame([FileClass::HOLE_EDITED, null, null], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertTrue($entry['comments']);
        $this->assertSame('el mismo código con otros comentarios: son del usuario y se conservan', $entry['reason']);

        // Sin editar: es la plantilla la que cambió sus comentarios.
        $this->tracked('src/B.php', $commented, self::CONTROLLER_STUB);
        $this->produced('src/B.php', self::REQUEST, self::CONTROLLER_STUB);
        $this->assertSame([FileClass::VERSION_DELTA, Category::DETERMINISTIC, 'regenerate'], $this->verdict('src/B.php'));

        // Lo que el manifiesto no conoce lo decide alguien.
        $this->write($this->root, 'src/C.php', $commented);
        $this->produced('src/C.php', self::REQUEST, self::CONTROLLER_STUB);
        $this->assertSame([FileClass::UNTRACKED, Category::DECISION, 'untracked-edited'], $this->verdict('src/C.php'));

        // En un archivo que es hueco entero, es el hueco.
        $this->tracked('src/D.php', self::REQUEST, 'Policy/PolicyTemplate.txt');
        $this->produced('src/D.php', self::REQUEST, 'Policy/PolicyTemplate.txt');
        $this->edit('src/D.php', $commented);
        $entry = $this->classify('src/D.php');
        $this->assertSame(FileClass::HOLE_EDITED, $entry['class']);
        $this->assertSame('editado sólo en sus huecos', $entry['reason']);
        $this->assertArrayNotHasKey('comments', $entry);
    }

    public function test_lo_escrito_a_mano_en_la_ruta_de_uno_generado_lo_decide_el_dueno(): void
    {
        $this->write($this->root, 'src/A.php', str_replace('return [];', "return ['x' => 'required'];", self::REQUEST));
        $this->produced('src/A.php', self::REQUEST, self::REQUEST_STUB);

        $entry = $this->classify('src/A.php');

        // Aunque sólo difiera en un hueco: sin registro no se sabe de quién es.
        $this->assertSame([FileClass::UNTRACKED, Category::DECISION, 'untracked-edited'], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame('en la ruta de un archivo generado, distinto de lo que generaría el contrato', $entry['reason']);
        $this->assertArrayNotHasKey('members', $entry);
    }

    public function test_una_migracion_se_compara_por_su_nombre_sin_la_fecha_y_no_se_reescribe(): void
    {
        $migration = "<?php\n\nreturn new class {\n    public function up() { Schema::create('posts', fn (\$t) => \$t->id()); }\n};\n";
        $this->tracked('database/migrations/2024_01_01_000000_create_posts_table.php', $migration, 'Migration/CreateTemplate.txt');
        $this->produced('database/migrations/2026_05_05_101010_create_posts_table.php', $migration, 'Migration/CreateTemplate.txt');
        $this->tracked('database/migrations/2024_01_01_000001_create_tags_table.php', $migration, 'Migration/CreateTemplate.txt');
        $this->produced('database/migrations/2026_05_05_101011_create_tags_table.php', str_replace('posts', 'tags', $migration), 'Migration/CreateTemplate.txt');
        $this->tracked('database/migrations/2024_02_02_000000_add_slug_to_posts_table.php', $migration, 'Migration/AlterTemplate.txt');

        // La fecha la pone la hora a la que se generó: no es una diferencia.
        $this->assertSame([FileClass::IDENTICAL, null, null], $this->verdict('database/migrations/2024_01_01_000000_create_posts_table.php'));

        // La que difiere es historia: fuera de alcance, ningún paso.
        $history = $this->classify('database/migrations/2024_01_01_000001_create_tags_table.php');
        $this->assertSame([FileClass::MIGRATION_HISTORY, Category::OUT_OF_SCOPE, null], [$history['class'], $history['category'], $history['type']]);
        $this->assertSame('una migración no se reescribe nunca: lo que difiere se resuelve con otra migración o con el contrato', $history['reason']);

        // Una alteración que el contrato no produce hoy.
        $alter = $this->classify('database/migrations/2024_02_02_000000_add_slug_to_posts_table.php');
        $this->assertSame([FileClass::NOT_REGENERATED, Category::OUT_OF_SCOPE, null], [$alter['class'], $alter['category'], $alter['type']]);
        $this->assertSame('una migración que hoy no produciría el contrato (una alteración, o de una tabla que ya existía)', $alter['reason']);

        // Una carpeta que no es la de migraciones, o un nombre sin fecha, es un archivo cualquiera.
        $this->tracked('database/seeders/2024_01_01_000000_create_posts_table.php', $migration, 'Seeder/SeederTemplate.txt');
        $this->produced('database/seeders/2026_05_05_101010_create_posts_table.php', $migration, 'Seeder/SeederTemplate.txt');
        $this->assertSame(FileClass::NOT_REGENERATED, $this->classify('database/seeders/2024_01_01_000000_create_posts_table.php')['class']);
    }

    public function test_lo_registrado_que_el_contrato_ya_no_produce_esta_fuera_de_alcance_y_dice_por_que(): void
    {
        $this->tracked('src/Models/Legacy.php', "<?php\nclass Legacy {}\n", 'Model/ModelTemplate.txt', 'Legacy');
        $this->tracked('src/Models/Post.php', "<?php\nclass Post {}\n", 'Model/ModelTemplate.txt', 'Post');
        $this->tracked('src/Http/Controllers/HealthController.php', "<?php\nclass HealthController {}\n", 'Endpoint/ControllerTemplate.txt', 'Health');
        $this->tracked('config/blog.php', "<?php\nreturn [];\n", 'Config/ConfigTemplate.txt', '');

        $reason = fn (string $file, bool $reference = true): string => $this->classifier($reference)->classify($file)['reason'];

        $entry = $this->classify('src/Models/Legacy.php');
        $this->assertSame([FileClass::NOT_REGENERATED, Category::OUT_OF_SCOPE, null], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame('el modelo Legacy ya no está en el contrato', $entry['reason']);
        $this->assertTrue($entry['hash_current']);

        // Los modelos y los controladores que el contrato declara no están retirados.
        $this->assertSame('import no lo regenera (proveedores, configuración, andamiaje)', $reason('src/Models/Post.php'));
        $this->assertSame('import no lo regenera (proveedores, configuración, andamiaje)', $reason('src/Http/Controllers/HealthController.php'));
        // Lo del paquete no es de ningún modelo.
        $this->assertSame('import no lo regenera (proveedores, configuración, andamiaje)', $reason('config/blog.php'));
        $this->assertSame('', $this->classify('config/blog.php')['model']);

        // Sin referencia no se puede decir más.
        $this->assertSame('sin referencia: el contrato no se pudo regenerar', $reason('src/Models/Legacy.php', false));
        $this->assertFalse($this->classifier(false)->hasReference());
        $this->assertTrue($this->classifier()->hasReference());
    }

    public function test_lo_que_falta_segun_quien_lo_conocia(): void
    {
        $code = "<?php\nclass X {}\n";
        // Registrado y el contrato lo sigue generando: lo decide el dueño.
        $this->tracked('src/Deleted.php', $code, self::CONTROLLER_STUB);
        $this->produced('src/Deleted.php', $code, self::CONTROLLER_STUB);
        unlink($this->root.'/src/Deleted.php');
        // Registrado, de un modelo retirado, o que import no regenera: sobra en el manifiesto.
        $this->tracked('src/Models/Legacy.php', $code, 'Model/ModelTemplate.txt', 'Legacy');
        unlink($this->root.'/src/Models/Legacy.php');
        $this->tracked('config/blog.php', $code, 'Config/ConfigTemplate.txt', '');
        unlink($this->root.'/config/blog.php');
        // Ni registrado: el contrato lo pide y nunca se generó.
        $this->produced('src/Never.php', $code, self::CONTROLLER_STUB);

        $deleted = $this->classify('src/Deleted.php');
        $this->assertSame([FileClass::MISSING, Category::DECISION, 'missing'], [$deleted['class'], $deleted['category'], $deleted['type']]);
        $this->assertSame('deleted', $deleted['cause']);
        $this->assertSame('borrado, y el contrato lo sigue generando', $deleted['reason']);
        $this->assertNull($deleted['hash_current']);

        $retired = $this->classify('src/Models/Legacy.php');
        $this->assertSame([FileClass::MISSING, Category::DETERMINISTIC, 'prune'], [$retired['class'], $retired['category'], $retired['type']]);
        $this->assertSame('retired', $retired['cause']);
        $this->assertSame('borrado, y el modelo Legacy ya no está en el contrato', $retired['reason']);

        $scaffold = $this->classify('config/blog.php');
        $this->assertSame([FileClass::MISSING, Category::DETERMINISTIC, 'prune'], [$scaffold['class'], $scaffold['category'], $scaffold['type']]);
        $this->assertSame('not_regenerated', $scaffold['cause']);
        $this->assertSame('borrado, y el contrato no lo volvería a generar', $scaffold['reason']);

        $never = $this->classify('src/Never.php');
        $this->assertSame([FileClass::MISSING, Category::DETERMINISTIC, 'regenerate'], [$never['class'], $never['category'], $never['type']]);
        $this->assertSame('el contrato lo generaría y no está', $never['reason']);
        $this->assertArrayNotHasKey('cause', $never);
        $this->assertSame(self::CONTROLLER_STUB, $never['stub']);

        // Ni en disco, ni en el manifiesto, ni en el contrato: nada que hacer.
        $absent = $this->classify('src/Nothing.php');
        $this->assertSame([FileClass::ABSENT, null, null, ''], [$absent['class'], $absent['category'], $absent['type'], $absent['reason']]);
        $this->assertNull($absent['stub']);

        // En disco y sin nadie que lo conozca, tampoco.
        $this->write($this->root, 'src/Own.php', $code);
        $own = $this->classify('src/Own.php');
        $this->assertSame([FileClass::ABSENT, null, null], [$own['class'], $own['category'], $own['type']]);
    }

    public function test_un_archivo_renombrado_se_reconoce_por_su_contenido_si_no_hay_duda(): void
    {
        $this->tracked('src/Old.php', "<?php\nclass Old {}\n", self::CONTROLLER_STUB);
        $this->tracked('src/Kept.php', "<?php\nclass Old {}\n", self::CONTROLLER_STUB);
        rename($this->root.'/src/Old.php', $this->root.'/src/New.php');
        mkdir($this->root.'/src/Sub');

        $entry = $this->classify('src/Old.php');

        // Kept.php tiene el mismo contenido, pero el manifiesto ya lo conoce.
        $this->assertSame([FileClass::RENAMED, Category::DETERMINISTIC, 'relink'], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame('src/New.php', $entry['to']);
        $this->assertSame('está en src/New.php', $entry['reason']);

        // Dos candidatos es ambiguo: no se adivina.
        copy($this->root.'/src/New.php', $this->root.'/src/Other.php');
        $ambiguous = $this->classify('src/Old.php');
        $this->assertSame(FileClass::MISSING, $ambiguous['class']);
        $this->assertArrayNotHasKey('to', $ambiguous);

        // Con otro contenido no es el mismo archivo.
        unlink($this->root.'/src/Other.php');
        file_put_contents($this->root.'/src/New.php', "<?php\nclass Otro {}\n");
        $this->assertSame(FileClass::MISSING, $this->classify('src/Old.php')['class']);

        // En la raíz del ámbito también se busca.
        $this->tracked('old.php', "<?php\nreturn 1;\n", 'Config/ConfigTemplate.txt', '');
        rename($this->root.'/old.php', $this->root.'/new.php');
        $this->assertSame('new.php', $this->classify('old.php')['to']);
    }

    public function test_una_migracion_renombrada_o_registrada_dos_veces(): void
    {
        $migration = "<?php\n\nreturn new class {};\n";
        $directory = 'database/migrations/';

        // Renombrada a mano con otra fecha (y otro contenido): es la misma.
        $this->tracked($directory.'2024_01_01_000000_create_posts_table.php', $migration, 'Migration/CreateTemplate.txt');
        unlink($this->root.'/'.$directory.'2024_01_01_000000_create_posts_table.php');
        $this->write($this->root, $directory.'2024_03_03_000000_create_posts_table.php', $migration."// retocada\n");

        $renamed = $this->classify($directory.'2024_01_01_000000_create_posts_table.php');
        $this->assertSame([FileClass::RENAMED, Category::DETERMINISTIC, 'relink'], [$renamed['class'], $renamed['category'], $renamed['type']]);
        $this->assertSame($directory.'2024_03_03_000000_create_posts_table.php', $renamed['to']);

        // Registrada dos veces —generada otra vez con otra fecha— y la otra
        // copia sigue ahí: la que falta sobra en el manifiesto.
        $this->tracked($directory.'2024_01_01_000000_create_tags_table.php', $migration, 'Migration/CreateTemplate.txt');
        $this->tracked($directory.'2024_04_04_000000_create_tags_table.php', $migration, 'Migration/CreateTemplate.txt');
        unlink($this->root.'/'.$directory.'2024_01_01_000000_create_tags_table.php');

        $superseded = $this->classify($directory.'2024_01_01_000000_create_tags_table.php');
        $this->assertSame([FileClass::MISSING, Category::DETERMINISTIC, 'prune'], [$superseded['class'], $superseded['category'], $superseded['type']]);
        $this->assertSame('superseded', $superseded['cause']);
        $this->assertSame($directory.'2024_04_04_000000_create_tags_table.php', $superseded['to']);
        $this->assertSame('borrado; el manifiesto registra la misma migración en '.$directory.'2024_04_04_000000_create_tags_table.php', $superseded['reason']);

        // Si la otra copia tampoco está, no la sustituye nadie.
        unlink($this->root.'/'.$directory.'2024_04_04_000000_create_tags_table.php');
        $this->assertNotSame('superseded', $this->classify($directory.'2024_01_01_000000_create_tags_table.php')['cause'] ?? null);
    }

    public function test_una_migracion_que_el_contrato_pide_no_falta_si_esta_con_otra_fecha(): void
    {
        $migration = "<?php\n\nreturn new class {};\n";
        $this->produced('database/migrations/2026_05_05_101010_create_posts_table.php', $migration, 'Migration/CreateTemplate.txt');
        $this->produced('database/migrations/2026_05_05_101011_create_tags_table.php', $migration, 'Migration/CreateTemplate.txt');
        $this->write($this->root, 'database/migrations/2020_01_01_000000_create_posts_table.php', $migration);

        // La de posts está, con su fecha de entonces.
        $this->assertSame(FileClass::ABSENT, $this->classify('database/migrations/2026_05_05_101010_create_posts_table.php')['class']);
        // La de tags no está con ninguna.
        $missing = $this->classify('database/migrations/2026_05_05_101011_create_tags_table.php');
        $this->assertSame([FileClass::MISSING, Category::DETERMINISTIC, 'regenerate'], [$missing['class'], $missing['category'], $missing['type']]);

        // Y en el ámbito entero, las migraciones regeneradas no salen como
        // archivos sueltos: se emparejan por nombre con las registradas.
        $this->tracked('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/Never.php', self::REQUEST, self::REQUEST_STUB);

        $all = array_column($this->classifier()->classifyAll(), 'class', 'file');
        ksort($all);
        $this->assertSame(['src/A.php' => FileClass::IDENTICAL, 'src/Never.php' => FileClass::MISSING], $all);
    }

    public function test_una_excepcion_aprobada_sobre_el_codigo_de_hoy_deja_el_archivo_sin_paso(): void
    {
        $this->tracked('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->produced('src/A.php', self::REQUEST, self::REQUEST_STUB);
        $this->edit('src/A.php', str_replace("can('create')", "can('create') || true", self::REQUEST));
        $target = 'realign:edited-outside:src/A.php';

        // Aprobada sobre otro código no cubre nada.
        HandRolledLock::write($this->root, [$target => ['reason' => 'Se queda.', 'fingerprint' => 'sha256:'.str_repeat('0', 64)]]);
        $entry = $this->classifier(true, true)->classify('src/A.php');
        $this->assertSame([FileClass::EDITED_OUTSIDE, Category::GUIDED, 'edited-outside'], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertArrayNotHasKey('excepted', $entry);

        HandRolledLock::write($this->root, [$target => ['reason' => 'Se queda.', 'fingerprint' => ExceptionTargets::fingerprint($this->root, $target)]]);
        $entry = $this->classifier(true, true)->classify('src/A.php');

        // La clase sigue siendo la que se exceptuó; lo que cambia es que no pide paso.
        $this->assertSame([FileClass::EDITED_OUTSIDE, null, null], [$entry['class'], $entry['category'], $entry['type']]);
        $this->assertSame($target, $entry['excepted']);
        $this->assertSame('editado fuera de los huecos: method:authorize (excepción aprobada)', $entry['reason']);

        // Sin las excepciones delante, la clasificación no las conoce.
        $this->assertSame('edited-outside', $this->classify('src/A.php')['type']);
    }

    public function test_en_un_paquete_las_rutas_del_informe_son_las_del_proyecto(): void
    {
        $package = $this->root.'/packages/pay';
        $reference = $this->root.'/.larapack/realign/cache/packages-pay/abc';
        $this->write($package, 'composer.json', (string) json_encode(['name' => 'acme/pay', 'autoload' => ['psr-4' => ['Acme\\Pay\\' => 'src']]]));
        (new Manifest($package))->record('Charge', 'Acme\\Pay', $this->write($package, 'src/A.php', self::REQUEST), self::REQUEST_STUB);
        (new Manifest($reference))->record('Charge', 'Acme\\Pay', $this->write($reference, 'src/A.php', self::REQUEST), self::REQUEST_STUB);
        $this->write($package, 'src/Http/Controllers/ChargeController.php', "<?php\nclass ChargeController {}\n");

        $classifier = new ScopeClassifier(['package' => 'acme/pay', 'path' => 'packages/pay', 'root' => $package, 'contract' => true, 'manifest' => true], $this->root, $reference);

        $entry = $classifier->classify('packages/pay/src/A.php');
        $this->assertSame(['packages/pay', 'acme/pay', 'packages/pay/src/A.php', 'src/A.php', FileClass::IDENTICAL], [$entry['scope'], $entry['package'], $entry['file'], $entry['path'], $entry['class']]);
        // Una ruta que ya es relativa al ámbito se toma tal cual.
        $this->assertSame('packages/pay/src/A.php', $classifier->classify('src/A.php')['file']);

        $this->assertSame([[
            'scope' => 'packages/pay', 'package' => 'acme/pay', 'file' => 'packages/pay/src/Http/Controllers/ChargeController.php', 'kind' => 'controller',
            'category' => Category::DECISION, 'type' => 'unmanaged-http',
        ]], $classifier->unmanaged());
    }

    public function test_el_codigo_http_y_de_modelos_que_nadie_gestiona(): void
    {
        $code = "<?php\nclass X {}\n";
        $this->write($this->root, 'src/Http/Controllers/Controller.php', $code);
        $this->write($this->root, 'src/Http/Controllers/Admin/ReportController.php', $code);
        $this->write($this->root, 'src/Http/Controllers/SpaController.php', SpaFallback::CONTROLLER_CODE);
        $this->write($this->root, 'src/Http/Controllers/Web/SpaController.php', $code);
        $this->write($this->root, 'src/Http/Controllers/notas.txt', 'no es PHP');
        $this->write($this->root, 'src/Http/Controllers/.oculto/Hidden.php', $code);
        $this->write($this->root, 'src/Http/Controllers/vendor/Third.php', $code);
        $this->write($this->root, 'src/Http/Requests/ReportRequest.php', $code);
        $this->write($this->root, 'src/Models/Legacy.php', $code);
        $this->write($this->root, 'src/Models/Traits/LegacyTrait.php', $code);
        $this->write($this->root, 'src/Models/Traits/Deep/Other.php', $code);
        $this->write($this->root, 'src/Services/Billing.php', $code);
        $this->write($this->root, 'src/Services/Deep/Tax.php', $code);
        $this->write($this->root, 'src/helpers.php', $code);
        $this->write($this->root, 'routes/web.php', $code);
        $this->write($this->root, 'routes/api/models/post.php', $code);
        $this->write($this->root, 'lib/Http/Controllers/OutsideController.php', $code);
        // Lo generado, registrado o que el contrato produce, no es código a mano.
        $this->tracked('src/Http/Controllers/PostController.php', $code, self::CONTROLLER_STUB);
        $this->tracked('src/Models/Post.php', $code, 'Model/ModelTemplate.txt');
        $this->produced('routes/api/models/post.php', $code, 'Routes/RoutesTemplate.txt');
        $this->produced('src/Models/Tag.php', $code, 'Model/ModelTemplate.txt');
        $this->write($this->root, 'src/Models/Tag.php', $code);

        $unmanaged = $this->classifier()->unmanaged();

        $this->assertSame([
            ['src/Http/Controllers/Admin/ReportController.php', 'controller', 'unmanaged-http'],
            // Un SpaController que no es el que escribe larapack:fix sí cuenta.
            ['src/Http/Controllers/Web/SpaController.php', 'controller', 'unmanaged-http'],
            ['src/Models/Legacy.php', 'model', 'unmanaged-models'],
            ['routes/web.php', 'routes', 'unmanaged-http'],
        ], array_map(fn (array $item): array => [$item['file'], $item['kind'], $item['type']], $unmanaged));
        $this->assertSame(['scope' => '.', 'package' => null, 'file' => 'src/Http/Controllers/Admin/ReportController.php', 'kind' => 'controller', 'category' => Category::DECISION, 'type' => 'unmanaged-http'], $unmanaged[0]);

        // Lo demás no se lista: se cuenta por su primer directorio.
        $this->assertSame(['(raíz)' => 1, 'Http' => 1, 'Models/Traits' => 2, 'Services' => 2], $this->classifier()->otherCode());

        // Una excepción aprobada sobre el código de hoy lo saca de la lista.
        $target = 'realign:unmanaged-http:src/Http/Controllers/Admin/ReportController.php';
        HandRolledLock::write($this->root, [$target => ['reason' => 'Informe interno.', 'fingerprint' => ExceptionTargets::fingerprint($this->root, $target)]]);
        $this->assertNotContains('src/Http/Controllers/Admin/ReportController.php', array_column($this->classifier(true, true)->unmanaged(), 'file'));
        $this->assertCount(3, $this->classifier(true, true)->unmanaged());
        $this->assertCount(4, $this->classifier()->unmanaged());
    }

    public function test_el_spa_controller_de_fix_reformateado_sigue_siendo_del_paquete(): void
    {
        $this->write($this->root, 'src/Http/Controllers/SpaController.php', str_replace('    ', "\t", SpaFallback::CONTROLLER_CODE));
        $this->assertSame([], $this->classifier()->unmanaged());

        $this->write($this->root, 'src/Http/Controllers/SpaController.php', SpaFallback::CONTROLLER_CODE."\n// tocado\n");
        $this->assertSame(['src/Http/Controllers/SpaController.php'], array_column($this->classifier()->unmanaged(), 'file'));
    }

    public function test_lo_que_la_raiz_carga_de_un_paquete_que_es_su_propio_ambito_es_de_ese_paquete(): void
    {
        $code = "<?php\nclass X {}\n";
        file_put_contents($this->root.'/composer.json', (string) json_encode(['name' => 'acme/app', 'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Acme\\Pay\\' => ['packages/pay/src', 'packages\\pay\\extra/'],
            'Acme\\Tools\\' => 'packages/tools/src',
            'Acme\\Gone\\' => 'no-existe/',
            'Acme\\Root\\' => '',
        ]]]));
        $this->write($this->root, 'app/Http/Controllers/HomeController.php', $code);
        $this->write($this->root, 'packages/pay/composer.json', '{"name": "acme/pay"}');
        $this->write($this->root, 'packages/pay/laraimport.json', '{"models": []}');
        $this->write($this->root, 'packages/pay/src/Http/Controllers/ChargeController.php', $code);
        $this->write($this->root, 'packages/pay/extra/Http/Controllers/ExtraController.php', $code);
        // Un paquete sin nada de LaraPack no es ámbito: su código lo mide la raíz.
        $this->write($this->root, 'packages/tools/composer.json', '{"name": "acme/tools"}');
        $this->write($this->root, 'packages/tools/src/Http/Controllers/ToolController.php', $code);

        $this->assertSame(
            ['app/Http/Controllers/HomeController.php', 'packages/tools/src/Http/Controllers/ToolController.php'],
            array_column($this->classifier()->unmanaged(), 'file')
        );
    }
}
