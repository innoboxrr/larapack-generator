<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * El esquema de laraimport es el contrato: describe qué se puede declarar y
 * qué significa. Todo lo demás —migraciones, requests, políticas, el módulo
 * JS— se deriva de ahí.
 *
 * Se publica con el paquete para que un editor o un agente puedan validar el
 * archivo antes de generar nada.
 */
final class Schema
{
    private const RELATIVE_PATH = '/../../../schema/laraimport.schema.json';

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $decoded = null;

    public static function path(): string
    {
        $path = realpath(__DIR__ . self::RELATIVE_PATH);

        if ($path === false) {
            throw new RuntimeException('No se encontró schema/laraimport.schema.json en el paquete.');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return self::$decoded ??= json_decode(file_get_contents(self::path()), true);
    }

    public static function id(): string
    {
        return self::toArray()['$id'];
    }

    /**
     * Valida la estructura y devuelve los errores con la ruta exacta del dato
     * que falla, para que quien lo lea —persona o agente— sepa dónde mirar.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, array{path: string, message: string}>
     */
    public static function validate(array $document): array
    {
        $validator = new Validator();

        $validator->resolver()->registerRaw(file_get_contents(self::path()), self::id());

        $result = $validator->validate(
            json_decode(json_encode($document)),
            self::id()
        );

        if ($result->isValid()) {
            return [];
        }

        return self::flatten((new ErrorFormatter())->format($result->error(), true));
    }

    /**
     * ErrorFormatter agrupa por puntero JSON y devuelve varios mensajes por
     * ruta; aquí se aplana a una lista para poder contarlos y ordenarlos.
     *
     * @param  array<string, array<int, string>|string>  $errors
     * @return array<int, array{path: string, message: string}>
     */
    private static function flatten(array $errors): array
    {
        $flat = [];

        foreach ($errors as $path => $messages) {
            foreach ((array) $messages as $message) {
                $flat[] = [
                    'path' => $path === '' ? '/' : $path,
                    'message' => $message,
                ];
            }
        }

        return $flat;
    }
}
