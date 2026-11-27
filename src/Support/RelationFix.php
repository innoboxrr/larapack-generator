<?php

namespace Innoboxrr\LarapackGenerator\Support;

use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Añade al trait de relaciones las relaciones que el laraimport declara y no
 * define nadie (ContractDrift::missingRelations()).
 *
 * El trait es un hueco del proyecto: se escribe una vez y después es suyo, así
 * que nunca se regenera ni se sobrescribe. Sólo se le añade un método cuando
 * es seguro de forma determinista:
 *
 *  - el trait existe y es un trait, uno solo en el archivo;
 *  - ni el modelo ni ninguno de sus traits define ya un método con ese nombre
 *    (eso lo garantiza ContractDrift, que no la reportaría);
 *  - el nombre corto del modelo relacionado no está importado ya para otra
 *    clase (si lo está con la misma, no se repite el `use`).
 *
 * Si algo de eso no se cumple, la relación se salta con la razón. El método es
 * el mismo que escribe el generador al crear el trait.
 *
 * @phpstan-import-type MissingRelation from ContractDrift
 *
 * @phpstan-type Planned array{file: string, original: string, code: string}
 * @phpstan-type Skipped array{file: string, reason: string}
 */
final class RelationFix
{
    /**
     * @return array{fixes: list<Planned>, skipped: list<Skipped>}
     */
    public static function plan(Manifest $manifest): array
    {
        $byTrait = [];

        foreach ((new ContractDrift($manifest))->missingRelations() as $missing) {
            $byTrait[$manifest->absolute($missing['trait'])][] = $missing;
        }

        $fixes = [];
        $skipped = [];

        foreach ($byTrait as $file => $relations) {
            if (! is_file($file)) {
                foreach ($relations as $relation) {
                    $skipped[] = ['file' => $file, 'reason' => "No existe el trait de relaciones: la relación «{$relation['name']}» va donde el modelo defina sus relaciones, a mano."];
                }

                continue;
            }

            $original = Files::read($file);
            $code = $original;

            foreach ($relations as $relation) {
                try {
                    $code = self::add($code, $relation);
                } catch (RuntimeException $e) {
                    $skipped[] = ['file' => $file, 'reason' => "La relación «{$relation['name']}»: {$e->getMessage()}"];
                }
            }

            if ($code !== $original) {
                $fixes[] = ['file' => $file, 'original' => $original, 'code' => $code];
            }
        }

        return ['fixes' => $fixes, 'skipped' => $skipped];
    }

    /**
     * El trait con el método y, si hace falta, su `use`.
     *
     * @param  MissingRelation  $relation
     */
    public static function add(string $code, array $relation): string
    {
        $tokens = PhpToken::tokenize($code);
        $traits = [];

        foreach ($tokens as $index => $token) {
            if ($token->is([T_CLASS, T_INTERFACE, T_ENUM]) && ! self::afterDoubleColon($tokens, $index)) {
                throw new RuntimeException('el archivo declara algo que no es un trait.');
            }

            if ($token->is(T_TRAIT)) {
                $traits[] = $index;
            }
        }

        if (count($traits) !== 1) {
            throw new RuntimeException('el archivo no tiene un único trait.');
        }

        $outline = PhpOutline::ofCode($code);

        foreach ($outline->classes()[0]['methods'] ?? [] as $method) {
            if (strtolower($method['name']) === strtolower($relation['name'])) {
                throw new RuntimeException('el trait ya tiene un método con ese nombre.');
            }
        }

        $close = self::closingBrace($tokens, $traits[0]);
        $before = rtrim(substr($code, 0, $close));
        $emptyBody = str_ends_with($before, '{');
        $code = $before."\n".($emptyBody ? '' : "\n").$relation['method']."\n".substr($code, $close);

        return $relation['use'] === null ? $code : self::import($code, $relation['use']);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private static function afterDoubleColon(array $tokens, int $index): bool
    {
        for ($index--; $index >= 0; $index--) {
            if (! $tokens[$index]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$index]->is(T_DOUBLE_COLON);
            }
        }

        return false;
    }

    /**
     * El offset de la llave que cierra el trait.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function closingBrace(array $tokens, int $trait): int
    {
        $depth = 0;

        for ($index = $trait; isset($tokens[$index]); $index++) {
            $text = $tokens[$index]->text;

            if ($text === '{' || $tokens[$index]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    return $tokens[$index]->pos;
                }
            }
        }

        throw new RuntimeException('el trait no se cierra.');
    }

    /**
     * Añade `use X;` detrás del último `use` del archivo, o del namespace.
     * Si el nombre corto ya se importa para otra clase, no se puede.
     */
    private static function import(string $code, string $use): string
    {
        $fqcn = trim(substr($use, 4, -1));
        $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

        if (preg_match_all('/^use\s+([^;\s]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('no se pudieron leer los use del trait.');
        }

        $last = null;

        foreach ($matches as $match) {
            $imported = ltrim($match[1][0], '\\');
            $alias = $match[2][0] ?? substr($imported, (int) strrpos($imported, '\\') + 1);

            if (strcasecmp($imported, $fqcn) === 0) {
                return $code;
            }

            if (strcasecmp($alias, $short) === 0) {
                throw new RuntimeException("el trait ya importa otra clase como {$short} ({$imported}).");
            }

            $last = $match[0][1] + strlen($match[0][0]);
        }

        if ($last === null) {
            if (preg_match('/^namespace\s+[^;]+;/m', $code, $namespace, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('el trait no tiene namespace ni use donde añadir el import.');
            }

            $at = $namespace[0][1] + strlen($namespace[0][0]);

            return substr($code, 0, $at)."\n\n{$use}".substr($code, $at);
        }

        $result = substr($code, 0, $last)."\n{$use}".substr($code, $last);

        try {
            PhpToken::tokenize($result, TOKEN_PARSE);
        } catch (ParseError) {
            throw new RuntimeException('añadir el import dejaba el archivo sin parsear.');
        }

        return $result;
    }
}
