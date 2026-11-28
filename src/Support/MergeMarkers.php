<?php

namespace Innoboxrr\LarapackGenerator\Support;

/**
 * Los marcadores que deja `git merge` en un archivo en conflicto.
 *
 * Con varias ramas en paralelo, un merge a medio resolver es la forma más
 * tonta de romper un proyecto: el archivo deja de ser PHP o JSON, y lo que lo
 * lee con tolerancia (un manifiesto ilegible se daba por vacío) lo esconde.
 * Se reconoce por la forma exacta de las tres líneas, al principio de línea,
 * para no confundirlo con un `=======` de un comentario o de un Markdown.
 */
final class MergeMarkers
{
    /**
     * Las tres líneas tienen que estar, y en orden: una sola no es un
     * conflicto (un subrayado de Markdown es `=======`).
     */
    private const PATTERN = '/^<{7}(?: [^\n]*)?\n.*?^={7}\r?\n.*?^>{7}(?: [^\n]*)?$/ms';

    public static function in(string $content): bool
    {
        return str_contains($content, '<<<<<<<') && preg_match(self::PATTERN, $content) === 1;
    }

    /**
     * La línea (desde 1) del primer `<<<<<<<`, o null si no hay conflicto.
     */
    public static function firstLine(string $content): ?int
    {
        if (! self::in($content) || preg_match(self::PATTERN, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count($content, "\n", 0, $match[0][1]) + 1;
    }
}
