<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Holes;
use PHPUnit\Framework\TestCase;

/**
 * Los huecos de Holes tienen que ser lo que el generador deja para el
 * usuario: ni más (se colaría lógica fuera de su sitio sin que el
 * diagnóstico la viera) ni menos (código legítimo en un método que la
 * plantilla deja vacío salía `edited_outside`, y la única salida era pedir
 * una excepción: un prepareForValidation() que normaliza el payload, en una
 * copia de un proyecto 7.x).
 *
 * Se cruzan tres fuentes sin interpretar prosa:
 *
 *  - las plantillas de src/Stubs: un método que la plantilla deja para el
 *    usuario (cuerpo vacío, una línea `//` o el `abort(501)` de lo que no
 *    está implementado) en un archivo que no es hueco entero;
 *  - Holes::METHODS y Holes::PLACEHOLDERS;
 *  - la tabla «Dónde va tu código» del README.
 *
 * Una plantilla nueva con un hueco que no se declare falla aquí.
 */
final class HolesTest extends TestCase
{
    /**
     * Métodos que la plantilla deja vacíos y no son hueco, con la razón.
     * `<stub>::<método>`.
     */
    private const NOT_HOLES = [
        // El constructor sólo promueve la propiedad: no tiene cuerpo que escribir.
        'Export/ExportTemplate.txt::__construct' => 'constructor con propiedad promovida',
        // Las migraciones no se reescriben nunca (migration_history): no tienen huecos.
        'Migration/DropTemplate.txt::down' => 'una migración no es un hueco',
    ];

    public function test_los_ganchos_de_validacion_de_una_request_son_hueco(): void
    {
        foreach (['prepareForValidation', 'passedValidation', 'messages', 'attributes', 'rules'] as $method) {
            $this->assertTrue(Holes::onlyHoles('Requests/CreateRequest.txt', ['method:'.strtolower($method)]), "{$method}() de una request es hueco");
        }

        $this->assertTrue(Holes::onlyHoles('Requests/UpdateRequest.txt', ['imports', 'method:prepareforvalidation', 'method:rules']));
        $this->assertFalse(Holes::onlyHoles('Requests/CreateRequest.txt', ['method:prepareforvalidation', 'method:handle']));
        $this->assertTrue(Holes::onlyHoles('Endpoint/RequestTemplate.txt', ['method:prepareforvalidation']));
    }

    public function test_el_prepare_del_index_no_es_hueco_porque_fuerza_managed(): void
    {
        // IndexRequest::prepareForValidation() no nace vacío: fuerza
        // `managed` y es lo que acota el listado. Quitarlo no es escribir en
        // un hueco.
        $this->assertFalse(Holes::onlyHoles('Requests/IndexRequest.txt', ['method:prepareforvalidation']));
        $this->assertTrue(Holes::onlyHoles('Requests/IndexRequest.txt', ['method:passedvalidation', 'method:rules']));
    }

    public function test_el_handle_sin_implementar_de_un_endpoint_sin_modelo_es_hueco(): void
    {
        $placeholder = $this->endpointRequest("        abort(501, 'POST x todavía no está implementado.');", '        return false;');
        $delegating = $this->endpointRequest('        return (new Post)->publishModel($this);', "        return \$this->user()->can('publish', Post::class);");

        $this->assertTrue(Holes::onlyHoles('Endpoint/RequestTemplate.txt', ['method:handle', 'method:authorize'], $placeholder));
        $this->assertFalse(Holes::onlyHoles('Endpoint/RequestTemplate.txt', ['method:handle'], $delegating));
        $this->assertFalse(Holes::onlyHoles('Endpoint/RequestTemplate.txt', ['method:authorize'], $delegating));
        // Sin lo regenerado no se sabe: no es hueco.
        $this->assertFalse(Holes::onlyHoles('Endpoint/RequestTemplate.txt', ['method:handle']));
        $this->assertSame(['prepareForValidation', 'rules', 'authorize', 'handle'], Holes::methods('Endpoint/RequestTemplate.txt', $placeholder));
    }

    public function test_cada_hueco_que_deja_una_plantilla_esta_declarado(): void
    {
        $undeclared = [];

        foreach ($this->stubs() as $stub => $content) {
            foreach ($this->leftForTheUser($content) as $method) {
                if (! $this->declared($stub, $method) && ! isset(self::NOT_HOLES["{$stub}::{$method}"])) {
                    $undeclared[] = "{$stub}::{$method}()";
                }
            }
        }

        $this->assertSame([], $undeclared, 'La plantilla deja estos métodos para el usuario y Holes no los declara: añádelos a Holes::METHODS (y a «Dónde va tu código» del README) o, si no son hueco, a NOT_HOLES con la razón.');
    }

