<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use ErrorException;
use Innoboxrr\LarapackGenerator\Support\LegacySecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cómo lee LegacySecurity el código, caso a caso: qué cuenta como forzar
 * `managed`, qué cuenta como heredar, y qué lleva la petición a setAppends().
 *
 * VerifyLegacySecurityTest prueba la comprobación sobre lo que generaban las
 * versiones anteriores. Aquí se fijan los bordes de la lectura —una llamada
 * encadenada detrás de setAppends(), una sentencia que no limpia la
 * siguiente, un método propio que se llama request()—, que son justo los que
 * un cambio pequeño en el recorrido de tokens rompería sin que nada avisara.
 *
 * Con los avisos convertidos en excepciones, como bajo Artisan: leer un
 * archivo a medio escribir no puede tumbar `verify`.
 */
final class LegacySecurityTest extends TestCase
{
    private int $errorReporting;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-legacy-'.bin2hex(random_bytes(6));
        mkdir($this->root);
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

    private function put(string $relative, string $code): string
    {
        $file = $this->root.'/app/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $code);

        return (string) realpath($file);
    }

    /**
     * Un IndexRequest de SearchSurge con este prepareForValidation() (o sin
     * él, si es null).
     */
    private function request(?string $prepare, string $extends = 'FormRequest', string $before = ''): string
    {
        $method = $prepare === null ? '' : "    protected function prepareForValidation()\n    {\n        {$prepare}\n    }\n";

        return $this->put('Http/Requests/Post/IndexRequest.php', <<<PHP
        <?php

        namespace App\\Http\\Requests\\Post;

        use Illuminate\\Foundation\\Http\\FormRequest;
        use Innoboxrr\\SearchSurge\\Search\\Builder;

        class IndexRequest extends {$extends}
        {
        {$before}{$method}
            public function handle()
            {
                return (new Builder())->get(Post::class, \$this->all());
            }
        }
        PHP);
    }

    /**
     * @return array{debt: list<array<string, mixed>>, clean: array<string, list<string>>}
     */
    private function inspect(): array
    {
        return (new LegacySecurity($this->root))->inspect();
    }

    /**
     * @return list<string>
     */
    private function checksOf(string $file): array
    {
        return array_values(array_map(
            fn (array $debt): string => $debt['check'],
            array_filter($this->inspect()['debt'], fn (array $debt): bool => $debt['file'] === $file)
        ));
    }

    // INDEX-MANAGED

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function prepares(): array
    {
        return [
            'merge con true' => ["\$this->merge(['managed' => true]);", true],
            'en mayúsculas: PHP no distingue' => ["\$this->MERGE(['managed' => TRUE]);", true],
            'replace con 1' => ["\$this->replace(['managed' => 1]);", true],
            "con '1'" => ["\$this->merge(['managed' => '1']);", true],
            "con 'true'" => ["\$this->merge(['managed' => 'true']);", true],
            'con false no fuerza' => ["\$this->merge(['managed' => false]);", false],
            "con 'false' no fuerza" => ["\$this->merge(['managed' => 'false']);", false],
            'mergeIfMissing deja ganar al cliente' => ["\$this->mergeIfMissing(['managed' => true]);", false],
            'merge sin llamar no cuenta' => ["\$merge = ['managed' => true];", false],
            'el managed de otra llamada no es el del merge' => ["\$this->merge(['page' => 1]);\n        \$this->log(['managed' => true]);", false],
            'el merge mira todos sus argumentos' => ["\$this->merge(\$this->defaults() + ['managed' => true]);", true],
            'con anidados antes' => ["\$this->merge(['filters' => [], 'managed' => true]);", true],
            'vacío' => ['//', false],
        ];
    }

    #[DataProvider('prepares')]
    public function test_que_cuenta_como_forzar_managed(string $prepare, bool $forces): void
    {
        $file = $this->request($prepare);

        $this->assertSame($forces ? [] : [LegacySecurity::INDEX_MANAGED], $this->checksOf($file));
        $this->assertSame($forces ? [$file] : [], $this->inspect()['clean'][LegacySecurity::INDEX_MANAGED]);
    }

