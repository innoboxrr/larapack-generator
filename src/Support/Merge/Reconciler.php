<?php

namespace Innoboxrr\LarapackGenerator\Support\Merge;

use Innoboxrr\LarapackGenerator\Support\ContractFingerprint;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\MergeDrift;
use Innoboxrr\LarapackGenerator\Support\MergeMarkers;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Realign\Regeneration;

/**
 * Deja un proyecto coherente después de fusionar ramas: es lo que hace
 * `larapack:reconcile`.
 *
 * ────────────────────────────────────────────────────────────────────────
 * POR QUÉ: con varias consolas trabajando a la vez, cada rama es coherente y
 * la fusión no. La auditoría de enjambre de 9.4.0 midió que dos ramas que
 * tocan el mismo ámbito chocan casi siempre en `laraimport.json` y en
 * `.larapack/manifest.json`, y que resolverlo a mano produce —sin que nada
 * avise— un contrato que no es el de nadie, un manifiesto que miente y
 * archivos generados que son mezcla de dos versiones. Nada de eso necesita
 * a una persona: todo sale del contrato. Lo que sí la necesita (el código
 * escrito a mano que chocó, dos ramas que declararon lo mismo de forma
 * distinta) se le deja, nombrado.
 * ────────────────────────────────────────────────────────────────────────
 *
 * Por cada ámbito —la raíz y cada paquete local—, en este orden:
 *
 *  1. El contrato. Si `laraimport.json` quedó en conflicto (o fusionado pero
 *     inválido), se fusiona por nombres (ContractMerge). Si las dos ramas
 *     declararon lo mismo de forma distinta, se para ahí y se dice dónde.
 *  2. El manifiesto. Se recompone de los de las dos ramas (ManifestMerge);
 *     si el que hay se lee, se parte de él y sólo se le devuelve lo que
 *     perdió. Lo que las dos ramas regeneraron sin editar se deja marcado
 *     como no editado para que el paso 4 lo reescriba.
 *  3. Las migraciones repetidas. Dos migraciones generadas, nacidas en las
 *     ramas de esta fusión, que crean la misma tabla o añaden la misma
 *     columna se retiran: el paso 4 escribe una con lo que falte.
 *  4. `larapack:import --force` con el contrato fusionado.
 *
 * Nunca toca un archivo editado a mano, nunca comitea y, con `dryRun`, no
 * escribe. Correrla dos veces da lo mismo que correrla una.
 *
 * @phpstan-import-type Sides from GitMerge
 *
 * @phpstan-type ScopeReport array{scope: string, actions: list<string>, pending: list<string>, review: list<string>}
 */
final class Reconciler
{
    private ?GitMerge $git;

    /**
     * @var Sides|null
     */
    private ?array $sides;

    /**
     * @var list<string>
     */
    private array $unmerged;

    /**
     * Lo que se resolvió y hay que marcar como resuelto en el índice.
     *
     * @var array<string, true>
     */
    private array $resolved = [];

    /**
     * @param  (\Closure(string, list<string>): array{ok: bool, summary: string, error: string|null, written: list<string>})|null  $import  cómo se corre la importación de un ámbito; null, con el `builder` del paquete
     */
    public function __construct(
        private readonly string $root,
        private readonly bool $dryRun = false,
        private readonly bool $keepMigrations = false,
        private readonly ?\Closure $import = null,
    ) {
        $this->git = GitMerge::at($root);
        $this->sides = $this->git?->sides();
        $this->unmerged = $this->git?->unmerged() ?? [];
    }

    /**
     * @return array{ok: bool, merge: string|null, scopes: list<ScopeReport>}
     */
    public function run(): array
    {
        $scopes = [];

        foreach ((new ProjectVerification($this->root))->scopes() as $scope) {
            $report = $this->scope($scope['root'], $scope['path']);

            if ($report !== null) {
                $scopes[] = $report;
            }
        }

        // Lo que estaba en conflicto y se resolvió se marca como resuelto,
        // con lo que haya quedado después de importar.
        if (! $this->dryRun && $this->git !== null && ($this->sides['inProgress'] ?? false)) {
            $this->git->stage(array_values(array_filter(
                array_map('strval', array_keys($this->resolved)),
                fn (string $path): bool => in_array($path, $this->unmerged, true) && is_file($path)
            )));
        }

        return [
            'ok' => array_filter($scopes, fn (array $scope): bool => $scope['pending'] !== []) === [],
            'merge' => $this->sides === null ? null : $this->sides['kind'].($this->sides['inProgress'] ? ' en curso' : ' ya comiteado'),
            'scopes' => $scopes,
        ];
    }

