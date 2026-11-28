<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\ControllerShape;
use Innoboxrr\LarapackGenerator\Support\Files;

/**
 * Qué cubre exactamente una excepción del realineamiento, y la huella de lo
 * que cubre.
 *
 * Una excepción #[HandRolled] la declara un atributo en el código, y su
 * candado guarda la huella del método o la clase que lo lleva. Lo que no
 * puede llevar atributo —un closure de una ruta, un controlador escrito a
 * mano que se deja fuera del contrato, un archivo generado que se borró a
 * propósito, un hallazgo de verify que se acepta— se nombra con un
 * **objetivo** que dice su tipo de paso, su comprobación y su archivo, y se
 * aprueba en el mismo candado (`.larapack/hand-rolled.lock`), con la misma
 * huella: si el código cambia, la excepción deja de cubrirlo y vuelve a
 * pedir aprobación.
 *
 * La forma de un objetivo (todos empiezan por `realign:`):
 *
 *     realign:<tipo>[/<comprobación>]:<archivo>[#<sujeto>]
 *
 *  - `<tipo>` es el tipo de paso (StepTypes); `/<comprobación>` va cuando
 *    el hallazgo es de verify y la comprobación no se llama como el tipo
 *    (`realign:fix/index-managed:src/…`).
 *  - `<archivo>`, relativo a la raíz del proyecto. Un hallazgo sin archivo
 *    (el de una entidad) usa `model:<Modelo>`.
 *  - `#<sujeto>`: lo que hay dentro del archivo. El closure de una ruta se
 *    nombra por su llamada y su URI (`#get('/csp-report')`, `#fallback()`);
 *    un hallazgo sin archivo, por el principio de la huella de su mensaje.
 *
 * Las huellas (todas `sha256:<64 hex>`, como las del candado):
 *
 *  - de un archivo, sus tokens sin espacios ni comentarios (un PHP) o su
 *    contenido con los saltos de línea normalizados (lo demás): reformatear
 *    o comentar no la cambia; cambiar una sentencia, sí;
 *  - de un closure, su llamada, su URI, sus parámetros y su cuerpo;
 *  - de lo que se aprueba que **no** esté (un archivo generado que se borró,
 *    el contrato de un ámbito que se deja fuera de LaraPack), una huella fija
 *    de su ausencia: si vuelve a aparecer, deja de cubrirlo;
 *  - de un hallazgo sin archivo, la de su mensaje: cubre esa frase de
 *    verify y ninguna otra.
 *
 * @phpstan-type Target array{type: string, check: string|null, file: string, subject: string|null}
 * @phpstan-type RouteClosure array{call: string, uri: string|null, line: int, label: string, target: string, fingerprint: string}
 */
final class ExceptionTargets
{
    public const PREFIX = 'realign:';

    /**
     * Los tipos de paso cuya excepción aprueba que algo **no** esté.
     */
    public const ABSENT = ['missing', 'no-contract'];

    /**
     * La ruta como la escribe un objetivo: sin `./` delante ni barras
     * invertidas.
     */
    public static function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        while (str_starts_with($file, './')) {
            $file = substr($file, 2);
        }

