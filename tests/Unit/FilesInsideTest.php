<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La regla por la que pasa toda escritura del proyecto: el camino real tiene
 * que quedarse en la raíz. Aquí, caso por caso; los ataques enteros están en
 * GeneratorInjectionAuditTest.
 */
final class FilesInsideTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        $base = (string) realpath(sys_get_temp_dir()).'/larapack-inside-'.bin2hex(random_bytes(6));

        $this->root = $base.'/project';
        $this->outside = $base.'/outside';

        mkdir($this->root.'/src', 0777, true);
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        self::remove(dirname($this->root));
    }

    public function test_una_ruta_normal_del_proyecto_vale_exista_o_no(): void
    {
        Files::assertInside($this->root, $this->root.'/src/Models/Post.php', true);
        Files::assertInside($this->root, $this->root.'/nuevo/sub/dir/archivo.php', true);

        $this->assertTrue(Files::isInside($this->root, $this->root.'/src/Models/Post.php'));
    }

    public function test_un_directorio_enlazado_hacia_fuera_no_vale(): void
    {
        symlink($this->outside, $this->root.'/src/Models');

        $this->assertFalse(Files::isInside($this->root, $this->root.'/src/Models/Post.php'));

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage($this->outside);

        Files::assertInside($this->root, $this->root.'/src/Models/Post.php');
    }

    public function test_un_enlace_roto_cuenta_como_fuera(): void
    {
        symlink($this->outside.'/no-existe.php', $this->root.'/src/Post.php');

        $this->assertFalse(Files::isInside($this->root, $this->root.'/src/Post.php'));

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('enlace simbólico roto');

        Files::assertInside($this->root, $this->root.'/src/Post.php');
    }

    public function test_un_enlace_que_se_queda_dentro_vale(): void
    {
        mkdir($this->root.'/real');
        symlink($this->root.'/real', $this->root.'/src/Models');
        file_put_contents($this->root.'/real/Post.php', '');
        symlink($this->root.'/real/Post.php', $this->root.'/src/Alias.php');

        Files::assertInside($this->root, $this->root.'/src/Models/Post.php', true);
        Files::assertInside($this->root, $this->root.'/src/Alias.php', true);

        $this->assertTrue(Files::isInside($this->root, $this->root.'/src/Alias.php'));
    }

    /**
     * Una ruta que sale por escrito la eligió quien corre el comando (un
     * temporal, `--path=../x`); estricta, viene de un archivo del proyecto.
     */
    public function test_salir_por_escrito_solo_es_un_error_si_la_ruta_viene_del_proyecto(): void
    {
        Files::assertInside($this->root, $this->root.'/../outside/x.php');
        Files::assertInside($this->root, $this->outside.'/x.php');

        $this->expectException(MakerException::class);

        Files::assertInside($this->root, $this->root.'/src/../../outside/x.php', true);
    }

    public function test_write_no_escribe_a_traves_del_enlace_y_falla_si_no_puede(): void
    {
        symlink($this->outside, $this->root.'/src/Models');

        try {
            Files::guard($this->root.'/src/Models/Post.php', $this->root);
            $this->fail('La guarda dejó pasar el enlace.');
        } catch (MakerException) {
            $this->assertFileDoesNotExist($this->outside.'/Post.php');
        }

        $this->assertSame(3, Files::write($this->root.'/src/a.txt', 'abc'));

        $this->expectException(MakerException::class);
        $this->expectExceptionMessage('No se pudo escribir');

        Files::write($this->root.'/no/existe/a.txt', 'abc');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function relativePaths(): array
    {
        return [
            'normal' => ['src/Models/Post.php', true],
            'con punto' => ['src/./Models/Post.php', true],
            'nombre con dos puntos dentro' => ['src/a..b/Post.php', true],
            'sube' => ['../x.php', false],
            'sube a mitad' => ['src/../../x.php', false],
            'sube con barra invertida' => ['src\\..\\..\\x.php', false],
            'absoluta' => ['/etc/passwd', false],
            'unidad de Windows' => ['C:/Windows/x.php', false],
            'vacía' => ['', false],
            'con NUL' => ["src/a\0.php", false],
        ];
    }

    #[DataProvider('relativePaths')]
    public function test_una_ruta_relativa_de_un_archivo_del_proyecto(string $relative, bool $inside): void
    {
        $this->assertSame($inside, Files::isRelativeInside($relative));
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
