<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Infer;

use Innoboxrr\LarapackGenerator\Support\PhpOutline;

/**
 * Los tokens de un archivo PHP como los leen ModelSource y RouteSource: sin
 * espacios ni comentarios y cada uno con su línea, también los de un solo
 * carácter (`(`, `;`), que `token_get_all()` da sin ella. Es la forma de
 * PhpOutline, para poder pasarle los trozos (PhpOutline::splitTopLevel()).
 *
 * @phpstan-import-type Token from PhpOutline
 */
final class SourceTokens
{
    private const IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML];

    /**
     * @param  int  $fromLine  sólo los tokens desde esta línea (la de la clase, en un modelo)
     * @return list<Token>
     */
    public static function of(string $code, int $fromLine = 1): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");

                if (! in_array($token[0], self::IGNORED, true) && $token[2] >= $fromLine) {
                    $tokens[] = [$token[0], $token[1], $token[2]];
                }

                continue;
            }

            if ($line >= $fromLine) {
                $tokens[] = [$token, $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * El valor de un literal de cadena, con sus comillas.
     */
    public static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
    }
}
