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
 *  - cada enlace relativo apunta a algo que existe y, si lleva `#ancla`, a un
 *    encabezado de ese documento, con el slug que calcula GitHub;
 *  - un enlace a un archivo PHP con una clase por texto (`[`Clase`](ruta.php)`,
 *    `[`Clase::miembro`](ruta.php)`) apunta al archivo de esa clase;
 *  - cada ruta del repositorio escrita como código (`src/...`, `schema/...`,
 *    `ecosystem.json`...) existe; una con `*` tiene que casar con algo, y una con
 *    `<...>` es un patrón de lo generado, no una ruta de aquí;
 *  - cada `Clase::miembro` nombra una única clase de src/ que tiene ese método
 *    —con las mismas mayúsculas—, esa constante o esa propiedad;
 *  - cada test nombrado como código (`AlgoTest`) existe bajo tests/.
 *
 * Lo que va dentro de un bloque de código —el diagrama, un ejemplo—, también uno
 * sangrado dentro de una lista, no se comprueba: son ilustraciones, no citas.
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
    public function test_cada_ancla_apunta_a_un_encabezado(string $document): void
    {
        preg_match_all('/\]\(([^)\s]*#[^)\s]+)\)/', $this->prose($document), $matches);

        $broken = [];

        foreach ($matches[1] as $target) {
            if (preg_match('#^[a-z]+:#i', $target)) {
                continue;
            }

            [$path, $anchor] = explode('#', $target, 2);

            // Sólo los documentos tienen encabezados; un #L12 a un PHP no se
            // puede comprobar sin GitHub.
            if ($path !== '' && ! str_ends_with($path, '.md')) {
                continue;
            }

            $file = $path === '' ? $document : $path;

            if (! is_file($this->root().'/'.$file) || ! in_array(rawurldecode($anchor), $this->anchors($file), true)) {
                $broken[] = $target;
            }
        }

        $this->assertSame([], array_values(array_unique($broken)), "{$document} enlaza a anclas que no existen.");
    }

    public function test_el_slug_de_un_encabezado_es_el_de_github(): void
    {
        $this->assertSame('el-contrato-esquema-valores-por-omisión-y-validación', $this->slug('El contrato: esquema, valores por omisión y validación'));
        $this->assertSame('verify', $this->slug('`verify`'));
        $this->assertSame('lo-que-no-pasa-por-toolgenerate', $this->slug('Lo que no pasa por `Tool::generate`'));
        $this->assertSame('audit-y-la-línea-base', $this->slug('[`audit`](#x) y la línea base'));
    }

    #[DataProvider('documents')]
    public function test_un_enlace_con_una_clase_apunta_a_su_archivo(string $document): void
    {
        preg_match_all(
            '/\[`\\\\?([A-Z][A-Za-z0-9]*)(?:::[^`]*)?`\]\(([^)\s#]+\.php)(?:#[^)\s]*)?\)/',
            $this->prose($document),
            $matches,
            PREG_SET_ORDER
        );

        $mismatched = [];

        foreach ($matches as [$link, $class, $path]) {
            if (basename($path, '.php') !== $class) {
                $mismatched[] = $link;
            }
        }

        $this->assertSame([], array_values(array_unique($mismatched)), "{$document} enlaza una clase al archivo de otra.");
    }

    #[DataProvider('documents')]
    public function test_cada_test_que_nombra_existe(string $document): void
    {
        $tests = $this->testClasses();
        $missing = [];

        foreach ($this->codeSpans($document) as $span) {
            $name = ltrim($span, '\\');

            if (preg_match('/^[A-Z][A-Za-z0-9_]*Test$/', $name) && ! isset($tests[$name])) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "{$document} nombra tests que no existen bajo tests/.");
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
            if (! preg_match('/^\\\\?([A-Z][A-Za-z0-9]*)::(\$?[A-Za-z_][A-Za-z0-9_]*)/', $span, $matches)) {
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

            // hasMethod() no distingue mayúsculas, como PHP al llamar: el
            // documento tiene que escribir el nombre tal como está declarado.
            $found = str_starts_with($member, '$')
                ? $reflection->hasProperty(substr($member, 1))
                : ($reflection->hasMethod($member) && $reflection->getMethod($member)->getName() === $member)
                    || $reflection->hasConstant($member);

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
     * El documento sin sus bloques de código, también los sangrados dentro de
     * una lista y los de `~~~`.
     */
    private function prose(string $document): string
    {
        $path = $this->root().'/'.$document;

        $this->assertFileExists($path);

        return (string) preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', '', (string) file_get_contents($path));
    }

    /**
     * El contenido de cada `código` de la prosa. Un span puede partirse en dos
     * líneas, como en Markdown: el salto y la sangría cuentan como un espacio.
     * Uno que cruzara un párrafo sería un acento suelto, no un span.
     *
     * @return list<string>
     */
    private function codeSpans(string $document): array
    {
        preg_match_all('/`([^`]+)`/', $this->prose($document), $matches);

        $spans = [];

        foreach ($matches[1] as $span) {
            if (preg_match('/\n[ \t]*\n/', $span)) {
                continue;
            }

            $spans[] = trim((string) preg_replace('/[ \t]*\n[ \t]*/', ' ', $span));
        }

        return $spans;
    }

    /**
     * Las anclas que GitHub da a los encabezados de un documento, con -1, -2...
     * para los repetidos.
     *
     * @return list<string>
     */
    private function anchors(string $document): array
    {
        preg_match_all('/^#{1,6}[ \t]+(.+?)[ \t#]*$/m', $this->prose($document), $matches);

        $anchors = [];
        $seen = [];

        foreach ($matches[1] as $heading) {
            $slug = $this->slug($heading);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : "{$slug}-{$count}";
        }

        return $anchors;
    }

    /**
     * El slug de GitHub: el texto como se ve (sin los acentos graves ni la
     * dirección de un enlace), en minúsculas, sin puntuación salvo `-` y `_`,
     * y cada espacio un guion.
     */
    private function slug(string $heading): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        $text = str_replace('`', '', $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', $text);

        return str_replace(' ', '-', $text);
    }

    /**
     * Nombre corto => ruta de cada archivo PHP de tests/, sin las fixtures.
     *
     * @return array<string, string>
     */
    private function testClasses(): array
    {
        $tests = [];
        $directory = $this->root().'/tests';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));

            if (str_ends_with($relative, '.php') && ! str_starts_with($relative, 'Fixtures/')) {
                $tests[basename($relative, '.php')] = $relative;
            }
        }

        return $tests;
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
