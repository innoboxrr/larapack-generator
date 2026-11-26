<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

/**
 * El nombre del modelo llega de la línea de órdenes y acaba en rutas de
 * archivo. Sin comprobarlo, `larapack:remove-full-model ..` borraba
 * recursivamente `src/Http`, `src/Models` y compañía: el directorio padre de
 * cada carpeta del modelo. Un agente que se equivoca de argumento no puede
 * llevarse el paquete entero por delante.
 */
final class ModelNameSafetyTest extends TestCase
{
    private ?string $outside = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:full-model', ['name' => 'Product']),
            "larapack:full-model terminó con error:\n".$this->lastOutput
        );
    }

    protected function tearDown(): void
    {
        if ($this->outside !== null) {
            @unlink($this->outside.'/keep.txt');
            @rmdir($this->outside);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousNames(): array
    {
        return [
            'padre' => ['..'],
            'actual' => ['.'],
            'vacío' => [''],
            'subida' => ['../../x'],
            'subcarpeta' => ['Foo/Bar'],
        ];
    }

    #[DataProvider('dangerousNames')]
    public function test_remove_full_model_rechaza_el_nombre_y_no_borra_nada(string $name): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:remove-full-model', ['name' => $name]));

        $this->assertStringContainsString('no es un nombre de modelo válido', $this->lastOutput);

        foreach ([
            'src/Http/Requests/Product/IndexRequest.php',
            'src/Models/Filters/Product/ManagedFilter.php',
            'src/Http/Events/Product/Events/CreateEvent.php',
            'src/Models/Product.php',
        ] as $file) {
            $this->assertTrue($this->project->has($file), "Se borró {$file} con el nombre «{$name}».");
        }
    }

    public function test_full_model_rechaza_un_nombre_que_sale_del_proyecto(): void
    {
        $this->assertSame(
            Command::FAILURE,
            $this->runCommand('larapack:full-model', ['name' => '../../Escape', '--format' => 'json'])
        );

        $report = json_decode($this->lastOutput, true);

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('no es un nombre de modelo válido', $report['error']);
        $this->assertFileDoesNotExist(dirname($this->project->path, 2).'/Escape.php');
    }

    public function test_un_comando_suelto_rechaza_un_nombre_con_ruta(): void
    {
        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('no es un nombre de modelo válido');

        $this->runCommand('larapack:model', ['name' => '../../Escape']);
    }

    /**
     * Los nombres de siempre siguen valiendo, también en snake: el inflector
     * los lleva a PascalCase.
     */
    public function test_acepta_nombres_en_pascal_y_en_snake(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:model', ['name' => 'blog_post']));

        $this->assertTrue($this->project->has('src/Models/BlogPost.php'));
    }

    public function test_quitar_un_modelo_no_sigue_los_enlaces_simbolicos(): void
    {
        $this->outside = sys_get_temp_dir().'/larapack-outside-'.bin2hex(random_bytes(6));
        mkdir($this->outside);
        file_put_contents($this->outside.'/keep.txt', 'no me borres');

        $link = $this->project->path.'/src/Http/Requests/Product/compartido';

        if (! @symlink($this->outside, $link)) {
            $this->markTestSkipped('El sistema no permite crear enlaces simbólicos.');
        }

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:remove-full-model', ['name' => 'Product']));

        $this->assertFalse($this->project->has('src/Http/Requests/Product'));
        $this->assertFileExists($this->outside.'/keep.txt', 'Borrar el modelo vació el destino de un enlace.');
    }
}
