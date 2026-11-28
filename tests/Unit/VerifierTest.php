<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Las comprobaciones del Verifier que dependen del manifiesto, sobre un
 * manifiesto escrito a mano: qué cuenta cada una, cuántas veces y con qué
 * mensaje.
 *
 * VerifyTest y VerifyDeclaredEndpointsTest generan un proyecto y lo rompen
 * como lo rompería un agente. Aquí el manifiesto se escribe directamente para
 * fijar lo que la generación no deja ver: dos hallazgos del mismo check en un
 * modelo, el segundo modelo de la lista, el modelo con metas entre otros sin
 * ellas, el texto entero de cada mensaje.
 *
 * Con los avisos convertidos en excepciones, como bajo Artisan.
 */
final class VerifierTest extends TestCase
{
    private int $errorReporting;

    private string $root;

    /**
     * @var array{version: int, package: array{namespace: string, files: array<string, array{stub: string, hash: string}>}, models: array<string, array<string, mixed>>}
     */
    private array $manifest = ['version' => 1, 'package' => ['namespace' => '', 'files' => []], 'models' => []];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-verifier-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->root = (string) realpath($this->root);
        // Todos los avisos, como Laravel (error_reporting(-1)): la
        // configuración de PHP de cada máquina no decide qué se ve.
        $this->errorReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $level, string $message): bool {
            // Lo silenciado con @ es parte del código probado, no un aviso.
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $level);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        error_reporting($this->errorReporting);
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove("{$path}/{$entry}");
                }
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function write(string $relative, string $content): void
    {
        $file = $this->root.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $content);
    }

    /**
     * Un archivo generado: en disco (salvo $content null) y en el manifiesto,
     * con su hash, o con otro si se editó después.
     */
    private function generated(string $model, string $relative, string $stub, ?string $content = '', bool $edited = false): void
    {
        if ($content !== null) {
            $this->write($relative, $content);
        }

        $entry = ['stub' => $stub, 'hash' => $content === null || $edited ? 'editado' : hash('sha256', $content)];

        if ($model === '') {
            $this->manifest['package']['files'][$relative] = $entry;
        } else {
            $this->manifest['models'][$model]['namespace'] ??= 'Acme\\School\\';
            $this->manifest['models'][$model]['files'][$relative] = $entry;
        }
    }

    /**
     * @param  array<string, mixed>  $declaration
     */
    private function declare(string $model, array $declaration): void
    {
        $this->manifest['models'][$model]['declaration'] = $declaration;
        $this->manifest['models'][$model]['namespace'] ??= 'Acme\\School\\';
        $this->manifest['models'][$model]['files'] ??= [];
    }

    private function verifier(): Verifier
    {
        $this->write('.larapack/manifest.json', (string) json_encode($this->manifest));

        return new Verifier(new Manifest($this->root));
    }

    /**
     * [check, model, file, message] de cada hallazgo de estos checks.
     *
     * @param  list<string>  $checks
     * @return list<array{0: string, 1: string|null, 2: string|null, 3: string}>
     */
    private function findings(array $checks): array
    {
        $findings = $this->verifier()->run($checks);
        $this->assertTrue(array_is_list($findings));

        return array_map(fn (array $f): array => [$f['check'], $f['model'], $f['file'], $f['message']], $findings);
    }

    /**
     * @return list<string>
     */
    private function messages(string $check): array
    {
        return array_column($this->findings([$check]), 3);
    }

    // ARCHIVOS

    public function test_cada_archivo_que_falta_y_cada_editado(): void
    {
        $this->generated('', 'src/Providers/AppServiceProvider.php', 'Package/Provider.txt', 'p', true);
        $this->generated('', 'config/x.php', 'Package/Config.txt', null);
        $this->generated('Alfa', 'src/Models/Alfa.php', 'Model/ModelTemplate.txt', null);
        $this->generated('Alfa', 'src/Policies/AlfaPolicy.php', 'Policy/PolicyTemplate.txt', null);
        $this->generated('Beta', 'src/Models/Beta.php', 'Model/ModelTemplate.txt', 'b', true);
        $this->generated('Beta', 'src/Policies/BetaPolicy.php', 'Policy/PolicyTemplate.txt', 'b', true);
        $this->generated('Beta', 'src/Observers/BetaObserver.php', 'Observer/ObserverTemplate.txt', null);

        $missing = 'Se generó pero ya no existe. Regenéralo con --force o retíralo del manifiesto.';
        $edited = 'Editado a mano desde que se generó; --force no lo tocará.';

        $this->assertSame([
            ['missing-file', null, 'config/x.php', $missing],
            ['missing-file', 'Alfa', 'src/Models/Alfa.php', $missing],
            ['missing-file', 'Alfa', 'src/Policies/AlfaPolicy.php', $missing],
            ['missing-file', 'Beta', 'src/Observers/BetaObserver.php', $missing],
            ['customised', null, 'src/Providers/AppServiceProvider.php', $edited],
            ['customised', 'Beta', 'src/Models/Beta.php', $edited],
            ['customised', 'Beta', 'src/Policies/BetaPolicy.php', $edited],
        ], $this->findings(['missing-file', 'customised']));
    }

    public function test_un_paquete_sin_modelos_tambien_se_comprueba(): void
    {
        $this->generated('', 'src/Providers/AppServiceProvider.php', 'Package/Provider.txt', 'p', true);
        $this->generated('', 'config/x.php', 'Package/Config.txt', null);

        $this->assertSame([
            ['missing-file', null, 'config/x.php', 'Se generó pero ya no existe. Regenéralo con --force o retíralo del manifiesto.'],
            ['customised', null, 'src/Providers/AppServiceProvider.php', 'Editado a mano desde que se generó; --force no lo tocará.'],
        ], $this->findings([]));
    }

    public function test_usa_el_manifiesto_que_recibe_y_no_el_del_directorio_actual(): void
    {
        $this->generated('Alfa', 'src/Models/Alfa.php', 'Model/ModelTemplate.txt', null);
        $verifier = $this->verifier();

        $elsewhere = $this->root.'/elsewhere';
        mkdir($elsewhere);

        $checks = ProjectRoot::using($elsewhere, fn (): array => array_column($verifier->run(['missing-file', 'empty-manifest']), 'check'));

        $this->assertSame(['missing-file'], $checks);
    }

    // CONSISTENCIA ENTRE ENTIDADES

    public function test_con_dos_entidades_ya_se_comparan(): void
    {
        $this->generated('Alfa', 'src/Models/Alfa.php', 'Model/ModelTemplate.txt');
        $this->generated('Alfa', 'src/Policies/AlfaPolicy.php', 'Policy/PolicyTemplate.txt');
        $this->generated('Beta', 'src/Models/Beta.php', 'Model/ModelTemplate.txt');

        $this->assertSame([
            ['inconsistent-entity', 'Beta', null, 'No tiene componente Policy y todas las demás entidades sí.'],
        ], $this->findings(['inconsistent-entity']));
    }

    public function test_solo_cuenta_lo_que_tienen_todas_las_demas(): void
    {
        foreach (['Alfa', 'Beta', 'Gama'] as $model) {
            $this->generated($model, "src/Models/{$model}.php", 'Model/ModelTemplate.txt');
        }

        $this->declare('Suelta', ['actions' => ['index'], 'immutable' => true]);
        $this->generated('Suelta', 'src/Policies/SueltaPolicy.php', 'Policy/PolicyTemplate.txt');

        // Metas en dos de tres: es una decisión por modelo, no deriva.
        $this->generated('Alfa', 'src/Models/AlfaMeta.php', 'ModelMetas/ModelMetaTemplate.txt');
        $this->generated('Beta', 'src/Models/BetaMeta.php', 'ModelMetas/ModelMetaTemplate.txt');

        // Un componente que sólo tiene una entidad no delata a las demás.
        $this->generated('Beta', 'src/Observers/BetaObserver.php', 'Observer/ObserverTemplate.txt');

        // Varias requests son un solo componente: si falta, se dice una vez.
        foreach (['Alfa', 'Beta'] as $model) {
            foreach (['Index', 'Show', 'Create'] as $action) {
                $this->generated($model, "src/Http/Requests/{$model}/{$action}Request.php", "Requests/{$action}Request.txt");
            }
        }

        $this->assertSame([
            ['inconsistent-entity', 'Gama', null, 'No tiene componente Requests y todas las demás entidades sí.'],
        ], $this->findings(['inconsistent-entity']));
    }

    // EL PREFIJO DE RUTAS

    public function test_el_prefijo_de_rutas_de_cada_contrato(): void
    {
        $this->generated('Post', 'resources/vue/post/controller.js', 'ModelView/controller.js', '// nada');
        $this->generated('Post', 'resources/vue/post/model.js', 'ModelView/model.js', "export const API_ROUTE_PREFIX = 'api.otra.post.';");
        $this->generated('Post', 'resources/react/post/model.js', 'ModelView/model.js', "export const API_ROUTE_PREFIX = 'api.otra.post.';");
        $this->generated('Post', 'resources/vue/post-tag/model.js', 'ModelView/model.js', "export const API_ROUTE_PREFIX = 'api.acme.school.post_tag.';");

        $this->assertSame([
            'No declara API_ROUTE_PREFIX.',
            "API_ROUTE_PREFIX es 'api.otra.post.' y el RouteServiceProvider registra 'api.acme.school.post.'.",
            "API_ROUTE_PREFIX es 'api.otra.post.' y el RouteServiceProvider registra 'api.acme.school.post.'.",
        ], $this->messages('route-prefix'));
        $this->assertSame(['error', 'error', 'error'], array_column($this->verifier()->run(['route-prefix']), 'level'));
    }

    // LO QUE NO SE DECLARÓ

    public function test_lo_que_queda_de_una_accion_que_ya_no_se_declara(): void
    {
        $this->declare('Post', ['actions' => ['index'], 'endpoints' => [['name' => 'showcase', 'route' => 'showcase', 'request' => 'ShowcaseRequest', 'ability' => 'showcase', 'operation' => 'showcase', 'public' => false, 'target' => 'record']]]);
        $this->generated('Post', 'src/routes/post.php', 'Route/RouteTemplate.txt', "->name('index')\n->name('showcase')\n->name('show')\n->name('force.delete')");
        $this->generated('Post', 'src/Http/Controllers/PostController.php', 'Controller/ControllerTemplate.txt', "<?php class PostController {\n public function index() {}\n public function show() {}\n}", true);
        $this->generated('Post', 'src/Models/Post.php', 'Model/ModelTemplate.txt');
        $this->write('src/Http/Requests/Post/ShowRequest.php', '<?php');

        $this->assertSame([
            'Hay una ruta para show, que Post ya no declara. Lo generó LaraPack cuando la declaraba: si la retiraste del laraimport, regenera con --force y borra lo que quede; si no, vuelve a declararla.',
            'El controlador tiene un método para show, que Post no declara. Alguien lo escribió a mano: declara la acción en el laraimport o retíralo.',
            'Existe el request de show, que Post no declara. Alguien lo escribió a mano: declara la acción en el laraimport o retíralo.',
            'Hay una ruta para forceDelete, que Post ya no declara. Lo generó LaraPack cuando la declaraba: si la retiraste del laraimport, regenera con --force y borra lo que quede; si no, vuelve a declararla.',
        ], $this->messages('route-not-declared'));
    }

    public function test_lo_que_nadie_declaro_en_el_controlador_las_rutas_y_las_requests(): void
    {
        $this->declare('Post', ['endpoints' => [['name' => 'publish', 'route' => 'publish', 'request' => 'PublishRequest', 'ability' => 'publish', 'operation' => 'publish', 'public' => false, 'target' => 'record']]]);
        $this->generated('Post', 'src/Models/Post.php', 'Model/ModelTemplate.txt');
        $this->generated('Post', 'src/Http/Controllers/PostController.php', 'Controller/ControllerTemplate.txt',
            "<?php class PostController {\n public function index() {}\n public function publish() {}\n public static function middleware() {}\n public function archive() {}\n private function helper() {}\n abstract public function nothing();\n}\nclass Extra {\n public function archive() {}\n}");
        $this->generated('Post', 'src/routes/post.php', 'Route/RouteTemplate.txt',
            "Route::get('a')->name('index');\nRoute::post('b')->name('publish');\nRoute::post('c')->name( \"archive\" );\nRoute::get('d');\n", true);
        $this->generated('Post', 'src/Http/Requests/Post/ArchiveRequest.php', 'Requests/EndpointRequest.txt');
        $this->write('src/Http/Requests/Post/IndexRequest.php', '<?php');
        $this->write('src/Http/Requests/Post/PublishRequest.php', '<?php');
        $this->write('src/Http/Requests/Post/OtherRequest.php', '<?php');

        $byHand = 'Alguien lo escribió a mano: declara el endpoint en el laraimport (endpoints, o controllers si no tiene modelo) y regenera.';
        $retired = 'Lo generó LaraPack para un endpoint que ya no está declarado: si lo retiraste del laraimport, regenera con --force para quitarlo del controlador y de las rutas, y borra su request y su test; si no, vuelve a declararlo.';

        $this->assertSame([
            "El controlador tiene un método archive que no es una acción ni un endpoint declarado. {$retired}",
            "Hay una ruta 'archive' que no es una acción ni un endpoint declarado. {$byHand}",
            'Hay rutas sin nombre. Las generadas lo llevan todas; ésa la escribió alguien a mano: declara el endpoint en el laraimport (endpoints, o controllers si no tiene modelo) y regenera.',
            "Existe una request que no es de ninguna acción ni endpoint declarado. {$retired}",
            "Existe una request que no es de ninguna acción ni endpoint declarado. {$byHand}",
        ], array_values(array_filter($this->messages('route-not-declared'), fn (string $m): bool => ! str_starts_with($m, 'Hay una ruta para') && ! str_starts_with($m, 'El controlador tiene un método para') && ! str_starts_with($m, 'Existe el request de'))));
    }

    // LOS ENDPOINTS DECLARADOS

    public function test_lo_que_le_falta_a_un_endpoint_declarado(): void
    {
        $endpoint = fn (string $name, array $extra = []): array => $extra + ['name' => $name, 'route' => $name, 'request' => ucfirst($name).'Request', 'ability' => $name, 'operation' => $name, 'public' => false, 'target' => 'record'];

        $this->declare('Post', ['endpoints' => [$endpoint('publish'), $endpoint('archive', ['public' => true, 'target' => 'collection']), $endpoint('reindex', ['ability' => 'update'])]]);
        $this->generated('Post', 'src/Models/Post.php', 'Model/ModelTemplate.txt');
        $this->generated('Post', 'src/Http/Controllers/PostController.php', 'Controller/ControllerTemplate.txt', "<?php class PostController {\n public function archive() {}\n public function reindex() {}\n}", true);
        $this->generated('Post', 'src/routes/post.php', 'Route/RouteTemplate.txt', null);
        $this->generated('Post', 'src/Policies/PostPolicy.php', 'Policy/PolicyTemplate.txt', "<?php class PostPolicy {\n public function publish() {}\n}");
        $this->write('src/Models/Traits/Operations/PostOperations.php', "<?php trait PostOperations {\n public function publish() {}\n public function reindex() {}\n}");
        $this->write('src/Http/Requests/Post/PublishRequest.php', '<?php');
        $this->write('src/Http/Requests/Post/ArchiveRequest.php', '<?php');
        $this->generated('Post', 'resources/vue/post/model.js', 'ModelView/model.js', "export const publish = 1;\nexport const archive = 1;");
        $this->generated('Post', 'resources/vue/post/controller.js', 'ModelView/controller.js', "export const publish = 1;\nexport const reindex = 1;", true);

        $this->assertSame([
            'El endpoint publish declarado no tiene método en el controlador. Regenerar no basta: el controlador está editado a mano y --force lo conserva. Lleva lo que tenga de más a la request o a la operación del modelo, bórralo y regenera.',
            "El endpoint publish declarado no tiene ruta 'publish'. Regenera con --force: el archivo de rutas no se escribe a mano.",
            "El endpoint archive declarado no tiene ruta 'archive'. Regenera con --force: el archivo de rutas no se escribe a mano.",
            'El endpoint archive declarado no tiene función en el contrato JS: falta `export const archive`. El contrato está editado a mano y --force lo conserva: escríbela con la forma de las de los demás endpoints, o devuelve el archivo a lo generado y regenera.',
            "El endpoint archive declarado pregunta a la política por 'archive' y la política no lo tiene. Añade `public function archive(?User \$user): Response|bool` y decide quién puede; sin él, Laravel lo niega siempre.",
            'El endpoint archive declarado delega en Post::archive($request) y el trait de operaciones no lo tiene. Escríbelo ahí: es donde va la lógica del endpoint.',
            "El endpoint reindex declarado no tiene ruta 'reindex'. Regenera con --force: el archivo de rutas no se escribe a mano.",
            'El endpoint reindex declarado no tiene su ReindexRequest. Regenera: se crea sin tocar lo demás.',
            'El endpoint reindex declarado no tiene función en el contrato JS: falta `export const reindex`. Regenera con --vue o --react y --force: el contrato no se escribe a mano.',
        ], $this->messages('endpoint-missing'));
    }

    public function test_sin_politica_no_se_pregunta_por_la_habilidad(): void
    {
        $this->declare('Post', ['endpoints' => [['name' => 'archive', 'route' => 'archive', 'request' => 'ArchiveRequest', 'ability' => 'archive', 'operation' => 'archive', 'public' => false, 'target' => 'record']]]);
        $this->generated('Post', 'src/Models/Post.php', 'Model/ModelTemplate.txt');
        $this->generated('Post', 'src/Http/Controllers/PostController.php', 'Controller/ControllerTemplate.txt', "<?php class PostController {\n public function archive() {}\n}");
        $this->generated('Post', 'src/routes/post.php', 'Route/RouteTemplate.txt', "->name('archive')");
        $this->write('src/Models/Traits/Operations/PostOperations.php', "<?php trait PostOperations {\n public function archive() {}\n}");
        $this->write('src/Http/Requests/Post/ArchiveRequest.php', '<?php');

        $this->assertSame([], $this->messages('endpoint-missing'));
    }

    public function test_un_controlador_sin_modelo_no_tiene_politica_ni_operaciones(): void
    {
        $endpoint = fn (string $name): array => ['name' => $name, 'route' => $name, 'request' => ucfirst($name).'Request', 'ability' => $name, 'operation' => $name, 'public' => false, 'target' => 'collection'];

        $this->declare('Desk', ['controller' => true, 'actions' => [], 'endpoints' => [$endpoint('overview'), $endpoint('stats')]]);
        $this->generated('Desk', 'src/Http/Controllers/DeskController.php', 'Controller/ControllerTemplate.txt', "<?php class DeskController {\n public function overview() {}\n public function stats() {}\n}");
        $this->generated('Desk', 'src/routes/desk.php', 'Route/RouteTemplate.txt', "->name('overview')");
        $this->write('src/Http/Requests/Desk/OverviewRequest.php', '<?php');
        $this->write('src/Http/Requests/Desk/StatsRequest.php', '<?php');

        $this->assertSame([
            "El endpoint stats declarado no tiene ruta 'stats'. Regenera con --force: el archivo de rutas no se escribe a mano.",
        ], $this->messages('endpoint-missing'));
    }

    // INMUTABLES Y SECRETOS

    public function test_cada_camino_de_escritura_de_un_inmutable(): void
    {
        $this->declare('Ledger', ['immutable' => true, 'actions' => ['index', 'show']]);
        $this->generated('Ledger', 'src/Models/Ledger.php', 'Model/ModelTemplate.txt', '<?php static::updating(fn () => false);');
        $this->generated('Ledger', 'src/routes/ledger.php', 'Route/RouteTemplate.txt', "->name('update')\n->name('delete')");

        $this->assertSame([
            'Hay una ruta para update, y Ledger es inmutable: sus filas no se modifican ni se borran.',
            'Hay una ruta para delete, y Ledger es inmutable: sus filas no se modifican ni se borran.',
            'El modelo ya no rechaza modificaciones. Sin la guarda de booted(), cualquier save() o delete() del propio paquete modifica una fila que no debería cambiar.',
        ], $this->messages('immutable-write'));
    }

    public function test_cada_salida_de_un_secreto(): void
    {
        $this->generated('Author', 'src/Models/Author.php', 'Model/ModelTemplate.txt', '<?php');
        $this->declare('User', ['secret' => ['token', 'name', 'pin']]);
        $this->generated('User', 'src/Models/User.php', 'Model/ModelTemplate.txt', "<?php\nprotected \$hidden = ['pin', \"name\"];\nprotected \$export_cols = ['token', 'pin'];");
        $this->generated('User', 'src/Http/Resources/UserResource.php', 'Resource/ResourceTemplate.txt', "<?php ['name' => 'x', 'route' => 'y', \$this->token, 'pin' => 1];");
        $this->generated('User', 'resources/vue/user/model.js', 'ModelView/model.js', "{ id: 'pin' }");

        $this->assertSame([
            ['src/Models/User.php', "'token' es secreta y no está en \$hidden: toArray() la devuelve, y con él el Resource."],
            ['src/Models/User.php', "'token' es secreta y está en \$export_cols: sale en la exportación."],
            ['src/Http/Resources/UserResource.php', "'token' es secreta y el Resource la nombra: sale por la API aunque esté en \$hidden."],
            ['src/Models/User.php', "'pin' es secreta y está en \$export_cols: sale en la exportación."],
            ['src/Http/Resources/UserResource.php', "'pin' es secreta y el Resource la nombra: sale por la API aunque esté en \$hidden."],
            ['resources/vue/user/model.js', "'pin' es secreta y es columna de la tabla."],
        ], array_map(fn (array $f): array => [$f[2], $f[3]], $this->findings(['secret-exposed'])));
    }

    public function test_un_secreto_sin_resource_ni_modelo_no_avisa(): void
    {
        $this->declare('User', ['secret' => ['token']]);
        $this->generated('User', 'src/Http/Controllers/UserController.php', 'Controller/ControllerTemplate.txt', '<?php');

        $this->assertSame([], $this->findings(['secret-exposed']));
    }

    // LOS TESTS DE ENDPOINTS DE ANTES DE 9.1

    public function test_los_tests_de_endpoints_de_antes_de_9_1(): void
    {
        $this->generated('Archive', 'src/Models/Archive.php', 'Model/ModelTemplate.txt');
        $this->generated('Course', 'src/Models/Course.php', 'Model/ModelTemplate.txt');
        $this->declare('Lookup', ['actions' => []]);
        $this->generated('Lookup', 'src/Models/Lookup.php', 'Model/ModelTemplate.txt');
        $this->write('tests/Feature/Models/LookupEndpointsTest.php', "<?php\n");
        $this->declare('Post', ['actions' => ['show']]);
        $this->generated('Post', 'src/Models/Post.php', 'Model/ModelTemplate.txt');
        $this->write('tests/Feature/Models/CourseEndpointsTest.php', "<?php\nfunction test_x_cada_accion_requiere_sesion() {}\n");
        $this->write('tests/Feature/Models/PostEndpointsTest.php', "<?php\n");

        $this->assertSame([
            'CourseEndpointsTest no tiene test_…_cada_accion_pregunta_a_la_politica, test_…_index_pasa_por_su_managed_filter: se generó antes de 9.1 y no prueba lo que cierra la autorización. No se regenera solo: guarda lo que le añadiste, bórralo, corre `larapack:import` (sin --force basta: crea el que falta) y vuelve a pegar lo tuyo.',
            'PostEndpointsTest no tiene test_…_cada_accion_requiere_sesion, test_…_cada_accion_pregunta_a_la_politica: se generó antes de 9.1 y no prueba lo que cierra la autorización. No se regenera solo: guarda lo que le añadiste, bórralo, corre `larapack:import` (sin --force basta: crea el que falta) y vuelve a pegar lo tuyo.',
        ], $this->messages('endpoint-tests-outdated'));
    }

    // LO QUE UN MANIFIESTO EDITADO A MANO PUEDE TRAER

    public function test_un_nombre_que_no_es_un_identificador_se_informa_sin_reventar(): void
    {
        // Un manifiesto editado a mano puede traer cualquier cosa. Metido tal
        // cual en una expresión regular, un `(` la rompe: preg_match() deja un
        // aviso, y bajo Artisan un aviso tumba `verify` entero. Cada nombre se
        // escapa, y lo que falta se dice como de cualquier otro.
        $this->declare('Post', [
            'endpoints' => [['name' => 'pub(lish', 'route' => 'pub(lish', 'request' => 'PubRequest', 'ability' => 'can(publish', 'operation' => 'do(publish', 'public' => false, 'target' => 'record']],
            'secret' => ['pi(n'],
        ]);
        $this->generated('Post', 'src/Models/Post.php', 'Model/ModelTemplate.txt', "<?php\nprotected \$hidden = ['pi(n'];\nprotected \$export_cols = ['pi(n'];");
        $this->generated('Post', 'src/Http/Controllers/PostController.php', 'Controller/ControllerTemplate.txt', "<?php class PostController {\n public function publish() {}\n}");
        $this->generated('Post', 'src/routes/post.php', 'Route/RouteTemplate.txt', "->name('pub(lish')");
        $this->generated('Post', 'src/Policies/PostPolicy.php', 'Policy/PolicyTemplate.txt', "<?php class PostPolicy {\n public function publish() {}\n}");
        $this->generated('Post', 'src/Http/Resources/PostResource.php', 'Resource/ResourceTemplate.txt', "<?php ['pi(n' => 1];");
        $this->generated('Post', 'resources/vue/post/model.js', 'ModelView/model.js', "export const publish = 1;\n{ id: 'pi(n' }");
        $this->write('src/Models/Traits/Operations/PostOperations.php', "<?php trait PostOperations {\n public function publish() {}\n}");
        $this->write('src/Http/Requests/Post/PubRequest.php', '<?php');

        $this->assertSame([
            'El endpoint pub(lish declarado no tiene método en el controlador. Regenera con --force: el controlador no se escribe a mano.',
            'El endpoint pub(lish declarado no tiene función en el contrato JS: falta `export const do(publish`. Regenera con --vue o --react y --force: el contrato no se escribe a mano.',
            "El endpoint pub(lish declarado pregunta a la política por 'can(publish' y la política no lo tiene. Añade `public function can(publish(User \$user, Post \$post): Response|bool` y decide quién puede; sin él, Laravel lo niega siempre.",
            'El endpoint pub(lish declarado delega en Post::do(publish($request) y el trait de operaciones no lo tiene. Escríbelo ahí: es donde va la lógica del endpoint.',
        ], $this->messages('endpoint-missing'));
        $this->assertSame([
            "'pi(n' es secreta y está en \$export_cols: sale en la exportación.",
            "'pi(n' es secreta y el Resource la nombra: sale por la API aunque esté en \$hidden.",
            "'pi(n' es secreta y es columna de la tabla.",
        ], $this->messages('secret-exposed'));
    }

    public function test_un_modelo_sin_hidden_no_esconde_ningun_secreto(): void
    {
        $this->declare('User', ['secret' => ['token', 'pin']]);
        $this->generated('User', 'src/Models/User.php', 'Model/ModelTemplate.txt', "<?php\nprotected \$fillable = ['token', 'pin'];");

        $this->assertSame([
            "'token' es secreta y no está en \$hidden: toArray() la devuelve, y con él el Resource.",
            "'pin' es secreta y no está en \$hidden: toArray() la devuelve, y con él el Resource.",
        ], $this->messages('secret-exposed'));
    }

    public function test_un_contrato_que_ya_no_existe_no_para_la_comprobacion_de_los_demas(): void
    {
        // El que falta lo dice missing-file; los de después se siguen mirando.
        $this->generated('Post', 'resources/vue/gone/model.js', 'ModelView/model.js', null);
        $this->generated('Post', 'resources/vue/post/model.js', 'ModelView/model.js', "export const API_ROUTE_PREFIX = 'api.otra.post.';");

        $this->assertSame([
            "API_ROUTE_PREFIX es 'api.otra.post.' y el RouteServiceProvider registra 'api.acme.school.post.'.",
        ], $this->messages('route-prefix'));
    }

    public function test_el_prefijo_de_rutas_de_un_espacio_de_nombres_con_acentos(): void
    {
        // PHP admite identificadores UTF-8, y el prefijo va en minúsculas:
        // también la Ñ, no sólo lo ASCII.
        $this->manifest['models']['Post']['namespace'] = 'Acme\\ÑANDÚ\\';
        $this->generated('Post', 'resources/vue/post/model.js', 'ModelView/model.js', "export const API_ROUTE_PREFIX = 'api.acme.ñandú.post.';");
        $this->generated('Post', 'resources/vue/tag/model.js', 'ModelView/model.js', "export const API_ROUTE_PREFIX = 'api.acme.ÑANDÚ.tag.';");

        $this->assertSame([
            "API_ROUTE_PREFIX es 'api.acme.ÑANDÚ.tag.' y el RouteServiceProvider registra 'api.acme.ñandú.tag.'.",
        ], $this->messages('route-prefix'));
    }

    public function test_una_ruta_cuyo_nombre_empieza_como_una_accion_no_es_esa_accion(): void
    {
        // `showcase` no es `show`: que Post no declare show no hace de la
        // ruta del endpoint un resto de la acción.
        $this->declare('Post', ['actions' => ['index'], 'endpoints' => [['name' => 'showcase', 'route' => 'showcase', 'request' => 'ShowcaseRequest', 'ability' => 'showcase', 'operation' => 'showcase', 'public' => false, 'target' => 'record']]]);
        $this->generated('Post', 'src/routes/post.php', 'Route/RouteTemplate.txt', "->name('index')\n->name('showcase')");

        $this->assertSame([], $this->messages('route-not-declared'));
    }

    // LO QUE DEJA UN MERGE (auditoría de 9.4.0)

    public function test_un_conflicto_sin_resolver_se_ve_con_un_manifiesto_legible(): void
    {
        $this->write('laraimport.json', "{\n<<<<<<< HEAD\n\"a\": 1\n=======\n\"a\": 2\n>>>>>>> otra\n}\n");

        $this->assertSame([[
            'merge-conflict', null, 'laraimport.json',
            'Tiene un conflicto de git sin resolver (línea 2). Si es el laraimport o un archivo generado sin editar, `larapack:reconcile` lo resuelve; si es código escrito a mano, lo resuelve quien lo escribió.',
        ]], $this->findings(['merge-conflict']));
    }

    public function test_un_manifiesto_ilegible_es_un_error_y_lo_demas_del_merge_se_sigue_mirando(): void
    {
        $this->write('.larapack/manifest.json', "{\n<<<<<<< HEAD\n}\n=======\n}\n>>>>>>> otra\n");
        $this->write('laraimport.json', "<<<<<<< HEAD\n=======\n>>>>>>> otra\n");

        $findings = (new Verifier(new Manifest($this->root)))->run(['manifest-unreadable', 'merge-conflict']);

        $this->assertSame([['manifest-unreadable', '.larapack/manifest.json'], ['merge-conflict', 'laraimport.json']], array_map(
            fn (array $f): array => [$f['check'], $f['file']],
            $findings
        ));
        $this->assertSame('error', $findings[0]['level']);
    }

    public function test_un_manifiesto_de_una_version_posterior_no_se_da_por_bueno(): void
    {
        $this->manifest['version'] = Manifest::VERSION + 1;

        $this->assertSame([[
            'manifest-unreadable', null, '.larapack/manifest.json',
            '.larapack/manifest.json está en la versión '.(Manifest::VERSION + 1).' del formato, y este LaraPack entiende hasta la '.Manifest::VERSION.': lo escribió una versión posterior y lo que no conoce no se comprueba. Actualiza innoboxrr/larapack-generator.',
        ]], $this->findings([]));
    }
}
