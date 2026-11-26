<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ARCHITECTURE.md y CONTRIBUTING.md citan archivos, clases y miembros del
 * generador. Un documento así envejece en silencio: se renombra una clase, se
 * mueve un directorio, y quien llega a mantener el generador sigue un mapa que
 * ya no corresponde con el terreno. Peor que no tener mapa.
 *
 * Aquí se comprueba, sin interpretar la prosa, lo que el documento afirma de
 * forma literal:
 *
 *  - cada enlace relativo apunta a algo que existe;
 *  - cada ruta del repositorio escrita como código (`src/...`, `schema/...`,
 *    `ecosystem.json`...) existe; una con `*` tiene que casar con algo, y una con
 *    `<...>` es un patrón de lo generado, no una ruta de aquí;
 *  - cada `Clase::miembro` nombra una única clase de src/ que tiene ese método,
 *    esa constante o esa propiedad.
 *
 * Lo que va dentro de un bloque de código —el diagrama, un ejemplo— no se
 * comprueba: son ilustraciones, no citas.
 */
final class ArchitectureDocTest extends TestCase
{
    /**
     * Lo que puede empezar una ruta de este repositorio. Una ruta de un
     * proyecto generado (`routes/api/...`, `.larapack/...`) no empieza así.
     */
    private const REPOSITORY_DIRECTORIES = ['src/', 'schema/', 'skill/', 'tests/', 'config/', 'docs/', '.github/'];

    private const REPOSITORY_FILES = [
        'builder', 'composer.json', 'ecosystem.json', 'VERSION', 'CHANGELOG.md', 'README.md',
        'ARCHITECTURE.md', 'CONTRIBUTING.md', 'phpunit.xml.dist', 'phpstan.neon.dist', 'pint.json',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function documents(): array
    {
        return [
            'ARCHITECTURE.md' => ['ARCHITECTURE.md'],
            'CONTRIBUTING.md' => ['CONTRIBUTING.md'],
        ];
    }

    #[DataProvider('documents')]
    public function test_los_enlaces_relativos_apuntan_a_algo_que_existe(string $document): void
    {
        preg_match_all('/\]\(([^)\s]+)\)/', $this->prose($document), $matches);

        $missing = [];

        foreach ($matches[1] as $target) {
            if (preg_match('#^([a-z]+:|\#)#i', $target)) {
                continue;
            }

            $path = explode('#', $target, 2)[0];

            if (! file_exists($this->root().'/'.$path)) {
                $missing[] = $target;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "{$document} enlaza a lo que ya no existe.");
    }

    #[DataProvider('documents')]
    public function test_las_rutas_del_repositorio_que_cita_existen(string $document): void
    {
        $missing = [];

        foreach ($this->codeSpans($document) as $span) {
            if (! $this->isRepositoryPath($span) || str_contains($span, '<')) {
                continue;
            }

            $exists = str_contains($span, '*')
                ? (glob($this->root().'/'.$span) ?: []) !== []
                : file_exists($this->root().'/'.$span);

            if (! $exists) {
                $missing[] = $span;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "{$document} cita rutas que ya no existen.");
    }

    #[DataProvider('documents')]
    public function test_cada_clase_y_miembro_que_cita_existe(string $document): void
    {
        $classes = $this->sourceClasses();
        $problems = [];

        foreach ($this->codeSpans($document) as $span) {
            if (! preg_match('/^([A-Z][A-Za-z0-9]*)::(\$?[A-Za-z_][A-Za-z0-9_]*)/', $span, $matches)) {
                continue;
            }

            [, $class, $member] = $matches;
            $candidates = $classes[$class] ?? [];

            if (count($candidates) !== 1) {
                $problems[] = $candidates === []
                    ? "`{$span}`: no hay ninguna clase {$class} en src/."
                    : "`{$span}`: hay varias clases {$class} en src/ (".implode(', ', $candidates).').';

                continue;
            }

            $reflection = new ReflectionClass($candidates[0]);

            $found = str_starts_with($member, '$')
                ? $reflection->hasProperty(substr($member, 1))
                : $reflection->hasMethod($member) || $reflection->hasConstant($member);

            if (! $found) {
                $problems[] = "`{$span}`: {$candidates[0]} no tiene {$member}.";
            }
        }

        $this->assertSame([], array_values(array_unique($problems)), "{$document} cita miembros que ya no existen.");
    }

    public function test_el_readme_enlaza_los_dos_documentos(): void
    {
        $readme = (string) file_get_contents($this->root().'/README.md');

        $this->assertStringContainsString('(ARCHITECTURE.md)', $readme);
        $this->assertStringContainsString('(CONTRIBUTING.md)', $readme);
    }

    /**
     * El documento sin sus bloques de código.
     */
    private function prose(string $document): string
    {
        $path = $this->root().'/'.$document;

        $this->assertFileExists($path);

        return (string) preg_replace('/^```.*?^```[ \t]*$/ms', '', (string) file_get_contents($path));
    }

    /**
     * El contenido de cada `código` de la prosa.
     *
     * @return list<string>
     */
    private function codeSpans(string $document): array
    {
        preg_match_all('/`([^`\n]+)`/', $this->prose($document), $matches);

        return array_map('trim', $matches[1]);
    }

    private function isRepositoryPath(string $span): bool
    {
        if (in_array($span, self::REPOSITORY_FILES, true)) {
            return true;
        }

        foreach (self::REPOSITORY_DIRECTORIES as $directory) {
            if (str_starts_with($span, $directory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nombre corto => nombres completos de las clases, traits e interfaces de
     * src/, sin las plantillas, que no son PHP.
     *
     * @return array<string, list<string>>
     */
    private function sourceClasses(): array
    {
        $classes = [];
        $source = $this->root().'/src';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));

            if (! str_ends_with($relative, '.php') || str_starts_with($relative, 'Stubs/') || str_starts_with($relative, 'Helpers/')) {
                continue;
            }

            $fqcn = 'Innoboxrr\\LarapackGenerator\\'.str_replace('/', '\\', substr($relative, 0, -4));

            $classes[basename($relative, '.php')][] = $fqcn;
        }

        return $classes;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
