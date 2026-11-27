<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\BranchProtection;
use Innoboxrr\LarapackGenerator\Support\CiGate;
use Innoboxrr\LarapackGenerator\Support\GitHub\Response;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeGitHubApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Que la rama exija el check «LaraPack verify»: el ruleset que se importa, y
 * la lectura de lo que GitHub aplica a la rama.
 *
 * Las respuestas son las de la API REST de GitHub —`rules/branches`, `branches`,
 * `branches/{rama}/protection`, `rulesets/{id}`—, simuladas: nunca hay red.
 * La regla que se prueba sobre todo: lo que no se pudo leer es «no se pudo
 * saber», nunca «protegida».
 */
final class BranchProtectionTest extends TestCase
{
    private const RULES = 'repos/acme/notes/rules/branches/main?per_page=100';

    private const BRANCH = 'repos/acme/notes/branches/main';

    /**
     * @param  array<string, mixed>|null  $parameters
     * @return array<string, mixed>
     */
    private static function rule(string $type, ?array $parameters = null, int $ruleset = 7, string $source = 'acme/notes'): array
    {
        return [
            'type' => $type,
            ...($parameters === null ? [] : ['parameters' => $parameters]),
            'ruleset_source_type' => 'Repository',
            'ruleset_source' => $source,
            'ruleset_id' => $ruleset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function statusChecks(?int $integration = BranchProtection::ACTIONS_APP_ID, string $context = CiGate::JOB): array
    {
        return [
            'strict_required_status_checks_policy' => false,
            'required_status_checks' => [['context' => $context, ...($integration === null ? [] : ['integration_id' => $integration])]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function codeOwners(bool $required = true): array
    {
        return [
            'required_approving_review_count' => 1,
            'dismiss_stale_reviews_on_push' => true,
            'require_code_owner_review' => $required,
            'require_last_push_approval' => false,
            'required_review_thread_resolution' => false,
        ];
    }

    /**
     * Una rama sin protección clásica, como la devuelve `GET …/branches/{rama}`.
     *
     * @return array<string, mixed>
     */
    private static function unprotectedBranch(): array
    {
        return ['name' => 'main', 'protected' => false, 'protection' => ['enabled' => false, 'required_status_checks' => ['enforcement_level' => 'off', 'contexts' => [], 'checks' => []]]];
    }

    // EL RULESET

    /**
     * El formato de exportación de rulesets de GitHub (el mismo que acepta
     * `POST repos/{o}/{r}/rulesets`): lo que se importa tiene que ser
     * exactamente esto, o la importación falla o protege otra cosa.
     */
    public function test_el_ruleset_tiene_la_forma_que_importa_github(): void
    {
        $ruleset = json_decode(BranchProtection::rulesetJson(), true);

        $this->assertSame(['name', 'target', 'enforcement', 'bypass_actors', 'conditions', 'rules'], array_keys($ruleset));
        $this->assertSame(CiGate::JOB, $ruleset['name']);
        $this->assertSame('branch', $ruleset['target']);
        $this->assertSame('active', $ruleset['enforcement']);
        $this->assertSame([], $ruleset['bypass_actors']);
        $this->assertSame(['ref_name' => ['include' => ['~DEFAULT_BRANCH'], 'exclude' => []]], $ruleset['conditions']);

        $rules = array_column($ruleset['rules'], null, 'type');

        $this->assertSame(['deletion', 'non_fast_forward', 'pull_request', 'required_status_checks'], array_keys($rules));
        $this->assertSame(['type' => 'deletion'], $rules['deletion']);
        $this->assertSame(['type' => 'non_fast_forward'], $rules['non_fast_forward']);

        // Los cinco parámetros de pull_request son obligatorios en la API.
        $this->assertSame([
            'required_approving_review_count' => 1,
            'dismiss_stale_reviews_on_push' => true,
            'require_code_owner_review' => true,
            'require_last_push_approval' => false,
            'required_review_thread_resolution' => false,
        ], $rules['pull_request']['parameters']);

        $this->assertSame([
            'strict_required_status_checks_policy' => false,
            'do_not_enforce_on_create' => false,
            'required_status_checks' => [['context' => 'LaraPack verify', 'integration_id' => 15368]],
        ], $rules['required_status_checks']['parameters']);

        // Siempre igual: así se sabe si alguien lo editó.
        $this->assertSame(BranchProtection::rulesetJson(), BranchProtection::rulesetJson());
        $this->assertStringEndsWith("}\n", BranchProtection::rulesetJson());
        $this->assertStringStartsWith("{\n    \"name\": \"LaraPack verify\",", BranchProtection::rulesetJson());
    }

    public function test_sin_enforce_admins_el_administrador_va_como_bypass(): void
    {
        $this->assertSame(
            [['actor_id' => 5, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always']],
            BranchProtection::ruleset(false)['bypass_actors']
        );
        $this->assertSame(
            array_diff_key(BranchProtection::ruleset(true), ['bypass_actors' => true]),
            array_diff_key(BranchProtection::ruleset(false), ['bypass_actors' => true])
        );
    }

    public function test_que_es_un_repositorio(): void
    {
        foreach (['acme/notes', 'a-b.c/d_e.f'] as $repository) {
            $this->assertTrue(BranchProtection::isRepository($repository), $repository);
        }

        foreach (['acme', 'acme/notes/x', 'acme/no tes', '../x', 'acme/..', './x', 'acme/n;tes', ''] as $repository) {
            $this->assertFalse(BranchProtection::isRepository($repository), $repository);
        }
    }

    // LA CONSULTA: LOS CUATRO ESTADOS

    public function test_exige_el_check_por_un_ruleset_sin_huecos(): void
    {
        $api = (new FakeGitHubApi)
            ->json('repos/acme/notes', ['default_branch' => 'main'])
            ->json(self::RULES, [
                self::rule('deletion'),
                self::rule('pull_request', self::codeOwners()),
                self::rule('required_status_checks', self::statusChecks()),
            ])
            ->json('repos/acme/notes/rulesets/7?includes_parents=true', ['id' => 7, 'name' => 'LaraPack verify', 'bypass_actors' => [], 'current_user_can_bypass' => 'never'])
            ->json(self::BRANCH, self::unprotectedBranch());

        $report = BranchProtection::check($api, 'acme/notes', null);

        $this->assertSame(BranchProtection::REQUIRED, $report['status']);
        $this->assertSame('main', $report['branch']);
        $this->assertSame('GitHub de prueba', $report['via']);
        $this->assertSame([[
            'id' => 7,
            'name' => 'LaraPack verify',
            'source' => 'acme/notes',
            'requires_check' => true,
            'app_id' => 15368,
            'code_owner_review' => true,
            'bypass' => 'none',
            'you_can_bypass' => false,
        ]], $report['rulesets']);
        $this->assertNull($report['classic']);
        $this->assertSame([], $report['holes']);
        $this->assertSame([], $report['unverified']);
        $this->assertSame([], $report['problems']);
    }

    public function test_protegida_sin_exigir_el_check(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [
                self::rule('pull_request', self::codeOwners()),
                // Otro check, no éste; y el nombre tiene que ser exacto.
                self::rule('required_status_checks', self::statusChecks(null, 'tests')),
                self::rule('required_status_checks', self::statusChecks(null, 'laraPack verify')),
            ])
            ->json(self::BRANCH, self::unprotectedBranch());

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::WITHOUT_CHECK, $report['status']);
        $this->assertFalse($report['rulesets'][0]['requires_check']);
        // Sin exigir el check no se enumeran huecos: el hueco es ése.
        $this->assertSame([], $report['holes']);
        // Con la rama dada no se pregunta por la de por defecto.
        $this->assertNotContains('repos/acme/notes', $api->calls);
    }

    public function test_sin_proteccion(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [])
            ->json(self::BRANCH, self::unprotectedBranch());

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::UNPROTECTED, $report['status']);
        $this->assertSame([], $report['rulesets']);
        $this->assertNull($report['classic']);
        $this->assertSame([], $report['problems']);
    }

    /**
     * Una rama que todavía no existe: las reglas se leen (valen para cuando
     * exista) y el 404 de la rama no es un problema.
     */
    public function test_una_rama_que_no_existe_todavia(): void
    {
        $api = (new FakeGitHubApi)->json(self::RULES, []);

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::UNPROTECTED, $report['status']);
        $this->assertSame([], $report['problems']);
    }

    /**
     * @return array<string, array{0: FakeGitHubApi|null, 1: string|null, 2: string|null, 3: string}>
     */
    public static function sinSaber(): array
    {
        return [
            'sin con qué preguntar' => [null, 'acme/notes', 'main', 'ni gh en el PATH'],
            'sin repositorio' => [new FakeGitHubApi, null, 'main', 'No se sabe qué repositorio es'],
            'el repositorio no responde' => [
                (new FakeGitHubApi)->on('repos/acme/notes', Response::failure('Sin respuesta de api.github.com: getaddrinfo failed')),
                'acme/notes', null, 'getaddrinfo failed',
            ],
            'el repositorio no se ve (404)' => [new FakeGitHubApi, 'acme/notes', null, 'HTTP 404: Not Found'],
            'las reglas, sin permiso, y la rama sin proteger' => [
                (new FakeGitHubApi)
                    ->json(self::RULES, ['message' => 'Upgrade to GitHub Pro or make this repository public to enable this feature.'], 403)
                    ->json(self::BRANCH, self::unprotectedBranch()),
                'acme/notes', 'main', 'HTTP 403: Upgrade to GitHub Pro',
            ],
            'las reglas no responden' => [
                (new FakeGitHubApi)->on(self::RULES, Response::failure('gh no respondió: HTTP 502'))->json(self::BRANCH, self::unprotectedBranch()),
                'acme/notes', 'main', 'gh no respondió',
            ],
            'la rama da 500' => [
                (new FakeGitHubApi)->json(self::RULES, [])->json(self::BRANCH, ['message' => 'Server Error'], 500),
                'acme/notes', 'main', 'La protección clásica de la rama: HTTP 500: Server Error',
            ],
            'las reglas no son una lista' => [
                (new FakeGitHubApi)->json(self::RULES, ['type' => 'pull_request'])->json(self::BRANCH, self::unprotectedBranch()),
                'acme/notes', 'main', 'Las reglas de la rama',
            ],
        ];
    }

    /**
     * Lo que no se pudo leer nunca es «protegida», ni tampoco «sin
     * protección»: se dice que no se sabe y por qué.
     */
    #[DataProvider('sinSaber')]
    public function test_lo_que_no_se_pudo_leer_no_se_sabe(?FakeGitHubApi $api, ?string $repository, ?string $branch, string $why): void
    {
        $report = BranchProtection::check($api, $repository, $branch);

        $this->assertSame(BranchProtection::UNKNOWN, $report['status']);
        $this->assertStringContainsString($why, implode("\n", $report['problems']));
        $this->assertSame([], $report['holes']);
    }

    /**
     * Si una de las dos fuentes ya exige el check, la otra que no se pudo
     * leer no lo cambia: exigido por una basta para que el PR no entre.
     */
    public function test_exigido_por_una_fuente_basta_aunque_la_otra_no_se_lea(): void
    {
        $api = (new FakeGitHubApi)
            ->on(self::RULES, Response::failure('sin red'))
            ->json(self::BRANCH, [
                'name' => 'main',
                'protected' => true,
                'protection' => ['enabled' => true, 'required_status_checks' => [
                    'enforcement_level' => 'everyone',
                    'contexts' => ['LaraPack verify'],
                    'checks' => [['context' => 'LaraPack verify', 'app_id' => 15368]],
                ]],
            ])
            ->json(self::BRANCH.'/protection', [
                'enforce_admins' => ['enabled' => true],
                'required_pull_request_reviews' => ['require_code_owner_reviews' => true, 'required_approving_review_count' => 1],
            ]);

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::REQUIRED, $report['status']);
        $this->assertSame(['requires_check' => true, 'app_id' => 15368, 'admins_included' => true, 'code_owner_review' => true], $report['classic']);
        $this->assertSame([], $report['holes']);
        // Lo que no se leyó se sigue diciendo.
        $this->assertStringContainsString('sin red', implode("\n", $report['problems']));
    }

    // LOS HUECOS DE UNA RAMA QUE SÍ LO EXIGE

    public function test_un_ruleset_con_huecos(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [
                self::rule('pull_request', self::codeOwners(false)),
                self::rule('required_status_checks', self::statusChecks(null)),
            ])
            ->json('repos/acme/notes/rulesets/7?includes_parents=true', [
                'id' => 7,
                'name' => 'main',
                'bypass_actors' => [['actor_id' => 5, 'actor_type' => 'RepositoryRole', 'bypass_mode' => 'always']],
                'current_user_can_bypass' => 'always',
            ])
            ->json(self::BRANCH, self::unprotectedBranch());

        $report = BranchProtection::check($api, 'acme/notes', 'main');
        $holes = implode("\n", $report['holes']);

        $this->assertSame(BranchProtection::REQUIRED, $report['status']);
        $this->assertCount(4, $report['holes']);
        $this->assertStringContainsString('se acepta venga de donde venga', $holes);
        $this->assertStringContainsString('el ruleset 7 «main» (acme/notes) tiene quien se lo salte', $holes);
        $this->assertStringContainsString('La cuenta con la que se pregunta se puede saltar el ruleset 7', $holes);
        $this->assertStringContainsString('Nadie exige la revisión de los dueños de código', $holes);
    }

