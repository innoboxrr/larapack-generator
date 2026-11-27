<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Files;
use PHPUnit\Framework\TestCase;

/**
 * Files::read es por donde pasan las lecturas que deciden algo —el
 * manifiesto, el hash de un archivo, el esquema, ecosystem.json—. Su razón de
 * ser es el camino que falla: si devolviera algo en vez de lanzar, un archivo
 * ilegible volvería a decidir en silencio.
 */
final class FilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/larapack-files-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/manifest.json');
        @rmdir($this->directory);
    }

    public function test_devuelve_el_contenido_tal_cual(): void
    {
        file_put_contents($this->directory.'/manifest.json', "{}\n");

        $this->assertSame("{}\n", Files::read($this->directory.'/manifest.json'));
    }

    public function test_un_archivo_vacio_es_vacio_no_un_fallo(): void
    {
        file_put_contents($this->directory.'/manifest.json', '');

        $this->assertSame('', Files::read($this->directory.'/manifest.json'));
    }

    public function test_un_archivo_que_no_existe_falla_con_su_ruta(): void
    {
        $missing = $this->directory.'/manifest.json';

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage("No se pudo leer {$missing}.");

        Files::read($missing);
    }

    public function test_un_directorio_falla_con_su_ruta(): void
    {
        $this->expectException(MakerException::class);
        $this->expectExceptionMessage("No se pudo leer {$this->directory}.");

        Files::read($this->directory);
    }
}
