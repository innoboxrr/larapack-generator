<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * La política del dueño, reducida a lo que cada prueba abre.
 */
class AuditPolicy
{
    /** @var array<string, true|int|string> */
    public static array $abilities = [];

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $ability, array $arguments): bool
    {
        $allowed = self::$abilities[$ability] ?? false;
        $record = $arguments[1] ?? null;

        if ($allowed === true || $allowed === false) {
            return $allowed;
        }

        return $record instanceof Model && (string) $record->getKey() === (string) $allowed;
    }
}
