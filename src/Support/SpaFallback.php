<?php

namespace Innoboxrr\LarapackGenerator\Support;

use PhpToken;
use RuntimeException;

/**
 * El fallback de la SPA que escribía laravel-setup hasta la 7.2.0 en
 * routes/web.php, y su corrección, que es lo que escribe la 7.2.1.
 *
 * Toda aplicación creada con laravel-setup <= 7.2.0 trae
 *
 *     Route::get('/', fn () => view('app'))->name('app');
 *
 *     Route::fallback(function () {
 *         abort_if(request()->is('api/*') || request()->expectsJson(), 404);
 *
 *         return view('app');
 *     });
 *
 * y `verify` marca el fallback como `route-closure`: con `--strict`, que es la
 * puerta de `larapack:ci`, nunca pasa. laravel-setup 7.2.1 lo resolvió para las
 * aplicaciones nuevas —SpaController delega en SpaRequest, que es quien decide
 * el 404—, pero una aplicación ya creada no cambia sola. `larapack:fix
 * route-closure` lo corrige con este reconocedor, que también usa `verify` para
 * decir que el aviso tiene arreglo automático.
 *
 * Se reconoce ESE closure y nada más: lo único que puede variar es el espacio
 * en blanco, las comillas de las cadenas y el orden de los dos operandos del
 * `||`. Un comentario dentro, un parámetro, otra vista, otra condición o un
 * `->name()` encadenado ya son otra cosa, y lo que no es exactamente esto lo
 * corrige una persona. Se compara token a token, sin expresiones regulares.
 *
 * @phpstan-type Statement array{start: int, end: int, line: int}
 */
final class SpaFallback
{
    public const CONTROLLER = 'App\\Http\\Controllers\\SpaController';

    public const REQUEST = 'App\\Http\\Requests\\SpaRequest';

    /**
     * De la que extiende SpaController: la trae toda aplicación de Laravel.
     */
    public const BASE_CONTROLLER = 'App\\Http\\Controllers\\Controller';

    public const FALLBACK = 'Route::fallback(SpaController::class);';

    public const ROOT = "Route::view('/', 'app')->name('app');";

    /**
     * stubs/app/common/app/Http/Controllers/SpaController.php de laravel-setup
     * 7.2.1, tal cual.
     */
    public const CONTROLLER_CODE = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpaRequest;

/**
 * Atiende cualquier pantalla de la SPA. Como todo controlador del ecosistema,
 * no lleva lógica: la decisión vive en el FormRequest.
 */
class SpaController extends Controller
{
    public function __invoke(SpaRequest $request)
    {
        return $request->handle();
    }
}

PHP;

    /**
     * stubs/app/common/app/Http/Requests/SpaRequest.php de laravel-setup
     * 7.2.1, tal cual.
     */
    public const REQUEST_CODE = <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La vista de la SPA es pública: el router del front decide qué pantalla toca
 * y cada llamada a la API se autoriza por su cuenta.
 */
class SpaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Una petición a la API que no existe, o una que espera JSON, sigue siendo
     * un 404 y no recibe el HTML de la SPA.
     */
    public function handle()
    {
        abort_if($this->is('api/*') || $this->expectsJson(), 404);

        return view('app');
    }
}

