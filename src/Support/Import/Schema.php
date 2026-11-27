<?php

namespace Innoboxrr\LarapackGenerator\Support\Import;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
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
        $path = realpath(__DIR__.self::RELATIVE_PATH);

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
        if (self::$decoded !== null) {
            return self::$decoded;
        }

        $decoded = json_decode((string) file_get_contents(self::path()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('schema/laraimport.schema.json no es un objeto JSON válido.');
        }

        return self::$decoded = $decoded;
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
        // El resolvedor se crea aquí, y no se pide al Validator: resolver()
        // puede devolver null y el registro del esquema se perdería.
        $resolver = new SchemaResolver;
        $resolver->registerRaw((string) file_get_contents(self::path()), self::id());

        $validator = (new Validator)->setResolver($resolver);

        // Opis valida objetos, y no todo array de PHP se puede volver objeto:
        // una clave que empieza por NUL no es un nombre de propiedad válido, y
        // un número fuera de rango (`1e999`) llega como INF, que JSON no
        // admite. Antes los dos daban `null` y un «The data (null) must match
        // the type: object» que no decía qué estaba mal.
        try {
            $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [[
                'path' => '/',
                'message' => 'El documento no se puede validar ('.$e->getMessage().'). Revisa que ninguna clave empiece por el carácter NUL (\\u0000) y que ningún número se salga del rango de un double.',
            ]];
        }

        $error = $validator->validate($data, self::id())->error();

        if ($error === null) {
            return [];
        }

        return self::flatten((new ErrorFormatter)->format($error, true));
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