    /**
     * @return ScopeReport|null null si en el ámbito no hay nada de LaraPack
     */
    private function scope(string $root, string $path): ?array
    {
        $contract = $root.'/laraimport.json';
        $manifestPath = $root.'/'.Manifest::RELATIVE_PATH;

        if (! is_file($contract) && ! is_file($manifestPath)) {
            return null;
        }

        $report = ['scope' => $path, 'actions' => [], 'pending' => [], 'review' => []];

        $contractOk = $this->contract($contract, $report);
        $manifestOk = $this->manifest($root, $report);

        if ($manifestOk) {
            $this->migrations($root, $report);
        }

        if ($contractOk && $manifestOk) {
            // Sólo si hace falta: algo se recompuso en este ámbito, o lo
            // generado no corresponde al contrato que hay (la misma huella
            // que mira `verify`). Una fusión que no tocó el ámbito no paga
            // una importación.
            if ($report['actions'] !== [] || $this->outdated($root)) {
                $this->import($root, $report);
            }
        } elseif (is_file($contract)) {
            $report['pending'][] = 'No se ha importado: antes hay que resolver lo de arriba y volver a correr `larapack:reconcile`.';
        }

        if (! $this->dryRun || $manifestOk) {
            foreach ($this->conflicts($root, $manifestOk) as $file) {
                $report['pending'][] = match (true) {
                    // El estado del realineamiento lleva fechas y el id del
                    // diagnóstico: dos ramas que diagnostican chocan siempre.
                    str_starts_with($file, '.larapack/realign/') => "{$file} sigue con un conflicto de git: el realineamiento de un ámbito lo lleva una sola rama. Quédate con el de esa rama (`git checkout --ours|--theirs -- {$file}`) y vuelve a correr `larapack:realign diagnose` y `plan`.",
                    $file === HandRolledLock::PATH => "{$file} sigue con un conflicto de git: son aprobaciones del dueño del proyecto, y las fusiona él (cada entrada es de una excepción; valen las de las dos ramas).",
                    default => "{$file} sigue con un conflicto de git: es código escrito a mano (o editado), y lo resuelve quien lo escribió. Después, `larapack:verify --strict`.",
                };
            }
        }

        return $report;
    }

    /**
     * @param  ScopeReport  $report
     */
    private function contract(string $file, array &$report): bool
    {
        if (! is_file($file)) {
            return false;
        }

        $content = Files::read($file);
        // Por los marcadores y no por el índice: uno que una persona ya
        // resolvió a mano sigue «sin fusionar» para git hasta el `git add`.
        $conflicted = MergeMarkers::in($content);

        if (! $conflicted && ImportDocument::fromFile($file)['document'] !== null) {
            $this->resolved[$file] = true;

            return true;
        }

        if ($this->sides === null || $this->git === null) {
            $report['pending'][] = $conflicted
                ? 'laraimport.json tiene un conflicto de git y no hay una fusión en curso de la que recomponerlo: resuélvelo a mano y valida con `larapack:validate`.'
                : 'laraimport.json no es válido (`larapack:validate` dice por qué).';

            return false;
        }

        $ours = $this->git->show($this->sides['ours'], $file);
        $theirs = $this->git->show($this->sides['theirs'], $file);

        if ($ours === null || $theirs === null) {
            $report['pending'][] = 'laraimport.json no es válido y sólo existe en una de las dos ramas: no hay nada que fusionar (`larapack:validate` dice por qué).';

            return false;
        }

        $merge = ContractMerge::mergeJson($this->git->show($this->sides['base'], $file), $ours, $theirs);

        if ($merge['json'] === null) {
            $report['pending'][] = 'laraimport.json no se pudo fusionar: '.$merge['error'].'.';

            return false;
        }

        if ($merge['conflicts'] !== []) {
            foreach ($merge['conflicts'] as $conflict) {
                $report['pending'][] = sprintf(
                    'laraimport.json %s: las dos ramas lo declararon distinto (nuestra: %s; la otra: %s). Decide cuál vale, déjalo así en laraimport.json y vuelve a correr `larapack:reconcile`.',
                    $conflict['path'],
                    self::short($conflict['ours']),
                    self::short($conflict['theirs']),
                );
            }

            return false;
        }

        $errors = array_filter(
            ImportDocument::fromArray((array) json_decode($merge['json'], true))['errors'],
            fn (array $error): bool => $error['level'] === 'error'
        );

        if ($errors !== []) {
            foreach ($errors as $error) {
                $report['pending'][] = "laraimport.json fusionado por nombres no es válido: {$error['path']} {$error['message']}";
            }

            return false;
        }

        $report['actions'][] = 'laraimport.json: fusionado por nombres (modelos, columnas, endpoints) a partir de las dos ramas.';

        if (! $this->dryRun) {
            file_put_contents($file, $merge['json']);
            $this->resolved[$file] = true;
        }

        return true;
    }