PHP;

    private const IS_API = ['request', '(', ')', '->', 'is', '(', "'api/*'", ')'];

    private const EXPECTS_JSON = ['request', '(', ')', '->', 'expectsJson', '(', ')'];

    /**
     * Ninguna instancia: todo es una función del código que se le pasa.
     */
    private function __construct() {}

    /**
     * La sentencia `Route::fallback(function () { ... });` de laravel-setup
     * <= 7.2.0, en el primer nivel del archivo: sus posiciones en bytes y la
     * línea de su `function`, que es la que da `verify`. null si no está o si
     * está más de una vez.
     *
     * @return Statement|null
     */
    public static function fallback(string $code): ?array
    {
        return self::statement($code, [
            self::fallbackTokens(self::IS_API, self::EXPECTS_JSON),
            self::fallbackTokens(self::EXPECTS_JSON, self::IS_API),
        ]);
    }

    /**
     * Los tokens del fallback con los dos operandos del `||` en este orden.
     *
     * @param  list<string>  $first
     * @param  list<string>  $second
     * @return list<string>
     */
    private static function fallbackTokens(array $first, array $second): array
    {
        return [
            'Route', '::', 'fallback', '(', 'function', '(', ')', '{',
            'abort_if', '(', ...$first, '||', ...$second, ',', '404', ')', ';',
            'return', 'view', '(', "'app'", ')', ';',
            '}', ')', ';',
        ];
    }

    /**
     * La raíz `Route::get('/', fn () => view('app'))->name('app');` (o con
     * `function () { return view('app'); }`). No es deuda —`verify` no la
     * marca—, pero laravel-setup 7.2.1 la escribe con Route::view() y se
     * corrige con el fallback para que el archivo quede como el suyo.
     *
     * @return Statement|null
     */
    private static function root(string $code): ?array
    {
        $tail = ['->', 'name', '(', "'app'", ')', ';'];

        return self::statement($code, [
            ['Route', '::', 'get', '(', "'/'", ',', 'fn', '(', ')', '=>', 'view', '(', "'app'", ')', ')', ...$tail],
            ['Route', '::', 'get', '(', "'/'", ',', 'function', '(', ')', '{', 'return', 'view', '(', "'app'", ')', ';', '}', ')', ...$tail],
        ]);
    }

    /**
     * routes/web.php con el fallback llevado a SpaController, la raíz a
     * Route::view() si es la de laravel-setup, y el `use` de SpaController.
     * Lo demás del archivo —otras rutas, comentarios, el orden— queda igual.
     *
     * @throws RuntimeException si no está el fallback o el archivo no admite
     *                          el cambio con certeza; el mensaje dice por qué
     */
    public static function rewrite(string $code): string
    {
        $fallback = self::fallback($code);

        if ($fallback === null) {
            throw new RuntimeException('No está el fallback de laravel-setup 7.2.0 tal cual.');
        }

        $tokens = self::tokens($code);

        foreach ($tokens as $token) {
            if ($token->id === T_NAMESPACE) {
                throw new RuntimeException('routes/web.php declara un espacio de nombres: el `use` de SpaController no se sabe dónde va.');
            }
        }

        $outline = PhpOutline::ofCode($code);
        $route = strtolower($outline->resolveName('Route'));

        if (! in_array($route, ['route', 'illuminate\\support\\facades\\route'], true)) {
            throw new RuntimeException("`Route` es {$outline->resolveName('Route')} en este archivo, no la fachada de Laravel.");
        }

        $edits = [[$fallback['start'], $fallback['end'], self::FALLBACK]];
        $root = self::root($code);

        if ($root !== null) {
            $edits[] = [$root['start'], $root['end'], self::ROOT];
        }

        $imported = $outline->resolveName('SpaController');

        if (strcasecmp($imported, self::CONTROLLER) !== 0) {
            if ($imported !== 'SpaController') {
                throw new RuntimeException("Ya importa otra clase como SpaController ({$imported}).");
            }

            $edits[] = self::import($code, $tokens);
        }

        usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($edits as [$start, $end, $replacement]) {
            $code = substr($code, 0, $start).$replacement.substr($code, $end);
        }

        return $code;
    }

    /**
     * Si un archivo es el de laravel-setup 7.2.1. Sin contar el espacio en
     * blanco: un salto de línea de Windows o la sangría no lo hacen otro.
     */
    public static function same(string $code, string $stub): bool
    {
        $significant = static fn (string $code): array => array_values(array_map(
            fn (PhpToken $token): string => $token->text,
            array_filter(PhpToken::tokenize($code), fn (PhpToken $token): bool => $token->id !== T_WHITESPACE)
        ));

        return $significant(str_replace("\r\n", "\n", $code)) === $significant($stub);
    }

    /**
     * Dónde va el `use` de SpaController: en orden alfabético entre los de
     * clases, como los deja Pint; si no hay ninguno, tras la etiqueta de
     * apertura.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{0: int, 1: int, 2: string}
     */
    private static function import(string $code, array $tokens): array
    {
        $line = 'use '.self::CONTROLLER.";\n";
        $imports = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            $depth += self::depthChange($token);

            if ($token->id !== T_USE || $depth !== 0 || ! self::startsStatement($tokens, $index)) {
                continue;
            }

            $name = self::next($tokens, $index);

            if ($name === null || ! in_array($tokens[$name]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                // `use function` y `use const` van detrás de las clases.
                continue;
            }

            $start = self::lineStart($code, $token->pos);
            $semicolon = $name;

            while (isset($tokens[$semicolon]) && $tokens[$semicolon]->text !== ';') {
                $semicolon++;
            }

            $newline = isset($tokens[$semicolon]) ? strpos($code, "\n", $tokens[$semicolon]->pos) : false;

            if ($newline === false
                || trim(substr($code, $start, $token->pos - $start)) !== ''
                || trim(substr($code, $tokens[$semicolon]->pos + 1, $newline - $tokens[$semicolon]->pos - 1)) !== ''
            ) {
                throw new RuntimeException('Un `use` que comparte su línea con más código.');
            }

            $imports[] = [ltrim($tokens[$name]->text, '\\'), $start, $newline + 1];
        }

        foreach ($imports as [$name, $start]) {
            if (strcasecmp($name, self::CONTROLLER) > 0) {
                return [$start, $start, $line];
            }
        }

        if ($imports !== []) {
            $after = $imports[count($imports) - 1][2];

            return [$after, $after, $line];
        }

        $open = $tokens[0] ?? null;

        if ($open === null || $open->id !== T_OPEN_TAG || ! str_ends_with($open->text, "\n")) {
            throw new RuntimeException('routes/web.php no empieza con `<?php` en su propia línea.');
        }

        $at = strlen($open->text);

        // Un `declare(...)` tiene que ser la primera sentencia del archivo:
        // un `use` delante de él parsea, pero PHP lo rechaza al compilar y
        // la aplicación entera deja de cargar. El `use` va detrás.
        $count = count($tokens);

        for ($index = 1; $index < $count; $index++) {
            $token = $tokens[$index];

            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token->id !== T_DECLARE) {
                break;
            }

            while ($index < $count && $tokens[$index]->text !== ';') {
                $index++;
            }

            $semicolon = $tokens[$index] ?? null;
            $newline = $semicolon === null ? false : strpos($code, "\n", $semicolon->pos);

            if ($semicolon === null || $newline === false || trim(substr($code, $semicolon->pos + 1, $newline - $semicolon->pos - 1)) !== '') {
                throw new RuntimeException('Un `declare` que comparte su línea con más código.');
            }

            $at = $newline + 1;
        }

        return [$at, $at, "\n".$line];
    }

    /**
     * La única sentencia del primer nivel del archivo que es una de
     * $sequences, comparando cada token significativo (los comentarios
     * cuentan: uno dentro de la sentencia la hace otra).
     *
     * La línea es la del closure (su `function` o su `fn`): la que da
     * `verify`.
     *
     * @param  list<list<string>>  $sequences
     * @return Statement|null
     */
    private static function statement(string $code, array $sequences): ?array
    {
        $tokens = array_values(array_filter(self::tokens($code), fn (PhpToken $token): bool => $token->id !== T_WHITESPACE));
        $found = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            $depth += self::depthChange($token);

            if ($depth !== 0 || $token->id !== T_STRING || $token->text !== 'Route' || ! self::startsStatement($tokens, $index)) {
                continue;
            }

            foreach ($sequences as $sequence) {
                $last = self::matches($tokens, $index, $sequence);

                if ($last !== null) {
                    $closure = $index;

                    while (! in_array($tokens[$closure]->id, [T_FUNCTION, T_FN], true)) {
                        $closure++;
                    }

                    $found[] = [
                        'start' => $token->pos,
                        'end' => $tokens[$last]->pos + strlen($tokens[$last]->text),
                        'line' => $tokens[$closure]->line,
                    ];

                    break;
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * El índice del último token si a partir de $index vienen, uno a uno, los
     * de $sequence.
     *
     * @param  list<PhpToken>  $tokens
     * @param  list<string>  $sequence
     */
    private static function matches(array $tokens, int $index, array $sequence): ?int
    {
        foreach ($sequence as $offset => $expected) {
            $token = $tokens[$index + $offset] ?? null;

            if ($token === null || self::normalized($token) !== $expected) {
                return null;
            }
        }

        return $index + count($sequence) - 1;
    }

    /**
     * Una cadena literal sin escapes, con comillas simples: `"app"` y `'app'`
     * son la misma. Lo demás, tal cual.
     */
    private static function normalized(PhpToken $token): string
    {
        if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
            return $token->text;
        }

        $inner = substr($token->text, 1, -1);

        return str_contains($inner, '\\') || str_contains($inner, '$') || str_contains($inner, "'") ? $token->text : "'{$inner}'";
    }

    /**
     * Si el token empieza una sentencia del primer nivel: lo que va antes,
     * saltando comentarios, es la etiqueta de apertura, un `;` o una `}`.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function startsStatement(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (in_array($tokens[$i]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i]->id === T_OPEN_TAG || in_array($tokens[$i]->text, [';', '}'], true);
        }

        return false;
    }

    private static function depthChange(PhpToken $token): int
    {
        return match (true) {
            $token->text === '{', $token->id === T_CURLY_OPEN, $token->id === T_DOLLAR_OPEN_CURLY_BRACES => 1,
            $token->text === '}' => -1,
            default => 0,
        };
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function next(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! in_array($tokens[$i]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return list<PhpToken>
     */
    private static function tokens(string $code): array
    {
        return array_values(PhpToken::tokenize($code));
    }

    private static function lineStart(string $code, int $pos): int
    {
        $newline = strrpos(substr($code, 0, $pos), "\n");

        return $newline === false ? 0 : $newline + 1;
    }
}
