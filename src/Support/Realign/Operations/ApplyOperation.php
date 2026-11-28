<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign\Operations;

use Innoboxrr\LarapackGenerator\Exceptions\MakerException;
use Innoboxrr\LarapackGenerator\Support\ContractFingerprint;
use Innoboxrr\LarapackGenerator\Support\Files;
use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\LegacyFix;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\ModelLists;
use Innoboxrr\LarapackGenerator\Support\ModelRegions;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\Changes;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\ConcernMove;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\EndpointTests;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\ForeignKeyPins;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Innoboxrr\LarapackGenerator\Support\UnifiedDiff;

/**
 * `larapack:realign apply`: los pasos deterministas que reescriben código o
 * el contrato, uno a uno (`apply <id>…`) o todos (`apply --deterministic`).
 *
 *  - `regenerate`: lo que nadie editó y quedó atrás (`version_delta`) o lo
 *    que el contrato pide y nunca se generó, copiado de lo regenerado —que
 *    ya pasó por la Pint del proyecto— y registrado con la versión actual.
 *    Es un `import --force` acotado a lo que es seguro: nunca toca un
 *    archivo editado, nunca una migración, nunca nada de un modelo que no se
 *    haya importado (su migración la escribe `larapack:import`).
 *  - `missing`, si se decidió `restore`: lo generado que se borró, igual.
 *  - `fix`: las correcciones de `larapack:fix` (LegacyFix), las mismas.
 *  - `endpoint-tests`: añade a cada `<Modelo>EndpointsTest` las pruebas que
 *    trae hoy la plantilla (EndpointTests), conservando lo escrito a mano.
 *  - `controller-concern`: mueve a `Http/Requests/Concerns` los traits de
 *    `Http/Controllers/Concerns` que sólo usan requests, con sus `use`
 *    (ConcernMove).
 *  - `model-lists`: completa las listas de un modelo editado con lo que
 *    declara el contrato (ModelRegions, lo mismo que hace `import --force`
 *    con un modelo editado): sólo añade.
 *  - `pin-foreign-keys`: escribe en el contrato la acción de cada llave que
 *    hacen las migraciones y el contrato no dice (ForeignKeyPins).
 *  - Y, con --deterministic o nombrándolos, los pasos de `adopt`
 *    (`relink`, `prune`, `adopt`), que delega en AdoptOperation.
 *
 * Cada archivo (o cada cambio que va junto: el trait y sus referencias) se
 * escribe entero o no se escribe (Changes): si después de escribirlo un PHP
 * no parsea, se devuelve todo a como estaba y se salta con la razón. Lo que
 * no se reconoce con certeza se salta, y el paso sigue pendiente: lo que
 * queda lo dice su playbook (`larapack:realign next`).
 */
final class ApplyOperation implements RealignOperation
{
    private const MIGRATION = '#(^|/)database/migrations/[^/]+\.php$#';

    /**
     * Los tipos que hace adopt y apply también ejecuta.
     */
    private const ADOPTS = ['relink', 'prune', 'adopt'];

    /**
     * @var array<string, ImportDocument|string>
     */
    private array $documents = [];

    /**
     * Las comprobaciones de `fix` que ya se corrieron en esta corrida.
     *
     * @var array<string, true>
     */
    private array $fixed = [];

    public function name(): string
    {
        return 'apply';
    }

    public function description(): string
    {
        return Operations::DESCRIPTIONS['apply'];
    }

    public function handles(): array
    {
        return [...self::ADOPTS, 'regenerate', 'missing', 'fix', 'controller-concern', 'model-lists', 'endpoint-tests', 'pin-foreign-keys'];
    }

