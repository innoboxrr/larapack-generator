<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Files;
use RuntimeException;

/**
 * Los playbooks de los pasos guiados: las instrucciones de cada tipo de
 * trabajo, escritas una vez en el paquete (resources/realign/playbooks/) y
 * rellenadas por `larapack:realign next` con lo de ESE paso. Es lo que
 * hace que el proceso no dependa de escribir un prompt por proyecto.
 *
 * Un playbook es Markdown con marcadores `{{nombre}}` (PLACEHOLDERS). Un
 * marcador que no existe es un error, no un hueco en blanco: una
 * instrucción a medias es peor que ninguna. Un tipo sin playbook propio usa
 * `generic.md`, y `next` dice que lo hizo.
 *
 * Para añadir uno: el archivo `<tipo>.md` con los marcadores que necesite, y
 * el tipo en StepTypes con `playbook` apuntando a él. RealignCatalogTest
 * comprueba los dos lados.
 *
 * Tres usos además del de cada tipo guiado:
 *
 *  - **Anexos** (`annexes` del paso): un playbook más corto que se añade al
 *    del tipo cuando los hallazgos dicen que hace falta —el controlador que
 *    valida con validate() recibe además `validate-to-rules`—. Los decide
 *    annexesFor() a partir de los mensajes de verify.
 *  - **El de una decisión** (`playbook` de la decisión): cómo pensarla antes
 *    de tomarla. Se muestra con sus opciones mientras no se decide.
 *  - **El de una opción** (`playbook` de la opción): cómo hacer lo decidido.
 *    Se muestra en `next` una vez decidida.
 *
 * `larapack:realign playbook <nombre>` imprime cualquiera sin rellenar,
 * con cada marcador explicado.
 */
final class Playbooks
{
    public const GENERIC = 'generic';

    /**
     * Los marcadores que se rellenan, y qué son.
     */
    public const PLACEHOLDERS = [
        'id' => 'el id del paso',
        'title' => 'el título del tipo de paso',
        'description' => 'qué agrupa el paso, con cuántos elementos',
        'scope' => 'el ámbito: `app (núcleo)` o el paquete con su ruta',
        'package' => 'el nombre para --package (`app` en el núcleo)',
        'files' => 'la lista de archivos, con su clase y su razón',
        'findings' => 'los hallazgos de verify, con su archivo y su mensaje',
        'risk' => 'el riesgo y por qué',
        'check' => 'la orden que comprueba que está hecho',
        'verify' => 'la orden de verify acotada a este paso',
        'done_when' => 'la comprobación de terminado, en palabras',
    ];

    public static function directory(): string
    {
        return dirname(__DIR__, 3).'/resources/realign/playbooks';
    }

