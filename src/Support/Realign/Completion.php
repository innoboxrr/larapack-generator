<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\ProjectValidation;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Verifier;

/**
 * El criterio de terminado de un realineamiento, uno y comprobable:
 * `larapack:realign done`.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * POR QUÉ: la guía decía a la vez «el plan sin pendientes» y «verify
 * --strict», y ninguno de los dos basta. `verify` no ve un archivo editado
 * fuera de sus huecos —eso lo encuentra el diagnóstico—: en una prueba de
 * autonomía, un agente dio por terminado con `verify --strict` en verde y el
 * plan con pasos `edited-outside` pendientes. Y el plan no ve lo que se
 * desvió después del último diagnóstico. Aquí se mira todo, en todos los
 * ámbitos, y se dice exactamente qué falta.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Qué hace, en orden:
 *
 *  1. Diagnostica y planifica otra vez (como `diagnose` y `plan`: sólo escribe
 *     el estado de `.larapack/realign/`), para que nada desviado después del
 *     último diagnóstico se escape y lo hecho o decidido se conserve.
 *  2. Mira el plan: cada paso que no está hecho es algo que falta —una
 *     decisión sin tomar va aparte, en `decisions`—.
 *  3. Mira las excepciones: cada una pedida y sin aprobar, o aprobada sobre
 *     otro código o sobre algo que ya no existe.
 *  4. `validate --strict` en cada ámbito con contrato (ProjectValidation).
 *  5. `verify --strict` en todo el proyecto (ProjectVerification): cada error
 *     y cada aviso; lo que cubre una excepción aprobada ya es `info`.
 *
 * Lo DECLARADO fuera no cuenta: un ámbito cuyo paso `no-contract` está hecho
 * (el dueño decidió que se queda sin contrato) no tiene contrato que validar
 * ni manifiesto que verificar (`empty-manifest`). Sale en `declared`, para
 * que se vea que está fuera por decisión y no por olvido.
 *
 * No hay aplazados: un paso que se deja para después sigue pendiente, y el
 * realineamiento no está terminado. Lo que se decide no hacer se decide
 * (`decide`) y, si se queda como está, con su excepción aprobada.
 *
 * Las pruebas del proyecto no las corre: eso lo dice la guía (verify.md).
 *
 * @phpstan-type Missing array{steps: list<array<string, mixed>>, decisions: list<array<string, mixed>>, exceptions: list<array<string, mixed>>, validate: list<array<string, mixed>>, verify: list<array<string, mixed>>}
 */
final class Completion
{
    public const KEYS = ['steps', 'decisions', 'exceptions', 'validate', 'verify'];

    public function __construct(
        private readonly string $root,
        private readonly int $jobs = 4,
        private readonly bool $useCache = true,
    ) {}

    /**
     * @param  list<string>  $packages  lo de --package, como lo escribió quien lo pide
     * @param  list<string>  $roots  sus raíces absolutas; vacío, todo el proyecto
     * @param  list<string>  $paths  sus rutas de ámbito (`.` para el núcleo); vacío, todas
     * @return array{done: bool, missing: Missing, declared: list<array<string, mixed>>, counts: array<string, int>, reasons: list<string>, diagnosed_at: string|null}
     */
    public function run(array $packages = [], array $roots = [], array $paths = []): array
    {
        $state = new RealignState($this->root);
        $report = (new Diagnosis($this->root, $this->jobs, $this->useCache))->run($packages);
        $plan = Planner::build($report, $state->plan());
        $state->savePlan($plan);

        $steps = array_values(array_filter(
            (array) $plan['steps'],
            fn (mixed $step): bool => is_array($step) && ($paths === [] || in_array($step['scope']['path'] ?? null, $paths, true))
        ));
        $byId = [];

        foreach ((array) $plan['steps'] as $step) {
            if (is_array($step) && is_string($step['id'] ?? null)) {
                $byId[$step['id']] = $step;
            }
        }

        $missing = array_fill_keys(self::KEYS, []);
        $declaredOut = [];

        foreach ($steps as $step) {
            $status = Planner::effectiveStatus($step, $byId);
            $path = (string) ($step['scope']['path'] ?? '.');

            if ($status === Planner::DONE) {
                if (($step['type'] ?? null) === 'no-contract') {
                    $declaredOut[$path] = (string) $step['id'];
                }

                continue;
            }

            $row = ['id' => $step['id'], 'title' => $step['title'] ?? null, 'category' => $step['category'] ?? null, 'status' => $status, 'scope' => $path];

            if ($status === Planner::AWAITING_DECISION) {
                $missing['decisions'][] = $row + ['decide' => 'larapack:realign decide '.$step['id'].' <opción> --note="por qué"'];
            } else {
                $missing['steps'][] = $row + ['check' => 'larapack:realign check '.$step['id']];
            }
        }

        $missing['exceptions'] = $this->exceptions($paths, $byId);
        $declared = [];

        foreach ($this->validate($roots) as $finding) {
            $scope = $this->scopeOfContract((string) $finding['file']);

            if (isset($declaredOut[$scope]) && ! is_file(($scope === '.' ? $this->root : $this->root.'/'.$scope).'/'.ProjectValidation::CONTRACT)) {
                $declared[] = ['source' => 'validate', 'scope' => $scope, 'step' => $declaredOut[$scope], 'message' => $finding['message']];

                continue;
            }

            $missing['validate'][] = $finding;
        }

        foreach ($this->verify($roots) as $finding) {
            $scope = (string) $finding['scope'];

            if ($finding['check'] === 'empty-manifest' && isset($declaredOut[$scope])) {
                $declared[] = ['source' => 'verify', 'scope' => $scope, 'step' => $declaredOut[$scope], 'message' => $finding['message']];

                continue;
            }

            $missing['verify'][] = $finding;
        }

        $counts = array_map('count', $missing);

        return [
            'done' => array_sum($counts) === 0,
            'missing' => $missing,
            'declared' => $declared,
            'counts' => $counts,
            'reasons' => self::reasons($counts),
            'diagnosed_at' => is_string($report['generated_at'] ?? null) ? $report['generated_at'] : null,
        ];
    }