    public function test_prepare_for_validation_puede_no_ser_el_primer_metodo(): void
    {
        $file = $this->request("\$this->merge(['managed' => true]);", 'FormRequest', "    public function authorize()\n    {\n        return true;\n    }\n\n");

        $this->assertSame([], $this->checksOf($file));
    }

    public function test_un_merge_a_medio_escribir_no_tumba_la_lectura(): void
    {
        $file = $this->put('Http/Requests/Post/IndexRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."class IndexRequest extends FormRequest\n{\n    public function handle() { return new Builder(); }\n\n"
            ."    protected function prepareForValidation()\n    {\n        \$this->merge(['page' => [1");

        $this->assertSame([LegacySecurity::INDEX_MANAGED], $this->checksOf($file));
    }

    public function test_un_managed_sin_valor_al_final_del_archivo_no_fuerza(): void
    {
        $file = $this->put('Http/Requests/Post/IndexRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."class IndexRequest extends FormRequest\n{\n    public function handle() { return new Builder(); }\n\n"
            ."    protected function prepareForValidation()\n    {\n        \$this->merge(['managed' =>");

        $this->assertSame([LegacySecurity::INDEX_MANAGED], $this->checksOf($file));
    }

    public function test_una_herencia_en_circulo_no_se_recorre_sin_fin(): void
    {
        // No carga en PHP, pero se lee: la vuelta no dice nada del padre,
        // y recorrerla otra vez no acabaría nunca.
        $this->put('Http/Requests/LoopRequest.php', "<?php\n\nnamespace App\\Http\\Requests;\n\n"
            ."class LoopRequest extends \\App\\Http\\Requests\\Post\\IndexRequest\n{\n}\n");
        $file = $this->request(null, '\\App\\Http\\Requests\\LoopRequest');

        $this->assertSame([], $this->checksOf($file));
        // Como lo heredado de fuera: no se sabe, y no se marca.
        $this->assertSame([$file], $this->inspect()['clean'][LegacySecurity::INDEX_MANAGED]);
    }

    public function test_heredar_el_prepare_de_una_request_base(): void
    {
        $this->put('Http/Requests/BaseRequest.php', "<?php\n\nnamespace App\\Http\\Requests;\n\n"
            ."abstract class BaseRequest extends \\Illuminate\\Foundation\\Http\\FormRequest\n{\n"
            ."    public function rules() { return []; }\n\n"
            ."    protected function prepareForValidation()\n    {\n        \$this->merge(['managed' => true]);\n    }\n}\n");

        // Sin declararlo, y llamando al padre (en cualquier caja): cuenta.
        $inherits = $this->request(null, '\\App\\Http\\Requests\\BaseRequest');
        $this->assertSame([], $this->checksOf($inherits));

        $calls = $this->request("\$this->merge(['page' => 1]);\n        PARENT::prepareForValidation();", '\\App\\Http\\Requests\\BaseRequest');
        $this->assertSame([], $this->checksOf($calls));

        // Declararlo sin llamar al padre lo tapa; llamar al de otro objeto o
        // a sí mismo no es heredar.
        foreach (['$this->other->prepareForValidation();', 'self::prepareForValidation();', 'static::prepareForValidation();'] as $call) {
            $hides = $this->request("\$this->merge(['page' => 1]);\n        {$call}", '\\App\\Http\\Requests\\BaseRequest');
            $this->assertSame([LegacySecurity::INDEX_MANAGED], $this->checksOf($hides), $call);
        }
    }

    public function test_lo_heredado_de_fuera_del_proyecto_no_se_adivina(): void
    {
        $file = $this->request(null, '\\Vendor\\Package\\BaseIndexRequest');
        $security = new LegacySecurity($this->root);
        $inspection = $security->inspect();

        // Ni deuda ni limpio no: no se sabe, y se da por bueno.
        $this->assertSame([], $inspection['debt']);
        $this->assertSame([$file], $inspection['clean'][LegacySecurity::INDEX_MANAGED]);
        $this->assertSame('unknown', $security->inheritedPrepare('App\Http\Requests\Post\IndexRequest'));
    }

