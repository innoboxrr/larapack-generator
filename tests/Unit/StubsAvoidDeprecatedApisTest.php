<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Lo que generan los stubs no debe usar APIs que Laravel 13 ya marca como
 * deprecadas.
 *
 * El Resource leia `appends` con `$request->get()`. Funciona, pero esta
 * deprecado en Laravel 13 y en Symfony 7.4, y Symfony 8 lo quita de su
 * Request; Laravel 13 ya admite Symfony 8. Cada paquete generado heredaba
 * la deuda sin que nadie lo hubiera escrito a mano.
 */
final class StubsAvoidDeprecatedApisTest extends TestCase
{
    /**
     * Patron => por que no.
     *
     * @var array<string, string>
     */
    private const DEPRECATED = [
        '/\$request->get\(/' => 'Request::get() esta deprecado: usa input(), query() o route().',
        '/request\(\)->get\(/' => 'Request::get() esta deprecado: usa input(), query() o route().',
        '/protected \$dates\b/' => '$dates ya no existe: declara el cast en casts().',
        '/protected \$casts\b/' => 'Laravel 11+ declara los casts en el metodo casts(), que es lo que genera el resto.',
    ];

    public function test_ningun_stub_usa_apis_deprecadas(): void
    {
        $stubs = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Stubs', \FilesystemIterator::SKIP_DOTS)
        );

        $hallazgos = [];

        foreach ($stubs as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::DEPRECATED as $pattern => $reason) {
                if (preg_match($pattern, $contents) === 1) {
                    $hallazgos[] = $file->getFilename().': '.$reason;
                }
            }
        }

        $this->assertSame([], $hallazgos, implode("\n", $hallazgos));
    }
}
