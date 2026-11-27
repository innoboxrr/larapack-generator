<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\PhpOutline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El candado como formato: la ruta, lo que read() rechaza, cómo se escribe y
 * la huella.
 *
 * La huella se guarda en el `.larapack/hand-rolled.lock` de cada proyecto y
 * la revisa su dueño. Si la serialización cambia, todas las aprobaciones de
 * todos los proyectos dejan de coincidir a la vez y vuelven a pedir revisión
 * sin que el código haya cambiado. Por eso aquí se fija su valor exacto: un
 * cambio de formato tiene que ser una decisión (con su nota en el CHANGELOG),
 * no algo que se cuele en un refactor.
 */
final class HandRolledLockFormatTest extends TestCase
{
    private const CONTROLLER = <<<'PHP'
    <?php

    namespace App\Http\Controllers;

    use App\Concerns\Signs;
    use App\Concerns\Throttles;

    #[\App\Attributes\Audited('webhook')]
    final class WebhookController extends Controller
    {
        use Signs, Throttles;

        #[\Innoboxrr\LarapackGenerator\Attributes\HandRolled('raw body')]
        public function __invoke(Request $request, int $limit = 10): Response
        {
            return response()->noContent();
        }

        protected static function verify(): bool { return true; }

        abstract public function nothing(): void;
    }
    PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-lock-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove("{$path}/{$entry}");
                }
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function outline(string $code = self::CONTROLLER): array
    {
        return PhpOutline::ofCode($code)->classes()[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function method(string $name, string $code = self::CONTROLLER): array
    {
        foreach ($this->outline($code)['methods'] as $method) {
            if ($method['name'] === $name) {
                return $method;
            }
        }

        $this->fail("No hay método {$name}.");
    }

    private function lock(string $json): void
    {
        mkdir($this->root.'/.larapack');
        file_put_contents($this->root.'/.larapack/hand-rolled.lock', $json);
    }

    private function assertUnreadable(string $json, string $message): void
    {
        $this->lock($json);

        try {
            HandRolledLock::read($this->root);
            $this->fail('read() tenía que rechazar '.$json);
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    // RUTA

    public function test_la_ruta_se_normaliza_con_barras_de_unix(): void
    {
        $this->assertSame('C:/proyecto/.larapack/hand-rolled.lock', HandRolledLock::path('C:\\proyecto\\'));
        $this->assertSame('/srv/app/.larapack/hand-rolled.lock', HandRolledLock::path('/srv/app/'));
        $this->assertSame('/srv/app/.larapack/hand-rolled.lock', HandRolledLock::path('/srv/app'));
    }

    // LECTURA

    public function test_lo_que_no_es_una_lista_no_se_lee(): void
    {
        $message = '.larapack/hand-rolled.lock no es una lista JSON de {"target", "reason", "fingerprint"}.';

        $this->assertUnreadable('{"target": "A", "reason": "r"}', $message);
    }

    public function test_un_json_roto_no_se_lee(): void
    {
        $this->assertUnreadable('[{"target": ', '.larapack/hand-rolled.lock no es una lista JSON de {"target", "reason", "fingerprint"}.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entradasIncompletas(): array
    {
        return [
            'sin target' => ['[{"reason": "r"}]'],
            'target en blanco' => ['[{"target": "  ", "reason": "r"}]'],
            'target que no es texto' => ['[{"target": 5, "reason": "r"}]'],
            'sin reason' => ['[{"target": "A"}]'],
            'reason en blanco' => ['[{"target": "A", "reason": " \t "}]'],
            'reason que no es texto' => ['[{"target": "A", "reason": 1}]'],
            'entrada que no es un objeto' => ['["A"]'],
        ];
    }

    #[DataProvider('entradasIncompletas')]
    public function test_una_entrada_sin_target_o_sin_reason_no_se_lee(string $json): void
    {
        $this->assertUnreadable($json, '.larapack/hand-rolled.lock tiene una entrada sin `target` o sin `reason` (posición 0).');
    }

    public function test_una_huella_mal_formada_dice_su_posicion(): void
    {
        $good = 'sha256:'.str_repeat('a', 64);

        $this->assertUnreadable(
            '[{"target": "A", "reason": "r", "fingerprint": "'.$good.'"}, {"target": "B", "reason": "r", "fingerprint": "sha1:abc"}]',
            '.larapack/hand-rolled.lock tiene una `fingerprint` que no es sha256:<64 hex> (posición 1).'
        );
    }

    public function test_sin_candado_no_hay_aprobaciones(): void
    {
        $this->assertNull(HandRolledLock::read($this->root));
        $this->assertFalse(HandRolledLock::exists($this->root));
    }

    // ESCRITURA

    public function test_escribir_crea_el_directorio_y_lo_relee_igual(): void
    {
        $approved = [
            'App\Zeta' => ['reason' => 'z', 'fingerprint' => null],
            '123' => ['reason' => 'un objetivo que parece un número', 'fingerprint' => 'sha256:'.str_repeat('0', 64)],
            'App\Alfa' => ['reason' => 'á', 'fingerprint' => null],
        ];

        HandRolledLock::write($this->root, $approved);

        $this->assertTrue(HandRolledLock::exists($this->root));
        $this->assertSame(0777 & ~umask(), fileperms($this->root.'/.larapack') & 0777);

        // Ordenado por objetivo, en orden binario, y con el objetivo siempre
        // como texto: un `123` que PHP convirtió en clave entera no puede
        // salir como número, o el candado deja de leerse.
        $this->assertSame(<<<'JSON'
        [
            {
                "target": "123",
                "reason": "un objetivo que parece un número",
                "fingerprint": "sha256:0000000000000000000000000000000000000000000000000000000000000000"
            },
            {
                "target": "App\\Alfa",
                "reason": "á",
                "fingerprint": null
            },
            {
                "target": "App\\Zeta",
                "reason": "z",
                "fingerprint": null
            }
        ]

        JSON, file_get_contents(HandRolledLock::path($this->root)));

        $read = HandRolledLock::read($this->root);
        $this->assertSame(['123', 'App\Alfa', 'App\Zeta'], array_map('strval', array_keys($read ?? [])));
    }

    public function test_si_no_puede_crear_el_directorio_lo_dice(): void
    {
        // Un archivo donde iría el directorio: mkdir() no puede.
        file_put_contents($this->root.'/.larapack', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo crear el directorio '.$this->root.'/.larapack.');

        @HandRolledLock::write($this->root, ['A' => ['reason' => 'r', 'fingerprint' => null]]);
    }

    // HUELLA

    public function test_la_serializacion_es_la_documentada(): void
    {
        // Cada lista con su número de elementos delante, cada cadena con su
        // longitud, T/F para los booleanos y N para null: `a` `bc` y `ab` `c`
        // no se confunden.
        $method = [
            'name' => 'Run', 'line' => 1, 'visibility' => 'public', 'static' => true, 'byRef' => false, 'params' => [],
            'attributes' => [['name' => 'App\A', 'line' => 1, 'args' => [[T_LNUMBER, '7', 1]]]],
            'signature' => [['(', '(', 1], [')', ')', 1]],
            'body' => null,
        ];

        $this->assertSame(
            'sha256:'.hash('sha256', '[2:s3:run[5:s6:publicT[1:[2:s5:app\a[1:s1:7]]][2:s1:(s1:)]N]]'),
            HandRolledLock::methodFingerprint($method)
        );

        $method['static'] = false;
        $method['body'] = [];

        $this->assertSame(
            'sha256:'.hash('sha256', '[2:s3:run[5:s6:publicF[1:[2:s5:app\a[1:s1:7]]][2:s1:(s1:)][0:]]]'),
            HandRolledLock::methodFingerprint($method)
        );

        // Por referencia, el `&` delante de la firma, como en el código.
        $method['byRef'] = true;

        $this->assertSame(
            'sha256:'.hash('sha256', '[2:s3:run[5:s6:publicF[1:[2:s5:app\a[1:s1:7]]][3:s1:&s1:(s1:)][0:]]]'),
            HandRolledLock::methodFingerprint($method)
        );
    }

    public function test_la_huella_es_un_formato_fijo(): void
    {
        $this->assertSame('sha256:71731818d58898e1360227aa6283c2488bbaa5bfdc3af209c182511c25036284', HandRolledLock::classFingerprint($this->outline()));
        $this->assertSame('sha256:1ce79eccb2d90256084612523f67a97db0bc43f4820cedd9a1baadc924ff8c54', HandRolledLock::methodFingerprint($this->method('__invoke')));
        $this->assertSame('sha256:333fd8ac800fca4d9de4fa827579eaa9064ffa7186affe7c519c16130b3b95a9', HandRolledLock::methodFingerprint($this->method('verify')));
        $this->assertSame('sha256:ceba8485e1e34796e0819214e92afd7fbf66f5869a308bc7d6e24882ad3dd78c', HandRolledLock::methodFingerprint($this->method('nothing')));
    }

    public function test_la_huella_no_depende_de_mayusculas_ni_del_orden_de_los_metodos(): void
    {
        $reordered = str_replace(
            ['extends Controller', 'use Signs, Throttles;', 'public function __invoke', 'protected static function verify(): bool { return true; }'],
            ['extends CONTROLLER', 'use SIGNS, THROTTLES;', 'public function __INVOKE', ''],
            self::CONTROLLER
        );
        $reordered = str_replace('abstract public function nothing(): void;', "abstract public function nothing(): void;\n\n        protected static function verify(): bool { return true; }", $reordered);

        // Las clases resuelven igual sin importar mayúsculas (`use` en
        // minúsculas en el alias): la huella también.
        $this->assertSame(
            HandRolledLock::classFingerprint($this->outline()),
            HandRolledLock::classFingerprint($this->outline(str_replace(['use App\Concerns\Signs;', 'use App\Concerns\Throttles;'], ['use App\Concerns\SIGNS;', 'use App\Concerns\THROTTLES;'], $reordered)))
        );
    }

    public function test_cada_parte_del_metodo_cambia_la_huella(): void
    {
        $original = HandRolledLock::methodFingerprint($this->method('verify'));

        $changes = [
            'visibilidad' => 'public static function verify(): bool { return true; }',
            'static' => 'protected function verify(): bool { return true; }',
            'firma' => 'protected static function verify(): ?bool { return true; }',
            'por referencia' => 'protected static function &verify(): bool { return true; }',
            'cuerpo' => 'protected static function verify(): bool { return false; }',
            'atributo' => '#[\App\Attributes\Cached] protected static function verify(): bool { return true; }',
            'argumento de atributo' => '#[\\App\\Attributes\\Cached(5)] protected static function verify(): bool { return true; }',
        ];

        $seen = [$original => 'original'];

        foreach ($changes as $what => $code) {
            $fingerprint = HandRolledLock::methodFingerprint(
                $this->method('verify', str_replace('protected static function verify(): bool { return true; }', $code, self::CONTROLLER))
            );

            $this->assertArrayNotHasKey($fingerprint, $seen, "Cambiar {$what} tiene que cambiar la huella.");
            $seen[$fingerprint] = $what;
        }
    }

    public function test_handrolled_no_entra_en_la_huella_y_los_demas_atributos_si(): void
    {
        $method = $this->method('__invoke');
        $original = HandRolledLock::methodFingerprint($method);

        // La razón de #[HandRolled] se compara aparte: cambiarla no es
        // cambiar el código.
        $method['attributes'][0]['args'] = [[T_CONSTANT_ENCAPSED_STRING, "'otra razón'", 1]];
        $this->assertSame($original, HandRolledLock::methodFingerprint($method));

        // Un atributo cualquiera sí, aunque su nombre ordene antes o después
        // que HandRolled, y también el segundo de varios.
        foreach (['App\Attributes\Audited', 'Zeta\Audited'] as $name) {
            $extra = $method;
            $extra['attributes'][] = ['name' => $name, 'line' => 1, 'args' => null];
            $this->assertNotSame($original, HandRolledLock::methodFingerprint($extra), $name);

            $two = $extra;
            $two['attributes'][] = ['name' => 'App\Attributes\Other', 'line' => 1, 'args' => null];
            $this->assertNotSame(HandRolledLock::methodFingerprint($extra), HandRolledLock::methodFingerprint($two), $name);
        }
    }

    public function test_la_huella_de_la_clase_sigue_a_su_padre_sus_traits_y_su_tipo(): void
    {
        $class = $this->outline();
        $original = HandRolledLock::classFingerprint($class);

        foreach ([
            'extends' => 'App\Http\Controllers\Other',
            'traits' => ['App\Concerns\Signs'],
            'kind' => 'trait',
            'abstract' => true,
        ] as $key => $value) {
            $changed = $class;
            $changed[$key] = $value;
            $this->assertNotSame($original, HandRolledLock::classFingerprint($changed), $key);
        }

        // Sin padre no es lo mismo que con uno, y no avisa: bajo Artisan un
        // aviso de deprecación es una excepción.
        $orphan = $class;
        $orphan['extends'] = null;
        set_error_handler(fn (int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $this->assertNotSame($original, HandRolledLock::classFingerprint($orphan));
        } finally {
            restore_error_handler();
        }
    }
}
