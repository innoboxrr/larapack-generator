<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Apply;

use Innoboxrr\LarapackGenerator\Support\Verifier;

/**
 * Completa un `<Modelo>EndpointsTest` de una versión anterior con las
 * pruebas que trae hoy su plantilla (Verifier::ENDPOINT_TESTS: sin sesión no
 * se entra, cada acción pregunta a la política, el listado pasa por su
 * ManagedFilter), copiándolas de lo regenerado.
 *
 * Es lo que `verify` pedía hacer a mano —guardar lo añadido, borrar el test,
 * importar y volver a pegar—, sin tocar lo que ya hay: sólo se AÑADEN los
 * métodos que faltan, los auxiliares privados que llaman y no existen, y los
 * `use` que necesitan. Lo escrito a mano en el test se queda byte a byte.
 *
 * No se completa —y se dice por qué— si algo no es seguro: el test no se
 * reconoce (dos clases, una importación agrupada), la clase o el namespace no
 * son los de lo regenerado, o una importación que hace falta choca con otra
 * del mismo nombre.
 *
 * @phpstan-type Completion array{content: string, added: list<string>, imports: list<string>}
 */
final class EndpointTests
{
    /**
     * @return Completion|string lo completado, o la razón para no tocarlo
     */
    public static function complete(string $current, string $regenerated): array|string
    {
        $mine = PhpMembers::of($current);
        $theirs = PhpMembers::of($regenerated);

        if ($mine === null) {
            return 'El test no se reconoce con certeza (no parsea, tiene más de una clase o una importación agrupada).';
        }

        if ($theirs === null) {
            return 'El test regenerado no se reconoce: actualiza el generador o complétalo a mano.';
        }

        if (strcasecmp($mine->className, $theirs->className) !== 0 || strcasecmp((string) $mine->namespace, (string) $theirs->namespace) !== 0) {
            return "La clase ({$mine->namespace}\\{$mine->className}) no es la que generaría hoy el contrato ({$theirs->namespace}\\{$theirs->className}).";
        }

        $add = [];

        foreach (array_keys(Verifier::ENDPOINT_TESTS) as $suffix) {
            if (self::find($mine->methods, $suffix) !== null) {
                continue;
            }

            $method = self::find($theirs->methods, $suffix);

            // La plantilla no la trae para este modelo (no declara index).
            if ($method !== null) {
                $add[strtolower($method['name'])] = $method;
            }
        }

        if ($add === []) {
            return ['content' => $current, 'added' => [], 'imports' => []];
        }

        // Los auxiliares que llaman y el test no tiene, de forma transitiva.
        $queue = array_values($add);

        while ($queue !== []) {
            $method = array_shift($queue);
            preg_match_all('/\$this->(\w+)\s*\(/', $method['code'], $calls);

            foreach ($calls[1] as $call) {
                $key = strtolower($call);

                if (isset($mine->methods[$key]) || isset($add[$key]) || ! isset($theirs->methods[$key]) || str_starts_with($key, 'test')) {
                    continue;
                }

                $add[$key] = $theirs->methods[$key];
                $queue[] = $theirs->methods[$key];
            }
        }

        $used = [];

        foreach ($add as $method) {
            $used += PhpMembers::namesUsedIn($method['code']);
        }

        $imports = [];

        foreach ($theirs->imports as $import) {
            if (! isset($used[strtolower($import['alias'])])) {
                continue;
            }

            $existing = $mine->imported($import['alias']);

            if ($existing !== null) {
                if (strcasecmp($existing, $import['name']) !== 0) {
                    return "Las pruebas que faltan usan {$import['alias']} como {$import['name']}, y el test ya importa ese nombre para {$existing}.";
                }

                continue;
            }

            foreach ($mine->imports as $other) {
                if (strcasecmp($other['name'], $import['name']) === 0) {
                    return "Las pruebas que faltan usan {$import['name']} como {$import['alias']}, y el test lo importa como {$other['alias']}.";
                }
            }

            $short = substr($import['name'], (int) strrpos('\\'.$import['name'], '\\'));
            $imports[] = 'use '.$import['name'].(strcasecmp($short, $import['alias']) === 0 ? '' : ' as '.$import['alias']).';';
        }

        // Primero lo de más abajo (los métodos), para no mover lo de arriba.
        $content = rtrim(substr($current, 0, $mine->classClose))
            ."\n\n".implode("\n\n", array_map(fn (array $method): string => rtrim($method['code']), array_values($add)))
            ."\n".substr($current, $mine->classClose);

        if ($imports !== []) {
            $last = $mine->imports === [] ? $mine->namespaceEnd : max(array_column($mine->imports, 'end'));

            if ($last === null) {
                return 'El test no tiene namespace ni importaciones donde añadir los `use` que faltan.';
            }

            $content = substr($content, 0, $last).($mine->imports === [] ? "\n" : '')."\n".implode("\n", $imports).substr($content, $last);
        }

        return ['content' => $content, 'added' => array_values(array_map(fn (array $method): string => $method['name'], $add)), 'imports' => $imports];
    }

    /**
     * El método cuyo nombre termina en la prueba que se busca.
     *
     * @param  array<string, array{name: string, start: int, end: int, code: string, visibility: string}>  $methods
     * @return array{name: string, start: int, end: int, code: string, visibility: string}|null
     */
    private static function find(array $methods, string $suffix): ?array
    {
        foreach ($methods as $method) {
            if (preg_match('/^test_\w*'.preg_quote($suffix, '/').'$/i', $method['name']) === 1) {
                return $method;
            }
        }

        return null;
    }
}