    public function test_una_plantilla_nueva_con_un_hueco_sin_declarar_se_detecta(): void
    {
        $stub = <<<'PHP'
            <?php

            class NewTemplate
            {
                public function done(): bool
                {
                    return true;
                }

                public function fillMe(): void
                {
                    //
                }
            }
            PHP;

        $this->assertSame(['fillMe'], $this->leftForTheUser($stub));
        $this->assertFalse($this->declared('New/NewTemplate.txt', 'fillMe'));
    }

    public function test_cada_hueco_declarado_existe_en_su_plantilla(): void
    {
        $stale = [];
        $stubs = $this->stubs();

        foreach ($this->declarations() as [$prefix, $methods]) {
            $matching = array_filter($stubs, fn (string $stub): bool => str_starts_with($stub, (string) $prefix), ARRAY_FILTER_USE_KEY);

            if ($matching === []) {
                $stale[] = "{$prefix}: ninguna plantilla";
            }

            foreach ($matching as $stub => $content) {
                foreach ($methods as $method) {
                    if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $content) !== 1) {
                        $stale[] = "{$stub}::{$method}()";
                    }
                }
            }
        }

        $this->assertSame([], $stale, 'Holes declara huecos que ninguna plantilla genera.');
    }

    public function test_el_readme_nombra_cada_hueco_de_metodo(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
        $start = (int) strpos($readme, '### Dónde va tu código');
        $section = substr($readme, $start, (int) strpos($readme, "\n### ", $start + 10) - $start);
        $missing = [];

        foreach ($this->declarations() as [$prefix, $methods]) {
            foreach ($methods as $method) {
                if (! str_contains($section, $method.'()') && ! str_contains($section, '::'.$method)) {
                    $missing[] = "{$prefix}: {$method}()";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'La tabla «Dónde va tu código» del README no nombra estos huecos.');
    }

    /**
     * Cada declaración de Holes, de métodos y de marcadores, como pares
     * [prefijo, métodos]: un mismo prefijo puede estar en las dos.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function declarations(): array
    {
        $declarations = [];

        foreach (Holes::METHODS as $prefix => $methods) {
            $declarations[] = [$prefix, $methods];
        }

        foreach (Holes::PLACEHOLDERS as $prefix => $markers) {
            $declarations[] = [$prefix, array_keys($markers)];
        }

        return $declarations;
    }

    /**
     * Si el método es hueco en algún archivo de ese stub: de método, de
     * marcador (PLACEHOLDERS) o porque el archivo entero lo es.
     */
    private function declared(string $stub, string $method): bool
    {
        if (Holes::wholeFile($stub)) {
            return true;
        }

        foreach ($this->declarations() as [$prefix, $methods]) {
            if (str_starts_with($stub, (string) $prefix) && in_array($method, $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los métodos que la plantilla deja para el usuario: cuerpo vacío (sin
     * contar comentarios), una línea que es sólo `//`, o un `abort(501)`.
     * Las líneas `@larapack:if` cuentan como comentario.
     *
     * @return list<string>
     */
    private function leftForTheUser(string $content): array
    {
        $found = [];

        if (preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{;]*\{(.*?)\n    \}/s', $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as [, $method, $body]) {
            $code = trim((string) preg_replace('#//.*$#m', '', $body));

            if ($code === '' || preg_match('#^\s*//\s*$#m', $body) === 1 || str_contains($body, 'abort(501')) {
                $found[] = $method;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<string, string> stub relativo a Stubs/ => contenido, sólo PHP
     */
    private function stubs(): array
    {
        $root = dirname(__DIR__, 2).'/src/Stubs';
        $stubs = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $content = (string) file_get_contents((string) $file);

            if (str_ends_with((string) $file, '.txt') && str_starts_with(ltrim($content), '<?php')) {
                $stubs[str_replace('\\', '/', substr((string) $file, strlen($root) + 1))] = $content;
            }
        }

        ksort($stubs);

        return $stubs;
    }

    private function endpointRequest(string $handle, string $authorize): string
    {
        return <<<PHP
            <?php

            namespace Acme\\Http\\Requests\\Post;

            use Illuminate\\Foundation\\Http\\FormRequest;

            class PublishRequest extends FormRequest
            {
                protected function prepareForValidation(): void
                {
                    //
                }

                public function authorize(): bool
                {
            {$authorize}
                }

                public function rules(): array
                {
                    return [];
                }

                public function handle()
                {
            {$handle}
                }
            }
            PHP;
    }
}
