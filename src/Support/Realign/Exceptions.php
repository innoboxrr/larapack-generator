<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use RuntimeException;

/**
 * Las excepciones aprobadas del realineamiento, en
 * `.larapack/realign/exceptions.json`: lo que alguien con autoridad decidió
 * dejar como está. El diagnóstico no las vuelve a proponer, y por eso un
 * paso de decisión que se resolvió con «dejarlo así» deja de aparecer.
 *
 * Es el contrato entre el núcleo y la operación `except` (que las escribe):
 *
 *     {
 *       "format_version": 1,
 *       "exceptions": [
 *         {"type": "unmanaged-http", "scope": ".", "file": "app/Http/Controllers/X.php",
 *          "check": null, "reason": "…", "approved_by": "…", "at": "2026-11-27T10:00:00+00:00"}
 *       ]
 *     }
 *
 * Una excepción casa con un elemento del diagnóstico si coinciden todas las
 * claves que trae: `type` (el tipo de paso), `scope` (la ruta del ámbito),
 * `file` (relativa a la raíz del proyecto) y `check` (de verify). Una sin
 * `file` cubre todo lo de ese tipo en ese ámbito. Sin `reason` no vale: una
 * excepción sin razón no se puede revisar.
 *
 * Las de `#[HandRolled]` no van aquí: tienen su candado
 * (`.larapack/hand-rolled.lock`) y verify ya las respeta.
 *
 * @phpstan-type RealignException array{type?: string|null, scope?: string|null, file?: string|null, check?: string|null, reason: string}
 */
final class Exceptions
{
    public const FILE = 'exceptions.json';

    /**
     * @var list<RealignException>
     */
    private array $exceptions;

    public function __construct(RealignState $state)
    {
        $this->exceptions = self::load($state->directory().'/'.self::FILE);
    }

    /**
     * @return list<RealignException>
     */
    public function all(): array
    {
        return $this->exceptions;
    }

    /**
     * @param  array{type?: string|null, scope?: string|null, file?: string|null, check?: string|null}  $item
     */
    public function covers(array $item): bool
    {
        foreach ($this->exceptions as $exception) {
            $matches = true;

            foreach (['type', 'scope', 'file', 'check'] as $key) {
                $wanted = $exception[$key] ?? null;

                if ($wanted !== null && $wanted !== ($item[$key] ?? null)) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RealignException>
     */
    private static function load(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode(Files::read($path), true);

        if (! is_array($decoded) || ! is_array($decoded['exceptions'] ?? null)) {
            throw new RuntimeException("{$path} no tiene la forma de las excepciones del realineamiento (un objeto con una lista `exceptions`).");
        }

        $valid = [];

        foreach ($decoded['exceptions'] as $exception) {
            // Una excepción sin razón no cuenta, y tampoco una que no acota
            // nada: aprobaría todo el proyecto de una vez.
            if (! is_array($exception) || ! is_string($exception['reason'] ?? null) || trim($exception['reason']) === '') {
                continue;
            }

            $narrowed = array_filter(
                array_intersect_key($exception, array_flip(['type', 'scope', 'file', 'check'])),
                fn (mixed $value): bool => is_string($value) && $value !== ''
            );

            if (! isset($narrowed['type']) && ! isset($narrowed['file']) && ! isset($narrowed['check'])) {
                continue;
            }

            /** @var array{type?: string, scope?: string, file?: string, check?: string} $narrowed */
            $valid[] = $narrowed + ['reason' => $exception['reason']];
        }

        return $valid;
    }
}
