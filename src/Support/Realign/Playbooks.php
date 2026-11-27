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
    public static function render(array $step): string
    {
        $name = self::resolve((string) ($step['playbook'] ?? self::GENERIC));
        $template = Files::read(self::directory().'/'.$name.'.md');
        $values = self::values($step);

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
                $files[] = '- `'.($file['file'] ?? '?').'` ('.($file['class'] ?? '?').'): '.($file['reason'] ?? '').$members;
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
            .implode('', array_map(fn (mixed $check): string => ' --check='.$check, (array) ($condition['checks'] ?? [])));

        return [
            'id' => $id,
            'title' => (string) ($step['title'] ?? ''),
            'description' => (string) ($step['description'] ?? ''),
            'scope' => $package === null ? 'app (núcleo)' : $package.' ('.($scope['path'] ?? '?').')',
            'package' => $package ?? 'app',
            'files' => $files === [] ? '(ninguno)' : implode("\n", $files),
            'findings' => $findings === [] ? '(ninguno)' : implode("\n", $findings),
            'risk' => (string) ($step['risk']['level'] ?? '?').': '.(string) ($step['risk']['why'] ?? ''),
            'check' => 'larapack:realign check '.$id,
            'verify' => $verify,
            'done_when' => self::describeCondition($condition),
        ];
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
            'decision' => 'hay una decisión registrada.',
            default => 'no se sabe comprobar.',
        };
    }
}
