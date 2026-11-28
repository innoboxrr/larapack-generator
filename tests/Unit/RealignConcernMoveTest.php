<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Apply\ConcernMove;
use PHPUnit\Framework\TestCase;

/**
 * `ConcernMove`: lo que el movimiento escribía y parseaba pero estaba roto.
 * Cada caso con un proyecto temporal de verdad: se planea, se escribe y se
 * mira lo que quedó en el disco.
 */
final class RealignConcernMoveTest extends TestCase
{
    private const OLD_NS = 'App\\Http\\Controllers\\Concerns';

    private const OLD = 'App\\Http\\Controllers\\Concerns\\Scoped';

    private const NEW = 'App\\Http\\Requests\\Concerns\\Scoped';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/larapack-concern-move-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        ConcernMove::reset();
    }

    protected function tearDown(): void
    {
        ConcernMove::reset();
        $this->remove($this->root);

        parent::tearDown();
    }

    // (a) El trait movido usa nombres cortos de su namespace viejo.

    public function test_importa_los_hermanos_que_el_trait_usaba_por_su_nombre_corto(): void
    {
        $this->put('app/Http/Controllers/Concerns/Helper.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\nfinal class Helper\n{\n    public static function x(): int\n    {\n        return 1;\n    }\n}\n");
        $this->put('app/Http/Controllers/Concerns/Sub/Thing.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns\\Sub;\n\nfinal class Thing {}\n");
        $this->put('app/Http/Controllers/Concerns/Scoped.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\nuse Illuminate\\Support\\Str;\n\ntrait Scoped\n{\n    public function scoped(): int\n    {\n        \$this->helper();\n\n        return Helper::x() + strlen(Str::lower(Sub\\Thing::class)) + (namespace\\Helper::class === '' ? 0 : 1) + (self::class === Scoped::class ? 0 : 1) + (\\".self::OLD."::class === '' ? 0 : 1);\n    }\n\n    public function helper(): void {}\n}\n");

        $this->move('app/Http/Controllers/Concerns/Scoped.php');

        $moved = $this->read('app/Http/Requests/Concerns/Scoped.php');
        $this->assertStringContainsString("namespace App\\Http\\Requests\\Concerns;\n\nuse Illuminate\\Support\\Str;\nuse App\\Http\\Controllers\\Concerns\\Helper;\n\ntrait Scoped", $moved);
        $this->assertStringContainsString('Helper::x()', $moved);
        $this->assertStringContainsString('\\App\\Http\\Controllers\\Concerns\\Sub\\Thing::class', $moved);
        $this->assertStringContainsString('\\App\\Http\\Controllers\\Concerns\\Helper::class', $moved);
        $this->assertStringContainsString('\\'.self::NEW.'::class', $moved);
        // Ni el método ni la llamada se confunden con la clase.
        $this->assertStringContainsString('$this->helper();', $moved);
        $this->assertStringContainsString('public function helper(): void', $moved);
        $this->assertStringNotContainsString('use App\\Http\\Controllers\\Concerns\\Scoped;', $moved);
    }

    public function test_no_mueve_el_trait_que_llama_a_una_funcion_de_su_namespace_viejo(): void
    {
        $this->put('app/Http/Controllers/Concerns/helpers.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\nfunction tenant(): int\n{\n    return 1;\n}\n");
        $this->put('app/Http/Controllers/Concerns/Scoped.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\ntrait Scoped\n{\n    public function scoped(): int\n    {\n        return tenant();\n    }\n}\n");

        $plan = (new ConcernMove($this->root))->plan('app/Http/Controllers/Concerns/Scoped.php');

        $this->assertIsString($plan);
        $this->assertStringContainsString('tenant()', $plan);
        $this->assertFileDoesNotExist($this->root.'/app/Http/Requests/Concerns/Scoped.php');
    }

    public function test_dos_hermanos_movidos_en_la_misma_corrida_siguen_viendose(): void
    {
        $this->put('app/Http/Controllers/Concerns/Tagged.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\ntrait Tagged\n{\n    public function tag(): int\n    {\n        return 2;\n    }\n}\n");
        $this->scoped("    use Tagged;\n");

        // La lista de archivos se arma con el primero y el segundo tiene que
        // ver lo que el primero escribió.
        $this->move('app/Http/Controllers/Concerns/Scoped.php');
        $this->assertStringContainsString('use App\\Http\\Controllers\\Concerns\\Tagged;', $this->read('app/Http/Requests/Concerns/Scoped.php'));

        $this->move('app/Http/Controllers/Concerns/Tagged.php');
        $moved = $this->read('app/Http/Requests/Concerns/Scoped.php');
        $this->assertStringContainsString('use App\\Http\\Requests\\Concerns\\Tagged;', $moved);
        $this->assertStringNotContainsString('Controllers', $moved);
    }

    // (b) Lo que rewrite() no reescribía.

    public function test_reescribe_a_traves_del_alias_de_un_namespace(): void
    {
        $code = "<?php\nnamespace App\\Http\\Requests;\nuse App\\Http\\Controllers\\Concerns as Cs;\nclass R { use Cs\\Scoped; public function x() { return Cs\\Scoped::class; } }\n";

        $this->assertSame(
            "<?php\nnamespace App\\Http\\Requests;\nuse App\\Http\\Controllers\\Concerns as Cs;\nclass R { use \\".self::NEW.'; public function x() { return \\'.self::NEW.'::class; } }'."\n",
            $this->rewrite($code)
        );
    }

    public function test_reescribe_a_traves_de_una_importacion_parcial(): void
    {
        $code = "<?php\nnamespace App\\Http\\Requests;\nuse App\\Http\\Controllers;\nclass R { use Controllers\\Concerns\\Scoped; }\n";

        $this->assertSame("<?php\nnamespace App\\Http\\Requests;\nuse App\\Http\\Controllers;\nclass R { use \\".self::NEW."; }\n", $this->rewrite($code));
    }

    public function test_el_use_dentro_de_una_clase_es_relativo_a_su_namespace(): void
    {
        $relative = "<?php\nnamespace App\\Http\\Controllers;\nclass R { use Concerns\\Scoped; }\n";
        $this->assertSame("<?php\nnamespace App\\Http\\Controllers;\nclass R { use \\".self::NEW."; }\n", $this->rewrite($relative));

        // Escrito como absoluto dentro de una clase no es el trait: resuelve
        // a App\Http\App\Http\…, y no se toca.
        $absolute = "<?php\nnamespace App\\Http;\nclass R { use ".self::OLD."; }\n";
        $this->assertSame($absolute, $this->rewrite($absolute));

        // Un closure dentro de una función de primer nivel no es una importación.
        $closure = "<?php\nnamespace App;\nuse ".self::OLD.";\n\$f = function () use (\$x) { return Scoped::class; };\n";
        $this->assertStringContainsString('use '.self::NEW.';', (string) $this->rewrite($closure));
    }

    public function test_reescribe_la_segunda_importacion_de_una_lista(): void
    {
        $code = "<?php\nnamespace App\\Http\\Requests;\nuse Foo\\Bar, ".self::OLD.";\nclass R { use Scoped; }\n";

        $this->assertSame("<?php\nnamespace App\\Http\\Requests;\nuse Foo\\Bar, ".self::NEW.";\nclass R { use Scoped; }\n", $this->rewrite($code));
    }

    public function test_un_use_agrupado_por_un_prefijo_mas_corto_se_salta(): void
    {
        $this->assertSame(['lo importa en un `use` agrupado: reescríbelo a mano.'], $this->rewrite("<?php\nuse App\\Http\\Controllers\\{Concerns\\Scoped, Kernel};\n"));
        // Agrupado sin el trait: no lo nombra.
        $other = "<?php\nuse App\\Http\\Controllers\\{Kernel, Base};\n";
        $this->assertSame($other, $this->rewrite($other));
    }

    // La red de seguridad: después de escribir, nada resuelve al nombre viejo.

    public function test_revierte_todo_si_despues_de_escribir_algo_sigue_nombrando_el_trait_viejo(): void
    {
        $this->scoped('');
        $this->put('app/Http/Requests/PostRequest.php', "<?php\n\nnamespace App\\Http\\Requests;\n\nuse ".self::OLD.";\n\nfinal class PostRequest\n{\n    use Scoped;\n}\n");

        $move = new ConcernMove($this->root);
        $plan = $move->plan('app/Http/Controllers/Concerns/Scoped.php');
        $this->assertIsArray($plan);

        // Un archivo que el plan no vio (se creó después de planear).
        $this->put('app/Http/Requests/LateRequest.php', "<?php\n\nnamespace App\\Http\\Requests;\n\nfinal class LateRequest\n{\n    use \\".self::OLD.";\n}\n");

        $problem = $move->commit($plan);

        $this->assertIsString($problem);
        $this->assertStringContainsString('app/Http/Requests/LateRequest.php', $problem);
        $this->assertFileExists($this->root.'/app/Http/Controllers/Concerns/Scoped.php');
        $this->assertFileDoesNotExist($this->root.'/app/Http/Requests/Concerns/Scoped.php');
        $this->assertStringContainsString('use '.self::OLD.';', $this->read('app/Http/Requests/PostRequest.php'));
    }

    /**
     * @return string|array{0: string}
     */
    private function rewrite(string $code): string|array
    {
        return ConcernMove::rewrite($code, self::OLD, self::NEW, self::OLD_NS, 'Scoped');
    }

    private function scoped(string $body): void
    {
        $this->put('app/Http/Controllers/Concerns/Scoped.php', "<?php\n\nnamespace App\\Http\\Controllers\\Concerns;\n\ntrait Scoped\n{\n{$body}    public function scoped(): int\n    {\n        return 1;\n    }\n}\n");
    }

    private function move(string $file): void
    {
        $move = new ConcernMove($this->root);
        $plan = $move->plan($file);

        $this->assertIsArray($plan, is_string($plan) ? $plan : '');
        $this->assertNull($move->commit($plan));
    }

    private function put(string $file, string $content): void
    {
        if (! is_dir(dirname($this->root.'/'.$file))) {
            mkdir(dirname($this->root.'/'.$file), 0777, true);
        }

        file_put_contents($this->root.'/'.$file, $content);
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->root.'/'.$file);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path.'/'.$entry);
                }
            }

            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}
