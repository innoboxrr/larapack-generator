<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
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
}