    public function test_la_visibilidad_del_prepare_heredado(): void
    {
        $this->put('Http/Requests/A.php', "<?php\n\nnamespace App\\Http\\Requests;\n\nclass A extends B\n{\n}\n");
        $this->put('Http/Requests/B.php', "<?php\n\nnamespace App\\Http\\Requests;\n\nclass B extends C\n{\n    public function prepareForValidation() {}\n}\n");
        $this->put('Http/Requests/C.php', "<?php\n\nnamespace App\\Http\\Requests;\n\nclass C\n{\n}\n");
        $this->put('Http/Requests/Loop.php', "<?php\n\nnamespace App\\Http\\Requests;\n\nclass X extends Y {}\nclass Y extends X {}\n");

        $security = new LegacySecurity($this->root);
        $security->inspect();

        $this->assertSame('public', $security->inheritedPrepare('APP\Http\Requests\A'));
        // Sin padre, o con FormRequest de padre, no hereda ninguno.
        $this->assertNull($security->inheritedPrepare('App\Http\Requests\C'));
        $this->assertNull($security->inheritedPrepare('App\Http\Requests\B'));
        // Un ciclo no se recorre para siempre.
        $this->assertSame('unknown', $security->inheritedPrepare('App\Http\Requests\X'));
        // Lo que no se inspeccionó no se conoce.
        $this->assertSame('unknown', $security->inheritedPrepare('App\Nothing'));
    }

    public function test_sin_searchsurge_o_sin_new_builder_no_se_mira(): void
    {
        // Importa SearchSurge pero no construye la consulta.
        $noQuery = $this->put('Http/Requests/Post/ShowRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."interface Marker {}\n\n"
            ."class ShowRequest extends FormRequest\n{\n    public function handle(\$builder)\n    {\n        return \$builder instanceof Builder ? Builder::class : null;\n    }\n}\n");

        // Construye un Builder que no es el de SearchSurge.
        $eloquent = $this->put('Http/Requests/Post/ListRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Illuminate\\Database\\Eloquent\\Builder;\n\n"
            ."class ListRequest extends FormRequest\n{\n    public function handle()\n    {\n        return new Builder(\$this->query());\n    }\n}\n");

        $inspection = $this->inspect();

        $this->assertSame([], $inspection['debt']);
        $this->assertSame([], $inspection['clean'][LegacySecurity::INDEX_MANAGED]);
        $this->assertNotSame($noQuery, $eloquent);
    }

    public function test_una_interfaz_antes_de_la_clase_no_la_esconde(): void
    {
        $file = $this->put('Http/Requests/Post/IndexRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."interface Listing {}\n\n"
            ."class IndexRequest extends \\Illuminate\\Foundation\\Http\\FormRequest implements Listing\n{\n    public function handle() { return new \\Innoboxrr\\SearchSurge\\Search\\Builder(); }\n}\n");

        $this->assertSame([LegacySecurity::INDEX_MANAGED], $this->checksOf($file));
    }

    public function test_dos_requests_bien_en_un_archivo_cuentan_una_vez(): void
    {
        $file = $this->put('Http/Requests/Post/IndexRequest.php', "<?php\n\nnamespace App\\Http\\Requests\\Post;\n\nuse Innoboxrr\\SearchSurge\\Search\\Builder;\n\n"
            ."class First extends FormRequest\n{\n    protected function prepareForValidation() { \$this->merge(['managed' => true]); }\n    public function handle() { return new Builder(); }\n}\n\n"
            ."class Second extends FormRequest\n{\n    protected function prepareForValidation() { \$this->merge(['managed' => true]); }\n    public function handle() { return new Builder(); }\n}\n");

        $this->assertSame([LegacySecurity::INDEX_MANAGED => [$file], LegacySecurity::RESOURCE_APPENDS => []], $this->inspect()['clean']);
    }

