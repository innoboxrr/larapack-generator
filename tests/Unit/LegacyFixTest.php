<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\LegacyFix;
use Innoboxrr\LarapackGenerator\Support\LegacySecurity;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Qué formas reconoce `larapack:fix` con certeza y cuáles deja como están,
 * una a una.
 *
 * FixLegacySecurityTest prueba la orden con lo que generaban las versiones
 * anteriores. Aquí se recorre la frontera: cada lectura de `appends` que se
 * sustituye, cada condición del `if` que se puede quitar, cada sitio donde
 * está el setAppends(), y la razón exacta con que se salta lo que no se
 * reconoce. Es la frontera la que no puede moverse sin que se note: un
 * arreglo que reescribe lo que no entiende cambia lo que hace el código del
 * proyecto.
 *
 * Con los avisos convertidos en excepciones, como bajo Artisan.
 */
final class LegacyFixTest extends TestCase
{
    private int $errorReporting;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-fix-'.bin2hex(random_bytes(6));
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

    private function put(string $relative, string $code): void
    {
        $file = $this->root.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $code);
    }

    private function resource(string $body, string $params = 'Request $request', string $extends = 'JsonResource', string $members = ''): void
    {
        $this->put('app/Http/Resources/PostResource.php', "<?php\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\n"
            ."class PostResource extends {$extends}\n{\n{$members}    public function toArray({$params}): array\n    {\n        {$body}\n\n        return parent::toArray(\$request);\n    }\n}\n");
    }

    private function index(string $members): void
    {
        $this->put('app/Http/Requests/Post/IndexRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."class IndexRequest extends FormRequest\n{\n{$members}\n    public function handle()\n    {\n        return (new Builder())->get(Post::class, \$this->all());\n    }\n}\n");
    }

    /**
     * Corre el arreglo y devuelve [la razón del salto o null, el archivo después].
     *
     * @param  list<string>  $checks
     * @return array{0: string|null, 1: string}
     */
    private function fix(string $file, array $checks = [LegacySecurity::RESOURCE_APPENDS, LegacySecurity::INDEX_MANAGED]): array
    {
        $fix = new LegacyFix($this->root, $checks);
        $report = $fix->plan();
        // Con la raíz del proyecto, que no tiene Pint: lo escrito es lo que
        // calcula el arreglo, no lo que deja el formateador.
        ProjectRoot::using($this->root, fn (): array => $fix->apply());

        $skipped = [];

        foreach ($report as $entry) {
            $skipped = [...$skipped, ...array_column($entry['skipped'], 'reason', 'file')];
        }

        return [$skipped[$file] ?? null, (string) file_get_contents($this->root.'/'.$file)];
    }

    private const RESOURCE = 'app/Http/Resources/PostResource.php';

    private const INDEX = 'app/Http/Requests/Post/IndexRequest.php';

    private const NOT_A_READ = 'setAppends() recibe algo que no es la lectura directa de `appends` de la petición.';

    // DE DÓNDE SE LEE LA PETICIÓN

    /**
     * @return array<string, array{0: string, 1: string|null, 2?: string}>
     */
    public static function lecturas(): array
    {
        return [
            'get' => ["\$request->get('appends')", '$request'],
            'input con [] y en mayúsculas' => ["\$request->INPUT('appends', [])", '$request'],
            'otro parámetro' => ["\$req->get('appends')", '$req', 'Request $req, $request = null'],
            'el helper' => ["request('appends')", 'request()'],
            'el helper en mayúsculas con []' => ["REQUEST('appends', [])", 'request()'],
            'el helper encadenado' => ["request()->input('appends')", 'request()'],
            'el helper encadenado con get y []' => ["request()->GET('appends', [])", 'request()'],
            'el helper encadenado en mayúsculas' => ["REQUEST()->input('appends')", 'request()'],
            'sin tipo' => ["\$request->get('appends')", '$request', '$request'],
            'la petición entera' => ['$request', null],
            'otra clave' => ["\$request->get('other')", null],
            'otro valor por defecto' => ["\$request->get('appends', 'x')", null],
            'otro método' => ["\$request->only('appends')", null],
            'algo detrás' => ["\$request->get('appends')['x']", null],
            'el helper con otra clave' => ["request('other')", null],
            'el helper con otro método' => ["request()->all('appends')", null],
            'el helper sin paréntesis vacíos' => ["request(1)->get('appends')", null],
            // Una variable no es el helper aunque se invoque igual.
            'la petición invocada' => ["\$request('appends')", null],
            'la petición invocada y encadenada' => ["\$request()->input('appends')", null],
        ];
    }

    #[DataProvider('lecturas')]
    public function test_de_donde_lee_appends(string $read, ?string $receiver, string $params = 'Request $request'): void
    {
        $this->resource("\$this->resource->setAppends({$read});", $params);

        [$skipped, $code] = $this->fix(self::RESOURCE);

        if ($receiver === null) {
            $this->assertSame(self::NOT_A_READ, $skipped);
            $this->assertStringContainsString("setAppends({$read});", $code);

            return;
        }

        $this->assertNull($skipped);
        $this->assertStringContainsString("        \$appends = {$receiver}->input('appends');\n\n        if (is_array(\$appends) && \$this->appendable !== []) {\n", $code);
        $this->assertStringContainsString("    protected array \$appendable = [];\n\n    public function toArray(", $code);
    }

    // LA CONDICIÓN DEL IF

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function condiciones(): array
    {
        return [
            'has' => ["\$request->has('appends')", null],
            'isset con corchetes' => ["isset(\$request['appends'])", null],
            'empty negado' => ["!empty(\$request->get('appends'))", null],
            'varias, en mayúsculas' => ["Array_Key_Exists('appends', \$request->all()) && is_array(\$request->query('appends'))", null],
            'otra clave' => ["\$request->has('other')", "'other'"],
            'otra variable' => ["\$request->has('appends') && \$user->isAdmin()", '$user'],
            'otra variable delante' => ["\$user && \$request->has('appends')", '$user'],
            'un o' => ["\$request->filled('appends') || true", '||'],
        ];
    }

    #[DataProvider('condiciones')]
    public function test_que_condicion_del_if_se_puede_quitar(string $condition, ?string $offending): void
    {
        $this->resource("if ({$condition}) {\n            \$this->resource->setAppends(\$request->get('appends'));\n        }");

        [$skipped, $code] = $this->fix(self::RESOURCE);

        if ($offending === null) {
            $this->assertNull($skipped);
            $this->assertStringNotContainsString($condition, $code);

            return;
        }

        $this->assertSame("La condición del `if` del setAppends() hace algo más que mirar `appends` ({$offending}).", $skipped);
    }

    // DÓNDE ESTÁ EL SETAPPENDS()

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sitios(): array
    {
        $call = "\$this->resource->setAppends(\$request->get('appends'));";

        return [
            'sobre otro objeto' => ["\$resource->setAppends(\$request->get('appends'));", 'setAppends() no se llama sobre $this->resource.'],
            'sobre otra propiedad' => ["\$this->model->setAppends(\$request->get('appends'));", 'setAppends() no se llama sobre $this->resource.'],
            'con ?->' => ["\$this->resource?->setAppends(\$request->get('appends'));", 'setAppends() no se llama sobre $this->resource.'],
            'estática' => ["\$this->resource::setAppends(\$request->get('appends'));", 'setAppends() no se llama sobre $this->resource.'],
            'sobre otra cosa que $this' => ["\$that->resource->setAppends(\$request->get('appends'));", 'setAppends() no se llama sobre $this->resource.'],
            'usando el resultado' => ["\$this->resource->setAppends(\$request->get('appends'))->refresh();", 'El resultado de setAppends() se usa en otra expresión.'],
            'dentro de un foreach' => ["foreach ([1] as \$i) {\n            {$call}\n        }", 'setAppends() está dentro de un bloque que no es un `if` del primer nivel del método.'],
            'detrás de una asignación' => ["\$x = {$call}", 'setAppends() está dentro de un bloque que no es el `if` de la plantilla.'],
            'con algo más en el if' => ["if (\$request->has('appends')) {\n            {$call}\n            \$x = 1;\n        }", 'setAppends() está dentro de un bloque que no es el `if` de la plantilla.'],
            'un if dentro de otro' => ["if (true) {\n            if (\$request->has('appends')) {\n                {$call}\n            }\n        }", 'setAppends() está dentro de un bloque que no es un `if` del primer nivel del método.'],
            'un if con else' => ["if (\$request->has('appends')) {\n            {$call}\n        } else {\n            \$x = 1;\n        }", 'El `if` del setAppends() tiene else: quitarlo cambiaría lo que hace.'],
            'un if con elseif' => ["if (\$request->has('appends')) {\n            {$call}\n        } elseif (true) {\n            \$x = 1;\n        }", 'El `if` del setAppends() tiene else: quitarlo cambiaría lo que hace.'],
            'un if sin llaves dentro de otro' => ["if (true) {\n            if (\$request->has('appends')) {$call}\n        }", 'setAppends() está dentro de un bloque que no es el `if` de la plantilla.'],
            'un while' => ["while (\$request->has('appends')) {\n            {$call}\n        }", 'setAppends() está dentro de un bloque que no es un `if` del primer nivel del método.'],
            'dos veces' => ["{$call}\n        {$call}", 'PostResource::toArray() llama 2 veces a setAppends(): sólo se arregla la forma de la plantilla, con una.'],
            'ya usa $appends' => ["\$appends = 1;\n        {$call}", 'PostResource::toArray() ya usa $appends: el bloque de la plantilla la pisaría.'],
            'ya usa $requested después' => ["{$call}\n        \$requested = 1;", 'PostResource::toArray() ya usa $requested: el bloque de la plantilla la pisaría.'],
            'no empieza su línea' => ["\$x = 1; {$call}", 'PostResource::toArray(): el setAppends() no empieza su línea.'],
        ];
    }

    #[DataProvider('sitios')]
    public function test_donde_esta_el_set_appends(string $body, string $reason): void
    {
        $this->resource($body);

        $this->assertSame($reason, $this->fix(self::RESOURCE)[0]);
    }

    public function test_una_variable_que_no_es_un_parametro_no_es_la_peticion(): void
    {
        $this->resource("\$other = \$request;\n        \$this->resource->setAppends(\$other->get('appends'));");

        $this->assertSame(self::NOT_A_READ, $this->fix(self::RESOURCE)[0]);
    }

    public function test_suelto_tras_otras_sentencias_y_dentro_del_if_de_la_plantilla(): void
    {
        $this->resource("\$x = 1;\n        \$this->resource->setAppends(\$request->get('appends'));");

        [$skipped, $code] = $this->fix(self::RESOURCE);

        $this->assertNull($skipped);
        $this->assertStringContainsString("        \$x = 1;\n        \$appends = \$request->input('appends');\n", $code);
    }

    public function test_appendable_solo_se_declara_si_falta_y_en_un_json_resource(): void
    {
        $members = "    protected array \$appendable = ['author'];\n\n";
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'JsonResource', $members);

        [$skipped, $code] = $this->fix(self::RESOURCE);
        $this->assertNull($skipped);
        $this->assertSame(1, substr_count($code, '$appendable = '));

        // Una variable local con ese nombre no es la propiedad.
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'JsonResource', "    public function other()\n    {\n        \$appendable = \"{\$x}\";\n    }\n\n");
        [$skipped, $code] = $this->fix(self::RESOURCE);
        $this->assertNull($skipped);
        $this->assertStringContainsString("    protected array \$appendable = [];\n\n    public function other()", $code);

        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'BaseResource');
        $this->assertSame('Extiende App\Http\Resources\BaseResource y no declara $appendable: no se sabe si su padre la declara ni con qué tipo.', $this->fix(self::RESOURCE)[0]);
    }

    public function test_la_forma_de_la_clase_alrededor(): void
    {
        $call = "\$this->resource->setAppends(\$request->get('appends'));";

        $cases = [
            'la propiedad declarada después de un método' => ['JsonResource', "    public function other()\n    {\n        return [];\n    }\n\n    protected array \$appendable = ['author'];\n\n", '$request', $call],
            'que se nombra a sí misma' => ['JsonResource', "    public static function wrapAll(\$x)\n    {\n        return PostResource::collection(\$x);\n    }\n\n", 'Request $request', $call],
            'con un método por referencia' => ['JsonResource', "    public function &items(): array\n    {\n        return \$this->items;\n    }\n\n", 'Request $request', $call],
            'con un closure en el cuerpo' => ['JsonResource', '', 'Request $request', "\$f = function (\$a) { return \$a; };\n        {$call}"],
            'con un if sin espacio' => ['JsonResource', '', 'Request $request', "if(\$request->has('appends')) {\n            {$call}\n        }"],
        ];

        foreach ($cases as $what => [$extends, $members, $params, $body]) {
            $this->resource($body, $params, $extends, $members);

            [$skipped, $code] = $this->fix(self::RESOURCE);

            $this->assertNull($skipped, $what);
            $this->assertSame(1, substr_count($code, '$appendable = '), $what);
            $this->assertStringContainsString("\$appends = \$request->input('appends');", $code, $what);
        }
    }

    public function test_una_clase_delante_en_el_mismo_archivo(): void
    {
        $this->put(self::RESOURCE, "<?php\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\n"
            ."final class Helper\n{\n}\n\n"
            ."class PostResource extends JsonResource\n{\n    public function toArray(Request \$request): array\n    {\n        \$this->resource->setAppends(\$request->get('appends'));\n\n        return [];\n    }\n}\n");

        [$skipped, $code] = $this->fix(self::RESOURCE);

        $this->assertNull($skipped);
        $this->assertStringContainsString("{\n    /**\n     * Los accessors", $code);
    }

    public function test_un_resource_sin_padre_no_se_toca(): void
    {
        $this->put(self::RESOURCE, "<?php\n\nnamespace App\\Http\\Resources;\n\nclass PostResource\n{\n    public function toArray(\$request): array\n    {\n        \$this->resource->setAppends(\$request->get('appends'));\n\n        return [];\n    }\n}\n");

        $this->assertSame('Extiende  y no declara $appendable: no se sabe si su padre la declara ni con qué tipo.', $this->fix(self::RESOURCE)[0]);
    }

    public function test_el_metodo_nuevo_va_delante_de_los_atributos_del_primero(): void
    {
        $this->index("    #[\\Deprecated]\n    public function authorize()\n    {\n        return true;\n    }\n");

        [$skipped, $code] = $this->fix(self::INDEX);

        $this->assertNull($skipped);
        $this->assertStringContainsString("        ]);\n    }\n\n    #[\\Deprecated]\n    public function authorize()", $code);
    }

    public function test_un_comentario_que_se_sustituye_con_espacios_detras(): void
    {
        $this->index("    protected function prepareForValidation()\n    {\n        //   \n    }\n");

        [$skipped, $code] = $this->fix(self::INDEX);

        $this->assertNull($skipped);
        $this->assertStringNotContainsString('//   ', $code);
    }

    public function test_un_comentario_de_bloque_en_la_llave_no_es_el_hueco(): void
    {
        $this->index("    protected function prepareForValidation()\n    {/* nada */}\n");

        $this->assertSame('prepareForValidation() tiene código en la línea de su llave de apertura.', $this->fix(self::INDEX)[0]);
    }

    public function test_formatea_solo_si_escribio_algo_y_hay_pint(): void
    {
        // Con Pint (el de este repositorio) y nada que arreglar: no formatea.
        // La suite corre con LARAPACK_PINT=off (phpunit.xml.dist): aquí se le
        // da una de verdad.
        putenv('LARAPACK_PINT='.dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint');

        try {
            $fix = new LegacyFix($this->root, [LegacySecurity::RESOURCE_APPENDS]);
            $fix->plan();
            $this->assertFalse($fix->apply()['formatted']);
        } finally {
            putenv('LARAPACK_PINT=off');
        }

        // Con algo que arreglar y sin Pint: tampoco.
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $fix = new LegacyFix($this->root, [LegacySecurity::RESOURCE_APPENDS]);
        $fix->plan();
        $this->assertFalse(ProjectRoot::using($this->root, fn (): array => $fix->apply())['formatted']);
    }

    public function test_solo_se_reanota_lo_que_el_manifiesto_tenia_sin_tocar(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $this->index("    protected function prepareForValidation()\n    {\n        //\n    }\n");
        $this->put('app/Http/Requests/Other/IndexRequest.php', str_replace('Post', 'Other', (string) file_get_contents($this->root.'/'.self::INDEX)));

        $hash = fn (string $file): string => hash('sha256', (string) file_get_contents($this->root.'/'.$file));
        $this->put('.larapack/manifest.json', (string) json_encode(['version' => 1, 'package' => ['namespace' => '', 'files' => []], 'models' => [
            'Post' => ['namespace' => 'App\\', 'files' => [
                self::INDEX => ['stub' => 'Requests/IndexRequest.txt', 'hash' => $hash(self::INDEX)],
                self::RESOURCE => ['stub' => 'Resource/ResourceTemplate.txt', 'hash' => 'editado'],
            ]],
        ]]));

        $this->fix(self::INDEX);

        $manifest = json_decode((string) file_get_contents($this->root.'/.larapack/manifest.json'), true);
        $this->assertSame($hash(self::INDEX), $manifest['models']['Post']['files'][self::INDEX]['hash']);
        $this->assertSame('editado', $manifest['models']['Post']['files'][self::RESOURCE]['hash']);
        $this->assertStringContainsString("'managed' => true", (string) file_get_contents($this->root.'/app/Http/Requests/Other/IndexRequest.php'));
    }

    // INDEX-MANAGED

    public function test_prepare_for_validation_con_y_sin_cuerpo(): void
    {
        $this->index("    protected function prepareForValidation()\n    {\n        //\n    }\n");
        [$skipped, $code] = $this->fix(self::INDEX);
        $this->assertNull($skipped);
        $this->assertStringContainsString("    protected function prepareForValidation()\n    {\n        // La restricción", $code);
        $this->assertStringContainsString("        ]);\n    }\n", $code);
        $this->assertSame(1, substr_count($code, "        //\n"), 'El `//` de la plantilla se sustituye; el del bloque nuevo queda.');

        // Dos comentarios vacíos ya no son el de la plantilla: se conservan.
        $this->index("    protected function prepareForValidation()\n    {\n        //\n        //\n    }\n");
        [$skipped, $code] = $this->fix(self::INDEX);
        $this->assertNull($skipped);
        $this->assertStringContainsString("        ]);\n\n        //\n        //\n    }\n", $code);

        $this->index("    protected function prepareForValidation()\n    {    }\n");
        $this->assertNull($this->fix(self::INDEX)[0]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function indexSaltados(): array
    {
        return [
            'abstracto' => ["    abstract protected function prepareForValidation();\n", 'prepareForValidation() es abstracto.'],
            'código en la línea de la llave' => ["    protected function prepareForValidation()\n    { \$this->x = 1;\n    }\n", 'prepareForValidation() tiene código en la línea de su llave de apertura.'],
            'un comentario en la línea de la llave' => ["    protected function prepareForValidation()\n    { // x\n    }\n", 'prepareForValidation() tiene código en la línea de su llave de apertura.'],
            'con traits' => ["    use \\App\\Concerns\\Prepares;\n", 'No declara prepareForValidation() y usa traits: si uno lo declara, añadirlo en la clase lo taparía.'],
        ];
    }

    #[DataProvider('indexSaltados')]
    public function test_lo_que_no_arregla_de_un_index(string $members, string $reason): void
    {
        $this->index($members);

        $this->assertSame($reason, $this->fix(self::INDEX)[0]);
    }

    public function test_crea_el_metodo_con_la_sangria_de_la_clase(): void
    {
        $this->put(self::INDEX, "<?php\n\nnamespace App\\Http\\Requests\\Post {\n\n    use Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."    class IndexRequest extends \\Illuminate\\Foundation\\Http\\FormRequest\n    {\n        public function handle()\n        {\n            return new Builder();\n        }\n    }\n}\n");

        [$skipped, $code] = $this->fix(self::INDEX);

        $this->assertNull($skipped);
        $this->assertStringContainsString("    {\n        protected function prepareForValidation(): void\n        {\n            // La restricción", $code);
        $this->assertStringContainsString("            ]);\n        }\n\n        public function handle()", $code);
    }

    public function test_un_padre_con_prepare_for_validation_privado_no_se_tapa(): void
    {
        // Privado, el del padre no se hereda ni se puede llamar con parent::;
        // añadir uno en la hija cambiaría a qué se llama. No se adivina.
        $this->put(self::INDEX, "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."class BaseIndex extends FormRequest\n{\n    private function prepareForValidation()\n    {\n    }\n}\n\n"
            ."class IndexRequest extends BaseIndex\n{\n    public function handle()\n    {\n        return (new Builder())->get(Post::class, \$this->all());\n    }\n}\n");

        $this->assertSame(
            'Hereda de App\\Http\\Requests\\Post\\BaseIndex, que no se ve entero en el proyecto: no se sabe qué prepareForValidation() taparía.',
            $this->fix(self::INDEX)[0]
        );
    }

    /**
     * Una clase sangrada con tabuladores y sus miembros con un tabulador y
     * cuatro espacios: el nivel de más son los espacios, y va detrás de la
     * sangría del miembro, no delante.
     */
    public function test_la_sangria_mezclada_se_extiende_por_detras(): void
    {
        $class = fn (string $members): string => "<?php\n\nnamespace App\\Http\\Requests\\Post {\n\n\tuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."\tclass IndexRequest extends \\Illuminate\\Foundation\\Http\\FormRequest\n\t{\n{$members}\t    public function handle()\n\t    {\n\t        return new Builder();\n\t    }\n\t}\n}\n";

        $this->put(self::INDEX, $class(''));
        [$skipped, $code] = $this->fix(self::INDEX);
        $this->assertNull($skipped);
        $this->assertStringContainsString("\t{\n\t    protected function prepareForValidation(): void\n\t    {\n\t        // La restricción", $code);

        $this->put(self::INDEX, $class("\t    protected function prepareForValidation()\n\t    {\n\t        //\n\t    }\n\n"));
        [$skipped, $code] = $this->fix(self::INDEX);
        $this->assertNull($skipped);
        $this->assertStringContainsString("\t    protected function prepareForValidation()\n\t    {\n\t        // La restricción", $code);
    }

    public function test_un_appends_pegado_a_la_llave_tambien_se_ve(): void
    {
        // Sin espacio detrás de la llave, el primer token del cuerpo ya es
        // código: también se mira.
        $this->put(self::RESOURCE, "<?php\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\n"
            ."class PostResource extends JsonResource\n{\n    public function toArray(Request \$request): array\n    {\$appends = [];\n        \$this->resource->setAppends(\$request->get('appends'));\n\n        return parent::toArray(\$request);\n    }\n}\n");

        $this->assertSame('PostResource::toArray() ya usa $appends: el bloque de la plantilla la pisaría.', $this->fix(self::RESOURCE)[0]);
    }

    // ALCANCE

    public function test_path_acota_por_directorio_entero(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $this->index("    protected function prepareForValidation()\n    {\n        \$this->merge(['managed' => true]);\n    }\n");

        $checks = [LegacySecurity::RESOURCE_APPENDS, LegacySecurity::INDEX_MANAGED];

        // Un prefijo que no es un directorio entero no cuenta.
        $report = (new LegacyFix($this->root, $checks, 'app/Http/Res'))->plan();
        $this->assertSame([], $report[LegacySecurity::RESOURCE_APPENDS]['fixed']);
        $this->assertSame(0, $report[LegacySecurity::INDEX_MANAGED]['unchanged']);

        // Con barras invertidas y de sobra, como se escribe en Windows.
        $report = (new LegacyFix($this->root, $checks, '\\app\\Http\\Resources\\'))->plan();
        $this->assertSame([['file' => self::RESOURCE]], $report[LegacySecurity::RESOURCE_APPENDS]['fixed']);
        $this->assertSame(0, $report[LegacySecurity::INDEX_MANAGED]['unchanged']);

        // El archivo exacto también es un alcance; y `/` es todo.
        $report = (new LegacyFix($this->root, $checks, self::INDEX))->plan();
        $this->assertSame([], $report[LegacySecurity::RESOURCE_APPENDS]['fixed']);
        $this->assertSame(1, $report[LegacySecurity::INDEX_MANAGED]['unchanged']);

        $report = (new LegacyFix($this->root, $checks, '/'))->plan();
        $this->assertSame([['file' => self::RESOURCE]], $report[LegacySecurity::RESOURCE_APPENDS]['fixed']);
        $this->assertSame(1, $report[LegacySecurity::INDEX_MANAGED]['unchanged']);
    }

    // LO QUE NO SE PUEDE ARREGLAR SIN ROMPERLO

    /**
     * Un archivo que ya no parseaba sigue sin parsear arreglado: no se
     * escribe, y se dice por qué. El arreglo no lo empeora, pero tampoco
     * puede comprobar que lo escrito es PHP.
     */
    public function test_un_archivo_que_no_parseaba_no_se_escribe(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'JsonResource', "    public function broken()\n    {\n        return 1\n    }\n\n");
        $before = (string) file_get_contents($this->root.'/'.self::RESOURCE);

        [$skipped, $code] = $this->fix(self::RESOURCE);

        $this->assertSame('El resultado no parsea (syntax error, unexpected token "}", expecting ";", línea 26).', $skipped);
        $this->assertSame($before, $code);
    }

    /**
     * Si Pint deja algo que no parsea, se devuelve el original y el archivo
     * pasa de arreglado a saltado. Con un Pint de mentira (LARAPACK_PINT) que
     * rompe lo que toca.
     */
    public function test_lo_que_pint_deja_sin_parsear_se_devuelve(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $this->index("    protected function prepareForValidation()\n    {\n        //\n    }\n");
        $before = (string) file_get_contents($this->root.'/'.self::RESOURCE);
        // Rompe el Resource y deja el IndexRequest como está.
        $pint = $this->root.'/pint.php';
        file_put_contents($pint, "<?php\nforeach (array_slice(\$argv, 1) as \$file) {\n    if (str_ends_with(\$file, 'PostResource.php')) {\n        file_put_contents(\$file, \"<?php\\nclass {\\n\");\n    }\n}\n");

        $fix = new LegacyFix($this->root, [LegacySecurity::RESOURCE_APPENDS, LegacySecurity::INDEX_MANAGED]);
        $fix->plan();
        putenv('LARAPACK_PINT='.$pint);

        try {
            $result = ProjectRoot::using($this->root, fn (): array => $fix->apply());
        } finally {
            putenv('LARAPACK_PINT=off'); // lo de la suite: phpunit.xml.dist
        }

        $this->assertTrue($result['formatted']);
        $this->assertSame([], $result['report'][LegacySecurity::RESOURCE_APPENDS]['fixed']);
        $this->assertSame(
            [['file' => self::RESOURCE, 'reason' => 'Después de formatearlo no parseaba, y se devolvió el original.']],
            $result['report'][LegacySecurity::RESOURCE_APPENDS]['skipped']
        );
        $this->assertSame($before, file_get_contents($this->root.'/'.self::RESOURCE));
        $this->assertSame([['file' => self::INDEX]], $result['report'][LegacySecurity::INDEX_MANAGED]['fixed']);
        $this->assertStringContainsString("'managed' => true", (string) file_get_contents($this->root.'/'.self::INDEX));
    }

    public function test_lo_que_pint_deja_sin_poder_leer_se_devuelve(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $before = (string) file_get_contents($this->root.'/'.self::RESOURCE);
        // Un Pint de mentira que se lleva el archivo.
        $pint = $this->root.'/pint.php';
        file_put_contents($pint, "<?php\nforeach (array_slice(\$argv, 1) as \$file) {\n    if (str_ends_with(\$file, 'PostResource.php')) {\n        unlink(\$file);\n    }\n}\n");

        $fix = new LegacyFix($this->root, [LegacySecurity::RESOURCE_APPENDS]);
        $fix->plan();
        putenv('LARAPACK_PINT='.$pint);

        try {
            $result = ProjectRoot::using($this->root, fn (): array => $fix->apply());
        } finally {
            putenv('LARAPACK_PINT=off'); // lo de la suite: phpunit.xml.dist
        }

        $this->assertSame(
            [['file' => self::RESOURCE, 'reason' => 'Después de formatearlo no se podía leer, y se devolvió el original.']],
            $result['report'][LegacySecurity::RESOURCE_APPENDS]['skipped']
        );
        $this->assertSame($before, file_get_contents($this->root.'/'.self::RESOURCE));
    }

    /**
     * Dos métodos con el mismo nombre no compilan, pero se leen: el primero
     * es el que cuenta, como en LegacySecurity, y si es abstracto no hay
     * cuerpo que arreglar.
     */
    public function test_un_metodo_repetido_y_abstracto_no_tiene_cuerpo(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'JsonResource', "    abstract public function TOARRAY(\$request);\n\n");

        $this->assertSame('No se encontró el cuerpo de PostResource::toArray().', $this->fix(self::RESOURCE)[0]);
    }

    /**
     * PHP 8.5 admite un closure estático como valor por defecto de una
     * propiedad. No es un método, y el arreglo no lo sabe leer: no toca la
     * clase.
     */
    public function test_un_closure_en_una_propiedad_no_se_toma_por_un_metodo(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'JsonResource', "    public \$format = static function (\$x) { return \$x; };\n\n");

        $this->assertSame('Una declaración de método que no se reconoce.', $this->fix(self::RESOURCE)[0]);
    }

    /**
     * El Resource de un paquete se vuelve a anotar en su manifiesto aunque
     * la raíz, que va antes, no tenga.
     */
    public function test_se_reanota_el_manifiesto_de_un_paquete_aunque_la_raiz_no_tenga(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $package = 'packages/blog/src/Http/Resources/PostResource.php';
        $this->put('packages/blog/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Blog\\' => 'src/']]]));
        $this->put($package, str_replace('namespace App\\Http\\Resources;', 'namespace Acme\\Blog\\Http\\Resources;', (string) file_get_contents($this->root.'/'.self::RESOURCE)));
        $this->put('packages/blog/.larapack/manifest.json', (string) json_encode(['version' => 1, 'package' => ['namespace' => 'Acme\\Blog\\', 'files' => []], 'models' => [
            'Post' => ['namespace' => 'Acme\\Blog\\', 'files' => [
                'src/Http/Resources/PostResource.php' => ['stub' => 'Resource/ResourceTemplate.txt', 'hash' => hash('sha256', (string) file_get_contents($this->root.'/'.$package))],
            ]],
        ]]));

        $this->fix(self::RESOURCE);

        $manifest = json_decode((string) file_get_contents($this->root.'/packages/blog/.larapack/manifest.json'), true);
        $this->assertStringContainsString('$appendable', (string) file_get_contents($this->root.'/'.$package));
        $this->assertSame(hash('sha256', (string) file_get_contents($this->root.'/'.$package)), $manifest['models']['Post']['files']['src/Http/Resources/PostResource.php']['hash']);
    }

    public function test_la_clase_esta_una_sola_vez_y_es_una_clase(): void
    {
        // Dos espacios de nombres en un archivo, cada uno con su PostResource.
        $class = "class PostResource extends \\Illuminate\\Http\\Resources\\Json\\JsonResource\n{\n    public function toArray(\$request): array\n    {\n        \$this->resource->setAppends(\$request->get('appends'));\n\n        return [];\n    }\n}\n";
        $this->put(self::RESOURCE, "<?php\n\nnamespace App\\Http\\Resources {\n{$class}}\n\nnamespace App\\Http\\Resources\\Old {\n{$class}}\n");

        $this->assertSame('La clase PostResource aparece más de una vez.', $this->fix(self::RESOURCE)[0]);

        // Un trait también se lee, pero no es una clase que se pueda arreglar.
        $trait = 'app/Http/Resources/Concerns/AppendsRequested.php';
        unlink($this->root.'/'.self::RESOURCE);
        $this->put($trait, "<?php\n\nnamespace App\\Http\\Resources\\Concerns;\n\ntrait AppendsRequested\n{\n    public function toArray(\$request): array\n    {\n        \$this->resource->setAppends(\$request->get('appends'));\n\n        return [];\n    }\n}\n");

        $this->assertSame('No se encontró la clase AppendsRequested.', $this->fix($trait)[0]);
    }

    public function test_un_archivo_sin_cerrar_no_se_toca(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $this->put(self::RESOURCE, rtrim((string) file_get_contents($this->root.'/'.self::RESOURCE), "}\n")."\n");

        $this->assertSame('Paréntesis o llaves sin cerrar.', $this->fix(self::RESOURCE)[0]);
    }

    public function test_sin_sangria_no_se_sabe_cuanto_meter(): void
    {
        $this->put(self::INDEX, "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."class IndexRequest extends FormRequest\n{\nprotected function prepareForValidation()\n{\n//\n}\n\npublic function handle()\n{\nreturn (new Builder())->get(Post::class, \$this->all());\n}\n}\n");

        $this->assertSame('No se pudo deducir la sangría de la clase.', $this->fix(self::INDEX)[0]);
    }

    /**
     * Un archivo con algo delante de `<?php` (un shebang, una línea en
     * blanco) parsea, pero no es lo que se espera de una clase: no se toca.
     */
    public function test_lo_que_no_empieza_con_la_etiqueta_de_php_no_se_toca(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        $this->put(self::RESOURCE, "\n".file_get_contents($this->root.'/'.self::RESOURCE));

        $this->assertSame('El resultado no empieza con <?php.', $this->fix(self::RESOURCE)[0]);
    }

    /**
     * El bloque de un `use` de trait (`{ a as b; }`) no es el principio del
     * método siguiente: $appendable va delante del método, no del `use`.
     */
    public function test_el_bloque_de_un_use_de_trait_no_es_parte_del_metodo(): void
    {
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));", 'Request $request', 'JsonResource', "    use \\App\\Concerns\\Formats {\n        format as protected;\n    }\n\n");

        [$skipped, $code] = $this->fix(self::RESOURCE);

        $this->assertNull($skipped);
        $this->assertStringContainsString("        format as protected;\n    }\n\n    /**\n     * Los accessors", $code);
    }

    public function test_lo_que_llega_por_un_enlace_de_fuera_de_la_raiz_no_se_reescribe(): void
    {
        // `app/Http` enlazado a otro proyecto (auditoría de seguridad de
        // 9.4.0): se lee, pero arreglarlo es cosa de quien corre fix en él.
        $outside = $this->root.'-fuera';
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");
        rename($this->root.'/app/Http', $outside);
        symlink($outside, $this->root.'/app/Http');
        $before = (string) file_get_contents($outside.'/Resources/PostResource.php');

        try {
            [$skipped, $code] = $this->fix(self::RESOURCE);

            $this->assertSame('Está fuera de la raíz del proyecto (llega por un repositorio `path` de composer.json o por un enlace simbólico): no se reescribe desde aquí. Corre larapack:fix con --root en su proyecto.', $skipped);
            $this->assertSame($before, $code);
        } finally {
            unlink($this->root.'/app/Http');
            $this->remove($outside);
        }
    }
}
