<?php

namespace Innoboxrr\LarapackGenerator\Support;

use Innoboxrr\LarapackGenerator\Support\GitHub\Api;
use Innoboxrr\LarapackGenerator\Support\GitHub\CliApi;
use Innoboxrr\LarapackGenerator\Support\GitHub\Response;
use Innoboxrr\LarapackGenerator\Support\GitHub\TokenApi;

/**
 * La otra mitad de la puerta de la CI: que la rama EXIJA el check «LaraPack
 * verify». Sin eso, el workflow corre, sale en rojo y el PR se fusiona igual.
 *
 * Proteger la rama lo hace el dueño del repositorio —cambia quién puede
 * fusionar, y eso no lo decide un comando ni un agente—. Lo que hace LaraPack
 * es dejárselo a un clic y comprobar después si quedó:
 *
 *  - `ruleset()`: un ruleset de GitHub listo para importar (Settings → Rules →
 *    Rulesets → New ruleset → Import a ruleset) o para mandar con
 *    `gh api --method POST repos/{o}/{r}/rulesets --input`. Mismo JSON para
 *    las dos vías: el formato de exportación de rulesets.
 *  - `check()`: lee lo que GitHub aplica a la rama —las reglas de los
 *    rulesets (`GET repos/{o}/{r}/rules/branches/{rama}`, que basta con
 *    leer el repositorio) y la protección clásica— y dice si exige el check.
 *    Lo que no se pudo leer es «no se pudo saber», nunca «protegida».
 *
 * Sólo lee. Ninguna ruta de aquí escribe en GitHub.
 */
final class BranchProtection
{
    /**
     * Donde `larapack:ci` deja el ruleset para importarlo. GitHub no lo lee
     * solo de ahí: es un archivo para el dueño.
     */
    public const RULESET_FILE = '.github/rulesets/larapack-verify.json';

    /**
     * El id de la app de GitHub Actions en github.com. Fijar el check a ella
     * impide que un estado con ese nombre, publicado con cualquier token que
     * tenga `statuses: write`, cuente como el check sin que corra nada. En
     * GitHub Enterprise Server el id es otro: allí se cambia o se quita.
     */
    public const ACTIONS_APP_ID = 15368;

    /**
     * El id del papel «admin» del repositorio como actor de bypass de un
     * ruleset (RepositoryRole).
     */
    public const ADMIN_ROLE_ID = 5;

    public const REQUIRED = 'required';

    public const WITHOUT_CHECK = 'protected-without-check';

    public const UNPROTECTED = 'unprotected';

    public const UNKNOWN = 'unknown';

    private static ?Api $fake = null;

    private static bool $faked = false;

    /**
     * Las pruebas cambian por dónde se pregunta (null: sin forma de
     * preguntar). Nunca hay red en la suite.
     */
    public static function useApi(?Api $api): void
    {
        self::$fake = $api;
        self::$faked = true;
    }

    public static function reset(): void
    {
        self::$fake = null;
        self::$faked = false;
    }

    /**
     * Por dónde preguntar: un token del entorno (`GH_TOKEN`, `GITHUB_TOKEN`;
     * el del job en Actions) y, si no, `gh` con la sesión de quien lo corre.
     * Null si no hay ninguno.
     */
    public static function api(): ?Api
    {
        if (self::$faked) {
            return self::$fake;
        }

        return TokenApi::fromEnvironment() ?? CliApi::find();
    }