    // RESOURCE-APPENDS

    private function resource(string $body, string $signature = '$request'): string
    {
        return $this->put('Http/Resources/PostResource.php', <<<PHP
        <?php

        namespace App\\Http\\Resources;

        use Illuminate\\Http\\Request;
        use Illuminate\\Http\\Resources\\Json\\JsonResource;

        class PostResource extends JsonResource
        {
            public function toArray({$signature})
            {
                {$body}

                return parent::toArray(\$request);
            }
        }
        PHP);
    }

    /**
     * La línea del setAppends() que se marca, o null.
     */
    private function flaggedLine(string $body, string $signature = '$request'): ?int
    {
        $file = $this->resource($body, $signature);
        $inspection = $this->inspect();
        $lines = array_column(array_filter($inspection['debt'], fn (array $debt): bool => $debt['file'] === $file), 'line');

        // Lo marcado no es a la vez limpio, y lo que no se marca y llama a
        // setAppends() sí lo es.
        $this->assertSame($lines === [] && stripos($body, 'setAppends') !== false ? [$file] : [], $inspection['clean'][LegacySecurity::RESOURCE_APPENDS]);

        return $lines[0] ?? null;
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2?: string}>
     */
    public static function appends(): array
    {
        return [
            'directo de la petición' => ["\$this->resource->setAppends(\$request->get('appends'));", true],
            'por una variable' => ["\$appends = \$request->input('appends');\n        \$this->resource->setAppends(\$appends);", true],
            'por el helper' => ["\$this->resource->setAppends(request('appends'));", true],
            'por el helper en mayúsculas' => ["\$this->resource->setAppends(REQUEST('appends'));", true],
            'por la fachada' => ["\$this->resource->setAppends(Request::input('appends'));", true],
            'con lista blanca' => ["\$this->resource->setAppends(array_intersect(\$this->appendable, \$request->get('appends')));", false],
            'con lista blanca en mayúsculas' => ["\$this->resource->setAppends(Array_Intersect(\$this->appendable, \$request->get('appends')));", false],
            'filtrada en una variable' => ["\$appends = \$request->input('appends');\n        \$appends = array_intersect(\$this->appendable, \$appends);\n        \$this->resource->setAppends(\$appends);", false],
            'reasignada a un literal' => ["\$appends = \$request->input('appends');\n        \$appends = [];\n        \$this->resource->setAppends(\$appends);", false],
            'una sentencia anterior no limpia la siguiente' => ["\$safe = array_intersect(\$this->appendable, []);\n        \$appends = \$request->input('appends');\n        \$this->resource->setAppends(\$appends);", true],
            'una variable en otra caja es otra variable' => ["\$appends = ['author'];\n        \$Appends = \$request->input('appends');\n        \$this->resource->setAppends(\$appends);", false],
            'las variables distinguen mayúsculas' => ["\$Appends = \$request->input('appends');\n        \$appends = ['author'];\n        \$this->resource->setAppends(\$appends);", false],
            'un closure dentro de la expresión no parte la sentencia' => ["\$appends = array_map(function (\$a) { return \$a; }, \$request->input('appends'));\n        \$this->resource->setAppends(\$appends);", true],
            'un closure dentro de los argumentos tampoco' => ["\$this->resource->setAppends(array_filter(\$request->input('appends'), function (\$a) { return \$a !== ''; }));", true],
            'lo que va detrás del paréntesis no es su argumento' => ["\$this->resource->setAppends(self::DEFAULTS)->makeHidden(\$request->input('hidden'));", false],
            'un argumento con paréntesis dentro' => ['$this->resource->setAppends(array_values(self::DEFAULTS));', false],
            'un método propio llamado request() no es el helper' => ["\$this->resource->setAppends(\$this->request('appends'));", false],
            'una constante llamada REQUEST no es la petición' => ['$this->resource->setAppends(REQUEST);', false],
            'sin setAppends no hay nada' => ["\$x = \$request->input('appends');", false],
            'setAppends sin llamar no cuenta' => ["\$name = 'setAppends';", false],
        ];
    }