    /**
     * Una línea por lo que falta, para quien lee el texto o el `error` del
     * JSON.
     *
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    public static function reasons(array $counts): array
    {
        $reasons = [];
        $texts = [
            'steps' => '%d pasos del plan sin hacer (larapack:realign next)',
            'decisions' => '%d decisiones sin tomar: las toma el dueño (larapack:realign decide)',
            'exceptions' => '%d excepciones sin aprobar o que ya no cubren lo aprobado: las aprueba el dueño (larapack:hand-rolled approve)',
            'validate' => '%d errores o avisos de larapack:validate --strict',
            'verify' => '%d errores o avisos de larapack:verify --strict',
        ];

        foreach ($texts as $key => $text) {
            if (($counts[$key] ?? 0) > 0) {
                $reasons[] = sprintf($text, $counts[$key]);
            }
        }

        return $reasons;
    }

    /**
     * Las excepciones que no cubren lo que deberían: pedidas y sin aprobar, o
     * aprobadas sobre otro código, con otra razón o sobre algo que ya no
     * existe. Las de un paso de otro ámbito, con --package, no cuentan.
     *
     * @param  list<string>  $paths
     * @param  array<string, array<string, mixed>>  $byId
     * @return list<array<string, mixed>>
     */
    private function exceptions(array $paths, array $byId = []): array
    {
        $exceptions = Exceptions::forRoot($this->root);
        $rows = [];

        foreach ($exceptions->requests() as $target => $request) {
            if ($paths !== [] && ! in_array($request['scope'], $paths, true)) {
                continue;
            }

            // Pedida bajo una decisión que después cambió para ese elemento:
            // ya no se pide (Navigation::stillRequested()).
            $step = $byId[$request['step']] ?? null;

            if (is_array($step) && ! Navigation::stillRequested($step, $request)) {
                continue;
            }

            $status = $exceptions->status((string) $target);

            if ($status !== Exceptions::APPROVED) {
                $rows[(string) $target] = ['target' => (string) $target, 'step' => $request['step'], 'scope' => $request['scope'], 'status' => $status];
            }
        }

        // Las aprobadas sin solicitud (las de antes, o de otra rama) también
        // caducan. Con --package no se sabe de qué ámbito son: no se miran.
        foreach ($paths === [] ? $exceptions->all() : [] as $approval) {
            if ($approval['status'] !== Exceptions::APPROVED && ! isset($rows[$approval['target']])) {
                $rows[$approval['target']] = ['target' => $approval['target'], 'step' => null, 'scope' => null, 'status' => $approval['status']];
            }
        }

        return array_values($rows);
    }

    /**
     * Los errores y avisos de `validate --strict` en cada ámbito.
     *
     * @param  list<string>  $roots
     * @return list<array<string, mixed>>
     */
    private function validate(array $roots): array
    {
        $result = (new ProjectValidation($this->root, $roots))->run(strict: true);

        return array_values(array_filter($result['findings'], fn (array $finding): bool => in_array($finding['level'], [SemanticValidator::ERROR, SemanticValidator::WARNING], true)));
    }

    /**
     * Los errores y avisos de `verify --strict`, cada uno con la ruta de su
     * ámbito (`scope`).
     *
     * @param  list<string>  $roots
     * @return list<array<string, mixed>>
     */
    private function verify(array $roots): array
    {
        ['findings' => $findings, 'summary' => $summary] = (new ProjectVerification($this->root, $roots))->run();
        $pathOf = [];

        foreach ($summary as $row) {
            $pathOf[$row['package'] ?? ''] = $row['path'];
        }

        $failing = [];

        foreach ($findings as $finding) {
            if (in_array($finding['level'], [Verifier::ERROR, Verifier::WARNING], true)) {
                $failing[] = $finding + ['scope' => $pathOf[$finding['package'] ?? ''] ?? '.'];
            }
        }

        return $failing;
    }

    /**
     * La ruta del ámbito de un contrato (`packages/x/laraimport.json` →
     * `packages/x`).
     */
    private function scopeOfContract(string $file): string
    {
        $directory = dirname($file);

        return $directory === '' ? '.' : $directory;
    }
}
