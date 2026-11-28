<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\ControllerShape;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ControllerShape caso a caso, sobre un proyecto mínimo: qué acepta un
 * constructor, qué cuenta como delegar, qué closure de ruta se perdona, y el
 * texto exacto de cada hallazgo.
 *
 * VerifyControllerShapeTest recorre el fixture con los casos de
 * school-management por la orden. Aquí se fija cada regla por separado,
 * incluidos los bordes que un cambio pequeño en la lectura de tokens movería
 * sin que el fixture lo notara: un `new Repo($repo)` que no es una llamada,
 * un `->can()` encadenado a `middleware()`, un `__Invoke` en otra caja.
 *
 * Los mensajes se comparan enteros: es lo que lee el agente que tiene que
 * corregir el controlador, y lo que le dice qué no es suyo decidir.
 *
 * Con los avisos convertidos en excepciones, como bajo Artisan.
 */
final class ControllerShapeTest extends TestCase
{
    private int $errorReporting;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-shape-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->root = (string) realpath($this->root);
        file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
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

    private function put(string $relative, string $contents): void
    {
        $file = $this->root.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);
    }

    /**
     * Un controlador en app/Http/Controllers, con sus `use` habituales.
     */
    private function controller(string $name, string $body, string $namespace = 'App\Http\Controllers'): void
    {
        $this->put('app/'.str_replace(['App\\', '\\'], ['', '/'], $namespace)."/{$name}.php", "<?php\n\nnamespace {$namespace};\n\n"
            ."use App\\Http\\Requests\\StoreRequest;\nuse Illuminate\\Http\\Request;\nuse Innoboxrr\\LarapackGenerator\\Attributes\\HandRolled;\n\n{$body}\n");
    }

    private function request(string $name = 'StoreRequest', string $body = "    public function handle() {}\n", string $extends = '\Illuminate\Foundation\Http\FormRequest'): void
    {
        $this->put("app/Http/Requests/{$name}.php", "<?php\n\nnamespace App\\Http\\Requests;\n\nclass {$name} extends {$extends}\n{\n{$body}}\n");
    }

    /**
     * Un controlador con una sola acción con este cuerpo y estos parámetros.
     */
    private function action(string $body, string $params = 'StoreRequest $request', string $before = ''): void
    {
        $this->request();
        $this->controller('PostController', "class PostController extends Controller\n{\n{$before}    public function store({$params})\n    {\n        {$body}\n    }\n}");
    }

    /**
     * @return list<array{level: string, check: string, model: string|null, file: string|null, message: string}>
     */
    private function findings(?ControllerShape $shape = null): array
    {
        return ($shape ?? new ControllerShape($this->root))->run();
    }

    /**
     * Los mensajes de un check, en orden.
     *
     * @return list<string>
     */
    private function messages(string $check, ?ControllerShape $shape = null): array
    {
        return array_values(array_column(array_filter($this->findings($shape), fn (array $f): bool => $f['check'] === $check), 'message'));
    }

    /**
     * [nivel, check] de cada hallazgo.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function kinds(): array
    {
        return array_map(fn (array $f): array => [$f['level'], $f['check']], $this->findings());
    }

    private function remedy(): string
    {
        return 'La acción es `return $request->handle();` con un FormRequest propio: authorize() autoriza, rules() valida y handle() hace el trabajo (o lo delega en la operación del modelo o en un soporte). '
            .'Marcarla como escrita a mano (#[HandRolled]) no es un arreglo: es una excepción que sólo aprueba el dueño del proyecto, y verify la sigue señalando.';
    }

    // LA ACCIÓN

    public function test_una_accion_que_delega_no_tiene_hallazgos(): void
    {
        $this->action('return $request->HANDLE();');

        $this->assertSame([], $this->findings());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2?: string}>
     */
    public static function noDelegan(): array
    {
        return [
            'cuerpo vacío' => ['', 'tiene el cuerpo vacío'],
            'otra cosa' => ['return $request->validated();', 'hace algo más que `return $request->handle();`'],
            'valida a mano, una vez aunque llame dos' => ['$request->validate([]); $request->validate([]); return 1;', 'valida en el controlador con validate()'],
            'valida a mano en mayúsculas' => ['$request->VALIDATE([]); return 1;', 'valida en el controlador con validate()'],
            'pasa una expresión a HANDLE()' => ['return $request->HANDLE($a . \'x\');', 'pasa a handle() algo que no es una variable: esa expresión es trabajo hecho en el controlador'],
            'pasa una expresión a handle()' => ['return $request->handle($a . \'x\');', 'pasa a handle() algo que no es una variable: esa expresión es trabajo hecho en el controlador'],
            'pasa una expresión con nombre' => ['return $request->handle(post: $this->post);', 'pasa a handle() algo que no es una variable: esa expresión es trabajo hecho en el controlador'],
            'handle() del controlador' => ['return $this->handle();', 'pasa a handle() algo que no es una variable: esa expresión es trabajo hecho en el controlador'],
            'no es un return' => ['echo $request->handle($x);', 'hace algo más que `return $request->handle();`'],
            'recibe la base, sin tipo en otro' => ['return view(\'x\');', 'recibe Illuminate\Http\Request', 'Request $request, $id'],
        ];
    }

    #[DataProvider('noDelegan')]
    public function test_por_que_no_delega(string $body, string $reason, string $params = 'StoreRequest $request'): void
    {
        $this->action($body, $params);

        $this->assertSame(["PostController::store() (línea 11) no delega: {$reason}. ".$this->remedy()], $this->messages('controller-shape'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tipos(): array
    {
        return [
            'sin tipo' => ['$request', 'no tipa $request con un FormRequest'],
            'con un tipo del lenguaje' => ['array $request', 'no tipa $request con un FormRequest'],
            'con la Request base' => ['Request $request', 'tipa $request con Illuminate\Http\Request, que no tiene handle()'],
            'con un parámetro que no existe' => ['StoreRequest $other', 'llama a $request->handle(), y $request no es un parámetro de la acción'],
        ];
    }

    #[DataProvider('tipos')]
    public function test_la_request_en_la_que_delega(string $params, string $problem): void
    {
        $this->action('return $request->handle();', $params);

        $this->assertSame(["PostController::store() (línea 11) {$problem}. ".$this->remedy()], $this->messages('controller-shape'));
    }

    public function test_que_metodo_es_una_accion(): void
    {
        $this->request();
        $this->controller('PostController', <<<'PHP'
        class PostController extends Controller
        {
            public function __Invoke(Request $request) { return 1; }

            public static function boot() { return 1; }

            public static function middleware(): array { return ['auth']; }

            public function callAction($method, $parameters) { return 1; }

            private static function helper() { return fn () => 1; }

            public function __get($name) { return 1; }

            abstract public function nothing();
        }
        PHP);

        // callAction() no es una acción, pero redefinirlo es una puerta
        // lateral (auditoría de seguridad de 9.4.0): corre delante de todas.
        $this->assertSame(['PostController::__Invoke()', 'PostController::boot()', 'PostController::callAction()'], array_map(
            fn (string $message): string => explode(' ', $message)[0],
            $this->messages('controller-shape')
        ));
    }

    public function test_una_request_que_solo_cambia_de_caja_se_comprueba_una_vez(): void
    {
        $this->request('StoreRequest', "    public function rules() {}\n");
        $this->controller('PostController', "class PostController\n{\n    public function a(StoreRequest \$r) { return \$r->handle(); }\n\n    public function b(\\App\\Http\\Requests\\storeRequest \$r) { return \$r->handle(); }\n}");

        $this->assertSame([
            'App\Http\Requests\StoreRequest no define handle(), y PostController::a() en app/Http/Controllers/PostController.php delega en él: la petición falla con BadMethodCallException. La operación va en su handle().',
        ], $this->messages('request-handle'));
    }

    public function test_handle_se_busca_en_la_request_sus_traits_y_sus_padres(): void
    {
        $this->request('StoreRequest', "    public function HANDLE() {}\n");
        $this->request('VendorRequest', '', '\Vendor\Package\BaseRequest');
        $this->request('MixedRequest', "    use \\Vendor\\Concerns\\Handles;\n", 'StoreRequest');
        $this->request('MissingRequest', '');
        $this->controller('PostController', "class PostController\n{\n"
            ."    public function a(StoreRequest \$r) { return \$r->handle(); }\n\n"
            ."    public function b(\\App\\Http\\Requests\\VendorRequest \$r) { return \$r->handle(); }\n\n"
            ."    public function c(\\App\\Http\\Requests\\MixedRequest \$r) { return \$r->handle(); }\n\n"
            ."    public function d(\\App\\Http\\Requests\\MissingRequest \$r) { return \$r->handle(); }\n\n"
            ."    public function e(\\App\\Http\\Requests\\GoneRequest \$r) { return \$r->handle(); }\n}");

        $this->assertSame([
            ['info', 'app/Http/Requests/VendorRequest.php', 'No se encontró en el código del proyecto dónde define handle() App\Http\Requests\VendorRequest (¿viene de vendor?), y PostController::b() delega en él: no se comprobó sin cargarlo.'],
            ['error', 'app/Http/Requests/MissingRequest.php', 'App\Http\Requests\MissingRequest no define handle(), y PostController::d() en app/Http/Controllers/PostController.php delega en él: la petición falla con BadMethodCallException. La operación va en su handle().'],
            ['error', 'app/Http/Controllers/PostController.php', 'PostController::e() delega en App\Http\Requests\GoneRequest, que no existe: su espacio de nombres es del proyecto y no está donde PSR-4 dice. Genérala (larapack:requests) o escríbela con su handle().'],
        ], array_map(fn (array $f): array => [$f['level'], $f['file'], $f['message']], array_values(array_filter($this->findings(), fn (array $f): bool => $f['check'] === 'request-handle'))));
    }

    public function test_un_trait_antes_del_controlador_no_lo_esconde(): void
    {
        $this->request();
        $this->controller('PostController', "trait Helps {}\n\nclass PostController\n{\n    public function index(Request \$request) { return 1; }\n}");

        $this->assertCount(1, $this->messages('controller-shape'));
    }

    public function test_una_interfaz_antes_del_controlador_no_lo_esconde(): void
    {
        $this->request();
        $this->controller('PostController', "interface Listing {}\n\nclass PostController implements Listing\n{\n    public function index(Request \$request) { return 1; }\n}");

        $this->assertCount(1, $this->messages('controller-shape'));
    }

    // EL CONSTRUCTOR Y LAS PUERTAS LATERALES

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function constructores(): array
    {
        return [
            'asignar lo inyectado' => ['$this->repo = $repo;', true],
            'asignar un new con argumentos' => ['$this->repo = new Repo($repo, [1, 2]);', true],
            'asignar un literal entre paréntesis' => ['$this->x = $flag ? (1) : (2);', true],
            'asignar algo que empieza por paréntesis' => ['$this->x = ($y);', true],
            'parent::__construct con argumentos' => ['Parent::__Construct($repo, [1]);', true],
            'middleware con only y except' => ['$this->Middleware([\'auth\', \'verified\'])->ONLY([\'index\'])->except(\'show\');', true],
            'una llamada en la asignación' => ['$this->x = compute();', false],
            'un new con una llamada dentro' => ['$this->x = new Foo(make());', false],
            'una llamada sobre el resultado' => ['$this->x = ($factory)();', false],
            'un closure asignado' => ['$this->resolver = function () { return 1; };', false],
            'asignar a otro objeto' => ['$other->x = 1;', false],
            'el constructor de otra clase' => ['Base::__construct($repo);', false],
            'el propio constructor' => ['$this->__construct();', false],
            'middleware de otro objeto' => ['$router->middleware(\'auth\');', false],
            'middleware de la fachada' => ['Route::middleware(\'auth\');', false],
            'otra llamada sobre $this' => ['$this->authorize(\'x\');', false],
            'middleware con otra cadena' => ['$this->middleware(\'auth\')->can(\'x\');', false],
            'middleware con algo detrás' => ['$this->middleware(\'auth\') . only(\'x\');', false],
        ];
    }

    #[DataProvider('constructores')]
    public function test_que_puede_hacer_un_constructor(string $statement, bool $allowed): void
    {
        $this->request();
        $this->controller('PostController', "class PostController\n{\n    public function __construct(Repo \$repo)\n    {\n        {$statement}\n    }\n}");

        $this->assertSame($allowed ? [] : [
            'PostController::__construct() (línea 11) hace algo más que asignar propiedades, llamar a parent::__construct() o registrar middleware (línea 13): '
            .'el constructor corre en cada acción y antes del middleware de la ruta, y lo que haga ahí es lógica que ninguna acción muestra. '
            .'Inyecta las dependencias como propiedades promovidas y deja el trabajo en el handle() de la request.',
        ], $this->messages('controller-shape'));
    }

    public function test_la_linea_de_la_sentencia_del_constructor(): void
    {
        $this->controller('PostController', "class PostController\n{\n    public function __construct()\n    {\n        \$this->x = 1;\n\n        compute\n            ();\n    }\n}");

        $this->assertStringContainsString('registrar middleware (línea 15):', $this->messages('controller-shape')[0]);
    }

    public function test_las_puertas_laterales(): void
    {
        $this->controller('PostController', <<<'PHP'
        class PostController
        {
            public function __Call($method, $parameters) {}

            public static function middleware(): array
            {
                return [fn ($request, $next) => $next($request)];
            }
        }
        PHP);

        $this->assertSame([
            'PostController::__Call() (línea 11) es __call: el router llega a él con cualquier nombre de acción que no exista (callAction), así que es una acción que no se ve al leer el controlador. '
                .'Quítalo y declara cada acción como un método público que haga `return $request->handle();`.',
            'PostController::middleware() (línea 13) registra un middleware en un closure: es lógica en el controlador que no se ve al leer sus acciones. '
                .'Hazlo una clase de middleware y regístrala por su nombre o su alias.',
        ], $this->messages('controller-shape'));
    }

    public function test_una_clase_excusada_no_cuenta_sus_puertas_laterales(): void
    {
        $this->controller('PostController', "#[HandRolled('webhook firmado')]\nclass PostController\n{\n    public function __call(\$m, \$p) {}\n}");

        $this->assertSame([['warning', 'hand-rolled']], $this->kinds());
    }

    public function test_hand_rolled_detras_de_otro_atributo_tambien_cuenta(): void
    {
        $this->controller('PostController', "class PostController\n{\n    #[\\Deprecated]\n    #[HandRolled('firma en bruto')]\n    public function store(Request \$request) { return 1; }\n}");

        $this->assertSame([['warning', 'hand-rolled']], $this->kinds());
    }

    // LOS TRAITS DE HTTP/CONTROLLERS

    public function test_un_trait_con_logica(): void
    {
        $this->controller('Concerns/Authorizes', <<<'PHP'
        trait Authorizes
        {
            abstract protected function user();

            public static function MIDDLEWARE() { return fn () => 1; }

            public static function helper() { return 1; }

            protected function check() { return 1; }

            #[HandRolled('aprobado')]
            protected function approved() { return 1; }
        }
        PHP, 'App\Http\Controllers\Concerns');
        $this->controller('PostController', "class PostController\n{\n    use Concerns\\Authorizes;\n}");
        $this->controller('PostController', "class PostController\n{\n    use \\App\\Http\\Controllers\\Concerns\\Authorizes;\n}", 'App\Http\Controllers\Admin');

        $this->assertSame([
            'El trait Authorizes (línea 9) vive en Http/Controllers y lleva lógica (helper(), check()), y lo usa PostController. '
            .'Lo que comparten varios controladores no es del controlador: llévalo a una request base o a un trait en Http/Requests, o a un soporte, y deja las acciones en `return $request->handle();`.',
        ], $this->messages('controller-concern'));
    }

    public function test_un_trait_excusado_entero_no_se_mira_por_dentro(): void
    {
        $this->controller('Concerns/Authorizes', "#[HandRolled('firma de webhooks')]\ntrait Authorizes\n{\n    protected function check() { return 1; }\n}", 'App\Http\Controllers\Concerns');

        $this->assertSame([['warning', 'hand-rolled']], $this->kinds());
    }

    public function test_un_trait_que_no_usa_ningun_controlador(): void
    {
        $this->controller('Concerns/Authorizes', "trait Authorizes\n{\n    protected function check() { return 1; }\n}", 'App\Http\Controllers\Concerns');

        $this->assertSame([
            ['warning', 'El trait Authorizes (línea 9) vive en Http/Controllers y lleva lógica (check()), pero ningún controlador lo usa: está en el sitio equivocado. Muévelo a Http/Requests/Concerns o a un soporte; en Http/Controllers invita a usarlo desde un controlador.'],
        ], array_map(fn (array $f): array => [$f['level'], $f['message']], $this->findings()));
    }

    // LAS RUTAS

    public function test_los_closures_de_las_rutas(): void
    {
        $this->put('routes/web.php', <<<'PHP'
        <?php

        Route::get('/', function () { return VIEW('welcome'); });
        Route::get('/a', fn () => view('a'));
        Route::get('/b', function () { echo view('b'); });
        Route::get('/c/{id}', function ($id) { return view('c'); });
        Route::get('/d', function () { return view('d')->with('x', 1); });
        Route::get('/e', function () { return response('ok'); });
        PHP);
        // Lo que no está en routes/ no se mira.
        $this->put('app/Support/Router.php', "<?php\n\nRoute::get('/x', function () { return 1; });\n");

        $this->assertSame([5, 6, 7, 8], array_map(
            fn (string $message): int => (int) preg_replace('/^La ruta de la línea (\d+).*$/s', '$1', $message),
            $this->messages('route-closure')
        ));
        $this->assertSame(
            'La ruta de la línea 5 lleva su acción en un closure (get()): es una acción sin controlador, y nada de lo que verify comprueba en los controladores la alcanza. '
            .'Llévala a un controlador cuya acción sea `return $request->handle();` con un FormRequest propio (o usa Route::view() si sólo pinta una vista).',
            $this->messages('route-closure')[0]
        );
    }

    // LAS EXCEPCIONES Y EL CANDADO

    private function exceptions(): void
    {
        $this->request();
        $this->controller('ZetaController', "class ZetaController\n{\n    #[HandRolled('firma en bruto')]\n    public function __invoke(Request \$request) { return 1; }\n}");
        $this->controller('AlfaController', "#[HandRolled('webhook')]\nclass AlfaController\n{\n    public function __invoke(Request \$request) { return 1; }\n}");
        $this->controller('SinRazonController', "class SinRazonController\n{\n    #[HandRolled('')]\n    public function __invoke(Request \$request) { return 1; }\n}");
    }

    public function test_las_excepciones_con_razon_ordenadas(): void
    {
        $this->exceptions();
        $this->controller('MixController', "class MixController\n{\n    #[HandRolled('b')]\n    public function zz(Request \$request) { return 1; }\n\n    #[HandRolled('a')]\n    public function aa(Request \$request) { return 1; }\n}");
        $shape = new ControllerShape($this->root);
        $shape->run();

        $this->assertSame([
            'App\Http\Controllers\AlfaController',
            'App\Http\Controllers\MixController::aa',
            'App\Http\Controllers\MixController::zz',
            'App\Http\Controllers\ZetaController::__invoke',
        ], array_keys($shape->exceptions()));
    }

    public function test_los_mensajes_de_cada_estado_de_la_aprobacion(): void
    {
        $this->exceptions();
        $shape = new ControllerShape($this->root);
        $shape->run();
        $exceptions = $shape->exceptions();

        $notYours = 'Sólo el dueño del proyecto aprueba excepciones: devuelve la acción a `return $request->handle();` o pídele que la revise. No edites .larapack/hand-rolled.lock ni corras la aprobación.';

        // Sin candado: aviso.
        $this->assertSame([
            'AlfaController (línea 9) escrito a mano a propósito: «webhook». Es una excepción al patrón que aprueba el dueño del proyecto; si no la aprobó, devuelve la acción a `return $request->handle();`.',
            'SinRazonController::__invoke() (línea 11) lleva #[HandRolled] sin razón. La razón es obligatoria y tiene que ser una cadena literal: es lo que permite revisar la excepción sin leer el código. Sin ella no excusa nada.',
            'ZetaController::__invoke() (línea 11) escrito a mano a propósito: «firma en bruto». Es una excepción al patrón que aprueba el dueño del proyecto; si no la aprobó, devuelve la acción a `return $request->handle();`.',
        ], $this->messages('hand-rolled'));

        // Con candado: aprobada, otra razón, sin huella, otro código, y lo que
        // sobra.
        HandRolledLock::write($this->root, [
            'App\Http\Controllers\AlfaController' => ['reason' => 'webhook', 'fingerprint' => $exceptions['App\Http\Controllers\AlfaController']['fingerprint']],
            'App\Http\Controllers\ZetaController::__invoke' => ['reason' => "otra\x1b[31m razón", 'fingerprint' => null],
            'App\Http\Controllers\Zz' => ['reason' => 'r', 'fingerprint' => null],
            'App\Http\Controllers\Aa' => ['reason' => 'r', 'fingerprint' => null],
        ]);

        $this->assertSame([
            'AlfaController (línea 9) escrito a mano a propósito: «webhook». Aprobada por el dueño del proyecto en .larapack/hand-rolled.lock.',
            'SinRazonController::__invoke() (línea 11) lleva #[HandRolled] sin razón. La razón es obligatoria y tiene que ser una cadena literal: es lo que permite revisar la excepción sin leer el código. Sin ella no excusa nada.',
        ], $this->messages('hand-rolled'));
        $this->assertSame([
            "ZetaController::__invoke() (línea 11) cambió la razón de su #[HandRolled]: se aprobó «otra [31m razón» y ahora dice «firma en bruto». La aprobación era de la otra razón (App\\Http\\Controllers\\ZetaController::__invoke). {$notYours}",
        ], $this->messages('hand-rolled-unapproved'));
        $this->assertSame([
            'Aprueba App\Http\Controllers\Aa, que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.',
            'Aprueba App\Http\Controllers\Zz, que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.',
        ], $this->messages('hand-rolled-stale'));

        HandRolledLock::write($this->root, [
            'App\Http\Controllers\AlfaController' => ['reason' => 'webhook', 'fingerprint' => 'sha256:'.str_repeat('0', 64)],
            'App\Http\Controllers\ZetaController::__invoke' => ['reason' => 'firma en bruto', 'fingerprint' => null],
        ]);

        $this->assertSame([
            "AlfaController (línea 9) cambió desde que se aprobó: el código aprobado ya no es el mismo (cambió sus métodos —uno nuevo también—, su padre o sus traits; la huella de .larapack/hand-rolled.lock no coincide). La aprobación era de aquel código (App\\Http\\Controllers\\AlfaController). {$notYours}",
            "ZetaController::__invoke() (línea 11) está en .larapack/hand-rolled.lock sin la huella del código aprobado (App\\Http\\Controllers\\ZetaController::__invoke): la aprobación no dice qué código cubre, y no cuenta. {$notYours}",
        ], $this->messages('hand-rolled-unapproved'));

        HandRolledLock::write($this->root, [
            'App\Http\Controllers\ZetaController::__invoke' => ['reason' => 'firma en bruto', 'fingerprint' => 'sha256:'.str_repeat('0', 64)],
        ]);

        $this->assertSame([
            "AlfaController (línea 9) lleva #[HandRolled] («webhook») y no está aprobada en .larapack/hand-rolled.lock (App\\Http\\Controllers\\AlfaController). {$notYours}",
            "ZetaController::__invoke() (línea 11) cambió desde que se aprobó: el código aprobado ya no es el mismo (cambió su firma o su cuerpo; la huella de .larapack/hand-rolled.lock no coincide). La aprobación era de aquel código (App\\Http\\Controllers\\ZetaController::__invoke). {$notYours}",
        ], $this->messages('hand-rolled-unapproved'));
    }

    public function test_lo_que_sobra_en_un_candado_escrito_a_mano_sale_ordenado(): void
    {
        $this->put('.larapack/hand-rolled.lock', (string) json_encode([
            ['target' => 'App\Zz', 'reason' => 'r', 'fingerprint' => null],
            ['target' => 'App\Aa', 'reason' => 'r', 'fingerprint' => null],
        ]));

        $this->assertSame([
            'Aprueba App\Aa, que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.',
            'Aprueba App\Zz, que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.',
        ], $this->messages('hand-rolled-stale'));
    }

    public function test_una_excepcion_del_realineamiento_que_no_es_de_un_closure_no_tapa_a_las_que_sobran_detras(): void
    {
        // Ordenadas, la del realineamiento va antes que la de la clase: la
        // juzga ProjectVerification, y lo que viene después se sigue mirando.
        $this->put('.larapack/hand-rolled.lock', (string) json_encode([
            ['target' => 'realign:accept-untested:posts', 'reason' => 'r', 'fingerprint' => null],
            ['target' => 'realign:route-closure:get:/old', 'reason' => 'r', 'fingerprint' => null],
            ['target' => 'vendor\Old', 'reason' => 'r', 'fingerprint' => null],
        ]));

        $this->assertSame([
            'Aprueba la excepción realign:route-closure:get:/old, y esa ruta ya no lleva su acción en un closure (o cambió de URI). El dueño del proyecto la retira con larapack:hand-rolled revoke.',
            'Aprueba vendor\Old, que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.',
        ], $this->messages('hand-rolled-stale'));
    }

    public function test_un_candado_ilegible_lo_dice_y_no_aprueba_nada(): void
    {
        $this->exceptions();
        $this->put('.larapack/hand-rolled.lock', '{"target": "A"}');

        $this->assertSame([
            '.larapack/hand-rolled.lock no es una lista JSON de {"target", "reason", "fingerprint"}. Mientras no se corrija, ninguna excepción #[HandRolled] cuenta como aprobada. Lo mantiene el dueño del proyecto con larapack:hand-rolled.',
        ], array_slice($this->messages('hand-rolled-unapproved'), 0, 1));
        $this->assertSame([], $this->messages('hand-rolled-stale'));
    }

    // ---- Auditoría de seguridad de 9.4.0: lo que verify no veía ----

    private const LOGIC = "    public function report(Request \$request)\n    {\n        \$data = \$request->validate(['q' => 'required']);\n\n        return \\DB::select('select 1');\n    }\n";

    private function support(string $name, string $declaration): void
    {
        $this->put("app/Support/{$name}.php", "<?php\n\nnamespace App\\Support;\n\nuse App\\Http\\Requests\\StoreRequest;\nuse Illuminate\\Http\\Request;\nuse Innoboxrr\\LarapackGenerator\\Attributes\\HandRolled;\n\n{$declaration}\n");
    }

    private function routes(string $code): void
    {
        $this->put('routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n{$code}\n");
    }

    public function test_una_accion_heredada_de_un_trait_de_fuera_de_http_controllers_se_comprueba(): void
    {
        $this->support('DoesEverything', "trait DoesEverything\n{\n".self::LOGIC.'}');
        $this->controller('ReportController', "class ReportController extends Controller\n{\n    use \\App\\Support\\DoesEverything;\n}");

        $this->assertSame([[
            'level' => 'error', 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Http/Controllers/ReportController.php',
            'message' => 'ReportController::report(), heredada de DoesEverything (app/Support/DoesEverything.php), (línea 11) no delega: recibe Illuminate\Http\Request, valida en el controlador con validate(). '.$this->remedy(),
        ]], $this->findings());
    }

    public function test_una_accion_heredada_de_un_padre_de_fuera_de_http_controllers_se_comprueba(): void
    {
        $this->support('BaseStats', "abstract class BaseStats\n{\n    use Helpers;\n\n    public function __call(\$name, \$arguments) { return 1; }\n\n    protected function helper() { return 1; }\n}");
        $this->support('Helpers', "trait Helpers\n{\n".self::LOGIC.'}');
        $this->controller('StatsController', "class StatsController extends \\App\\Support\\BaseStats\n{\n}");

        $messages = $this->messages('controller-shape');

        $this->assertCount(2, $messages);
        $this->assertStringStartsWith('StatsController::__call(), heredada de BaseStats (app/Support/BaseStats.php), (línea 13) es __call:', $messages[0]);
        $this->assertStringStartsWith('StatsController::report(), heredada de Helpers (app/Support/Helpers.php), (línea 11) no delega:', $messages[1]);
    }

    public function test_una_accion_heredada_de_un_padre_abstracto_de_http_controllers_se_comprueba_en_el_concreto(): void
    {
        $this->controller('BaseReport', "abstract class BaseReport\n{\n".self::LOGIC.'}');
        $this->controller('RealReport', "class RealReport extends BaseReport\n{\n}");
        $this->controller('OtherReport', "class OtherReport extends BaseReport\n{\n    public function report(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");
        $this->request();

        $this->assertSame([[
            'level' => 'error', 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Http/Controllers/RealReport.php',
            'message' => 'RealReport::report(), heredada de BaseReport (app/Http/Controllers/BaseReport.php), (línea 11) no delega: recibe Illuminate\Http\Request, valida en el controlador con validate(). '.$this->remedy(),
        ]], $this->findings());
    }

    public function test_lo_heredado_que_delega_o_no_es_una_accion_no_se_marca(): void
    {
        $this->request();
        $this->support('Thin', "trait Thin\n{\n    public function store(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n\n    protected function helper() { return \\DB::select('x'); }\n\n    abstract public function nothing();\n}");
        $this->controller('ThinController', "class ThinController extends \\Illuminate\\Routing\\Controller\n{\n    use \\App\\Support\\Thin, \\Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests;\n\n    public function nothing(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");

        $this->assertSame([], $this->findings());
    }

    public function test_un_metodo_heredado_con_hand_rolled_es_una_excepcion_que_se_anota_una_vez(): void
    {
        $this->support('Signed', "trait Signed\n{\n    #[HandRolled('firma el cuerpo en bruto')]\n".self::LOGIC.'}');
        $this->controller('AController', "class AController\n{\n    use \\App\\Support\\Signed;\n}");
        $this->controller('BController', "class BController\n{\n    use \\App\\Support\\Signed;\n}");

        $shape = new ControllerShape($this->root);

        $this->assertSame([['warning', 'hand-rolled']], array_map(fn (array $f): array => [$f['level'], $f['check']], $shape->run()));
        $this->assertSame(['App\Support\Signed::report'], array_keys($shape->exceptions()));
        $this->assertSame('app/Support/Signed.php', $shape->exceptions()['App\Support\Signed::report']['file']);
    }

    public function test_una_clase_declarada_dentro_de_un_bloque_se_comprueba(): void
    {
        $this->put('app/Http/Controllers/OddController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse Illuminate\\Http\\Request;\n\nif (! class_exists(OddController::class)) {\n    class OddController\n    {\n".self::LOGIC."    }\n}\n");

        $this->assertSame([[
            'level' => 'error', 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Http/Controllers/OddController.php',
            'message' => 'OddController::report() (línea 10) no delega: recibe Illuminate\Http\Request, valida en el controlador con validate(). '.$this->remedy(),
        ]], $this->findings());
    }

    public function test_una_clase_de_fuera_de_http_controllers_puesta_como_accion_de_una_ruta_es_un_controlador(): void
    {
        $this->request();
        $this->put('app/Actions/Export.php', "<?php\n\nnamespace App\\Actions;\n\nuse App\\Http\\Requests\\StoreRequest;\nuse Illuminate\\Http\\Request;\n\nclass Export\n{\n    public function __invoke(Request \$request)\n    {\n        return \\DB::select('select 1');\n    }\n\n    public function run(Request \$request)\n    {\n        return \\DB::select('select 2');\n    }\n\n    public function thin(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n\n    public function unrouted()\n    {\n        return \\DB::select('select 3');\n    }\n}\n");
        $this->routes("use App\\Actions\\Export;\n\nRoute::get('/a', Export::class);\nRoute::middleware('auth')->post('/b', [Export::class, 'run']);\nRoute::get('/c', 'App\\Actions\\Export@run');\nRoute::get('/d', [Export::class, 'thin']);");

        $suffix = ' Es la acción de una ruta de routes/web.php: aunque su clase no esté en Http/Controllers, para el router es un controlador.';

        $this->assertSame([
            ['level' => 'error', 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Actions/Export.php',
                'message' => 'Export::__invoke() (línea 10) no delega: recibe Illuminate\Http\Request. '.$this->remedy().$suffix],
            ['level' => 'error', 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Actions/Export.php',
                'message' => 'Export::run() (línea 15) no delega: recibe Illuminate\Http\Request. '.$this->remedy().$suffix],
        ], $this->findings());
    }

    public function test_route_resource_sobre_una_clase_de_fuera_comprueba_todas_sus_acciones(): void
    {
        $this->put('app/Actions/Posts.php', "<?php\n\nnamespace App\\Actions;\n\nuse Illuminate\\Http\\Request;\n\nclass Posts\n{\n    public function index(Request \$request)\n    {\n        return \\DB::select('select 1');\n    }\n\n    public function store(Request \$request)\n    {\n        return \\DB::select('select 2');\n    }\n}\n");
        $this->routes("Route::apiResource('posts', \\App\\Actions\\Posts::class);");

        $this->assertCount(2, $this->messages('controller-shape'));
    }

    public function test_un_invocable_cuyo_metodo_viene_de_vendor_o_una_clase_que_no_es_del_proyecto_no_se_marcan(): void
    {
        $this->put('app/Livewire/Counter.php', "<?php\n\nnamespace App\\Livewire;\n\nclass Counter extends \\Livewire\\Component\n{\n    public function render()\n    {\n        return view('counter');\n    }\n}\n");
        $this->routes("Route::get('/counter', \\App\\Livewire\\Counter::class);\nRoute::get('/vendor', [\\Vendor\\Package\\Controller::class, 'show']);\nRoute::view('/v', 'welcome');\nRoute::redirect('/a', '/b');\nRoute::get('/sin-accion');");

        $this->assertSame([], $this->findings());
    }

    public function test_una_accion_de_ruta_que_no_se_puede_leer_es_un_closure_por_otro_camino(): void
    {
        $this->routes("\$handler = function () { return \\DB::select('select 1'); };\nfunction raw() { return 1; }\n\nRoute::get('/a', \$handler);\nRoute::get('/b', raw(...));\nRoute::get('/c', ['uses' => function () { return 1; }]);\nRoute::middleware('x')->prefix('y')->get('/d', \$handler);\nRoute::get('/e', [new \\App\\Actions\\Export, 'run']);\nRoute::fallback(\\Closure::fromCallable('raw'));\n\$router->post('/f', \$handler);\n\n// No son rutas: no las registra el router.\n\$value = config()->get('key', \$handler);\n\$cached = \\Cache::get('key', \$handler);");

        $messages = $this->messages('route-closure');
        $text = fn (int $line, string $call): string => "La ruta de la línea {$line} ({$call}()) lleva como acción algo que no se puede leer sin ejecutarlo —una variable, una función, un objeto o un closure dentro de un array—: es una acción sin controlador, como un closure, y nada de lo que verify comprueba en los controladores la alcanza. "
            .'Llévala a un controlador cuya acción sea `return $request->handle();` con un FormRequest propio, y nómbralo en la ruta (`[Controlador::class, \'accion\']`).';

        $this->assertSame([$text(8, 'get'), $text(9, 'get'), $text(10, 'get'), $text(11, 'get'), $text(12, 'get'), $text(13, 'fallback'), $text(14, 'post')], $messages);
        $this->assertSame(['warning'], array_values(array_unique(array_column($this->findings(), 'level'))));
    }

    public function test_redefinir_call_action_un_destructor_con_cuerpo_y_un_closure_en_get_middleware_son_puertas_laterales(): void
    {
        $this->request();
        $this->controller('SneakyController', "class SneakyController extends Controller\n{\n    public function callAction(\$method, \$parameters)\n    {\n        request()->validate(['q' => 'required']);\n\n        return parent::callAction(\$method, \$parameters);\n    }\n\n    public function getMiddleware()\n    {\n        return [['middleware' => fn (\$request, \$next) => \$next(\$request), 'options' => []]];\n    }\n\n    public function __destruct()\n    {\n        \\DB::select('select 1');\n    }\n\n    public function store(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");
        $this->controller('PlainController', "class PlainController extends Controller\n{\n    public function getMiddleware()\n    {\n        return [];\n    }\n\n    public function __destruct()\n    {\n    }\n}");

        $messages = $this->messages('controller-shape');

        $this->assertCount(3, $messages);
        $this->assertStringStartsWith('SneakyController::callAction() (línea 11) redefine callAction(), por donde el router llama a todas las acciones:', $messages[0]);
        $this->assertStringStartsWith('SneakyController::getMiddleware() (línea 18) devuelve un middleware en un closure desde getMiddleware():', $messages[1]);
        $this->assertStringStartsWith('SneakyController::__destruct() (línea 23) es un destructor con cuerpo:', $messages[2]);
    }

    // LO QUE AÑADIÓ LA AUDITORÍA DE SEGURIDAD, REGLA POR REGLA
    //
    // Las pruebas de arriba fijan cada regla con un caso que la pisa entera;
    // éstas, cada forma por separado, para que ninguna se sostenga en otra:
    // una ruta que nombra `run` de dos maneras dejaba de ver una sin que
    // cambiara nada (Infection, docs/mutacion.md).

    private const EXPORT = "class Export\n{\n    public function __invoke(Request \$request)\n    {\n        return \\DB::select('select 1');\n    }\n\n    public function run(Request \$request)\n    {\n        return \\DB::select('select 2');\n    }\n\n    public function thin(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n}";

    private function actionClass(string $name, string $declaration): void
    {
        $this->put("app/Actions/{$name}.php", "<?php\n\nnamespace App\\Actions;\n\nuse App\\Http\\Requests\\StoreRequest;\nuse Illuminate\\Http\\Request;\nuse Innoboxrr\\LarapackGenerator\\Attributes\\HandRolled;\n\n{$declaration}\n");
    }

    /**
     * Lo que nombra cada hallazgo de controller-shape: su primera palabra.
     *
     * @return list<string>
     */
    private function flagged(): array
    {
        return array_map(fn (string $message): string => explode(' ', $message)[0], $this->messages('controller-shape'));
    }

    public function test_los_mensajes_de_call_action_del_destructor_y_de_get_middleware_enteros(): void
    {
        $this->controller('SneakyController', "class SneakyController extends Controller\n{\n    public function callAction(\$method, \$parameters)\n    {\n        return 1;\n    }\n\n    public function getMiddleware()\n    {\n        return [['middleware' => function (\$request, \$next) { return \$next(\$request); }]];\n    }\n\n    public function __destruct()\n    {\n        \\DB::select('select 1');\n    }\n}");

        $this->assertSame([
            'SneakyController::callAction() (línea 11) redefine callAction(), por donde el router llama a todas las acciones: lo que haga ahí corre en cada una y ninguna lo muestra. '
                .'Quítalo; lo que tenga que pasar antes de una acción es un middleware con nombre, y lo demás va en el handle() de su request.',
            'SneakyController::getMiddleware() (línea 16) devuelve un middleware en un closure desde getMiddleware(): es lógica en el controlador que no se ve al leer sus acciones. '
                .'Hazlo una clase de middleware y regístrala por su nombre o su alias.',
            'SneakyController::__destruct() (línea 21) es un destructor con cuerpo: corre al terminar cada petición que pasa por el controlador, y es lógica que ninguna acción muestra. '
                .'Quítalo; lo que haya que hacer después de responder va en un middleware terminable o en un listener.',
        ], $this->messages('controller-shape'));
    }

    /**
     * Cada forma de nombrar la acción de una ruta, sola.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function accionesDeRuta(): array
    {
        return [
            'invocable' => ["Route::get('/a', Export::class);", ['Export::__invoke()']],
            'array con método' => ["Route::get('/a', [Export::class, 'run']);", ['Export::run()']],
            'array sin método: invocable' => ["Route::get('/a', [Export::class]);", ['Export::__invoke()']],
            'array con un método que delega' => ["Route::get('/a', [Export::class, 'thin']);", []],
            'cadena Clase@método' => ["Route::get('/a', 'App\\Actions\\Export@run');", ['Export::run()']],
            'cadena con barra inicial' => ["Route::get('/a', '\\App\\Actions\\Export@run');", ['Export::run()']],
            'cadena sin método: invocable' => ["Route::get('/a', 'App\\Actions\\Export');", ['Export::__invoke()']],
            'cadena con dos @: ningún método se llama así' => ["Route::get('/a', 'App\\Actions\\Export@run@x');", []],
            'match: la acción es el tercero' => ["Route::match(['get', 'post'], '/a', [Export::class, 'run']);", ['Export::run()']],
            'addRoute: la acción es el tercero' => ["Route::addRoute('GET', '/a', [Export::class, 'run']);", ['Export::run()']],
            'fallback: la acción es el primero' => ["Route::fallback([Export::class, 'run']);", ['Export::run()']],
            'action con nombre' => ["Route::get(uri: '/a', action: [Export::class, 'run']);", ['Export::run()']],
            'controller con nombre' => ["Route::apiResource(name: 'a', controller: Export::class);", ['Export::__invoke()', 'Export::run()']],
            'la fachada con su nombre completo' => ["\\Illuminate\\Support\\Facades\\Route::get('/a', [Export::class, 'run']);", ['Export::run()']],
            'el router' => ["\$router->get('/a', [Export::class, 'run']);", ['Export::run()']],
            'en una cadena de llamadas' => ["Route::middleware('auth')->prefix('x')->get('/a', [Export::class, 'run']);", ['Export::run()']],
            'otra fachada no registra rutas' => ["Cache::get('/a', [Export::class, 'run']);", []],
            'una constante no es una clase' => ["Route::get('/a', ACTION);", []],
            'Clase::class con algo detrás no es la clase' => ["Route::get('/a', Export::class . '');", []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('accionesDeRuta')]
    public function test_la_accion_de_una_ruta_en_cada_forma(string $route, array $expected): void
    {
        $this->request();
        $this->actionClass('Export', self::EXPORT);
        $this->routes("use App\\Actions\\Export;\n\n{$route}");

        $this->assertSame($expected, $this->flagged());
        $this->assertSame([], $this->messages('route-closure'));
    }

    public function test_lo_que_no_es_una_ruta_o_es_un_closure_no_corta_el_recorrido_de_las_demas(): void
    {
        $this->actionClass('Export', self::EXPORT);
        $this->routes("use App\\Actions\\Export;\n\nCache::get('k', [Export::class, 'run']);\nRoute::get('/x', function () { return 1; });\nRoute::get('/y', SOME_CONSTANT);\nRoute::get('/a', Export::class);");

        $this->assertSame(['Export::__invoke()'], $this->flagged());
    }

    public function test_la_misma_accion_enrutada_dos_veces_con_otra_caja_se_marca_una_vez(): void
    {
        $this->actionClass('Export', self::EXPORT);
        $this->routes("use App\\Actions\\Export;\n\nRoute::get('/a', [Export::class, 'run']);\nRoute::get('/b', [Export::class, 'RUN']);\nRoute::get('/c', [\\App\\Actions\\export::class, 'run']);");

        $this->assertSame(['Export::run()'], $this->flagged());
    }

    public function test_la_accion_enrutada_que_viene_de_un_trait_dice_de_donde(): void
    {
        $this->support('Runs', "trait Runs\n{\n".self::LOGIC.'}');
        $this->actionClass('Export', "class Export\n{\n    use \\App\\Support\\Runs;\n}");
        $this->routes("Route::get('/a', [\\App\\Actions\\Export::class, 'report']);");

        $this->assertSame([
            'Export::report(), heredada de Runs (app/Support/Runs.php), (línea 11) no delega: recibe Illuminate\Http\Request, valida en el controlador con validate(). '.$this->remedy()
                .' Es la acción de una ruta de routes/web.php: aunque su clase no esté en Http/Controllers, para el router es un controlador.',
        ], $this->messages('controller-shape'));
    }

    public function test_una_clase_enrutada_con_hand_rolled_es_una_excepcion_que_se_anota(): void
    {
        $this->actionClass('Export', "#[HandRolled('exporta en streaming')]\n".self::EXPORT);
        $this->routes("Route::get('/a', [\\App\\Actions\\Export::class, 'run']);");

        $shape = new ControllerShape($this->root);

        $this->assertSame([['warning', 'hand-rolled']], array_map(fn (array $f): array => [$f['level'], $f['check']], $shape->run()));
        $this->assertSame(['App\Actions\Export'], array_keys($shape->exceptions()));
    }

    public function test_un_metodo_enrutado_con_hand_rolled_se_anota_y_los_demas_se_siguen_mirando(): void
    {
        $this->actionClass('Export', "class Export\n{\n    #[HandRolled('firma la descarga')]\n    public function __invoke(Request \$request)\n    {\n        return \\DB::select('select 1');\n    }\n\n    public function __call(\$name, \$arguments)\n    {\n        return 1;\n    }\n\n    public function run(Request \$request)\n    {\n        return \\DB::select('select 2');\n    }\n}");
        $this->routes("Route::resource('a', \\App\\Actions\\Export::class);");

        $shape = new ControllerShape($this->root);

        $this->assertSame(['Export::__call()', 'Export::run()'], array_map(
            fn (array $f): string => explode(' ', $f['message'])[0],
            array_values(array_filter($shape->run(), fn (array $f): bool => $f['check'] === 'controller-shape'))
        ));
        $this->assertSame(['App\Actions\Export::__invoke'], array_keys($shape->exceptions()));
    }

    public function test_dos_acciones_enrutadas_que_delegan_en_la_misma_request_sin_handle_se_dicen_una_vez(): void
    {
        $this->request('StoreRequest', "    public function rules() {}\n");
        $this->actionClass('Export', "class Export\n{\n    public function a(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n\n    public function b(\\App\\Http\\Requests\\storeRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");
        $this->routes("Route::resource('a', \\App\\Actions\\Export::class);");

        $this->assertSame([
            'App\Http\Requests\StoreRequest no define handle(), y Export::a() en app/Actions/Export.php delega en él: la petición falla con BadMethodCallException. La operación va en su handle().',
        ], $this->messages('request-handle'));
    }

    public function test_un_controlador_con_hand_rolled_excusa_lo_que_hereda(): void
    {
        $this->support('DoesEverything', "trait DoesEverything\n{\n".self::LOGIC.'}');
        $this->controller('ReportController', "#[HandRolled('informe heredado')]\nclass ReportController extends Controller\n{\n    use \\App\\Support\\DoesEverything;\n}");

        $this->assertSame([['warning', 'hand-rolled']], $this->kinds());
    }

    public function test_un_trait_con_hand_rolled_excusa_lo_que_da_y_se_anota_una_vez(): void
    {
        $this->support('DoesEverything', "#[HandRolled('informe compartido')]\ntrait DoesEverything\n{\n".self::LOGIC.'}');
        $this->controller('AController', "class AController\n{\n    use \\App\\Support\\DoesEverything;\n}");
        $this->controller('BController', "class BController\n{\n    use \\App\\Support\\DoesEverything;\n}");

        $shape = new ControllerShape($this->root);

        $this->assertSame([['warning', 'hand-rolled']], array_map(fn (array $f): array => [$f['level'], $f['check']], $shape->run()));
        $this->assertSame(['App\Support\DoesEverything'], array_keys($shape->exceptions()));
    }

    public function test_un_metodo_heredado_excusado_no_tapa_al_siguiente(): void
    {
        $this->support('Mixed', "trait Mixed\n{\n    #[HandRolled('firma el cuerpo en bruto')]\n    public function signed(Request \$request)\n    {\n        return 1;\n    }\n\n".self::LOGIC.'}');
        $this->controller('ReportController', "class ReportController\n{\n    use \\App\\Support\\Mixed;\n}");

        $this->assertSame(['ReportController::report(),'], $this->flagged());
    }

    public function test_dos_controladores_que_heredan_la_misma_delegacion_en_una_request_sin_handle_se_dicen_una_vez(): void
    {
        $this->request('StoreRequest', "    public function rules() {}\n");
        $this->support('Thin', "trait Thin\n{\n    public function store(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");
        $this->support('OtherThin', "trait OtherThin\n{\n    public function save(\\App\\Http\\Requests\\storeRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");
        $this->controller('AController', "class AController\n{\n    use \\App\\Support\\Thin;\n}");
        $this->controller('BController', "class BController\n{\n    use \\App\\Support\\Thin, \\App\\Support\\OtherThin;\n}");

        $this->assertSame([
            'App\Http\Requests\StoreRequest no define handle(), y AController::store(), heredada de Thin (app/Support/Thin.php), en app/Http/Controllers/AController.php delega en él: la petición falla con BadMethodCallException. La operación va en su handle().',
        ], $this->messages('request-handle'));
    }

    public function test_lo_que_el_controlador_declara_gana_a_lo_heredado_aunque_cambie_la_caja(): void
    {
        $this->request();
        $this->support('Loud', "trait Loud\n{\n    public function rePort(Request \$request)\n    {\n        return \\DB::select('select 1');\n    }\n}");
        $this->controller('ReportController', "class ReportController\n{\n    use \\App\\Support\\Loud;\n\n    public function Report(StoreRequest \$request)\n    {\n        return \$request->handle();\n    }\n}");

        $this->assertSame([], $this->findings());
    }

    public function test_un_trait_dentro_de_otro_trait_tambien_se_hereda(): void
    {
        $this->support('Inner', "trait Inner\n{\n".self::LOGIC.'}');
        $this->support('Outer', "trait Outer\n{\n    use Inner;\n}");
        $this->controller('ReportController', "class ReportController\n{\n    use \\App\\Support\\Outer;\n}");

        $this->assertSame(['ReportController::report(), heredada de Inner (app/Support/Inner.php), (línea 11) no delega:'], array_map(
            fn (string $message): string => implode(' ', array_slice(explode(' ', $message), 0, 9)),
            $this->messages('controller-shape')
        ));
    }

    public function test_un_trait_de_http_controllers_no_se_cuenta_como_herencia(): void
    {
        $this->put('app/Http/Controllers/Concerns/Reports.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\nuse Illuminate\\Http\\Request;\n\ntrait Reports\n{\n".self::LOGIC."}\n");
        $this->controller('ReportController', "class ReportController\n{\n    use Concerns\\Reports;\n}");

        $this->assertSame([], $this->messages('controller-shape'));
    }

    public function test_un_padre_concreto_de_fuera_de_http_controllers_se_hereda(): void
    {
        $this->support('BaseStats', "class BaseStats\n{\n".self::LOGIC.'}');
        $this->controller('StatsController', "class StatsController extends \\App\\Support\\BaseStats\n{\n}");

        $this->assertSame(['StatsController::report(),'], $this->flagged());
    }

    public function test_un_padre_concreto_de_http_controllers_se_comprueba_en_el_y_no_en_sus_hijos(): void
    {
        $this->controller('BaseReport', "class BaseReport\n{\n".self::LOGIC.'}');
        $this->controller('RealReport', "class RealReport extends BaseReport\n{\n}");

        $this->assertSame(['BaseReport::report()'], $this->flagged());
    }

    public function test_un_padre_abstracto_de_http_controllers_con_hand_rolled_excusa_a_sus_hijos(): void
    {
        $this->controller('BaseReport', "#[HandRolled('la base firma los informes')]\nabstract class BaseReport\n{\n".self::LOGIC.'}');
        $this->controller('RealReport', "class RealReport extends BaseReport\n{\n}");
        $this->controller('BlankReport', "#[HandRolled('')]\nabstract class BlankBase\n{\n}");

        $this->assertSame([], $this->messages('controller-shape'));
    }

    public function test_un_padre_abstracto_de_http_controllers_con_hand_rolled_sin_razon_no_excusa(): void
    {
        // Sin argumentos: sin razón, y sin cadena que leer.
        $this->controller('BaseReport', "#[HandRolled]\nabstract class BaseReport\n{\n".self::LOGIC.'}');
        $this->controller('RealReport', "class RealReport extends BaseReport\n{\n}");

        $this->assertSame(['RealReport::report(),'], $this->flagged());
    }

    public function test_el_metodo_de_una_accion_en_una_variable_es_un_closure_por_otro_camino(): void
    {
        $this->actionClass('Export', self::EXPORT);
        $this->routes("use App\\Actions\\Export;\n\nRoute::get('/a', [Export::class, \$method]);");

        $this->assertSame([], $this->flagged());
        $this->assertCount(1, $this->messages('route-closure'));
    }

    public function test_un_trait_de_http_controllers_dentro_de_otro_tampoco_se_cuenta_como_herencia(): void
    {
        $this->put('app/Http/Controllers/Concerns/Reports.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\nuse Illuminate\\Http\\Request;\n\ntrait Reports\n{\n".self::LOGIC."}\n");
        $this->support('Outer', "trait Outer\n{\n    use \\App\\Http\\Controllers\\Concerns\\Reports;\n}");
        $this->controller('ReportController', "class ReportController\n{\n    use \\App\\Support\\Outer;\n}");

        $this->assertSame([], $this->messages('controller-shape'));
    }

    public function test_http_controllers_en_la_raiz_del_espacio_de_nombres_tambien_es_de_controladores(): void
    {
        // Un PSR-4 que apunta a la raíz: la ruta relativa empieza por
        // Http/Controllers/, sin nada delante.
        file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => '']]]));
        $this->put('Http/Controllers/PostController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse Illuminate\\Http\\Request;\n\nclass PostController\n{\n".self::LOGIC."}\n");
        $this->put('Http/Controllers/ChildController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass ChildController extends PostController\n{\n}\n");
        $this->routes("Route::get('/a', [\\App\\Http\\Controllers\\PostController::class, 'report']);");

        $this->assertSame(['PostController::report()'], $this->flagged());
    }
}
