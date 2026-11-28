<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Attributes\HandRolled;
use RuntimeException;

/**
 * Las excepciones #[HandRolled] que aprobó el dueño del proyecto.
 *
 * En 9.0 un #[HandRolled] con razón excusaba la forma y `verify` lo listaba
 * como aviso. Eso deja la decisión en manos de quien escribe el atributo, y
 * un agente que quiere ver `verify` en verde escribe una línea. El candado
 * separa las dos cosas: el código declara la excepción y el candado dice que
 * alguien la aprobó. Como es un archivo del repositorio, la aprobación pasa
 * por el mismo control que cualquier cambio —CODEOWNERS pide la revisión del
 * dueño (`larapack:ci --owner`)— y queda en el historial.
 *
 * Se guarda la razón y la huella del código, no sólo el objetivo. Aprobar un
 * nombre sería un cheque en blanco: una clase aprobada una vez cubriría cada
 * acción que se le añadiera después, y un método aprobado, cualquier cambio de
 * su cuerpo, sin que el candado —y con él la revisión del dueño— se moviera.
 * Si cambia la razón o el código, la excepción ya no es la que se aprobó, y
 * vuelve a pedir aprobación.
 *
 * La huella es un sha256 de los tokens, sin espacios ni comentarios:
 * reformatear o comentar no la cambia; cambiar una sola sentencia, sí. De un
 * método (methodFingerprint), su firma completa y su cuerpo; de una clase
 * (classFingerprint), todos sus métodos —de cualquier visibilidad: un privado
 * nuevo es código que las acciones pueden llamar—, su padre y sus traits. Lo
 * que vive fuera de la clase (otra clase, el cuerpo de un trait, el padre) no
 * entra: la huella dice qué código se aprobó, no todo lo que ese código
 * alcanza.
 *
 * El formato es una lista de {target, reason, fingerprint} ordenada por
 * `target`, con sangría fija y salto de línea final: dos aprobaciones de lo
 * mismo producen el mismo archivo byte a byte, y el diff de una revisión sólo
 * enseña lo que cambió.
 *
 * Desde 9.4.0 una entrada puede llevar `approved_by`: el nombre de la persona
 * que la aprobó sin terminal (`LARAPACK_APPROVER` y `--approver`,
 * OwnerConfirmation), para que quien revisa el diff lo vea. Lo aprobado en
 * la terminal no lo lleva: quien aprueba es quien comitea. Una versión
 * anterior lo ignora al leer.
 *
 * @phpstan-import-type MethodOutline from PhpOutline
 * @phpstan-import-type ClassOutline from PhpOutline
 * @phpstan-import-type AttributeOutline from PhpOutline
 * @phpstan-import-type Token from PhpOutline
 *
 * @phpstan-type Approval array{reason: string, fingerprint: string|null, approved_by?: string|null}
 */
final class HandRolledLock
{
    public const PATH = '.larapack/hand-rolled.lock';

    /**
     * La forma de una huella. El prefijo deja cambiar el algoritmo sin que una
     * huella vieja se confunda con una nueva.
     */
    public const FINGERPRINT = '/^sha256:[0-9a-f]{64}$/';

    public static function path(string $root): string
    {
        return rtrim(str_replace('\\', '/', $root), '/').'/'.self::PATH;
    }

    public static function exists(string $root): bool
    {
        return is_file(self::path($root));
    }

    /**
     * Las aprobaciones por objetivo, o null si no hay candado.
     *
     * Un candado que no se puede leer no se trata como ausente: eso volvería
     * al comportamiento de 9.0 sin que nadie lo decidiera. Se lanza, y quien
     * llama decide cómo fallar. Una entrada sin `fingerprint` se lee —con la
     * huella a null— pero no aprueba nada: no dice qué código se aprobó.
     *
     * @return array<string, Approval>|null
     */
    public static function read(string $root): ?array
    {
        $file = self::path($root);

        if (! is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $decoded = $raw === false ? null : json_decode($raw, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException(self::PATH.' no es una lista JSON de {"target", "reason", "fingerprint"}.');
        }

        $approved = [];

        foreach ($decoded as $index => $entry) {
            $target = is_array($entry) ? ($entry['target'] ?? null) : null;
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;
            $fingerprint = is_array($entry) ? ($entry['fingerprint'] ?? null) : null;

            if (! is_string($target) || trim($target) === '' || ! is_string($reason) || trim($reason) === '') {
                throw new RuntimeException(self::PATH." tiene una entrada sin `target` o sin `reason` (posición {$index}).");
            }

            if ($fingerprint !== null && (! is_string($fingerprint) || preg_match(self::FINGERPRINT, $fingerprint) !== 1)) {
                throw new RuntimeException(self::PATH." tiene una `fingerprint` que no es sha256:<64 hex> (posición {$index}).");
            }

            if (isset($approved[$target])) {
                throw new RuntimeException(self::PATH." aprueba dos veces {$target}.");
            }

            $approver = $entry['approved_by'] ?? null;

            if ($approver !== null && (! is_string($approver) || trim($approver) === '')) {
                throw new RuntimeException(self::PATH." tiene un `approved_by` que no es un nombre (posición {$index}).");
            }

            $approved[$target] = ['reason' => $reason, 'fingerprint' => $fingerprint] + ($approver === null ? [] : ['approved_by' => $approver]);
        }

        return $approved;
    }

    /**
     * @param  array<string, Approval>  $approved
     */
    public static function write(string $root, array $approved): void
    {
        $file = self::path($root);
        $directory = dirname($file);

        // Otro proceso puede crearlo entre la pregunta y el mkdir(): lo que
        // importa es que exista después, lo haya creado quien lo haya creado.
        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        if (! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}.");
        }

        if (file_put_contents($file, self::encode($approved)) === false) {
            throw new RuntimeException("No se pudo escribir {$file}.");
        }
    }