    public function run(OperationContext $context): OperationResult
    {
        $result = new OperationResult;

        foreach ($context->steps as $step) {
            $type = (string) $step['type'];

            if (in_array($type, self::ADOPTS, true)) {
                $result->merge((new AdoptOperation)->run(new OperationContext($context->root, $context->state, [$step], $context->dryRun, $context->options)));

                continue;
            }

            $reference = ScopeReference::for($context->root, (string) ($step['scope']['path'] ?? '.'), $context->state);

            if ($reference === null) {
                $result->skipped(null, "{$step['id']}: su ámbito ya no existe.", ['step' => $step['id']]);

                continue;
            }

            try {
                match ($type) {
                    'regenerate' => $this->regenerate($step, $reference, $context, $result),
                    'missing' => $this->restore($step, $reference, $context, $result),
                    'fix' => $this->fix($step, $context, $result),
                    'endpoint-tests' => $this->endpointTests($step, $reference, $context, $result),
                    'controller-concern' => $this->concerns($step, $context, $result),
                    'model-lists' => $this->modelLists($step, $reference, $context, $result),
                    'pin-foreign-keys' => $this->pins($step, $reference, $context, $result),
                    default => $result->skipped(null, "{$step['id']}: apply no sabe hacer pasos {$type}.", ['step' => $step['id']]),
                };
            } catch (MakerException|\RuntimeException $e) {
                // Un paso que revienta no se lleva a los demás: lo que ya
                // escribió es un cambio entero (Changes), se dice y se sigue.
                $result->skipped(null, "{$step['id']}: se interrumpió ({$e->getMessage()}). Lo que escribió antes queda; vuelve a diagnosticar.", ['step' => $step['id']]);
            }
        }

        ConcernMove::reset();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function regenerate(array $step, ScopeReference $reference, OperationContext $context, OperationResult $result): void
    {
        if (! $reference->hasReference()) {
            $result->skipped(null, "{$step['id']}: no se pudo regenerar el contrato para copiar de él; corre `larapack:realign diagnose`.", ['step' => $step['id']]);

            return;
        }

        $models = $reference->manifest()->models();

        foreach ($this->files($step) as $file) {
            $entry = $reference->classify($file);
            $extra = ['step' => $step['id']];

            if (in_array($entry['class'], [FileClass::IDENTICAL, FileClass::FORMAT_ONLY], true) && $entry['tracked'] && $entry['hash_current'] === true) {
                $result->unchanged($file, 'Ya es lo que genera el contrato.', $extra);

                continue;
            }

            if ($entry['class'] === FileClass::VERSION_DELTA && $entry['tracked'] && $entry['hash_current'] === true) {
                $this->copy($reference, $file, $context, $result, $extra, 'Regenerado: nadie lo había editado y '.(($entry['cause'] ?? null) === 'generator' ? 'la plantilla cambió' : 'el contrato cambió').'.');

                continue;
            }

            if ($entry['class'] === FileClass::MISSING && ! $entry['tracked'] && ! isset($entry['cause'])) {
                $produced = $reference->producedBy($reference->relative($file));

                if ($produced !== null && $produced['model'] !== '' && ! in_array($produced['model'], $models, true)) {
                    $result->skipped($file, "El modelo {$produced['model']} nunca se importó: `larapack:import` (sin --force) lo crea entero, con su migración.", $extra);

                    continue;
                }

                $this->copy($reference, $file, $context, $result, $extra, 'Creado: el contrato lo pide y nunca se generó.');

                continue;
            }

            $result->skipped($file, "Ya no es lo que se regenera sin perder nada: es {$entry['class']} ({$entry['reason']}).", $extra);
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function restore(array $step, ScopeReference $reference, OperationContext $context, OperationResult $result): void
    {
        $chosen = $step['decision']['option'] ?? null;

        if ($chosen !== 'restore') {
            $result->skipped(null, $chosen === null
                ? "{$step['id']}: falta decidir (larapack:realign decide {$step['id']} restore)."
                : "{$step['id']}: se decidió `{$chosen}`, no restaurarlos.", ['step' => $step['id']]);

            return;
        }

        foreach ($this->files($step) as $file) {
            $entry = $reference->classify($file);
            $extra = ['step' => $step['id']];

            match (true) {
                is_file($reference->absolute($file)) => $result->unchanged($file, 'Ya está en disco.', $extra),
                $entry['class'] === FileClass::MISSING && ($entry['cause'] ?? null) === 'deleted' => $this->copy($reference, $file, $context, $result, $extra, 'Restaurado desde el contrato.'),
                default => $result->skipped($file, "No se restaura: es {$entry['class']} ({$entry['reason']}).", $extra),
            };
        }
    }

    /**
     * Copia lo regenerado a su sitio y lo registra como generado con la
     * versión actual.
     *
     * @param  array<string, mixed>  $extra
     */
    private function copy(ScopeReference $reference, string $file, OperationContext $context, OperationResult $result, array $extra, string $reason): void
    {
        $relative = $reference->relative($file);
        $source = $reference->regeneratedFile($relative);
        $absolute = $reference->absolute($file);

        if (preg_match(self::MIGRATION, $file) === 1) {
            $result->skipped($file, 'Una migración no se escribe nunca desde aquí: la escribe `larapack:import`, que lee todas las que hay.', $extra);

            return;
        }

        if ($source === null) {
            $result->skipped($file, 'Lo regenerado no lo trae.', $extra);

            return;
        }

        $content = Files::read($source);

        if ($context->dryRun()) {
            $result->fixed($file, $reason, $extra + ['diff' => UnifiedDiff::of(is_file($absolute) ? Files::read($absolute) : '', $content, $file)]);

            return;
        }

        $changes = new Changes($context->root);
        $changes->put($absolute, $content);
        $problem = $changes->commit();

        if ($problem !== null) {
            $result->skipped($file, $problem, $extra);

            return;
        }

        $manifest = $reference->manifest();
        $tracked = $manifest->entryOf($absolute);
        $produced = $reference->producedBy($relative);
        $model = $tracked['model'] ?? ($produced['model'] ?? '');

        $manifest->record($model, (string) ($manifest->namespaceOf($model) ?? ($produced['namespace'] ?? '')), $absolute, $produced['stub'] ?? ($tracked['stub'] ?? ''));
        $result->fixed($file, $reason, $extra + ($context->simulation ? ['diff' => $changes->appliedDiff()] : []));
    }

    /**
     * Todos los pasos `fix` de una misma comprobación en una sola corrida de
     * LegacyFix, acotada a sus paquetes: LegacyFix lee el proyecto entero al
     * planificar, y en school-management son 135 pasos `fix` en 82 ámbitos.
     * Lo que hace cada archivo se anota en el paso de su ámbito.
     *
     * @param  array<string, mixed>  $step
     */
    private function fix(array $step, OperationContext $context, OperationResult $result): void
    {
        $check = (string) ($step['subject'] ?? '');

        if (isset($this->fixed[$check])) {
            return;
        }

        $this->fixed[$check] = true;

        if (! in_array($check, LegacyFix::CHECKS, true)) {
            $result->skipped(null, "{$step['id']}: `larapack:fix` no sabe corregir {$check}.", ['step' => $step['id']]);

            return;
        }

        // Ruta del ámbito => id del paso, de los pasos de esta comprobación.
        $steps = [];
        $roots = [];

        foreach ($context->steps as $other) {
            if (($other['type'] ?? null) === 'fix' && ($other['subject'] ?? null) === $check) {
                $path = (string) ($other['scope']['path'] ?? '.');
                $reference = ScopeReference::for($context->root, $path, $context->state);

                if ($reference !== null) {
                    $steps[$path] = (string) $other['id'];
                    $roots[] = $reference->scope['root'];
                }
            }
        }

        $stepOf = function (?string $file) use ($steps): string {
            $best = '.';

            foreach (array_keys($steps) as $path) {
                $path = (string) $path;

                if ($path !== '.' && $file !== null && str_starts_with($file, $path.'/') && strlen($path) > strlen($best)) {
                    $best = $path;
                }
            }

            return $steps[$best] ?? (string) reset($steps);
        };

        $fix = new LegacyFix($context->root, [$check], null, array_values(array_unique($roots)));
        $fix->plan();
        $before = [];

        if ($context->simulation) {
            // En la copia se escribe de verdad: el diff es el de lo que quedó.
            foreach (($fix->withDiffs()[$check]['fixed'] ?? []) as $planned) {
                $absolute = $context->root.'/'.$planned['file'];
                $before[$planned['file']] = is_file($absolute) ? Files::read($absolute) : '';
            }
        }

        $report = $context->dryRun() ? $fix->withDiffs() : $fix->apply()['report'];
        $entry = $report[$check] ?? ['fixed' => [], 'skipped' => [], 'unchanged' => 0];

        foreach ($entry['fixed'] as $fixed) {
            $diff = isset($fixed['diff']) ? ['diff' => $fixed['diff']] : [];

            if (isset($before[$fixed['file']])) {
                $absolute = $context->root.'/'.$fixed['file'];
                $diff = ['diff' => UnifiedDiff::of($before[$fixed['file']], is_file($absolute) ? Files::read($absolute) : '', $fixed['file'])];
            }

            $result->fixed($fixed['file'], "larapack:fix {$check}.", ['step' => $stepOf($fixed['file'])] + $diff);
        }

        foreach ($entry['skipped'] as $skipped) {
            $step = $stepOf($skipped['file']);
            $owner = $this->ownerOf($check, $skipped, $step, $context);

            // Lo que `larapack:fix` salta y no es de este paso (en
            // route-closure, un closure que no es el fallback de la SPA) lo
            // resuelve otro paso del plan, una decisión: no es trabajo que
            // le quede a éste, y el paso puede quedar hecho sin él. Se dice
            // a cuál le toca. Si el plan no tiene ese paso, queda saltado y
            // el paso, a medias.
            $owner === null
                ? $result->skipped($skipped['file'], $skipped['reason'], ['step' => $step])
                : $result->unchanged($skipped['file'], "No es de este paso: lo resuelve {$owner} ({$skipped['reason']})", ['step' => $step, 'deferred_to' => $owner]);
        }

        if ($entry['unchanged'] > 0) {
            $result->unchanged(null, "{$check}: {$entry['unchanged']} ya estaban bien.", ['steps' => array_values($steps)]);
        }

        if (($entry['admin_path_missing'] ?? []) !== []) {
            $result->note(sprintf('%d ManagedFilter sin camino para el administrador (heurística: revísalos con `larapack:fix index-managed --dry-run`).', count($entry['admin_path_missing'])));
        }

        if ($check === 'resource-appends' && $entry['fixed'] !== []) {
            $result->note('Los Resources arreglados ignoran `appends[]` hasta que declaras en $appendable los accessors que pide la interfaz (`larapack:fix resource-appends --dry-run` los propone).');
        }
    }

    /**
     * El id del paso del plan que resuelve lo que `larapack:fix` saltó, si
     * es de otro tipo que `fix` (StepTypes::forFinding con el mensaje de
     * verify, que es la razón que da LegacyFix) y el plan lo tiene en el
     * mismo ámbito que el paso `fix`; null si es de este paso.
     *
     * @param  array{file: string, reason: string}  $skipped
     */
    private function ownerOf(string $check, array $skipped, string $stepId, OperationContext $context): ?string
    {
        $type = StepTypes::forFinding(['check' => $check, 'message' => $skipped['reason']]);

        if ($type === null || $type === 'fix') {
            return null;
        }

        $steps = Planner::byId($context->state->plan());
        $scope = $steps[$stepId]['scope']['path'] ?? null;

        foreach ($steps as $id => $step) {
            if (($step['type'] ?? null) === $type && ($step['scope']['path'] ?? null) === $scope) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function endpointTests(array $step, ScopeReference $reference, OperationContext $context, OperationResult $result): void
    {
        foreach ($this->findings($step, 'endpoint-tests-outdated') as $finding) {
            $file = (string) $finding['file'];
            $extra = ['step' => $step['id']];
            $absolute = $reference->absolute($file);
            $regenerated = $reference->regeneratedFile($reference->relative($file));

            if (! is_file($absolute)) {
                $result->skipped($file, 'Ya no existe: `larapack:import` (sin --force) lo crea con todas sus pruebas.', $extra);

                continue;
            }

            if ($regenerated === null) {
                $result->skipped($file, 'Lo regenerado no trae este test (el contrato ya no le da acciones o lo declara como controlador): no hay de dónde copiar.', $extra);

                continue;
            }

            $current = Files::read($absolute);
            $completion = EndpointTests::complete($current, Files::read($regenerated));

            if (is_string($completion)) {
                $result->skipped($file, $completion, $extra);

                continue;
            }

            if ($completion['added'] === []) {
                $result->unchanged($file, 'Ya tiene las pruebas que trae la plantilla.', $extra);

                continue;
            }

            $reason = 'Añadidas: '.implode(', ', $completion['added']).($completion['imports'] === [] ? '' : '; con '.implode(' ', $completion['imports'])).'. Lo demás del test no se toca.';
            $this->write([$absolute => $completion['content']], $context, $result, $file, $extra, $reason, true, $reference->manifest());
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function concerns(array $step, OperationContext $context, OperationResult $result): void
    {
        foreach ($this->findings($step, 'controller-concern') as $finding) {
            $file = (string) $finding['file'];
            $extra = ['step' => $step['id']];

            if (StepTypes::forFinding(['check' => 'controller-concern', 'message' => (string) $finding['message']]) !== 'controller-concern') {
                $result->skipped($file, 'Lo usa un controlador: no basta con moverlo, la lógica va a la request (controller-shape).', $extra);

                continue;
            }

            $move = new ConcernMove($context->root);
            $plan = $move->plan($file);

            if (is_string($plan)) {
                $result->skipped($file, $plan, $extra);

                continue;
            }

            $extra += ['to' => $plan['to'], 'references' => $plan['references']];
            $reason = "Movido a {$plan['to']}".($plan['references'] === [] ? '' : '; actualizados sus `use` en '.count($plan['references']).' '.(count($plan['references']) === 1 ? 'archivo' : 'archivos')).'.';

            if ($context->dryRun()) {
                $result->fixed($file, $reason, $extra + ['diff' => $plan['changes']->diff()]);

                continue;
            }

            // Escribe y comprueba en todo el proyecto que nada siga nombrando
            // el trait viejo; si algo queda, lo devuelve todo.
            $problem = $move->commit($plan);

            if ($problem !== null) {
                $result->skipped($file, $problem, $extra);

                continue;
            }

            if ($context->simulation) {
                $extra += ['diff' => $plan['changes']->appliedDiff()];
            }

            // Si alguien lo registró, el registro sigue al archivo.
            $owner = ScopeReference::ownerOf($context->root, $file);

            if ($owner !== null && $owner->knows($context->root.'/'.$file)) {
                $owner->relink($context->root.'/'.$file, $context->root.'/'.$plan['to']);
            }

            $result->fixed($file, $reason, $extra);
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function modelLists(array $step, ScopeReference $reference, OperationContext $context, OperationResult $result): void
    {
        $document = $this->document($reference->scope['root']);

        foreach ($this->findings($step, 'model-lists-outdated') as $finding) {
            $file = (string) $finding['file'];
            $model = (string) ($finding['model'] ?? '');
            $extra = ['step' => $step['id']];

            if (is_string($document)) {
                $result->skipped($file, $document, $extra);

                continue;
            }

            $absolute = $reference->absolute($file);

            try {
                $declared = $document->model($model);
            } catch (MakerException) {
                $result->skipped($file, "El contrato ya no declara {$model}.", $extra);

                continue;
            }

            if (! is_file($absolute)) {
                $result->skipped($file, 'Ya no existe.', $extra);

                continue;
            }

            $sync = ModelRegions::sync(Files::read($absolute), ModelLists::expected($declared), $reference->relative($file));

            foreach ($sync['skipped'] as $skip) {
                $result->skipped($file, "{$skip['region']}: {$skip['reason']} A mano: {$skip['manual']}", $extra);
            }

            if ($sync['added'] === []) {
                if ($sync['skipped'] === []) {
                    $result->unchanged($file, 'Sus listas ya tienen lo que declara el contrato.', $extra);
                }

                continue;
            }

            $added = [];

            foreach ($sync['added'] as $region => $entries) {
                $added[] = ($region === 'casts' ? 'casts()' : '$'.$region).': '.implode(', ', $entries);
            }

            $this->write([$absolute => $sync['code']], $context, $result, $file, $extra, 'Completadas sus listas (sólo se añade): '.implode('; ', $added).'.', true, $reference->manifest());
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function pins(array $step, ScopeReference $reference, OperationContext $context, OperationResult $result): void
    {
        $pins = array_values(array_filter((array) ($step['details']['contract'] ?? []), 'is_array'));
        $file = ($reference->scope['path'] === '.' ? '' : $reference->scope['path'].'/').'laraimport.json';
        $extra = ['step' => $step['id']];
        $outcome = ForeignKeyPins::apply($reference->scope['root'], array_map(
            fn (array $pin): array => array_intersect_key($pin, array_flip(['model', 'column', 'on_delete', 'on_update'])) + ['model' => '', 'column' => ''],
            $pins
        ));

        if (is_string($outcome)) {
            $result->skipped($file, $outcome, $extra);

            return;
        }

        if ($outcome['pinned'] === []) {
            $result->unchanged($file, 'El contrato ya declara la acción de esas llaves.', $extra);

            return;
        }

        $contract = $reference->scope['root'].'/laraimport.json';
        $manifest = new Manifest($reference->scope['root']);
        $before = ContractFingerprint::ofFile($contract)['models'] ?? [];

        // Los modelos cuyo código correspondía al contrato antes de fijar:
        // fijar declara lo que el código ya hace, así que siguen
        // correspondiendo y su huella se vuelve a anotar. Los que ya estaban
        // desfasados lo siguen estando (contract-outdated).
        $inSync = array_filter(
            array_unique(array_map(fn (array $pin): string => (string) ($pin['model'] ?? ''), $pins)),
            fn (string $model): bool => isset($before[$model]) && ContractFingerprint::matches($manifest->contractOf($model), $before[$model])
        );

        $this->write([$contract => $outcome['json']], $context, $result, $file, $extra,
            'Fijadas en el contrato las acciones que hacen hoy las migraciones: '.implode('; ', $outcome['pinned']).'. La base no cambia.', false, null);

        if (! $context->dryRun()) {
            $manifest->stampContracts(array_intersect_key(ContractFingerprint::ofFile($contract)['models'] ?? [], array_flip($inSync)));

            // El contrato cambió: lo regenerado de antes ya no vale.
            ScopeReference::forget();
            $this->documents = [];
        }
    }

    /**
     * Escribe un cambio entero, o lo simula.
     *
     * @param  array<string, string>  $contents  ruta absoluta => contenido
     * @param  array<string, mixed>  $extra
     */
    private function write(array $contents, OperationContext $context, OperationResult $result, string $file, array $extra, string $reason, bool $format, ?Manifest $manifest): void
    {
        $changes = new Changes($context->root);

        foreach ($contents as $absolute => $content) {
            $changes->put($absolute, $content);
        }

        if ($context->dryRun()) {
            $result->fixed($file, $reason, $extra + ['diff' => $changes->diff()]);

            return;
        }

        $problem = $changes->commit($format, $manifest);

        $problem === null
            ? $result->fixed($file, $reason, $extra + ($context->simulation ? ['diff' => $changes->appliedDiff()] : []))
            : $result->skipped($file, $problem, $extra);
    }

    private function document(string $scopeRoot): ImportDocument|string
    {
        if (isset($this->documents[$scopeRoot])) {
            return $this->documents[$scopeRoot];
        }

        ['document' => $document, 'errors' => $errors] = ImportDocument::fromFile($scopeRoot.'/laraimport.json');

        return $this->documents[$scopeRoot] = $document ?? 'El contrato no valida ('.($errors[0]['message'] ?? 'sin detalle').'): corrígelo antes.';
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function files(array $step): array
    {
        return array_values(array_filter((array) ($step['files'] ?? []), 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<array<string, mixed>>
     */
    private function findings(array $step, string $check): array
    {
        return array_values(array_filter(
            (array) ($step['details']['findings'] ?? []),
            fn (mixed $finding): bool => is_array($finding) && ($finding['check'] ?? null) === $check && is_string($finding['file'] ?? null)
        ));
    }
}
