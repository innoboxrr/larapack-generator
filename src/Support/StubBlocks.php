<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;

/**
 * Bloques condicionales dentro de un stub.
 *
 * Un modelo que sólo se lee no debe traer el método update() de su
 * controlador, ni su ruta PUT, ni su formulario de edición. Hasta ahora la
 * única salida era generar las diez acciones y borrar a mano lo que sobraba,
 * lo que dejaba esos archivos marcados como editados para siempre.
 *
 * Los stubs marcan lo que depende de una acción con dos líneas:
 *
 *     // @larapack:if update
 *     public function update(UpdateRequest $request) { ... }
 *     // @larapack:endif
 *
 * La sintaxis del comentario da igual —`//`, `<!-- -->`, `{/* *\/}`—: se mira
 * la línea entera y la línea del marcador desaparece siempre. Eso es lo que
 * garantiza que un modelo con las diez acciones salga byte a byte igual que
 * antes de que existieran los marcadores.
 *
 * `a|b` se cumple si se cumple cualquiera; `a&b`, si se cumplen todas. No se
 * mezclan: una condición que necesite las dos cosas es una señal de que el
 * bloque está en el sitio equivocado.
 */
final class StubBlocks
{
    private const OPEN = '/@larapack:if\s+([A-Za-z|&]+)/';

    private const CLOSE = '/@larapack:endif\b/';

    /**
     * @param  callable(string): bool  $holds  Si una condición simple se cumple.
     */
    public static function apply(string $content, callable $holds): string
    {
        $output = '';
        $stack = [];

        foreach (self::lines($content) as $number => $line) {
            if (preg_match(self::OPEN, $line, $matches)) {
                $parentKept = $stack === [] || end($stack);
                $stack[] = $parentKept && self::evaluate($matches[1], $holds, $number);

                continue;
            }

            if (preg_match(self::CLOSE, $line)) {
                if ($stack === []) {
                    throw new MakerException('@larapack:endif sin @larapack:if en la línea ' . ($number + 1) . '.');
                }

                array_pop($stack);

                continue;
            }

            if ($stack === [] || end($stack)) {
                $output .= $line;
            }
        }

        if ($stack !== []) {
            throw new MakerException('Hay ' . count($stack) . ' @larapack:if sin cerrar.');
        }

        return $output;
    }

    /**
     * Las condiciones simples que usa un stub, para poder comprobar que todas
     * existen sin tener que generar nada.
     *
     * @return array<int, string>
     */
    public static function conditions(string $content): array
    {
        preg_match_all(self::OPEN, $content, $matches);

        $conditions = [];

        foreach ($matches[1] as $expression) {
            foreach (preg_split('/[|&]/', $expression) as $condition) {
                $conditions[] = $condition;
            }
        }

        return array_values(array_unique($conditions));
    }

    /**
     * Las líneas conservando su fin de línea, para no tener que adivinar si el
     * stub venía en LF o en CRLF.
     *
     * @return array<int, string>
     */
    private static function lines(string $content): array
    {
        return preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  callable(string): bool  $holds
     */
    private static function evaluate(string $expression, callable $holds, int $number): bool
    {
        $all = str_contains($expression, '&');
        $any = str_contains($expression, '|');

        if ($all && $any) {
            throw new MakerException("La condición '{$expression}' de la línea " . ($number + 1) . ' mezcla & y |.');
        }

        $conditions = preg_split('/[|&]/', $expression);

        if ($all) {
            foreach ($conditions as $condition) {
                if (! $holds($condition)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($conditions as $condition) {
            if ($holds($condition)) {
                return true;
            }
        }

        return false;
    }
}