    /**
     * @param  array<string, Approval>  $approved
     */
    public static function encode(array $approved): string
    {
        // Orden binario y no el de la configuración regional: el mismo en
        // cualquier máquina.
        ksort($approved, SORT_STRING);

        $entries = [];

        foreach ($approved as $target => $approval) {
            $entries[] = ['target' => (string) $target, 'reason' => $approval['reason'], 'fingerprint' => $approval['fingerprint']]
                + (isset($approval['approved_by']) ? ['approved_by' => $approval['approved_by']] : []);
        }

        return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * La huella de un método: su nombre, sus modificadores, sus atributos
     * (menos #[HandRolled], cuya razón se compara aparte), su firma completa
     * y su cuerpo.
     *
     * @param  MethodOutline  $method
     */
    public static function methodFingerprint(array $method): string
    {
        return self::hash(self::method($method));
    }

    /**
     * La huella de una clase (o un trait) aprobada entera: su tipo, su padre,
     * sus traits, sus atributos y cada uno de sus métodos, en orden de nombre.
     * Añadir una acción a una clase aprobada la cambia.
     *
     * @param  ClassOutline  $class
     */
    public static function classFingerprint(array $class): string
    {
        $methods = array_map(self::method(...), $class['methods']);
        usort($methods, fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return self::hash([
            'class',
            $class['kind'],
            $class['abstract'],
            strtolower((string) $class['extends']),
            array_map('strtolower', $class['traits']),
            self::attributes($class['attributes']),
            $methods,
        ]);
    }

    /**
     * @param  MethodOutline  $method
     * @return array{0: string, 1: list<mixed>}
     */
    private static function method(array $method): array
    {
        // PHP no distingue mayúsculas en el nombre de un método: `Store` y
        // `store` son la misma acción.
        //
        // El `&` de un método que devuelve por referencia va delante de la
        // firma, como en el código: `function &items()` y `function items()`
        // no son lo mismo, y aprobar uno no aprueba el otro. Sólo cuando está,
        // para que la huella de los demás métodos no cambie.
        $signature = self::texts($method['signature']);

        return [strtolower($method['name']), [
            $method['visibility'],
            $method['static'],
            self::attributes($method['attributes']),
            $method['byRef'] ? ['&', ...$signature] : $signature,
            $method['body'] === null ? null : self::texts($method['body']),
        ]];
    }

    /**
     * @param  list<AttributeOutline>  $attributes
     * @return list<array{0: string, 1: list<string>|null}>
     */
    private static function attributes(array $attributes): array
    {
        $kept = [];

        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute['name'], HandRolled::class) !== 0) {
                $kept[] = [strtolower($attribute['name']), $attribute['args'] === null ? null : self::texts($attribute['args'])];
            }
        }

        return $kept;
    }

    /**
     * El texto de cada token. PhpOutline ya quitó los espacios y los
     * comentarios.
     *
     * @param  list<Token>  $tokens
     * @return list<string>
     */
    private static function texts(array $tokens): array
    {
        return array_map(fn (array $token): string => $token[1], $tokens);
    }

    /**
     * sha256 de una serialización sin ambigüedad: cada cadena con su longitud
     * delante, para que `a` `bc` y `ab` `c` no den lo mismo, y sin pasar por
     * JSON, que falla con bytes que no son UTF-8.
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

        // Lo que llega es una cadena, un booleano, null o una lista: el tipo de
        // otra cosa se apuntaría como texto, nunca su valor.
        $text = is_string($value) ? $value : get_debug_type($value);

        return 's'.strlen($text).':'.$text;
    }
}