    public static function exists(string $name): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_file(self::directory().'/'.$name.'.md');
    }

    /**
     * El playbook que usará un tipo: el suyo o el genérico.
     */
    public static function resolve(string $name): string
    {
        return self::exists($name) ? $name : self::GENERIC;
    }

    /**
     * Los playbooks del paquete, por nombre.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map(fn (string $file): string => basename($file, '.md'), glob(self::directory().'/*.md') ?: []);
        sort($names);

        return $names;
    }

    /**
     * Los marcadores que usa un playbook.
     *
     * @return list<string>
     */
    public static function placeholdersOf(string $name): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', Files::read(self::directory().'/'.self::resolve($name).'.md'), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * El playbook de un paso, rellenado.
     *
     * @param  array<string, mixed>  $step
     */
    public static function render(array $step, ?string $playbook = null): string
    {
        $name = self::resolve($playbook ?? (string) ($step['playbook'] ?? self::GENERIC));
        $text = self::fill($name, self::values($step));

        // Los anexos van con el playbook del tipo, no con el de una opción.
        foreach ($playbook === null ? (array) ($step['annexes'] ?? []) : [] as $annex) {
            if (is_string($annex) && self::exists($annex)) {
                $text = rtrim($text)."\n\n---\n\n".self::fill($annex, self::values($step));
            }
        }

        return $text;
    }

    /**
     * Un playbook sin rellenar, con cada marcador en `<…>` diciendo qué
     * pondría: para leerlo antes de tener un paso (`larapack:realign
     * playbook <nombre>`).
     */
    public static function describe(string $name): string
    {
        return self::fill(self::resolve($name), array_map(fn (string $what): string => '<'.$what.'>', self::PLACEHOLDERS));
    }

    /**
     * Los anexos de un paso: los playbooks que se añaden al de su tipo porque
     * sus hallazgos lo piden.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    public static function annexesFor(string $type, array $findings): array
    {
        if ($type !== 'controller-shape') {
            return [];
        }

        $annexes = [];

        foreach ($findings as $finding) {
            $message = (string) ($finding['message'] ?? '');

            if (str_contains($message, 'valida en el controlador con validate()')) {
                $annexes[] = 'validate-to-rules';
            }

            if (preg_match('/(recibe|con) Illuminate\\\\Http\\\\Request\b/i', $message) === 1) {
                $annexes[] = 'raw-request-to-formrequest';
            }
        }

        return array_values(array_unique($annexes));
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function fill(string $name, array $values): string
    {
        $template = Files::read(self::directory().'/'.$name.'.md');

        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $match) use ($values, $name): string {
            if (! array_key_exists($match[1], $values)) {
                throw new RuntimeException("El playbook {$name} usa el marcador {{{$match[1]}}}, que no existe. Disponibles: ".implode(', ', array_keys(self::PLACEHOLDERS)).'.');
            }

            return $values[$match[1]];
        }, $template);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, string>
     */
    private static function values(array $step): array
    {
        $scope = is_array($step['scope'] ?? null) ? $step['scope'] : [];
        $package = is_string($scope['package'] ?? null) ? $scope['package'] : null;
        $details = is_array($step['details'] ?? null) ? $step['details'] : [];
        $id = (string) ($step['id'] ?? '');

        $files = [];

        foreach ((array) ($details['files'] ?? []) as $file) {
            if (is_array($file)) {
                $members = is_array($file['members'] ?? null) && $file['members'] !== [] ? ' — piezas: '.implode(', ', $file['members']) : '';
                $regenerated = is_string($file['regenerated'] ?? null) ? "\n  regenerado: `".$file['regenerated'].'`' : '';
                $files[] = '- `'.($file['file'] ?? '?').'` ('.($file['class'] ?? '?').'): '.($file['reason'] ?? '').$members.$regenerated;
            }
        }

        foreach ((array) ($details['unmanaged'] ?? []) as $item) {
            if (is_array($item)) {
                $files[] = '- `'.($item['file'] ?? '?').'` ('.($item['kind'] ?? '?').')';
            }
        }

        if ($files === []) {
            foreach ((array) ($step['files'] ?? []) as $file) {
                $files[] = '- `'.$file.'`';
            }
        }

        $findings = [];

        foreach ((array) ($details['findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $findings[] = '- `'.($finding['check'] ?? '?').'` en `'.($finding['file'] ?? '-').'`: '.($finding['message'] ?? '');
            }
        }

        $condition = is_array($step['done_when'] ?? null) ? $step['done_when'] : [];
        $verify = 'larapack:verify --format=json --package='.($package ?? 'app')
            .implode('', array_map(fn (mixed $check): string => ' --check='.$check, (array) ($condition['checks'] ?? [])))
            .implode('', array_map(fn (mixed $file): string => ' --path='.self::shellArgument((string) $file), array_slice((array) ($condition['files'] ?? []), 0, 20)));

        return [
            'id' => $id,
            'title' => (string) ($step['title'] ?? ''),
            'description' => (string) ($step['description'] ?? ''),
            'scope' => $package === null ? 'app (núcleo)' : $package.' ('.($scope['path'] ?? '?').')',
            'package' => $package ?? 'app',
            'files' => $files === [] ? '(ninguno)' : implode("\n", $files),
            'findings' => $findings === [] ? '(ninguno)' : implode("\n", $findings),
            'risk' => (string) ($step['risk']['level'] ?? '?').': '.(string) ($step['risk']['why'] ?? ''),
            // Lo que cambia comportamiento sólo queda hecho con su prueba:
            // la orden lo pide, y sin pruebas check lo deja untested.
            'check' => 'larapack:realign check '.$id.(StepTypes::changesBehaviour($step) ? ' --tested="<la orden de pruebas del proyecto que corriste y pasó>"' : ''),
            'verify' => $verify,
            'done_when' => self::describeCondition($condition),
        ];
    }

    /**
     * Una ruta como argumento de una orden: tal cual si no tiene nada que la
     * shell interprete, entre comillas simples si lo tiene.
     */
    private static function shellArgument(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_./@:+-]+$#', $value) === 1 ? $value : "'".str_replace("'", "'\\''", $value)."'";
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public static function describeCondition(array $condition): string
    {
        $files = count((array) ($condition['files'] ?? []));

        return match ($condition['kind'] ?? null) {
            'verify' => '`verify` no encuentra '.implode(', ', array_map('strval', (array) ($condition['checks'] ?? []))).($files > 0 ? " en estos {$files} archivos" : '').'.',
            'classification' => match (true) {
                isset($condition['accept']) => "cada uno de los {$files} archivos queda como ".implode(' o ', array_map('strval', (array) $condition['accept'])).'.',
                isset($condition['reject']) => "ninguno de los {$files} archivos queda como ".implode(' ni ', array_map('strval', (array) $condition['reject'])).'.',
                default => "ninguno de los {$files} archivos tiene un paso pendiente.",
            },
            'unmanaged' => "ninguno de los {$files} archivos sigue sin gestionar.",
            'contract' => 'el laraimport.json del ámbito valida.',
            'foreign-keys' => 'el laraimport.json declara on_delete/on_update en cada una de las '.count((array) ($condition['columns'] ?? [])).' llaves.',
            'decision' => 'hay una decisión registrada.',
            default => 'no se sabe comprobar.',
        };
    }
}
