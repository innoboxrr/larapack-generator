<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que el modulo generado declara tiene que ser instalable.
 *
 * Los stubs traian `innoboxrr-form-core: ^1.0.0` y `innoboxrr-vue-datatable:
 * ^1.4.0` mucho despues de que esos paquetes fueran por 2.x. Nadie se entera:
 * el modulo se genera igual, npm resuelve una version antigua, y el fallo
 * aparece en el navegador como un componente que no existe.
 *
 * Las versiones viven en ecosystem.json y estos tests comprueban que los
 * stubs digan lo mismo.
 */
final class GeneratedModuleDependenciesTest extends TestCase
{
    /** @var array<string, string> */
    private array $blessed;

    protected function setUp(): void
    {
        parent::setUp();

        $ecosystem = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/ecosystem.json'),
            true
        );

        $this->blessed = $ecosystem['internalNpm'] ?? [];

        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', [
                'jsonPath' => dirname(__DIR__) . '/Fixtures/laraimport.json',
                '--vue' => true,
                '--react' => true,
            ]),
            "larapack:import --vue --react terminó con error:\n" . $this->lastOutput
        );
    }

    public function test_el_manifiesto_declara_las_versiones_del_ecosistema(): void
    {
        $this->assertNotSame([], $this->blessed, 'ecosystem.json no declara internalNpm.');
    }

    public function test_el_modulo_vue_pide_las_versiones_bendecidas(): void
    {
        $this->assertDependenciesMatch('resources/vue/package.json');
    }

    public function test_el_modulo_react_pide_las_versiones_bendecidas(): void
    {
        $this->assertDependenciesMatch('resources/react/package.json');
    }

    /**
     * form-core aporta la hoja de estilos y el mapa de iconos: sin el, el
     * modulo se genera pero no se ve.
     */
    public function test_ambos_modulos_dependen_del_nucleo_visual(): void
    {
        foreach (['resources/vue/package.json', 'resources/react/package.json'] as $manifest) {
            $this->assertArrayHasKey(
                'innoboxrr-form-core',
                $this->dependenciesOf($manifest),
                "{$manifest} no declara innoboxrr-form-core."
            );
        }
    }

    public function test_el_tema_carga_la_hoja_de_estilos(): void
    {
        // Sin esta linea los controles salen sin estilo y no lo dice nadie:
        // las clases existen, pero no hay CSS que las defina.
        foreach (['resources/vue/src/theme.js', 'resources/react/src/theme.js'] as $file) {
            $this->assertStringContainsString(
                "import 'innoboxrr-form-core/styles'",
                $this->read($file),
                "{$file} no importa la hoja de estilos."
            );
        }
    }

    private function assertDependenciesMatch(string $manifest): void
    {
        $declared = $this->dependenciesOf($manifest);
        $desviaciones = [];

        foreach ($declared as $name => $constraint) {
            if (! str_starts_with($name, 'innoboxrr-')) {
                continue;
            }

            if (! isset($this->blessed[$name])) {
                $desviaciones[] = "{$name} no está en ecosystem.json";

                continue;
            }

            if ($this->blessed[$name] !== $constraint) {
                $desviaciones[] = "{$name}: el módulo pide {$constraint} y la línea base es {$this->blessed[$name]}";
            }
        }

        $this->assertSame([], $desviaciones, $manifest . ":\n" . implode("\n", $desviaciones));
    }

    /**
     * @return array<string, string>
     */
    private function dependenciesOf(string $manifest): array
    {
        $decoded = json_decode($this->read($manifest), true);

        return array_merge(
            $decoded['dependencies'] ?? [],
            $decoded['peerDependencies'] ?? [],
        );
    }

    private function read(string $relative): string
    {
        $path = $this->project->path . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