    /**
     * El ruleset que exige el check en la rama por defecto.
     *
     * - `~DEFAULT_BRANCH`: sigue a la rama por defecto aunque se renombre.
     * - El check fijado a GitHub Actions (`integration_id`).
     * - La revisión de los dueños de código, que es la aprobación del candado
     *   de #[HandRolled], y que una revisión se descarte si después se empuja
     *   otra cosa: sin eso, se aprueba el candado y luego se cambia.
     * - Sin borrar la rama ni reescribirla (`deletion`, `non_fast_forward`).
     * - Sin bypass: en un ruleset, un administrador no se lo salta salvo que
     *   esté en `bypass_actors`. Con `$enforceAdmins` en false, el papel de
     *   administrador va ahí, que es lo que pidió quien lo quiso así.
     *
     * @return array<string, mixed>
     */
    public static function ruleset(bool $enforceAdmins = true): array
    {
        return [
            'name' => CiGate::JOB,
            'target' => 'branch',
            'enforcement' => 'active',
            'bypass_actors' => $enforceAdmins ? [] : [
                ['actor_id' => self::ADMIN_ROLE_ID, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always'],
            ],
            'conditions' => [
                'ref_name' => ['include' => ['~DEFAULT_BRANCH'], 'exclude' => []],
            ],
            'rules' => [
                ['type' => 'deletion'],
                ['type' => 'non_fast_forward'],
                ['type' => 'pull_request', 'parameters' => [
                    'required_approving_review_count' => 1,
                    'dismiss_stale_reviews_on_push' => true,
                    'require_code_owner_review' => true,
                    'require_last_push_approval' => false,
                    'required_review_thread_resolution' => false,
                ]],
                ['type' => 'required_status_checks', 'parameters' => [
                    'strict_required_status_checks_policy' => false,
                    'do_not_enforce_on_create' => false,
                    'required_status_checks' => [
                        ['context' => CiGate::JOB, 'integration_id' => self::ACTIONS_APP_ID],
                    ],
                ]],
            ],
        ];
    }

    public static function rulesetJson(bool $enforceAdmins = true): string
    {
        return json_encode(self::ruleset($enforceAdmins), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Si es `dueño/repositorio`. Va en la ruta de la API: `.` y `..` no son
     * nombres, son saltos de directorio.
     */
    public static function isRepository(string $repository): bool
    {
        return preg_match('#^(?!\.{1,2}/)[A-Za-z0-9_.-]+/(?!\.{1,2}$)[A-Za-z0-9_.-]+$#', $repository) === 1;
    }

    /**
     * Lo que GitHub aplica a la rama, y si exige el check.
     *
     * @return array{
     *     repository: string|null,
     *     branch: string|null,
     *     check: string,
     *     status: string,
     *     via: string|null,
     *     rulesets: list<array{id: int|null, name: string|null, source: string|null, requires_check: bool, app_id: int|null, code_owner_review: bool, bypass: string, you_can_bypass: bool|null}>,
     *     classic: array{requires_check: bool, app_id: int|null, admins_included: bool|null, code_owner_review: bool|null}|null,
     *     holes: list<string>,
     *     unverified: list<string>,
     *     problems: list<string>
     * }
     */
    public static function check(?Api $api, ?string $repository, ?string $branch): array
    {
        $report = [
            'repository' => $repository,
            'branch' => $branch,
            'check' => CiGate::JOB,
            'status' => self::UNKNOWN,
            'via' => $api?->describe(),
            'rulesets' => [],
            'classic' => null,
            'holes' => [],
            'unverified' => [],
            'problems' => [],
        ];

        if ($api === null) {
            $report['problems'][] = 'No hay con qué preguntar a GitHub: ni GH_TOKEN ni GITHUB_TOKEN en el entorno, ni gh en el PATH.';

            return $report;
        }

        if ($repository === null) {
            $report['problems'][] = 'No se sabe qué repositorio es: ni --repo, ni un remoto origin de github.com, ni GITHUB_REPOSITORY.';

            return $report;
        }

        if ($branch === null) {
            $response = $api->get("repos/{$repository}");
            $default = is_array($response->json) ? ($response->json['default_branch'] ?? null) : null;

            if (! $response->ok() || ! is_string($default) || $default === '') {
                $report['problems'][] = 'No se pudo leer la rama por defecto de '.$repository.' ('.$response->describe().').';

                return $report;
            }

            $report['branch'] = $branch = $default;
        }

        $path = "repos/{$repository}";
        $encoded = implode('/', array_map('rawurlencode', explode('/', $branch)));

        $rules = $api->get("{$path}/rules/branches/{$encoded}?per_page=100");

        if ($rules->ok() && is_array($rules->json) && array_is_list($rules->json)) {
            $report['rulesets'] = self::rulesets($api, $path, $rules->json);
        } else {
            $report['problems'][] = 'Las reglas de la rama (rulesets): '.$rules->describe().'.';
        }

        $classic = $api->get("{$path}/branches/{$encoded}");

        if ($classic->ok() && is_array($classic->json)) {
            $report['classic'] = self::classic($api, "{$path}/branches/{$encoded}", $classic->json);
        } elseif ($classic->status !== 404) {
            // Un 404 es una rama que todavía no existe: las reglas sí se
            // pueden leer (valen para cuando exista) y no hay protección
            // clásica que leer.
            $report['problems'][] = 'La protección clásica de la rama: '.$classic->describe().'.';
        }

        $requiring = array_values(array_filter($report['rulesets'], fn (array $ruleset): bool => $ruleset['requires_check']));
        $classicRequires = $report['classic'] !== null && $report['classic']['requires_check'];

        $report['status'] = match (true) {
            $requiring !== [] || $classicRequires => self::REQUIRED,
            $report['problems'] !== [] => self::UNKNOWN,
            $report['rulesets'] !== [] || $report['classic'] !== null => self::WITHOUT_CHECK,
            default => self::UNPROTECTED,
        };

        if ($report['status'] === self::REQUIRED) {
            [$report['holes'], $report['unverified']] = self::holes($report['rulesets'], $requiring, $report['classic']);
        }

        return $report;
    }

    /**
     * Las reglas de la rama, agrupadas por el ruleset del que salen.
     *
     * @param  list<mixed>  $rules
     * @return list<array{id: int|null, name: string|null, source: string|null, requires_check: bool, app_id: int|null, code_owner_review: bool, bypass: string, you_can_bypass: bool|null}>
     */
    private static function rulesets(Api $api, string $path, array $rules): array
    {
        $grouped = [];

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_string($rule['type'] ?? null)) {
                continue;
            }

            $id = is_int($rule['ruleset_id'] ?? null) ? $rule['ruleset_id'] : null;
            $key = $id === null ? '?' : (string) $id;

            $grouped[$key] ??= [
                'id' => $id,
                'name' => null,
                'source' => is_string($rule['ruleset_source'] ?? null) ? $rule['ruleset_source'] : null,
                'requires_check' => false,
                'app_id' => null,
                'code_owner_review' => false,
                'bypass' => 'unknown',
                'you_can_bypass' => null,
            ];

            $parameters = is_array($rule['parameters'] ?? null) ? $rule['parameters'] : [];

            if ($rule['type'] === 'pull_request' && ($parameters['require_code_owner_review'] ?? false) === true) {
                $grouped[$key]['code_owner_review'] = true;
            }

            if ($rule['type'] === 'required_status_checks') {
                foreach (is_array($parameters['required_status_checks'] ?? null) ? $parameters['required_status_checks'] : [] as $check) {
                    if (is_array($check) && ($check['context'] ?? null) === CiGate::JOB) {
                        $grouped[$key]['requires_check'] = true;
                        $grouped[$key]['app_id'] = is_int($check['integration_id'] ?? null) ? $check['integration_id'] : null;
                    }
                }
            }
        }

        foreach ($grouped as $key => $ruleset) {
            if ($ruleset['id'] === null) {
                continue;
            }

            // El nombre y quién se lo salta sólo están en el ruleset. Los de
            // la organización salen con includes_parents. Quien sólo lee el
            // repositorio no ve bypass_actors: entonces queda «sin saber».
            $detail = $api->get("{$path}/rulesets/{$ruleset['id']}?includes_parents=true");

            if (! $detail->ok() || ! is_array($detail->json)) {
                continue;
            }

            $grouped[$key]['name'] = is_string($detail->json['name'] ?? null) ? $detail->json['name'] : null;

            if (is_array($detail->json['bypass_actors'] ?? null)) {
                $grouped[$key]['bypass'] = $detail->json['bypass_actors'] === [] ? 'none' : 'some';
            }

            $grouped[$key]['you_can_bypass'] = match ($detail->json['current_user_can_bypass'] ?? null) {
                'always', 'pull_requests_only', 'exempt' => true,
                'never' => false,
                default => null,
            };
        }

        return array_values($grouped);
    }

    /**
     * La protección clásica de la rama, si la tiene.
     *
     * `GET …/branches/{rama}` basta con leer el repositorio y trae los checks
     * exigidos y a quién se aplican. La revisión de los dueños de código y
     * `enforce_admins` exactos sólo los da `…/protection`, que pide
     * administración: se intenta, y si no, quedan sin saber.
     *
     * @param  array<mixed>  $branch
     * @return array{requires_check: bool, app_id: int|null, admins_included: bool|null, code_owner_review: bool|null}|null
     */
    private static function classic(Api $api, string $path, array $branch): ?array
    {
        $protection = is_array($branch['protection'] ?? null) ? $branch['protection'] : [];

        if (($protection['enabled'] ?? false) !== true) {
            return null;
        }

        $checks = is_array($protection['required_status_checks'] ?? null) ? $protection['required_status_checks'] : [];
        $level = $checks['enforcement_level'] ?? null;
        $contexts = is_array($checks['contexts'] ?? null) ? $checks['contexts'] : [];
        $requires = in_array(CiGate::JOB, $contexts, true);
        $appId = null;

        foreach (is_array($checks['checks'] ?? null) ? $checks['checks'] : [] as $check) {
            if (is_array($check) && ($check['context'] ?? null) === CiGate::JOB) {
                $requires = true;
                $appId = is_int($check['app_id'] ?? null) ? $check['app_id'] : null;
            }
        }

        $classic = [
            'requires_check' => $requires && $level !== 'off',
            'app_id' => $appId,
            'admins_included' => match ($level) {
                'everyone' => true,
                'non_admins' => false,
                default => null,
            },
            'code_owner_review' => null,
        ];

        $detail = self::adminView($api->get("{$path}/protection"));

        if ($detail !== null) {
            $enforce = is_array($detail['enforce_admins'] ?? null) ? ($detail['enforce_admins']['enabled'] ?? null) : null;
            $classic['admins_included'] = is_bool($enforce) ? $enforce : $classic['admins_included'];
            $reviews = is_array($detail['required_pull_request_reviews'] ?? null) ? $detail['required_pull_request_reviews'] : [];
            $classic['code_owner_review'] = ($reviews['require_code_owner_reviews'] ?? false) === true;
        }

        return $classic;
    }

    /**
     * @return array<mixed>|null
     */
    private static function adminView(Response $response): ?array
    {
        return $response->ok() && is_array($response->json) ? $response->json : null;
    }

    /**
     * Lo que deja abierta una rama que sí exige el check: un check que puede
     * llegar de cualquier sitio, alguien que se lo salta, nadie que revise el
     * candado. `unverified` es lo que no se pudo comprobar con estas
     * credenciales: se dice, sin darlo por bueno ni por malo.
     *
     * @param  list<array{id: int|null, name: string|null, source: string|null, requires_check: bool, app_id: int|null, code_owner_review: bool, bypass: string, you_can_bypass: bool|null}>  $rulesets
     * @param  list<array{id: int|null, name: string|null, source: string|null, requires_check: bool, app_id: int|null, code_owner_review: bool, bypass: string, you_can_bypass: bool|null}>  $requiring
     * @param  array{requires_check: bool, app_id: int|null, admins_included: bool|null, code_owner_review: bool|null}|null  $classic
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function holes(array $rulesets, array $requiring, ?array $classic): array
    {
        $holes = [];
        $unverified = [];
        $classicRequires = $classic !== null && $classic['requires_check'];

        $pinned = array_filter($requiring, fn (array $ruleset): bool => $ruleset['app_id'] === self::ACTIONS_APP_ID) !== []
            || ($classicRequires && $classic['app_id'] === self::ACTIONS_APP_ID);

        if (! $pinned) {
            $holes[] = 'El check «'.CiGate::JOB.'» se acepta venga de donde venga: un estado con ese nombre, publicado con cualquier token con statuses: write, cuenta como pasado sin que corra nada. Fíjalo a GitHub Actions (integration_id '.self::ACTIONS_APP_ID.' en github.com), como el ruleset de larapack:ci.';
        }

        foreach ($requiring as $ruleset) {
            $label = self::label($ruleset);

            if ($ruleset['bypass'] === 'some') {
                $holes[] = "{$label} tiene quien se lo salte (bypass_actors): esas cuentas fusionan sin el check.";
            } elseif ($ruleset['bypass'] === 'unknown') {
                $unverified[] = "Quién se salta {$label}: con estas credenciales GitHub no enseña bypass_actors.";
            }

            if ($ruleset['you_can_bypass'] === true) {
                $holes[] = "La cuenta con la que se pregunta se puede saltar {$label}.";
            }
        }

        if ($requiring === [] && $classicRequires && $classic['admins_included'] === false) {
            $holes[] = 'La protección clásica no se aplica a los administradores (enforce_admins=false): un push de administrador se salta el check.';
        } elseif ($requiring === [] && $classicRequires && $classic['admins_included'] === null) {
            $unverified[] = 'Si la protección clásica se aplica a los administradores (enforce_admins).';
        }

        $codeOwners = array_filter($rulesets, fn (array $ruleset): bool => $ruleset['code_owner_review']) !== []
            || ($classic !== null && $classic['code_owner_review'] === true);

        if (! $codeOwners && $classic !== null && $classic['code_owner_review'] === null) {
            $unverified[] = 'Si la protección clásica exige la revisión de los dueños de código: sólo se ve con permisos de administración.';
        } elseif (! $codeOwners) {
            $holes[] = 'Nadie exige la revisión de los dueños de código: el candado de #[HandRolled] y el propio workflow se cambian sin el dueño.';
        }

        return [$holes, $unverified];
    }

    /**
     * @param  array{id: int|null, name: string|null, source: string|null}  $ruleset
     */
    public static function label(array $ruleset): string
    {
        $name = $ruleset['name'] === null ? '' : ' «'.$ruleset['name'].'»';
        $source = $ruleset['source'] === null ? '' : ' ('.$ruleset['source'].')';

        return 'el ruleset '.($ruleset['id'] ?? '?').$name.$source;
    }

    /**
     * Una anotación de Actions (`::warning title=…::mensaje`), con lo que el
     * runner exige escapar: `%`, saltos de línea y, en las propiedades, `:` y
     * `,`.
     */
    public static function annotation(string $level, string $title, string $message): string
    {
        $data = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message);
        $property = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $title);