    #[DataProvider('appends')]
    public function test_que_lleva_la_peticion_a_set_appends(string $body, bool $flagged): void
    {
        $line = $this->flaggedLine($body);

        if (! $flagged) {
            $this->assertNull($line);

            return;
        }

        // La línea del setAppends(), no la del método.
        $lines = explode("\n", (string) file_get_contents($this->root.'/app/Http/Resources/PostResource.php'));
        $this->assertNotNull($line);
        $this->assertStringContainsStringIgnoringCase('setAppends', $lines[$line - 1]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function firmas(): array
    {
        return [
            'tipada con Request' => ['Request $req', true],
            'tipada con una request propia' => ['\\App\\Http\\Requests\\PostShowRequest $req', true],
            'sin tipo y llamada $request' => ['$request', true],
            'sin tipo y llamada $Request' => ['$Request', true],
            'sin tipo con otro nombre no es la petición' => ['$req', false],
            'tipada con otra cosa' => ['array $req', false],
        ];
    }

    #[DataProvider('firmas')]
    public function test_que_parametro_es_la_peticion(string $signature, bool $flagged): void
    {
        $variable = substr($signature, (int) strrpos($signature, '$'));

        $this->assertSame($flagged, $this->flaggedLine("\$this->resource->setAppends({$variable}->get('appends'));", $signature) !== null);
    }

    public function test_un_resource_a_medio_escribir_no_tumba_la_lectura(): void
    {
        $file = $this->put('Http/Resources/PostResource.php', "<?php\n\nnamespace App\\Http\\Resources;\n\nclass PostResource extends JsonResource\n{\n"
            ."    public function toArray(\$request)\n    {\n        \$this->resource->setAppends(\$request->get('appends'");

        $debt = (new LegacySecurity($this->root))->inspect()['debt'];

        $this->assertSame([[LegacySecurity::RESOURCE_APPENDS, $file, 9]], array_map(fn (array $d): array => [$d['check'], $d['file'], $d['line']], $debt));
    }

    // LO QUE SE DICE

    public function test_los_mensajes(): void
    {
        $this->request("\$this->mergeIfMissing(['managed' => true]);");
        $this->resource("\$this->resource->setAppends(\$request->get('appends'));");

        $findings = (new LegacySecurity($this->root))->run();

        $this->assertSame([
            [
                'level' => 'warning',
                'check' => 'index-managed',
                'model' => null,
                'file' => 'app/Http/Requests/Post/IndexRequest.php',
                'message' => 'IndexRequest (línea 8) lista con el Builder de SearchSurge y no fuerza `managed` en prepareForValidation(): ManagedFilter::canView() sólo acota si llega `managed`, y quien lo omite en la petición ve todas las filas. '
                    ."Añade `\$this->merge(['managed' => true]);` en prepareForValidation(), como la plantilla actual del IndexRequest. Con `merge` gana sobre lo que mande el cliente; `mergeIfMissing` no. `larapack:fix index-managed` lo hace.",
            ],
            [
                'level' => 'warning',
                'check' => 'resource-appends',
                'model' => null,
                'file' => 'app/Http/Resources/PostResource.php',
                'message' => 'PostResource::toArray() (línea 12) pasa a setAppends() lo que manda el cliente sin cruzarlo con una lista blanca: el cliente decide qué accessor del modelo se ejecuta en cada fila, también los de uso interno, y uno que no existe tumba la respuesta. '
                    .'Declara los permitidos en `protected array $appendable = [...]` y pasa sólo `array_intersect($this->appendable, $appends)`, sumado a getAppends(), como la plantilla actual del Resource. `larapack:fix resource-appends` lo hace.',
            ],
        ], $findings);
    }
}
