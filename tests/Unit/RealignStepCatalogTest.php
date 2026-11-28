<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\Scopes;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Las piezas pequeñas de las que cuelga el plan, llamadas directamente: qué
 * paso pide cada hallazgo de verify, qué se le dice a quien hace un paso
 * guiado (el playbook rellenado) y qué es un ámbito.
 *
 * RealignCatalogTest comprueba que el catálogo sea coherente consigo mismo y
 * con la documentación; esto, lo que cada función contesta.
 */
final class RealignStepCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()).'/larapack-catalog-'.bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);
    }

    public function test_que_paso_pide_cada_hallazgo_de_verify(): void
    {
        $for = fn (string $check, string $message = 'Lo que dice verify.'): ?string => StepTypes::forFinding(['check' => $check, 'message' => $message]);

        $this->assertSame('fix', $for('index-managed'));
        $this->assertSame('controller-shape', $for('request-handle'));
        $this->assertSame('declared-routes', $for('route-prefix'));
        $this->assertSame('security', $for('secret-exposed'));
        // La clasificación de archivos dice más que estas comprobaciones: no piden paso.
        $this->assertNull($for('customised'));
        $this->assertNull($for('missing-file'));
        $this->assertNull($for('empty-manifest'));
        // Una que el plan no conoce por su nombre se resuelve leyendo su mensaje.
        $this->assertSame('verify-other', $for('una-comprobacion-nueva'));

        // El único closure que larapack:fix sabe corregir lo dice su mensaje.
        $this->assertSame('route-closure', $for('route-closure', 'La ruta de la línea 5 lleva su acción en un closure.'));
        $this->assertSame('fix', $for('route-closure', 'Es el fallback de la SPA: lo corrige larapack:fix route-closure.'));
        // Ese mensaje en otra comprobación no la vuelve de fix.
        $this->assertSame('security', $for('secret-exposed', 'larapack:fix route-closure'));

        // Un trait de Http/Controllers que usa un controlador no se arregla
        // moviéndolo; el que sólo usan requests, sí.
        $this->assertSame('controller-concern', $for('controller-concern', 'El trait X está en Http/Controllers y ningún controlador lo usa: muévelo.'));
        $this->assertSame('controller-shape', $for('controller-concern', 'El trait X lo usa PostController: su lógica va a la request.'));
        $this->assertSame('security', $for('immutable-write', 'ningún controlador lo usa'));
    }

    public function test_un_tipo_que_no_esta_en_el_catalogo_es_un_hallazgo_de_verify_sin_nombre(): void
    {
        $this->assertTrue(StepTypes::exists('adopt'));
        $this->assertFalse(StepTypes::exists('inventado'));
        $this->assertFalse(StepTypes::exists(''));

        $this->assertSame(StepTypes::TYPES['adopt'], StepTypes::get('adopt'));
        $this->assertSame(StepTypes::TYPES['verify-other'], StepTypes::get('inventado'));
        $this->assertSame(Category::GUIDED, StepTypes::get('inventado')['category']);

        // En el orden del plan, lo que no se conoce va detrás de todo.
        $this->assertSame(0, StepTypes::position('relink'));
        $this->assertSame(2, StepTypes::position('adopt'));
        $this->assertSame(count(StepTypes::ORDER) - 1, StepTypes::position('unmanaged-models'));
        $this->assertSame(count(StepTypes::ORDER), StepTypes::position('inventado'));
    }

    public function test_las_clases_que_cumplen_y_las_categorias(): void
    {
        $this->assertSame([FileClass::IDENTICAL, FileClass::FORMAT_ONLY, FileClass::HOLE_EDITED], FileClass::CONFORMING);
        $this->assertSame(FileClass::ALL, array_keys(FileClass::DESCRIPTIONS));
        $this->assertCount(11, array_unique(FileClass::ALL));
        $this->assertSame([], array_diff(FileClass::CONFORMING, FileClass::ALL));
        $this->assertSame(['deterministic', 'guided', 'decision', 'out_of_scope'], Category::ALL);
        $this->assertSame(Category::ALL, array_keys(Category::LABELS));
    }

    public function test_un_playbook_existe_si_esta_en_el_paquete_con_un_nombre_limpio(): void
    {
        $this->assertTrue(Playbooks::exists('controller-to-request'));
        $this->assertTrue(Playbooks::exists(Playbooks::GENERIC));
        $this->assertFalse(Playbooks::exists('no-existe'));
        $this->assertFalse(Playbooks::exists(''));
        // Un nombre no es una ruta, ni con mayúsculas ni con algo detrás.
        $this->assertFalse(Playbooks::exists('../playbooks/generic'));
        $this->assertFalse(Playbooks::exists('Generic'));
        $this->assertFalse(Playbooks::exists("generic\n"));
        $this->assertFalse(Playbooks::exists('generic.md'));

        $names = Playbooks::names();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $this->assertContains('generic', $names);
        $this->assertContains('validate-to-rules', $names);
        $this->assertCount(count((array) glob(Playbooks::directory().'/*.md')), $names);
        $this->assertDirectoryExists(Playbooks::directory());

        $this->assertSame(['title', 'id', 'scope', 'description', 'files', 'findings', 'risk', 'verify', 'check', 'done_when'], Playbooks::placeholdersOf('generic'));
        // El de un tipo sin playbook propio es el genérico.
        $this->assertSame(Playbooks::placeholdersOf('generic'), Playbooks::placeholdersOf('no-existe'));
    }

    public function test_el_playbook_de_un_paso_se_rellena_con_lo_de_ese_paso(): void
    {
        $step = [
            'id' => 'edited-outside:Requests@packages/blog',
            'type' => 'edited-outside',
            'category' => Category::GUIDED,
            'subject' => 'Requests',
            'title' => 'Devolver a los huecos lo editado fuera de ellos',
            'description' => '2 elementos: archivos generados editados fuera de sus huecos.',
            'scope' => ['package' => 'acme/blog', 'path' => 'packages/blog'],
            'files' => ['no se usa: hay detalles'],
            'details' => [
                'files' => [
                    ['file' => 'packages/blog/src/A.php', 'class' => 'edited_outside', 'reason' => 'editado fuera de los huecos: method:authorize',
                        'members' => ['method:authorize', 'method:rules'], 'regenerated' => '.larapack/realign/cache/x/src/A.php'],
                    ['file' => 'packages/blog/src/B.php', 'class' => 'edited_outside', 'reason' => 'otra', 'members' => []],
                    'suelto',
                ],
                'unmanaged' => [['file' => 'packages/blog/routes/web.php', 'kind' => 'routes'], 'suelto'],
                'findings' => [
                    ['check' => 'secret-exposed', 'file' => 'packages/blog/src/R.php', 'message' => 'Sale el token.'],
                    ['check' => 'inconsistent-entity', 'file' => null, 'message' => 'Sin factory.'],
                    'suelto',
                ],
            ],
            'risk' => ['level' => 'medium', 'why' => 'Mueve lógica.'],
            'done_when' => ['kind' => 'verify', 'checks' => ['secret-exposed', 'request-handle'], 'files' => ['packages/blog/src/R.php', "packages/blog/src/It's raro.php"]],
        ];

        $rendered = Playbooks::render($step);

        $this->assertStringStartsWith("# Devolver a los huecos lo editado fuera de ellos\n\nPaso `edited-outside:Requests@packages/blog` · ámbito acme/blog (packages/blog)\n\n2 elementos: archivos generados editados fuera de sus huecos.\n", $rendered);
        $this->assertStringContainsString(
            "Archivos:\n\n"
            ."- `packages/blog/src/A.php` (edited_outside): editado fuera de los huecos: method:authorize — piezas: method:authorize, method:rules\n"
            ."  regenerado: `.larapack/realign/cache/x/src/A.php`\n"
            ."- `packages/blog/src/B.php` (edited_outside): otra\n"
            ."- `packages/blog/routes/web.php` (routes)\n\n",
            $rendered
        );
        $this->assertStringContainsString("Hallazgos de `verify`:\n\n- `secret-exposed` en `packages/blog/src/R.php`: Sale el token.\n- `inconsistent-entity` en `-`: Sin factory.\n\n", $rendered);
        $this->assertStringContainsString("## Riesgo\n\nmedium: Mueve lógica.\n", $rendered);
        // La orden de verify, acotada a las comprobaciones y los archivos del
        // paso; una ruta con algo que la shell interpreta va entre comillas.
        $this->assertStringContainsString(
            "    larapack:verify --format=json --package=acme/blog --check=secret-exposed --check=request-handle --path=packages/blog/src/R.php --path='packages/blog/src/It'\\''s raro.php'\n",
            $rendered
        );
        // Cambia comportamiento: la orden de check pide la prueba.
        $this->assertStringContainsString("    larapack:realign check edited-outside:Requests@packages/blog --tested=\"<la orden de pruebas del proyecto que corriste y pasó>\"\n", $rendered);
        $this->assertStringContainsString('Está hecho cuando `verify` no encuentra secret-exposed, request-handle en estos 2 archivos.', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
        $this->assertStringNotContainsString('no se usa', $rendered);
        $this->assertStringNotContainsString('suelto', $rendered);
    }

    public function test_un_paso_del_nucleo_sin_detalles_se_rellena_con_lo_que_hay(): void
    {
        $step = ['id' => 'adopt@.', 'type' => 'adopt', 'category' => Category::DETERMINISTIC, 'files' => ['src/A.php', 'src/B.php'], 'done_when' => ['kind' => 'decision']];

        $rendered = Playbooks::render($step);

        $this->assertStringStartsWith("# \n\nPaso `adopt@.` · ámbito app (núcleo)\n", $rendered);
        // Sin detalles, la lista de archivos del paso.
        $this->assertStringContainsString("Archivos:\n\n- `src/A.php`\n- `src/B.php`\n\n", $rendered);
        $this->assertStringContainsString("Hallazgos de `verify`:\n\n(ninguno)\n\n", $rendered);
        $this->assertStringContainsString("    larapack:verify --format=json --package=app\n", $rendered);
        // No cambia comportamiento: check sin --tested.
        $this->assertStringContainsString("    larapack:realign check adopt@.\n", $rendered);
        $this->assertStringContainsString("## Riesgo\n\n?: \n", $rendered);
        $this->assertStringContainsString('Está hecho cuando hay una decisión registrada.', $rendered);

        $empty = Playbooks::render(['id' => 'x@.']);
        $this->assertStringContainsString("Archivos:\n\n(ninguno)\n\n", $empty);
        $this->assertStringContainsString('Está hecho cuando no se sabe comprobar.', $empty);

        // Un paquete que no dice su ruta.
        $this->assertStringContainsString('ámbito acme/blog (?)', Playbooks::render(['id' => 'x@.', 'scope' => ['package' => 'acme/blog']]));
        // La orden de verify nombra como mucho veinte archivos.
        $many = Playbooks::render(['id' => 'x@.', 'done_when' => ['kind' => 'verify', 'checks' => ['x'], 'files' => array_map(fn (int $n): string => "src/F{$n}.php", range(1, 25))]]);
        $this->assertStringContainsString('--path=src/F20.php', $many);
        $this->assertStringNotContainsString('--path=src/F21.php', $many);
        $this->assertStringContainsString('en estos 25 archivos', $many);
    }

    public function test_los_anexos_van_con_el_playbook_del_tipo_y_no_con_el_de_una_opcion(): void
    {
        $step = ['id' => 'controller-shape@src/X.php', 'type' => 'controller-shape', 'category' => Category::GUIDED, 'playbook' => 'controller-to-request',
            'annexes' => ['validate-to-rules', 'no-existe', 7, 'raw-request-to-formrequest']];

        $rendered = Playbooks::render($step);

        $this->assertStringStartsWith('# Llevar la lógica del controlador a su FormRequest', Playbooks::render(['title' => 'Llevar la lógica del controlador a su FormRequest'] + $step));
        $this->assertSame(2, substr_count($rendered, "\n\n---\n\n# Anexo: "));
        $this->assertStringContainsString("\n\n---\n\n# Anexo: la validación del controlador pasa a `rules()`\n\nPaso `controller-shape@src/X.php` · ámbito app (núcleo)\n", $rendered);
        $this->assertLessThan(strpos($rendered, 'Anexo: la acción recibe'), strpos($rendered, 'Anexo: la validación'));
        $this->assertStringNotContainsString('{{', $rendered);

        // El de una opción (cómo hacer lo decidido) va solo.
        $option = Playbooks::render($step, 'closure-to-controller');
        $this->assertStringNotContainsString('Anexo:', $option);
        $this->assertNotSame($rendered, $option);
        // Sin anexos, el playbook y nada más.
        $this->assertStringNotContainsString("\n---\n", Playbooks::render(['annexes' => []] + $step));
    }

    public function test_los_anexos_los_piden_los_mensajes_de_verify_de_un_controlador(): void
    {
        $validate = ['message' => 'store() no delega: valida en el controlador con validate().'];
        $raw = ['message' => 'index() no delega: recibe Illuminate\\Http\\Request.'];
        $with = ['message' => 'show() trabaja con illuminate\\http\\request y no con su FormRequest.'];

        $this->assertSame(['validate-to-rules'], Playbooks::annexesFor('controller-shape', [$validate, $validate]));
        $this->assertSame(['raw-request-to-formrequest'], Playbooks::annexesFor('controller-shape', [$raw]));
        $this->assertSame(['raw-request-to-formrequest'], Playbooks::annexesFor('controller-shape', [$with]));
        $this->assertSame(['raw-request-to-formrequest', 'validate-to-rules'], Playbooks::annexesFor('controller-shape', [$raw, $validate, $with]));
        $this->assertSame(['validate-to-rules', 'raw-request-to-formrequest'], Playbooks::annexesFor('controller-shape', [['message' => $validate['message'].' '.$raw['message']]]));
        $this->assertSame([], Playbooks::annexesFor('controller-shape', [['message' => 'usa Illuminate\\Http\\RequestFactory'], ['message' => 'Illuminate\\Http\\Request sin más'], ['sin' => 'mensaje']]));
        $this->assertSame([], Playbooks::annexesFor('controller-shape', []));
        // Sólo los de un controlador.
        $this->assertSame([], Playbooks::annexesFor('security', [$validate, $raw]));
    }

    public function test_la_comprobacion_de_terminado_en_palabras(): void
    {
        $this->assertSame('`verify` no encuentra controller-shape, request-handle en estos 1 archivos.', Playbooks::describeCondition(['kind' => 'verify', 'checks' => ['controller-shape', 'request-handle'], 'files' => ['a']]));
        $this->assertSame('`verify` no encuentra inconsistent-entity.', Playbooks::describeCondition(['kind' => 'verify', 'checks' => ['inconsistent-entity'], 'files' => []]));
        $this->assertSame('cada uno de los 2 archivos queda como identical o format_only.', Playbooks::describeCondition(['kind' => 'classification', 'files' => ['a', 'b'], 'accept' => ['identical', 'format_only']]));
        $this->assertSame('ninguno de los 1 archivos queda como renamed ni missing.', Playbooks::describeCondition(['kind' => 'classification', 'files' => ['a'], 'reject' => ['renamed', 'missing']]));
        $this->assertSame('ninguno de los 3 archivos tiene un paso pendiente.', Playbooks::describeCondition(['kind' => 'classification', 'files' => ['a', 'b', 'c'], 'resolved' => true]));
        $this->assertSame('ninguno de los 2 archivos sigue sin gestionar.', Playbooks::describeCondition(['kind' => 'unmanaged', 'files' => ['a', 'b']]));
        $this->assertSame('el laraimport.json del ámbito valida.', Playbooks::describeCondition(['kind' => 'contract', 'scope' => '.']));
        $this->assertSame('el laraimport.json declara on_delete/on_update en cada una de las 2 llaves.', Playbooks::describeCondition(['kind' => 'foreign-keys', 'columns' => [[], []]]));
        $this->assertSame('hay una decisión registrada.', Playbooks::describeCondition(['kind' => 'decision']));
        $this->assertSame('no se sabe comprobar.', Playbooks::describeCondition(['kind' => 'telepatia']));
        $this->assertSame('no se sabe comprobar.', Playbooks::describeCondition([]));
    }

    public function test_un_playbook_sin_rellenar_dice_que_pondria_en_cada_marcador(): void
    {
        $described = Playbooks::describe('generic');

        $this->assertStringStartsWith("# <el título del tipo de paso>\n\nPaso `<el id del paso>` · ámbito <el ámbito: `app (núcleo)` o el paquete con su ruta>\n", $described);
        $this->assertStringNotContainsString('{{', $described);
        $this->assertSame($described, Playbooks::describe('no-existe'));
        $this->assertCount(11, Playbooks::PLACEHOLDERS);
    }

    public function test_los_ambitos_de_un_proyecto_con_larapack_son_la_raiz_y_los_paquetes_que_lo_usan(): void
    {
        $this->write('composer.json', (string) json_encode(['name' => 'acme/app', 'repositories' => [['type' => 'path', 'url' => 'modules/*'], ['type' => 'path', 'url' => '../fuera-'.basename($this->root)]]]));
        $this->write('laraimport.json', '{"models": []}');
        $this->write('packages/blog/composer.json', '{"name": "acme/blog"}');
        $this->write('packages/blog/laraimport.json', '{"models": []}');
        $this->write('packages/blog/.larapack/manifest.json', '{}');
        $this->write('packages/legacy/.larapack/manifest.json', '{}');
        // Sin nada de LaraPack: su código lo mide la raíz.
        $this->write('packages/tools/composer.json', '{"name": "acme/tools"}');
        $this->write('modules/pay/composer.json', '{"name": ""}');
        $this->write('modules/pay/laraimport.json', '{"models": []}');
        // Un repositorio `path` de fuera de la raíz es una dependencia, no un ámbito.
        $outside = dirname($this->root).'/fuera-'.basename($this->root);
        mkdir($outside);
        file_put_contents($outside.'/laraimport.json', '{"models": []}');

        try {
            $scopes = new Scopes($this->root);

            $this->assertSame([
                ['package' => null, 'path' => '.', 'root' => $this->root, 'contract' => true, 'manifest' => false],
                // Sin nombre de Composer se llama por su ruta.
                ['package' => 'modules/pay', 'path' => 'modules/pay', 'root' => $this->root.'/modules/pay', 'contract' => true, 'manifest' => false],
                ['package' => 'acme/blog', 'path' => 'packages/blog', 'root' => $this->root.'/packages/blog', 'contract' => true, 'manifest' => true],
                ['package' => 'packages/legacy', 'path' => 'packages/legacy', 'root' => $this->root.'/packages/legacy', 'contract' => false, 'manifest' => true],
            ], $scopes->all());
            $this->assertSame(['modules/pay', 'packages/blog', 'packages/legacy'], $scopes->packagePaths());
            $this->assertSame($this->root, $scopes->sources()->root());

            // --package acota, por el nombre de Composer, el directorio, la ruta o `app`.
            $this->assertSame(['packages/blog'], array_column($scopes->all(['acme/blog']), 'path'));
            $this->assertSame(['.', 'packages/blog'], array_column($scopes->all(['blog', 'app']), 'path'));
            $this->assertSame([$this->root.'/packages/blog', $this->root], $scopes->resolve(['packages/blog', 'app', 'acme/blog']));
            $this->assertSame([], $scopes->resolve([]));
            // Un paquete que existe y no es ámbito: acotar a él no da nada.
            $this->assertSame([], $scopes->all(['tools']));
        } finally {
            exec('rm -rf '.escapeshellarg($outside));
        }
    }

    public function test_un_nombre_de_paquete_que_no_existe_falla_en_vez_de_acotar_a_nada(): void
    {
        $this->write('composer.json', '{"name": "acme/app"}');
        $this->write('packages/blog/composer.json', '{"name": "acme/blog"}');

        try {
            (new Scopes($this->root))->all(['nadie']);
            $this->fail('Acotar a un paquete que no existe parecería un proyecto sin desviaciones.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'No hay un paquete local nadie. Usa el nombre de su directorio en packages/, su ruta, su nombre de Composer o `app` para el núcleo. Hay: app, acme/blog.',
                $e->getMessage()
            );
        }
    }

    public function test_en_un_proyecto_sin_larapack_cada_paquete_es_su_propio_ambito(): void
    {
        $this->write('composer.json', '{"name": "acme/app"}');
        $this->write('packages/tools/composer.json', '{"name": "acme/tools"}');
        $this->write('packages/blog/src/x.php', '<?php');

        $scopes = new Scopes($this->root);

        // Ni la raíz ni los paquetes tienen nada: cada uno decide lo suyo.
        $this->assertSame([
            ['package' => null, 'path' => '.', 'root' => $this->root, 'contract' => false, 'manifest' => false],
            ['package' => 'packages/blog', 'path' => 'packages/blog', 'root' => $this->root.'/packages/blog', 'contract' => false, 'manifest' => false],
            ['package' => 'acme/tools', 'path' => 'packages/tools', 'root' => $this->root.'/packages/tools', 'contract' => false, 'manifest' => false],
        ], $scopes->all());

        // Con sólo el manifiesto en la raíz ya es un proyecto con LaraPack.
        $this->write('.larapack/manifest.json', '{}');
        $this->assertSame(['.'], array_column((new Scopes($this->root))->all(), 'path'));
        $this->assertSame([], (new Scopes($this->root))->packagePaths());
        $this->assertSame('acme/tools', $scopes->nameOf($this->root.'/packages/tools'));
        $this->assertSame('packages/blog', $scopes->nameOf($this->root.'/packages/blog'));
    }
}