    /**
     * El token de un job no ve bypass_actors: queda sin comprobar, no como
     * hueco ni como cerrado. Y un ruleset de la organización se lee con
     * includes_parents.
     */
    public function test_lo_que_estas_credenciales_no_ven_queda_sin_comprobar(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [
                self::rule('pull_request', self::codeOwners(), 3, 'acme'),
                self::rule('required_status_checks', self::statusChecks(), 3, 'acme'),
            ])
            ->json('repos/acme/notes/rulesets/3?includes_parents=true', ['id' => 3, 'name' => 'org', 'source_type' => 'Organization'])
            ->json(self::BRANCH, self::unprotectedBranch());

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::REQUIRED, $report['status']);
        $this->assertSame('unknown', $report['rulesets'][0]['bypass']);
        $this->assertNull($report['rulesets'][0]['you_can_bypass']);
        $this->assertSame([], $report['holes']);
        $this->assertSame(['Quién se salta el ruleset 3 «org» (acme): con estas credenciales GitHub no enseña bypass_actors.'], $report['unverified']);
    }

    public function test_un_check_fijado_a_otra_app(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [
                self::rule('pull_request', self::codeOwners()),
                self::rule('required_status_checks', self::statusChecks(999)),
            ])
            ->json(self::BRANCH, self::unprotectedBranch());

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(999, $report['rulesets'][0]['app_id']);
        $this->assertStringContainsString('se acepta venga de donde venga', implode("\n", $report['holes']));
    }

    /**
     * La protección clásica vista con el token de un job: el check exigido,
     * sin fijar a una app y sin los administradores; la revisión de los
     * dueños, sin saber (`…/protection` pide administración).
     */
    public function test_la_proteccion_clasica_sin_administracion(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [])
            ->json(self::BRANCH, [
                'name' => 'main',
                'protected' => true,
                'protection' => ['enabled' => true, 'required_status_checks' => [
                    'enforcement_level' => 'non_admins',
                    'contexts' => ['LaraPack verify'],
                    'checks' => [['context' => 'LaraPack verify', 'app_id' => null]],
                ]],
            ])
            ->json(self::BRANCH.'/protection', ['message' => 'Resource not accessible by integration'], 403);

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::REQUIRED, $report['status']);
        $this->assertSame(['requires_check' => true, 'app_id' => null, 'admins_included' => false, 'code_owner_review' => null], $report['classic']);
        $this->assertCount(2, $report['holes']);
        $this->assertStringContainsString('se acepta venga de donde venga', $report['holes'][0]);
        $this->assertStringContainsString('enforce_admins=false', $report['holes'][1]);
        $this->assertSame(['Si la protección clásica exige la revisión de los dueños de código: sólo se ve con permisos de administración.'], $report['unverified']);
    }

    /**
     * Con enforcement_level «off» la protección existe pero no exige
     * ningún check, aunque la lista lo nombre.
     */
    public function test_la_proteccion_clasica_con_los_checks_apagados(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [])
            ->json(self::BRANCH, [
                'name' => 'main',
                'protection' => ['enabled' => true, 'required_status_checks' => ['enforcement_level' => 'off', 'contexts' => ['LaraPack verify']]],
            ]);

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::WITHOUT_CHECK, $report['status']);
        $this->assertSame(['requires_check' => false, 'app_id' => null, 'admins_included' => null, 'code_owner_review' => null], $report['classic']);
    }

    public function test_sin_enforce_admins_sabido_queda_sin_comprobar(): void
    {
        $api = (new FakeGitHubApi)
            ->json(self::RULES, [self::rule('pull_request', self::codeOwners(), 9)])
            ->json(self::BRANCH, [
                'name' => 'main',
                'protection' => ['enabled' => true, 'required_status_checks' => ['contexts' => ['LaraPack verify']]],
            ]);

        $report = BranchProtection::check($api, 'acme/notes', 'main');

        $this->assertSame(BranchProtection::REQUIRED, $report['status']);
        $this->assertContains('Si la protección clásica se aplica a los administradores (enforce_admins).', $report['unverified']);
        // La revisión la exige el ruleset 9: no hace falta saberla de la clásica.
        $this->assertStringNotContainsString('dueños de código', implode("\n", [...$report['holes'], ...$report['unverified']]));
    }

    /**
     * La rama va en la ruta segmento a segmento: `release/2.x` sigue siendo
     * una ruta, y lo que no es de URL va codificado.
     */
    public function test_la_rama_en_la_ruta(): void
    {
        $api = (new FakeGitHubApi)->json('repos/acme/notes/rules/branches/release/2.x%23b?per_page=100', []);

        BranchProtection::check($api, 'acme/notes', 'release/2.x#b');

        $this->assertSame([
            'repos/acme/notes/rules/branches/release/2.x%23b?per_page=100',
            'repos/acme/notes/branches/release/2.x%23b',
        ], $api->calls);
    }

    // LO QUE SE ENSEÑA EN ACTIONS

    public function test_las_anotaciones_de_actions_se_escapan(): void
    {
        $this->assertSame(
            '::warning title=a%3A b%2C c%25::uno: dos, 100%25%0Atres',
            BranchProtection::annotation('warning', 'a: b, c%', "uno: dos, 100%\ntres")
        );
    }

    public function test_el_resumen_del_job(): void
    {
        $base = ['repository' => 'acme/notes', 'branch' => 'main', 'holes' => [], 'unverified' => [], 'problems' => []];

        $required = BranchProtection::summary(['status' => BranchProtection::REQUIRED] + $base, 'php vendor/bin/builder');

        $this->assertStringContainsString('La rama `main` de `acme/notes` exige el check «LaraPack verify»', $required);
        $this->assertStringNotContainsString('Import a ruleset', $required);

        $missing = BranchProtection::summary(['status' => BranchProtection::UNPROTECTED] + $base, 'php artisan', 'https://ghe.example.com/');

        $this->assertStringContainsString('**La rama `main` de `acme/notes` no tiene protección ni reglas.**', $missing);
        $this->assertStringContainsString('https://ghe.example.com/acme/notes/settings/rules → *New ruleset* → *Import a ruleset* → `.github/rulesets/larapack-verify.json`', $missing);
        $this->assertStringContainsString("`gh api --method POST 'repos/acme/notes/rulesets' --input '.github/rulesets/larapack-verify.json'`", $missing);
        $this->assertStringContainsString("`php artisan larapack:ci` imprime la orden `gh api --method PUT 'repos/acme/notes/branches/main/protection' …`", $missing);
        $this->assertStringContainsString('`php artisan larapack:ci --check`', $missing);
        // El ruleset va entero: un paquete nuevo no lo tiene escrito.
        $this->assertStringContainsString("```json\n".rtrim(BranchProtection::rulesetJson())."\n```", $missing);

        $without = BranchProtection::summary(['status' => BranchProtection::WITHOUT_CHECK] + $base, 'php artisan');

        $this->assertStringContainsString('está protegida, pero no exige el check', $without);

        $unknown = BranchProtection::summary(['status' => BranchProtection::UNKNOWN, 'branch' => null, 'problems' => ['HTTP 403: no']] + $base, 'php artisan');

        $this->assertStringContainsString('**No se pudo saber si la rama `RAMA` de `acme/notes` exige el check «LaraPack verify».** No se da por protegida.', $unknown);
        $this->assertStringContainsString('- HTTP 403: no', $unknown);

        $holes = BranchProtection::summary(['status' => BranchProtection::REQUIRED, 'holes' => ['un hueco'], 'unverified' => ['algo sin ver']] + $base, 'php artisan');

        $this->assertStringContainsString("**Lo que queda abierto:**\n\n- un hueco", $holes);
        $this->assertStringContainsString("Sin comprobar con este token:\n\n- algo sin ver", $holes);

        // Un nombre que llega de fuera no rompe el Markdown.
        $odd = BranchProtection::summary(['status' => BranchProtection::REQUIRED, 'branch' => "a`b\nc"] + $base, 'php artisan');

        $this->assertStringContainsString("La rama `a'b c` de", $odd);
    }
}
