<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * `larapack:realign plan`: del diagnóstico a una lista de pasos ordenados,
 * con id estable, que se pueden hacer de uno en uno y retomar.
 *
 * Cada paso agrupa lo que se resuelve junto —los archivos a adoptar de un
 * ámbito, la deuda de `larapack:fix` de un paquete, los hallazgos de un
 * controlador— y lleva todo lo necesario para hacerlo sin contexto: qué
 * es, su riesgo y por qué, si se puede deshacer, cómo se hace (la orden, el
 * playbook o las opciones) y su `done_when`, la comprobación concreta que
 * dice que está hecho.
 *
 * El orden:
 *
 *  1. Lo que desbloquea: que el manifiesto diga la verdad (relink, prune,
 *     adopt), los saltos de versión (regenerate) y la deuda de fix. Sin eso,
 *     lo demás se juzga contra un manifiesto que miente.
 *  2. Lo guiado, por ámbito: del que más cumple al que menos, para que los
 *     primeros pasos terminen paquetes enteros.
 *  3. Las decisiones, agrupadas por tipo.
 *
 * Volver a planificar después de diagnosticar otra vez no pierde nada: un
 * paso con el mismo id conserva su estado, su decisión y su historia; uno
 * hecho que vuelve a salir se reabre (algo lo deshizo); uno pendiente que ya
 * no sale se da por hecho, porque el diagnóstico ya no encuentra lo que lo
 * pedía.
 *
 * @phpstan-type Step array<string, mixed>
 */