        return "::{$level} title={$property}::{$data}";
    }

    /**
     * El resumen del job (`$GITHUB_STEP_SUMMARY`), en Markdown: qué hay y,
     * si la rama no exige el check, los pasos exactos para que lo exija.
     *
     * @param  array{repository: string|null, branch: string|null, status: string, holes: list<string>, unverified: list<string>, problems: list<string>}  $report
     */
    public static function summary(array $report, string $binary, string $server = 'https://github.com'): string
    {
        $repository = self::plain($report['repository'] ?? null) ?? 'DUEÑO/REPOSITORIO';
        $branch = self::plain($report['branch'] ?? null) ?? 'RAMA';
        $check = '«'.CiGate::JOB.'»';

        $lines = ['### La puerta de LaraPack', ''];

        $lines[] = match ($report['status']) {
            self::REQUIRED => "La rama `{$branch}` de `{$repository}` exige el check {$check}: un PR en rojo no se fusiona.",
            self::WITHOUT_CHECK => "**La rama `{$branch}` de `{$repository}` está protegida, pero no exige el check {$check}.** Este job corre y un PR en rojo se fusiona igual.",
            self::UNPROTECTED => "**La rama `{$branch}` de `{$repository}` no tiene protección ni reglas.** Este job corre y un PR en rojo se fusiona igual; también se puede empujar directo.",
            default => "**No se pudo saber si la rama `{$branch}` de `{$repository}` exige el check {$check}.** No se da por protegida.",
        };

        foreach ($report['problems'] as $problem) {
            $lines[] = '';
            $lines[] = '- '.$problem;
        }

        if ($report['holes'] !== []) {
            $lines = [...$lines, '', '**Lo que queda abierto:**', ''];

            foreach ($report['holes'] as $hole) {
                $lines[] = '- '.$hole;
            }
        }

        if ($report['unverified'] !== []) {
            $lines = [...$lines, '', 'Sin comprobar con este token:', ''];

            foreach ($report['unverified'] as $item) {
                $lines[] = '- '.$item;
            }
        }

        if ($report['status'] !== self::REQUIRED) {
            $lines = [...$lines, '', ...self::guideMarkdown($repository, $branch, $binary, $server)];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Los pasos para que la rama exija el check, en Markdown.
     *
     * @return list<string>
     */
    public static function guideMarkdown(string $repository, string $branch, string $binary, string $server = 'https://github.com'): array
    {
        return [
            'Lo hace el dueño del repositorio, una vez. Una de estas tres vías:',
            '',
            '1. **En la web, importando el ruleset** (la recomendada): '.rtrim($server, '/')."/{$repository}/settings/rules → *New ruleset* → *Import a ruleset* → `".self::RULESET_FILE.'` (lo escribe `larapack:ci`; el contenido está abajo) → revisar → *Create*.',
            "2. **Con la CLI de GitHub**, el mismo ruleset: `gh api --method POST 'repos/{$repository}/rulesets' --input '".self::RULESET_FILE."'`",
            "3. **Con la protección clásica de ramas**: `{$binary} larapack:ci` imprime la orden `gh api --method PUT 'repos/{$repository}/branches/{$branch}/protection' …`. No fija de qué app llega el check: el ruleset va primero.",
            '',
            "Para comprobarlo: `{$binary} larapack:ci --check`. Este paso lo vuelve a mirar en cada corrida.",
            '',
            'En un repositorio privado de una cuenta Free, GitHub no aplica ni rulesets ni protección de ramas: hace falta Pro, Team o Enterprise.',
            '',
            '<details><summary>'.self::RULESET_FILE.'</summary>',
            '',
            '```json',
            rtrim(self::rulesetJson()),
            '```',
            '',
            '</details>',
        ];
    }

    /**
     * Un valor que llega de GitHub o del entorno, sin lo que rompería el
     * Markdown en línea.
     */
    private static function plain(?string $value): ?string
    {
        return $value === null || $value === '' ? null : str_replace(['`', "\r", "\n"], ["'", ' ', ' '], $value);
    }
}
