<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\BranchProtection;
use Innoboxrr\LarapackGenerator\Support\GitHub\Response;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeGitHubApi;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:ci --check`: si la rama por defecto exige el check «LaraPack
 * verify», preguntándole a GitHub. Es lo que corre el último paso del
 * workflow, con `--format=github`.
 *
 * GitHub es FakeGitHubApi: nunca hay red. Lo que se prueba es lo que ve
 * quien lo corre —el estado, la guía cuando falta, el código de salida— y
 * que nunca escribe nada.
 */
final class CiCheckTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Notes\\'));
        $this->env('GITHUB_REPOSITORY', null);
        $this->env('GITHUB_STEP_SUMMARY', null);
        $this->env('GITHUB_SERVER_URL', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    private function env(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->environment)) {
            $this->environment[$name] = getenv($name);
        }

        putenv($value === null ? $name : "{$name}={$value}");
    }

    private function remote(string $url = 'git@github.com:acme/notes.git'): void
    {
        foreach ([['init', '--quiet'], ['remote', 'add', 'origin', $url]] as $arguments) {
            exec('git -C '.escapeshellarg($this->project->path).' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function check(?FakeGitHubApi $api, array $options = []): int
    {
        BranchProtection::useApi($api);

        return $this->runCommand('larapack:ci', ['--check' => true, ...$options]);
    }

    private static function github(string $branch = 'trunk'): FakeGitHubApi
    {
        return (new FakeGitHubApi)
            ->json('repos/acme/notes', ['full_name' => 'acme/notes', 'default_branch' => $branch])
            ->json("repos/acme/notes/branches/{$branch}", ['name' => $branch, 'protected' => false, 'protection' => ['enabled' => false]]);
    }

    private static function required(FakeGitHubApi $api, string $branch = 'trunk', ?int $integration = 15368): FakeGitHubApi
    {
        return $api
            ->json("repos/acme/notes/rules/branches/{$branch}?per_page=100", [
                ['type' => 'pull_request', 'parameters' => ['require_code_owner_review' => true], 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'acme/notes', 'ruleset_id' => 42],
                ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => [['context' => 'LaraPack verify', ...($integration === null ? [] : ['integration_id' => $integration])]]], 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'acme/notes', 'ruleset_id' => 42],
            ])
            ->json('repos/acme/notes/rulesets/42?includes_parents=true', ['id' => 42, 'name' => 'LaraPack verify', 'bypass_actors' => [], 'current_user_can_bypass' => 'never']);
    }

    public function test_sin_proteccion_lo_dice_guia_y_sale_con_1(): void
    {
        $this->remote();
        $api = self::github()->json('repos/acme/notes/rules/branches/trunk?per_page=100', []);

        $this->assertSame(Command::FAILURE, $this->check($api), $this->lastOutput);

        $this->assertStringContainsString('acme/notes, rama trunk (por GitHub de prueba):', $this->lastOutput);
        $this->assertStringContainsString('Sin protección ni reglas: un PR en rojo se fusiona igual', $this->lastOutput);
        $this->assertStringContainsString('protección clásica: no tiene.', $this->lastOutput);
        // La guía, con el repositorio y la rama que dijo GitHub.
        $this->assertStringContainsString('Proteger la rama trunk', $this->lastOutput);
        $this->assertStringContainsString('https://github.com/acme/notes/settings/rules -> New ruleset -> Import a ruleset', $this->lastOutput);
        $this->assertStringContainsString("gh api --method POST 'repos/acme/notes/rulesets' --input '.github/rulesets/larapack-verify.json'", $this->lastOutput);
        $this->assertStringContainsString("gh api --method PUT 'repos/acme/notes/branches/trunk/protection'", $this->lastOutput);

        // Sólo lee: ni el workflow, ni el ruleset.
        $this->assertFalse($this->project->has('.github'));
    }

    public function test_protegida_sin_el_check(): void
    {
        $this->remote();
        $api = self::github()->json('repos/acme/notes/rules/branches/trunk?per_page=100', [
            ['type' => 'pull_request', 'parameters' => ['require_code_owner_review' => true], 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'acme/notes', 'ruleset_id' => 8],
        ]);

        $this->assertSame(Command::FAILURE, $this->check($api), $this->lastOutput);

        $this->assertStringContainsString('Protegida, pero NO exige «LaraPack verify»', $this->lastOutput);
        $this->assertStringContainsString('el ruleset 8 (acme/notes): no exige el check, revisión de dueños de código, bypass: sin saber.', $this->lastOutput);
        $this->assertStringContainsString('Import a ruleset', $this->lastOutput);
    }

    public function test_exigida_sale_con_0_y_no_guia(): void
    {
        $this->remote('https://github.com/acme/notes');

        $this->assertSame(Command::SUCCESS, $this->check(self::required(self::github())), $this->lastOutput);

        $this->assertStringContainsString("\n  Exige «LaraPack verify».\n", $this->lastOutput);
        $this->assertStringContainsString('el ruleset 42 «LaraPack verify» (acme/notes): exige el check (de GitHub Actions), revisión de dueños de código, bypass: nadie.', $this->lastOutput);
        $this->assertStringNotContainsString('Import a ruleset', $this->lastOutput);
        $this->assertStringNotContainsString('Abierto:', $this->lastOutput);
    }

    public function test_exigida_con_huecos_los_enseña(): void
    {
        $this->remote();

        $this->assertSame(Command::SUCCESS, $this->check(self::required(self::github(), 'trunk', null)), $this->lastOutput);

        $this->assertStringContainsString('exige el check (de cualquier fuente)', $this->lastOutput);
        $this->assertStringContainsString('Abierto:', $this->lastOutput);
        $this->assertStringContainsString('se acepta venga de donde venga', $this->lastOutput);
    }

    /**
     * Sin con qué preguntar no se sabe, y sale con 2: nunca «Exige», nunca
     * 0.
     */
    public function test_sin_saber_sale_con_2_y_nunca_dice_protegida(): void
    {
        $this->remote();

        $this->assertSame(Command::INVALID, $this->check(null), $this->lastOutput);

        $this->assertStringContainsString('No se pudo saber si exige «LaraPack verify». No se da por protegida.', $this->lastOutput);
        $this->assertStringContainsString('ni GH_TOKEN ni GITHUB_TOKEN en el entorno, ni gh en el PATH', $this->lastOutput);
        $this->assertStringNotContainsString("\n  Exige «", $this->lastOutput);
        $this->assertStringContainsString('Import a ruleset', $this->lastOutput);

        // Con la API, pero sin red.
        $offline = (new FakeGitHubApi)->on('repos/acme/notes', Response::failure('Sin respuesta de api.github.com: php_network_getaddresses'));

        $this->assertSame(Command::INVALID, $this->check($offline), $this->lastOutput);
        $this->assertStringContainsString('php_network_getaddresses', $this->lastOutput);
        $this->assertStringContainsString('acme/notes, rama sin saber (por GitHub de prueba):', $this->lastOutput);
    }

    public function test_en_json(): void
    {
        $this->remote();

        $this->assertSame(Command::SUCCESS, $this->check(self::required(self::github()), ['--format' => 'json']), $this->lastOutput);

        $report = json_decode($this->lastOutput, true);

        $this->assertSame('required', $report['status']);
        $this->assertSame('acme/notes', $report['repository']);
        $this->assertSame('trunk', $report['branch']);
        $this->assertSame('LaraPack verify', $report['check']);
        $this->assertSame(42, $report['rulesets'][0]['id']);
        $this->assertSame(['holes' => [], 'unverified' => [], 'problems' => []], array_intersect_key($report, array_flip(['holes', 'unverified', 'problems'])));
    }

    /**
     * Lo que corre el workflow: el ::warning en la corrida y el resumen del
     * job con los pasos. En un job de Actions el remoto de actions/checkout es
     * https://github.com/…, pero aquí se prueba también sin él: con
     * GITHUB_REPOSITORY.
     */
    public function test_en_actions_avisa_y_escribe_el_resumen(): void
    {
        $summary = $this->project->path.'/summary.md';
        file_put_contents($summary, "## Antes\n");
        $this->env('GITHUB_REPOSITORY', 'acme/notes');
        $this->env('GITHUB_STEP_SUMMARY', $summary);
        $this->env('GITHUB_SERVER_URL', 'https://github.com');

        $api = self::github('main')->json('repos/acme/notes/rules/branches/main?per_page=100', []);

        $this->assertSame(Command::FAILURE, $this->check($api, ['--format' => 'github']), $this->lastOutput);

        $this->assertStringStartsWith(
            '::warning title=La puerta de LaraPack no está cerrada::La rama main de acme/notes no está protegida: un PR en rojo se fusiona igual. Los pasos, en el resumen del job.',
            $this->lastOutput
        );

        $written = (string) file_get_contents($summary);

        // Se añade, no se pisa: otros pasos escriben ahí.
        $this->assertStringStartsWith("## Antes\n### La puerta de LaraPack\n", $written);
        $this->assertStringContainsString('https://github.com/acme/notes/settings/rules → *New ruleset* → *Import a ruleset*', $written);
        $this->assertStringContainsString('`php vendor/bin/builder larapack:ci --check`', $written);
        $this->assertStringContainsString('"integration_id": 15368', $written);

        // Exigida y con un hueco: un ::warning por hueco, ninguno de «no está cerrada».
        file_put_contents($summary, '');

        $this->assertSame(Command::SUCCESS, $this->check(self::required(self::github('main'), 'main', null), ['--format' => 'github']), $this->lastOutput);

        $this->assertStringContainsString('::warning title=La puerta de LaraPack tiene un hueco::El check «LaraPack verify» se acepta venga de donde venga', $this->lastOutput);
        $this->assertStringNotContainsString('no está cerrada', $this->lastOutput);
        $this->assertStringContainsString('exige el check «LaraPack verify»', (string) file_get_contents($summary));

        // Sin saber: también se avisa, con el porqué.
        $this->assertSame(Command::INVALID, $this->check(null, ['--format' => 'github']), $this->lastOutput);
        $this->assertStringStartsWith('::warning title=La puerta de LaraPack no está cerrada::No se pudo saber si la rama por defecto de acme/notes exige «LaraPack verify»; no se da por protegida. No hay con qué preguntar', $this->lastOutput);
    }

    public function test_repo_y_rama_a_mano(): void
    {
        $api = (new FakeGitHubApi)
            ->json('repos/otra/cosa/rules/branches/release/2.x?per_page=100', [])
            ->json('repos/otra/cosa/branches/release/2.x', ['name' => 'release/2.x', 'protection' => ['enabled' => false]]);

        $this->assertSame(Command::FAILURE, $this->check($api, ['--repo' => 'otra/cosa', '--branch' => 'release/2.x']), $this->lastOutput);

        $this->assertSame(['repos/otra/cosa/rules/branches/release/2.x?per_page=100', 'repos/otra/cosa/branches/release/2.x'], $api->calls);
        $this->assertStringContainsString('otra/cosa, rama release/2.x', $this->lastOutput);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidas(): array
    {
        return [
            '--check con --force' => [['--check' => true, '--force' => true], '--check sólo lee: no va con --force.'],
            '--check con --dry-run' => [['--check' => true, '--dry-run' => true], '--check sólo lee: no va con --dry-run.'],
            '--check con --owner' => [['--check' => true, '--owner' => ['@acme/x']], '--check sólo lee: no va con --owner.'],
            '--check con --runs-on' => [['--check' => true, '--runs-on' => ['self-hosted']], '--check sólo lee: no va con --runs-on.'],
            'un formato que no existe' => [['--check' => true, '--format' => 'xml'], '--format=xml no existe: txt, json o github.'],
            'un repositorio que no lo es' => [['--check' => true, '--repo' => '../../user'], '«../../user» no es dueño/repositorio.'],
            '--repo sin --check' => [['--repo' => 'acme/notes'], '--repo sólo va con --check.'],
            '--branch sin --check' => [['--branch' => 'main'], '--branch sólo va con --check.'],
            '--format sin --check' => [['--format' => 'json'], '--format sólo va con --check.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidas')]
    public function test_lo_que_no_se_combina_falla_sin_escribir_ni_preguntar(array $options, string $message): void
    {
        $api = new FakeGitHubApi;
        BranchProtection::useApi($api);

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:ci', $options), $this->lastOutput);

        $this->assertStringContainsString($message, $this->lastOutput);
        $this->assertSame([], $api->calls);
        $this->assertFalse($this->project->has('.github'));
    }
}