final class Planner
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    /**
     * Lo que se calcula al leer y no se guarda.
     */
    public const BLOCKED = 'blocked';

    public const AWAITING_DECISION = 'awaiting_decision';

    public const DECIDED = 'decided';

    /**
     * Los pasos de un ámbito que tienen que estar hechos antes que lo
     * guiado y las decisiones de ese ámbito: el manifiesto, primero.
     */
    private const UNBLOCKS = ['relink', 'prune', 'adopt'];

    /**
     * Y antes de devolver código a sus huecos, además, lo que lo reescribe.
     */
    private const REWRITES = ['regenerate', 'fix', 'model-lists'];

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed> el plan, sin guardar
     */
    public static function build(array $report, ?array $previous = null): array
    {
        $groups = [];
        $add = function (string $type, string $scope, ?string $subject, array $item) use (&$groups): void {
            $id = $type.($subject === null ? '' : ':'.$subject).'@'.$scope;
            $groups[$id] ??= ['id' => $id, 'type' => $type, 'scope' => $scope, 'subject' => $subject, 'files' => [], 'findings' => [], 'items' => []];
            $groups[$id]['items'][] = $item;
        };

        foreach (self::listOf($report, 'files') as $entry) {
            if (! is_string($entry['type'] ?? null)) {
                continue;
            }

            // Lo editado fuera de los huecos, por familia de plantilla: en
            // expediente eran 256 archivos, y las 61 notificaciones de
            // exportación tenían el mismo parche. Un paso con los 256 no lo
            // termina nadie; uno por familia, sí.
            $subject = $entry['type'] === 'edited-outside' && is_string($entry['stub'] ?? null)
                ? strtok($entry['stub'], '/')
                : null;

            $add($entry['type'], (string) $entry['scope'], $subject === false ? null : $subject, ['file' => $entry]);
        }

        foreach (self::listOf($report, 'unmanaged') as $item) {
            $add((string) $item['type'], (string) $item['scope'], null, ['unmanaged' => $item]);
        }

        foreach (self::listOf($report, 'findings') as $finding) {
            $type = (string) $finding['type'];
            $scope = (string) $finding['scope'];

            match ($type) {
                // Un controlador por paso: es la unidad que un agente lleva de
                // principio a fin con sus pruebas.
                'controller-shape' => $add($type, (string) ($finding['file'] ?? $scope), null, ['finding' => $finding]),
                'fix' => $add($type, $scope, (string) $finding['check'], ['finding' => $finding]),
                default => $add($type, $scope, null, ['finding' => $finding]),
            };
        }

        // Lo que el contrato tiene que decir y no dice (ForeignKeyPins).
        foreach (self::listOf($report, 'contract') as $item) {
            $add((string) $item['type'], (string) $item['scope'], null, ['contract' => $item]);
        }

        foreach ((array) ($report['scopes'] ?? []) as $path => $scope) {
            $path = (string) $path;
            $regeneration = is_array($scope) ? ($scope['regeneration'] ?? null) : null;

            if (is_array($scope) && ! ($scope['contract'] ?? false) && ! ($scope['excepted'] ?? false)) {
                $add('no-contract', $path, null, ['scope' => $scope]);
            } elseif (is_array($regeneration) && ! ($regeneration['ok'] ?? false)) {
                $add('contract-invalid', $path, null, ['scope' => $scope]);
            }
        }

        $rank = self::compliance($report);
        $steps = [];

        foreach ($groups as $group) {
            $steps[] = self::step($group, $report);
        }

        // El ámbito de un paso por controlador es su archivo: su orden es el
        // del ámbito al que pertenece.
        $scopeOf = fn (array $step): string => (string) ($step['scope']['path'] ?? '.');
        $phase = fn (array $step): int => array_search($step['category'], [Category::DETERMINISTIC, Category::GUIDED, Category::DECISION], true) ?: 0;

        usort($steps, function (array $a, array $b) use ($rank, $scopeOf, $phase): int {
            return [$phase($a),
                $a['category'] === Category::GUIDED ? ($rank[$scopeOf($a)] ?? PHP_INT_MAX) : 0,
                StepTypes::position((string) $a['type']), $scopeOf($a), $a['id']]
                <=> [$phase($b),
                    $b['category'] === Category::GUIDED ? ($rank[$scopeOf($b)] ?? PHP_INT_MAX) : 0,
                    StepTypes::position((string) $b['type']), $scopeOf($b), $b['id']];
        });

        $steps = self::dependencies($steps);
        $steps = self::merge($steps, is_array($previous['steps'] ?? null) ? $previous['steps'] : []);

        foreach ($steps as $index => $step) {
            $steps[$index]['order'] = $index + 1;
        }

        return [
            'generator' => $report['generator'] ?? null,
            'planned_at' => gmdate('c'),
            'report_generated_at' => $report['generated_at'] ?? null,
            'steps' => $steps,
        ];
    }

    /**
     * El estado de un paso tal como lo ve quien lo lee: `blocked` si algo de
     * lo que depende no está hecho, y en una decisión, si falta decidir.
     *
     * @param  Step  $step
     * @param  array<string, Step>  $byId
     */
    public static function effectiveStatus(array $step, array $byId): string
    {
        if (($step['status'] ?? self::PENDING) === self::DONE) {
            return self::DONE;
        }

        foreach ((array) ($step['depends_on'] ?? []) as $dependency) {
            if (isset($byId[$dependency]) && ($byId[$dependency]['status'] ?? self::PENDING) !== self::DONE) {
                return self::BLOCKED;
            }
        }

        if ($step['category'] === Category::DECISION) {
            return is_array($step['decision'] ?? null) ? self::DECIDED : self::AWAITING_DECISION;
        }

        return self::PENDING;
    }

    /**
     * @param  array{id: string, type: string, scope: string, subject: string|null, items: list<array<string, mixed>>}  $group
     * @param  array<string, mixed>  $report
     * @return Step
     */
    private static function step(array $group, array $report): array
    {
        $type = $group['type'];
        $meta = StepTypes::get($type);
        $scopePath = $type === 'controller-shape' ? self::scopeOfFile($group['scope'], $report) : $group['scope'];
        $scope = is_array($report['scopes'][$scopePath] ?? null) ? $report['scopes'][$scopePath] : null;
        $package = $scope['package'] ?? self::packageOfFindings($group['items']);

        $files = [];
        $findings = [];
        $unmanaged = [];
        $contract = [];

        foreach ($group['items'] as $item) {
            if (isset($item['file']) && is_array($item['file'])) {
                $files[] = array_intersect_key($item['file'], array_flip(['file', 'class', 'reason', 'stub', 'model', 'to', 'members', 'cause', 'tracked', 'hash_current']));
            }

            if (isset($item['finding']) && is_array($item['finding'])) {
                $findings[] = array_intersect_key($item['finding'], array_flip(['level', 'check', 'model', 'file', 'message', 'package']));
            }

            if (isset($item['unmanaged']) && is_array($item['unmanaged'])) {
                $unmanaged[] = array_intersect_key($item['unmanaged'], array_flip(['file', 'kind']));
            }

            if (isset($item['contract']) && is_array($item['contract'])) {
                $contract[] = array_intersect_key($item['contract'], array_flip(['file', 'model', 'column', 'table', 'on_delete', 'on_update', 'migration']));
            }
        }

        $paths = array_values(array_unique(array_filter([
            ...array_column($files, 'file'),
            ...array_column($findings, 'file'),
            ...array_column($unmanaged, 'file'),
            ...array_column($contract, 'file'),
        ], 'is_string')));
        sort($paths);

        $step = [
            'id' => $group['id'],
            'type' => $type,
            'category' => $meta['category'],
            'title' => $meta['title'],
            'description' => self::describe($type, $group['subject'], count($files) + count($findings) + count($unmanaged) + count($contract)),
            'scope' => ['package' => $package, 'path' => $scopePath],
            'subject' => $group['subject'],
            'files' => $paths,
            // Lo que hay detrás de cada archivo: su clase, el hallazgo de
            // verify con su mensaje. El JSON lo lee un agente.
            'details' => ['files' => $files, 'findings' => $findings, 'unmanaged' => $unmanaged] + ($contract === [] ? [] : ['contract' => $contract]),
            'risk' => ['level' => $meta['risk'], 'why' => $meta['why']],
            'reversible' => $meta['reversible'],
            'done_when' => self::doneWhen($type, $group, $paths, $findings, $package, $contract),
            'status' => self::PENDING,
            'history' => [],
        ];

        if (isset($meta['run'])) {
            $step['command'] = self::fill($meta['run'], $step);
        }

        if (isset($meta['playbook'])) {
            $step['playbook'] = $meta['playbook'];
            $annexes = Playbooks::annexesFor($type, $findings);

            if ($annexes !== []) {
                $step['annexes'] = $annexes;
            }
        }

        if (isset($meta['options'])) {
            $step['options'] = array_map(
                fn (array $option): array => isset($option['run']) ? ['run' => self::fill($option['run'], $step)] + $option : $option,
                $meta['options']
            );
        }

        return $step;
    }

    /**
     * Lo que dice que un paso está hecho. Siempre algo que se comprueba sin
     * opinar: que verify no encuentre esos hallazgos, que esos archivos se
     * clasifiquen como deben, que el contrato valide.
     *
     * @param  array{id: string, type: string, scope: string, subject: string|null, items: list<array<string, mixed>>}  $group
     * @param  list<string>  $paths
     * @param  list<array<string, mixed>>  $findings
     * @param  list<array<string, mixed>>  $contract
     * @return array<string, mixed>
     */
    private static function doneWhen(string $type, array $group, array $paths, array $findings, ?string $package, array $contract = []): array
    {
        if ($contract !== []) {
            return [
                'kind' => 'foreign-keys',
                'scope' => $group['scope'],
                'columns' => array_map(
                    fn (array $item): array => array_intersect_key($item, array_flip(['model', 'column', 'on_delete', 'on_update'])),
                    $contract
                ),
            ];
        }

        if ($findings !== []) {
            // Un controlador está hecho cuando delega y cuando la request en
            // la que delega tiene handle(): las dos, aunque hoy sólo falle una.
            $checks = array_column($findings, 'check');

            if ($type === 'controller-shape') {
                $checks = [...$checks, 'controller-shape', 'request-handle'];
            }

            return [
                'kind' => 'verify',
                'checks' => array_values(array_unique($checks)),
                'package' => $package,
                'files' => array_values(array_unique(array_filter(array_column($findings, 'file'), 'is_string'))),
            ];
        }

        return match ($type) {
            'relink' => ['kind' => 'classification', 'files' => $paths, 'reject' => [FileClass::RENAMED, FileClass::MISSING]],
            'prune' => ['kind' => 'classification', 'files' => $paths, 'accept' => [FileClass::ABSENT, FileClass::RENAMED]],
            'adopt' => ['kind' => 'classification', 'files' => $paths, 'accept' => [FileClass::IDENTICAL, FileClass::FORMAT_ONLY], 'tracked' => true, 'hash_current' => true],
            'regenerate' => ['kind' => 'classification', 'files' => $paths, 'accept' => [FileClass::IDENTICAL, FileClass::FORMAT_ONLY]],
            'edited-outside' => ['kind' => 'classification', 'files' => $paths, 'accept' => FileClass::CONFORMING],
            'missing', 'untracked-edited' => ['kind' => 'classification', 'files' => $paths, 'resolved' => true],
            'unmanaged-http', 'unmanaged-models' => ['kind' => 'unmanaged', 'files' => $paths],
            'contract-invalid' => ['kind' => 'contract', 'scope' => $group['scope']],
            default => ['kind' => 'decision'],
        };
    }

    /**
     * Lo guiado y las decisiones de un ámbito esperan a que su manifiesto
     * diga la verdad; lo que devuelve código a sus huecos, además, a que se
     * haya regenerado y arreglado lo que se regenera y se arregla.
     *
     * @param  list<Step>  $steps
     * @return list<Step>
     */
    private static function dependencies(array $steps): array
    {
        $byScope = [];

        foreach ($steps as $step) {
            $byScope[(string) $step['scope']['path']][(string) $step['type']][] = (string) $step['id'];
        }

        foreach ($steps as $index => $step) {
            if ($step['category'] === Category::DETERMINISTIC) {
                $steps[$index]['depends_on'] = [];

                continue;
            }

            $types = in_array($step['type'], ['edited-outside', 'model-lists'], true) ? [...self::UNBLOCKS, ...self::REWRITES] : self::UNBLOCKS;
            $depends = [];

            foreach ($types as $type) {
                array_push($depends, ...($byScope[(string) $step['scope']['path']][$type] ?? []));
            }

            $steps[$index]['depends_on'] = $depends;
        }

        return $steps;
    }

    /**
     * @param  list<Step>  $steps
     * @param  array<int|string, mixed>  $previous
     * @return list<Step>
     */
    private static function merge(array $steps, array $previous): array
    {
        $old = [];

        foreach ($previous as $step) {
            if (is_array($step) && is_string($step['id'] ?? null)) {
                $old[$step['id']] = $step;
            }
        }

        $now = gmdate('c');

        foreach ($steps as $index => $step) {
            $before = $old[$step['id']] ?? null;
            unset($old[$step['id']]);

            if ($before === null) {
                continue;
            }

            $steps[$index]['history'] = is_array($before['history'] ?? null) ? $before['history'] : [];

            foreach (['decision', 'last_check'] as $key) {
                if (isset($before[$key])) {
                    $steps[$index][$key] = $before[$key];
                }
            }

            if (($before['status'] ?? null) === self::DONE) {
                // Una decisión tomada sigue tomada; un paso hecho cuyo
                // problema vuelve a salir se reabre.
                if ($step['category'] === Category::DECISION && isset($before['decision'])) {
                    continue;
                }

                $steps[$index]['history'][] = ['at' => $now, 'event' => 'reopened', 'detail' => 'El diagnóstico lo vuelve a encontrar.'];
            }
        }

        // Lo que ya no sale: hecho (por quien sea) o ya estaba hecho.
        foreach ($old as $step) {
            if (($step['status'] ?? null) !== self::DONE) {
                $step['status'] = self::DONE;
                $step['history'] = [...(is_array($step['history'] ?? null) ? $step['history'] : []),
                    ['at' => $now, 'event' => 'resolved', 'detail' => 'El diagnóstico ya no lo encuentra.']];
            }

            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * Ámbito => posición, del que más cumple al que menos: la proporción de
     * sus archivos que cumplen, y a igualdad, el que menos pasos guiados
     * tiene.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, int>
     */
    private static function compliance(array $report): array
    {
        $guided = [];

        foreach ([...self::listOf($report, 'files'), ...self::listOf($report, 'findings')] as $item) {
            if (($item['category'] ?? null) === Category::GUIDED) {
                $guided[(string) $item['scope']] = ($guided[(string) $item['scope']] ?? 0) + 1;
            }
        }

        $scores = [];

        foreach ((array) ($report['scopes'] ?? []) as $path => $scope) {
            $total = is_array($scope) ? (int) ($scope['total'] ?? 0) : 0;
            $ratio = $total === 0 ? 1.0 : (int) ($scope['conforming'] ?? 0) / $total;
            $scores[(string) $path] = [-$ratio, $guided[(string) $path] ?? 0, (string) $path];
        }

        foreach (array_keys($guided) as $path) {
            $scores[$path] ??= [-1.0, $guided[$path], $path];
        }

        uasort($scores, fn (array $a, array $b): int => $a <=> $b);

        return array_flip(array_map('strval', array_keys($scores)));
    }

    /**
     * El ámbito al que pertenece un archivo de un hallazgo: el más largo que
     * lo contiene.
     *
     * @param  array<string, mixed>  $report
     */
    private static function scopeOfFile(string $file, array $report): string
    {
        $best = '.';

        foreach (array_keys((array) ($report['scopes'] ?? [])) as $path) {
            $path = (string) $path;

            if ($path !== '.' && str_starts_with($file, $path.'/') && strlen($path) > strlen($best)) {
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function packageOfFindings(array $items): ?string
    {
        foreach ($items as $item) {
            $package = $item['finding']['package'] ?? ($item['unmanaged']['package'] ?? null);

            if (is_string($package)) {
                return $package;
            }
        }

        return null;
    }

    private static function describe(string $type, ?string $subject, int $count): string
    {
        $what = match ($type) {
            'relink' => 'archivos renombrados que el manifiesto conoce con otra ruta',
            'prune' => 'registros del manifiesto de archivos que ya no existen y el contrato no volvería a generar',
            'adopt' => 'archivos iguales a lo que generaría el contrato que el manifiesto da por editados o no conoce',
            'regenerate' => 'archivos sin editar que difieren de lo que genera hoy el contrato, o que el contrato pide y faltan',
            'fix' => "hallazgos de {$subject} que corrige `larapack:fix {$subject}`",
            'pin-foreign-keys' => 'llaves foráneas cuya acción hacen las migraciones y el contrato no declara',
            'endpoint-tests' => 'tests de endpoints a los que les faltan pruebas que trae hoy la plantilla',
            'model-lists' => 'modelos cuyas listas no tienen lo que declara el contrato',
            'controller-concern' => 'traits de Http/Controllers/Concerns que sólo usan requests',
            'edited-outside' => 'archivos generados editados fuera de sus huecos',
            'unmanaged-http' => 'controladores y archivos de rutas escritos a mano',
            'unmanaged-models' => 'modelos escritos a mano',
            'missing' => 'archivos generados que se borraron y el contrato sigue pidiendo',
            'untracked-edited' => 'archivos escritos a mano en la ruta de uno generado',
            'no-contract', 'contract-invalid' => 'el ámbito entero',
            default => 'hallazgos de verify',
        };

        return "{$count} ".($count === 1 ? 'elemento' : 'elementos').": {$what}.";
    }

    /**
     * @param  Step  $step
     */
    private static function fill(string $command, array $step): string
    {
        $package = $step['scope']['package'] ?? null;

        return strtr($command, [
            '{id}' => (string) $step['id'],
            '{check}' => (string) ($step['subject'] ?? ''),
            '{package}' => is_string($package) ? $package : 'app',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private static function listOf(array $report, string $key): array
    {
        return array_values(array_filter((array) ($report[$key] ?? []), 'is_array'));
    }
}