    /**
     * @param  ScopeReport  $report
     */
    private function manifest(string $root, array &$report): bool
    {
        $file = $root.'/'.Manifest::RELATIVE_PATH;
        $manifest = new Manifest($root);
        $readable = ! in_array($file, $this->unmerged, true) && $manifest->unreadable() === null;
        $current = $readable && is_file($file) ? $manifest->read() : null;

        if ($this->sides === null || $this->git === null) {
            if (! $readable) {
                $report['pending'][] = Manifest::RELATIVE_PATH.' no se puede leer y no hay una fusión de la que recomponerlo: recupéralo de git (`git checkout <commit> -- '.Manifest::RELATIVE_PATH.'`) y corre `larapack:import --force`.';
            }

            return $readable;
        }

        $git = $this->git;
        $sides = $this->sides;

        $decode = function (?string $commit) use ($file, $git): ?array {
            $decoded = json_decode((string) $git->show($commit, $file), true);

            return is_array($decoded) && isset($decoded['models']) ? $decoded : null;
        };

        $ours = $decode($sides['ours']);
        $theirs = $decode($sides['theirs']);

        $contentAt = fn (string $side, string $relative): ?string => $git->show($side === 'theirs' ? $sides['theirs'] : $sides['ours'], $root.'/'.$relative);
        $merge = ManifestMerge::merge($decode($sides['base']), $ours, $theirs, $contentAt);
        $merged = $merge['manifest'];

        if ($merged === null) {
            return $readable;
        }

        foreach ($merge['review'] as $review) {
            $report['review'][] = "{$review['file']}: {$review['reason']}.";
        }

        if ($current === null) {
            $result = $merged;
            $report['actions'][] = Manifest::RELATIVE_PATH.': recompuesto a partir de los manifiestos de las dos ramas.';
        } else {
            $result = $current;
            $restored = self::restore($result, $merged, $root);

            if ($restored > 0) {
                $report['actions'][] = Manifest::RELATIVE_PATH.": devueltos {$restored} registros que estaban en una de las ramas y se perdieron al fusionar.";
            }
        }

        $regenerated = 0;

        foreach ($merge['regenerate'] as $relative) {
            $regenerated += (int) $this->settle($result, $root, $relative, $contentAt, $report);
        }

        if ($regenerated > 0) {
            $report['actions'][] = "{$regenerated} archivos que las dos ramas regeneraron sin editar quedan marcados para regenerarse con el contrato fusionado.";
        }

        if (! $this->dryRun && ($current === null || $result !== $current)) {
            $manifest->replace($result);
            $this->resolved[$file] = true;
        }

        return true;
    }

    /**
     * Añade a `$result` lo que `$merged` registra, `$result` no y está en
     * disco: lo que se pierde al resolver el conflicto del manifiesto
     * quedándose con un lado.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $merged
     */
    private static function restore(array &$result, array $merged, string $root): int
    {
        $known = [];

        foreach ([$result['package'] ?? [], ...array_values($result['models'] ?? [])] as $bucket) {
            $known += is_array($bucket['files'] ?? null) ? $bucket['files'] : [];
        }

        $restored = 0;

        foreach (is_array($merged['package']['files'] ?? null) ? $merged['package']['files'] : [] as $relative => $entry) {
            if (! isset($known[$relative]) && is_file($root.'/'.$relative)) {
                $result['package']['files'][$relative] = $entry;
                $restored++;
            }
        }

        foreach (is_array($merged['models'] ?? null) ? $merged['models'] : [] as $model => $bucket) {
            foreach (is_array($bucket['files'] ?? null) ? $bucket['files'] : [] as $relative => $entry) {
                if (isset($known[$relative]) || ! is_file($root.'/'.$relative)) {
                    continue;
                }

                $result['models'][$model] ??= array_diff_key($bucket, ['files' => true]);
                $result['models'][$model]['files'][$relative] = $entry;
                $restored++;
            }
        }

        return $restored;
    }