        return $file;
    }

    public static function isRealign(string $target): bool
    {
        return str_starts_with($target, self::PREFIX);
    }

    /**
     * El objetivo de un elemento con archivo.
     */
    public static function forFile(string $type, ?string $check, string $file, ?string $subject = null): string
    {
        return self::PREFIX.$type.($check === null || $check === $type ? '' : '/'.$check).':'.self::relative($file).($subject === null ? '' : '#'.$subject);
    }

    /**
     * El objetivo de un hallazgo de verify sin archivo (el de una entidad):
     * su modelo y el principio de la huella de su mensaje.
     */
    public static function forStatement(string $type, ?string $check, string $model, string $message): string
    {
        return self::forFile($type, $check, 'model:'.$model, substr(self::statementFingerprint($message), 7, 12));
    }

    /**
     * El objetivo del closure de una ruta.
     */
    public static function forClosure(string $file, string $label): string
    {
        return self::forFile('route-closure', null, $file, $label);
    }

    /**
     * El contrato de un ámbito, que es lo que no está cuando se deja fuera de
     * LaraPack (`no-contract`).
     */
    public static function contractOf(string $scope): string
    {
        return ($scope === '.' || $scope === '' ? '' : rtrim($scope, '/').'/').'laraimport.json';
    }

    /**
     * Las partes de un objetivo, o null si no es del realineamiento.
     *
     * @return Target|null
     */
    public static function parse(string $target): ?array
    {
        if (preg_match('#^realign:([a-z0-9-]+)(?:/([a-z0-9-]+))?:([^\#]+)(?:\#(.+))?$#', $target, $match) !== 1) {
            return null;
        }

        return [
            'type' => $match[1],
            'check' => $match[2] === '' ? null : $match[2],
            'file' => $match[3],
            'subject' => ($match[4] ?? '') === '' ? null : $match[4],
        ];
    }

    /**
     * La huella actual de lo que cubre un objetivo, o null si ya no existe
     * (el closure ya no está en su archivo) o no se puede calcular sin el
     * hallazgo (los de una entidad: su huella es la de su mensaje).
     */
    public static function fingerprint(string $root, string $target): ?string
    {
        $parts = self::parse($target);

        if ($parts === null || str_starts_with($parts['file'], 'model:')) {
            return null;
        }

        $path = rtrim($root, '/').'/'.$parts['file'];

        if (in_array($parts['type'], self::ABSENT, true)) {
            return is_file($path) ? self::fileFingerprint($path) : self::absentFingerprint($parts['file']);
        }

        if ($parts['type'] === 'route-closure' && $parts['subject'] !== null) {
            foreach (is_file($path) ? self::routeClosures($parts['file'], Files::read($path)) : [] as $closure) {
                if ($closure['label'] === $parts['subject']) {
                    return $closure['fingerprint'];
                }
            }

            return null;
        }

        return is_file($path) ? self::fileFingerprint($path) : null;
    }

    public static function fileFingerprint(string $path): string
    {
        $content = Files::read($path);

        if (! str_ends_with(strtolower($path), '.php')) {
            return self::hash(['file', str_replace("\r\n", "\n", $content)]);
        }

        $texts = [];

        foreach (token_get_all($content) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $texts[] = is_array($token) ? $token[1] : $token;
        }

        return self::hash(['php', $texts]);
    }

    public static function absentFingerprint(string $file): string
    {
        return self::hash(['absent', self::relative($file)]);
    }

    public static function statementFingerprint(string $message): string
    {
        return self::hash(['statement', $message]);
    }

    /**
     * Los closures de este archivo de rutas que `route-closure` marca, con el
     * nombre que les da un objetivo (`get('/csp-report')`) y su huella. Dos
     * con la misma llamada y la misma URI se distinguen por orden (`~2`).
     *
     * @return list<RouteClosure>
     */
    public static function routeClosures(string $file, string $code): array
    {
        $uris = self::routeUris($code);
        $seen = [];
        $result = [];

        foreach (ControllerShape::flaggedClosures($code) as $closure) {
            $call = strtolower($closure['call']);
            $uri = $uris[$call.'@'.$closure['line']] ?? null;
            $label = $call.'('.($uri === null ? '' : "'".$uri."'").')';
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $label .= $seen[$label] > 1 ? '~'.$seen[$label] : '';

            $result[] = [
                'call' => $call,
                'uri' => $uri,
                'line' => $closure['line'],
                'label' => $label,
                'target' => self::forClosure($file, $label),
                'fingerprint' => self::hash(['closure', $call, $uri, $closure['arrow'],
                    array_column($closure['params'], 1), array_column($closure['body'], 1)]),
            ];
        }

        return $result;
    }

    /**
     * La URI de cada ruta que recibe un closure, por `<llamada>@<línea del
     * closure>`: el primer argumento que sea una cadena literal
     * (`Route::get('/a', …)`, `Route::match(['get'], '/a', …)`). Una ruta sin
     * URI literal (`fallback()`, una URI en una variable) no la tiene.
     *
     * @return array<string, string>
     */
    private static function routeUris(string $code): array
    {
        $tokens = [];

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = is_array($token) ? $token : [$token, $token, 0];
        }

        $uris = [];
        $count = count($tokens);

        for ($index = 1; $index < $count - 1; $index++) {
            if (! in_array($tokens[$index - 1][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                || $tokens[$index + 1][0] !== '(') {
                continue;
            }

            $uri = null;
            $line = null;
            $depth = 0;
            $argumentStart = true;

            for ($position = $index + 1; $position < $count; $position++) {
                $token = $tokens[$position];

                if (in_array($token[0], ['(', '[', '{'], true) || $token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                    $argumentStart = $depth === 1;

                    continue;
                }

                if (in_array($token[0], [')', ']', '}'], true)) {
                    if (--$depth === 0) {
                        break;
                    }

                    continue;
                }

                if ($depth !== 1) {
                    continue;
                }

                if ($token[0] === ',') {
                    $argumentStart = true;

                    continue;
                }

                if ($argumentStart && $token[0] === T_CONSTANT_ENCAPSED_STRING && $uri === null) {
                    $uri = stripcslashes(substr($token[1], 1, -1));
                } elseif ($argumentStart && in_array($token[0], [T_FUNCTION, T_FN], true) && $line === null) {
                    $line = $token[2];
                }

                // `static function`, `action: function`: lo que va delante del
                // closure no termina el principio del argumento.
                $argumentStart = $argumentStart && in_array($token[0], [T_STATIC, T_STRING, ':'], true);
            }

            if ($uri !== null && $line !== null) {
                $uris[strtolower($tokens[$index][1]).'@'.$line] = $uri;
            }
        }

        return $uris;
    }

    /**
     * sha256 de una serialización sin ambigüedad, como la del candado de
     * #[HandRolled]: cada cadena con su longitud delante.
     */
    private static function hash(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::pack($value));
    }

    private static function pack(mixed $value): string
    {
        if (is_array($value)) {
            return '['.count($value).':'.implode('', array_map(self::pack(...), $value)).']';
        }

        if (is_bool($value)) {
            return $value ? 'T' : 'F';
        }

        if ($value === null) {
            return 'N';
        }

        $text = is_scalar($value) ? (string) $value : get_debug_type($value);

        return 's'.strlen($text).':'.$text;
    }
}