    /**
     * Un archivo que las dos ramas regeneraron, cada una con su contrato, y
     * ninguna editó: lo deja registrado como «tal como salió del generador»
     * para que la importación lo reescriba.
     *
     * Sólo si lo que hay en disco es de verdad lo que dejó la fusión: el
     * conflicto sin resolver, la versión de una de las ramas o la mezcla
     * limpia que hizo git. Si alguien lo tocó después, lleva su trabajo y se
     * queda como editado.
     *
     * @param  array<string, mixed>  $result
     * @param  \Closure(string, string): (string|null)  $contentAt
     * @param  ScopeReport  $report
     */
    private function settle(array &$result, string $root, string $relative, \Closure $contentAt, array &$report): bool
    {
        $absolute = $root.'/'.$relative;

        if (! is_file($absolute)) {
            return false;
        }

        $disk = Files::read($absolute);
        $ours = (string) $contentAt('ours', $relative);
        $theirs = (string) $contentAt('theirs', $relative);
        $normal = fn (string $content): string => str_replace("\r\n", "\n", $content);

        if (in_array($absolute, $this->unmerged, true) || MergeMarkers::in($disk)) {
            // El conflicto de un archivo que es todo del generador no se
            // resuelve: se deja la versión nuestra y se regenera.
            if (! $this->dryRun) {
                file_put_contents($absolute, $ours);
                $this->resolved[$absolute] = true;
            }

            $disk = $ours;
        } elseif ($normal($disk) !== $normal($ours) && $normal($disk) !== $normal($theirs)) {
            $base = $this->git?->show($this->sides['base'] ?? null, $absolute);
            $clean = $this->git?->cleanMerge($ours, $base, $theirs);

            if ($clean === null || $normal($clean) !== $normal($disk)) {
                $report['review'][] = "{$relative}: las dos ramas lo regeneraron y después de la fusión alguien lo editó: se conserva como editado; comprueba que lleve lo que el contrato fusionado pide.";

                return false;
            }
        }

        return self::rehash($result, $relative, Manifest::hashOf($disk));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function rehash(array &$manifest, string $relative, string $hash): bool
    {
        $found = false;

        if (isset($manifest['package']['files'][$relative])) {
            $manifest['package']['files'][$relative]['hash'] = $hash;
            $found = true;
        }

        foreach (array_keys(is_array($manifest['models'] ?? null) ? $manifest['models'] : []) as $model) {
            if (isset($manifest['models'][$model]['files'][$relative])) {
                $manifest['models'][$model]['files'][$relative]['hash'] = $hash;
                $found = true;
            }
        }

        return $found;
    }

    /**
     * Retira las migraciones repetidas que trae la rama que entra. Tienen que
     * cumplir las tres cosas: generadas (están en el manifiesto), sin editar
     * (su hash es el registrado) y nuevas en la rama que entra (ni el
     * ancestro común ni nuestra rama las tenían, así que nada que parta de
     * esta rama las ha corrido). La importación que sigue escribe una con lo
     * que al historial le falte para llegar al contrato fusionado.
     *
     * @param  ScopeReport  $report
     */
    private function migrations(string $root, array &$report): void
    {
        $manifest = new Manifest($root);

        // En un ensayo el manifiesto recompuesto no se escribió: si el de
        // disco no se lee, no se puede decir qué migración es generada.
        if ($manifest->unreadable() !== null) {
            return;
        }

        $collisions = (new MergeDrift($root, $manifest->snapshot()))->migrationCollisions();

        if ($collisions === []) {
            return;
        }

        // Sólo las que trae la rama que entra: las nuestras ya estaban en
        // esta rama antes de fusionar, y puede que ya hayan corrido.
        $retirable = fn (string $file): bool => $this->sides !== null && $this->git !== null && ! $this->keepMigrations
            && $manifest->knows($file) && ! $manifest->wasCustomised($file)
            && ! $this->git->has($this->sides['base'], $file) && ! $this->git->has($this->sides['ours'], $file);

        $retired = [];

        foreach ($collisions as $collision) {
            $incoming = array_filter([$collision['previous'], $collision['file']], $retirable);

            if ($incoming === []) {
                $report['pending'][] = 'database/migrations/'.basename($collision['file']).' '.$collision['what'].', que ya '.$collision['verb'].' '.basename($collision['previous'])
                    .': no se retira sola (está editada, no la generó LaraPack, ya estaba en esta rama antes de la fusión o se pidió --keep-migrations). Decide cuál se queda: las dos juntas tumban `migrate`.';

                continue;
            }

            foreach ($incoming as $file) {
                $retired[$file] = true;
            }
        }

        foreach (array_keys($retired) as $file) {
            $report['actions'][] = 'database/migrations/'.basename($file).': retirada (venía de la rama que entra y repetía lo que ya hace otra; la importación escribe lo que falte). Si ya corrió en alguna base, haz ahí `migrate:rollback` antes de traer este cambio.';

            if (! $this->dryRun) {
                $manifest->drop($file);
                $this->git?->remove($file);
            }
        }
    }

    /**
     * Si lo generado en el ámbito no corresponde a su laraimport: alguna
     * pieza sin generar o con otra huella.
     */
    private function outdated(string $root): bool
    {
        $declared = ContractFingerprint::ofFile($root.'/laraimport.json');

        if ($declared === null) {
            return false;
        }

        $manifest = (new Manifest($root))->snapshot();

        // Como `verify` (contract-outdated): un manifiesto que no anota
        // huellas no se puede comparar, y un modelo sin huella tampoco.
        if (! $manifest->tracksContract()) {
            return false;
        }

        $known = $manifest->models();

        foreach ($declared['models'] as $model => $fingerprint) {
            $recorded = $manifest->contractOf($model);

            if (! in_array($model, $known, true) || ($recorded !== null && ! ContractFingerprint::matches($recorded, $fingerprint))) {
                return true;
            }
        }

        $pivots = $manifest->pivotContracts();

        foreach ($declared['pivots'] as $pivot => $fingerprint) {
            if (! ContractFingerprint::matches($pivots[$pivot] ?? null, $fingerprint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  ScopeReport  $report
     */
    private function import(string $root, array &$report): void
    {
        if ($this->dryRun) {
            $report['actions'][] = 'larapack:import --force: se correría con el contrato fusionado (aquí no: es un ensayo).';

            return;
        }

        $ui = is_file($root.'/'.Manifest::RELATIVE_PATH) ? Regeneration::uiOf($root) : [];
        $outcome = $this->import === null ? self::builder($root, $ui) : ($this->import)($root, $ui);

        if (! $outcome['ok']) {
            $report['pending'][] = 'larapack:import --force falló: '.($outcome['error'] ?? 'sin detalle').'.';

            return;
        }

        $report['actions'][] = 'larapack:import --force'.implode('', array_map(fn (string $framework): string => ' --'.$framework, $ui)).': '.$outcome['summary'].'.';

        // Lo que se regeneró dentro de la fusión entra en ella con lo que
        // quedó después de regenerar.
        foreach ($outcome['written'] as $written) {
            $this->resolved[$written] = true;
        }
    }

    /**
     * @param  list<string>  $ui
     * @return array{ok: bool, summary: string, error: string|null, written: list<string>}
     */
    private static function builder(string $root, array $ui): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 3).'/builder', 'larapack:import', $root.'/laraimport.json',
            '--root='.$root, '--force', '--format=json', ...array_map(fn (string $framework): string => '--'.$framework, $ui)];

        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);

        if (! is_resource($process)) {
            return ['ok' => false, 'summary' => '', 'error' => 'no se pudo arrancar el generador', 'written' => []];
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $code = proc_close($process);
        $document = json_decode($output, true);

        if ($code !== 0 || ! is_array($document) || ($document['ok'] ?? false) !== true) {
            return ['ok' => false, 'summary' => '', 'error' => is_array($document) && is_string($document['error'] ?? null) ? $document['error'] : ($errors !== '' ? $errors : "terminó con código {$code}"), 'written' => []];
        }

        $summary = is_array($document['summary'] ?? null) ? $document['summary'] : [];
        $parts = [];

        foreach (['create' => 'creados', 'overwrite' => 'regenerados', 'preserved' => 'conservados por estar editados a mano'] as $key => $label) {
            $parts[] = (int) ($summary[$key] ?? 0).' '.$label;
        }

        $written = [];

        foreach (is_array($document['files'] ?? null) ? $document['files'] : [] as $entry) {
            if (is_array($entry) && in_array($entry['action'] ?? null, ['create', 'overwrite'], true) && is_string($entry['file'] ?? null)) {
                $written[] = str_starts_with($entry['file'], '/') ? $entry['file'] : $root.'/'.$entry['file'];
            }
        }

        return ['ok' => true, 'summary' => implode(', ', $parts), 'error' => null, 'written' => $written];
    }

    /**
     * Los archivos del ámbito que siguen con un conflicto de git.
     *
     * @return list<string>
     */
    private function conflicts(string $root, bool $manifestOk): array
    {
        $manifest = new Manifest($root);
        $readable = $manifestOk && $manifest->unreadable() === null;

        return array_values(array_filter(array_map(
            fn (array $finding): string => (string) $finding['file'],
            (new MergeDrift($root, $readable ? $manifest->snapshot() : null))->conflicts()
        ), fn (string $file): bool => $file !== 'laraimport.json' && $file !== Manifest::RELATIVE_PATH));
    }

    private static function short(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return mb_strlen($json) > 80 ? mb_substr($json, 0, 77).'…' : $json;
    }
}
