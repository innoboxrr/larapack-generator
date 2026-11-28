<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\BranchProtection;
use Innoboxrr\LarapackGenerator\Support\CiGate;
use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\NamedApproval;
use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeGitHubApi;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Las aprobaciones sin terminal (`approved_by` en el candado), a la vista de
 * quien revisa: verify las lista y el paso de la CI que genera `larapack:ci`
 * (`larapack:hand-rolled list --format=github`) las anota sobre su línea del
 * candado —en el diff del PR— y comprueba con GitHub que su corrida es la del
 * workflow de aprobación, lanzada a mano por quien dice.
 *
 * GitHub es FakeGitHubApi: nunca hay red.
 */
final class NamedApprovalTest extends TestCase
{
    private const WEBHOOK = 'Acme\Notes\Http\Controllers\InboundWebhookController';

    private const TOKEN = 'Acme\Notes\Http\Controllers\TokenController';

    private const RUN = 'https://github.com/acme/notes/actions/runs/4242';

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Notes\\'));
        $this->env('GITHUB_REPOSITORY', 'acme/notes');

        foreach (['GITHUB_STEP_SUMMARY', 'GITHUB_ACTIONS', 'GITHUB_EVENT_NAME', 'GITHUB_RUN_ID', 'GITHUB_WORKFLOW_REF', 'GITHUB_ACTOR', 'GITHUB_TRIGGERING_ACTOR', 'GITHUB_SERVER_URL', OwnerConfirmation::APPROVER_ENV] as $name) {
            $this->env($name, null);
        }

        // Una en la terminal (sin approved_by) y otra sin terminal.
        mkdir($this->project->path.'/.larapack');
        HandRolledLock::write($this->project->path, [
            self::TOKEN => ['reason' => 'RFC 6749.', 'fingerprint' => null],
            self::WEBHOOK => ['reason' => 'La firma se calcula sobre el cuerpo en bruto.', 'fingerprint' => null, 'approved_by' => 'ana', 'approved_run' => self::RUN],
        ]);
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

    /**
     * @param  array<string, mixed>  $run
     */
    private static function github(array $run = []): FakeGitHubApi
    {
        return (new FakeGitHubApi)->json('repos/acme/notes/actions/runs/4242', $run + [
            'id' => 4242,
            'path' => NamedApproval::WORKFLOW,
            'event' => 'workflow_dispatch',
            'actor' => ['login' => 'ana'],
            'triggering_actor' => ['login' => 'ana'],
        ]);
    }

    private function annotations(?FakeGitHubApi $api): int
    {
        BranchProtection::useApi($api);

        return $this->runCommand('larapack:hand-rolled', ['action' => 'list', '--format' => 'github']);
    }

    public function test_verify_lista_las_aprobadas_sin_terminal(): void
    {
        $this->runCommand('larapack:verify');

        $this->assertStringContainsString('Aprobadas sin terminal en '.HandRolledLock::PATH.' (1)', $this->lastOutput);
        $this->assertStringContainsString(self::WEBHOOK.' — por ana en '.self::RUN, $this->lastOutput);
        $this->assertStringNotContainsString(self::TOKEN.' — por', $this->lastOutput);

        $this->runCommand('larapack:verify', ['--format' => 'json']);
        $report = json_decode($this->lastOutput, true);

        $this->assertSame([[
            'target' => self::WEBHOOK,
            'approved_by' => 'ana',
            'approved_run' => self::RUN,
            'line' => HandRolledLock::lineOf($this->project->path, self::WEBHOOK),
        ]], $report['named_approvals']);
    }

    public function test_sin_aprobadas_sin_terminal_verify_no_dice_nada(): void
    {
        HandRolledLock::write($this->project->path, [self::TOKEN => ['reason' => 'RFC 6749.', 'fingerprint' => null]]);

        $this->runCommand('larapack:verify');
        $this->assertStringNotContainsString('Aprobadas sin terminal', $this->lastOutput);

        $this->runCommand('larapack:verify', ['--format' => 'json']);
        $this->assertSame([], json_decode($this->lastOutput, true)['named_approvals']);

        $this->assertSame(Command::SUCCESS, $this->annotations(self::github()));
        $this->assertSame('', trim($this->lastOutput));
    }

    public function test_la_ci_anota_la_aprobada_sin_terminal_sobre_su_linea_del_candado(): void
    {
        $line = HandRolledLock::lineOf($this->project->path, self::WEBHOOK);
        $this->assertStringContainsString('"target": "'.str_replace('\\', '\\\\', self::WEBHOOK).'"', explode("\n", $this->project->read(HandRolledLock::PATH))[$line - 1]);

        $this->assertSame(Command::SUCCESS, $this->annotations(self::github()), $this->lastOutput);

        $this->assertStringStartsWith('::notice file=.larapack/hand-rolled.lock,line='.$line.',title=Aprobada sin terminal::', trim($this->lastOutput));
        $this->assertStringContainsString(self::WEBHOOK.': aprobada sin terminal por ana en '.self::RUN, $this->lastOutput);
        $this->assertStringNotContainsString(self::TOKEN, $this->lastOutput);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: string}>
     */
    public static function forgedRuns(): array
    {
        return [
            'la corrida no existe' => [null, 'GitHub no da esa corrida'],
            'de otro workflow' => [['path' => '.github/workflows/agente.yml'], 'no es del workflow de aprobación'],
            'no lanzada a mano' => [['event' => 'push'], 'no se lanzó a mano'],
            'la lanzó otra persona' => [['actor' => ['login' => 'mallory'], 'triggering_actor' => ['login' => 'mallory']], 'la lanzó mallory, no ana'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    #[DataProvider('forgedRuns')]
    public function test_la_ci_falla_con_una_aprobacion_sin_terminal_que_github_no_confirma(?array $run, string $why): void
    {
        $api = $run === null ? new FakeGitHubApi : self::github($run);

        $this->assertSame(Command::FAILURE, $this->annotations($api), $this->lastOutput);
        $this->assertStringStartsWith('::error file=.larapack/hand-rolled.lock,line=', trim($this->lastOutput));
        $this->assertStringContainsString($why, $this->lastOutput);
    }

    public function test_la_ci_falla_sin_corrida_apuntada_o_de_otro_repositorio_o_sin_github(): void
    {
        HandRolledLock::write($this->project->path, [self::WEBHOOK => ['reason' => 'x', 'fingerprint' => null, 'approved_by' => 'ana']]);
        $this->assertSame(Command::FAILURE, $this->annotations(self::github()));
        $this->assertStringContainsString('approved_run', $this->lastOutput);

        HandRolledLock::write($this->project->path, [self::WEBHOOK => ['reason' => 'x', 'fingerprint' => null, 'approved_by' => 'ana', 'approved_run' => 'https://github.com/otro/repo/actions/runs/4242']]);
        $this->assertSame(Command::FAILURE, $this->annotations(self::github()));
        $this->assertStringContainsString('no es una corrida de este repositorio', $this->lastOutput);

        HandRolledLock::write($this->project->path, [self::WEBHOOK => ['reason' => 'x', 'fingerprint' => null, 'approved_by' => 'ana', 'approved_run' => self::RUN]]);
        $this->assertSame(Command::FAILURE, $this->annotations(null));
        $this->assertStringContainsString('No hay con qué preguntar a GitHub', $this->lastOutput);
    }

    public function test_github_solo_es_formato_de_list(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:hand-rolled', ['action' => 'approve', '--all' => true, '--format' => 'github']));
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:hand-rolled', ['action' => 'list', '--format' => 'xml']));
    }

    public function test_el_candado_rechaza_un_approved_run_que_no_es_una_direccion(): void
    {
        file_put_contents($this->project->path.'/'.HandRolledLock::PATH, json_encode([
            ['target' => self::WEBHOOK, 'reason' => 'x', 'fingerprint' => null, 'approved_by' => 'ana', 'approved_run' => 'javascript:alert(1)'],
        ]));

        $this->expectExceptionMessage('approved_run');
        HandRolledLock::read($this->project->path);
    }

    public function test_larapack_ci_con_approval_escribe_el_workflow_de_aprobacion(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:ci', ['--owner' => ['@ana']]), $this->lastOutput);
        $this->assertFalse($this->project->has(NamedApproval::WORKFLOW));
        // CODEOWNERS lo cubre aunque no exista: nadie añade uno a su gusto.
        $this->assertStringContainsString('/'.NamedApproval::WORKFLOW.' @ana', $this->project->read('.github/CODEOWNERS'));

        $verify = Yaml::parse($this->project->read(CiGate::WORKFLOW))['jobs']['verify'];
        $this->assertSame(['contents' => 'read', 'actions' => 'read'], $verify['permissions']);
        $this->assertContains('php vendor/bin/builder larapack:hand-rolled list --format=github', array_column($verify['steps'], 'run'));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:ci', ['--owner' => ['@ana'], '--approval' => true]), $this->lastOutput);
        $workflow = Yaml::parse($this->project->read(NamedApproval::WORKFLOW));

        // Sólo a mano, y a nombre de quien la lanza.
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $job = $workflow['jobs']['approve'];
        $this->assertSame(NamedApproval::JOB, $job['name']);
        $this->assertSame(['contents' => 'write'], $job['permissions']);
        $approve = array_values(array_filter($job['steps'], fn (array $step): bool => ($step['name'] ?? null) === 'Aprobar'))[0];
        $this->assertSame('${{ github.triggering_actor }}', $approve['env'][OwnerConfirmation::APPROVER_ENV]);
        $this->assertStringContainsString('larapack:hand-rolled approve "$@" --approver="$LARAPACK_APPROVER"', $approve['run']);
        // Las entradas no van dentro de la orden.
        $this->assertStringNotContainsString('${{', $approve['run']);

        // Dos veces, lo mismo.
        $before = $this->project->read(NamedApproval::WORKFLOW);
        $this->runCommand('larapack:ci', ['--owner' => ['@ana'], '--approval' => true]);
        $this->assertSame($before, $this->project->read(NamedApproval::WORKFLOW));
    }

    /**
     * La corrida del workflow que genera `larapack:ci --approval` es la que
     * acepta NamedApproval: el nombre del archivo, el actor y la opción.
     */
    public function test_el_entorno_de_esa_corrida_es_el_que_acepta_la_aprobacion(): void
    {
        $env = [
            'GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_REPOSITORY' => 'acme/notes', 'GITHUB_RUN_ID' => '4242',
            'GITHUB_WORKFLOW_REF' => 'acme/notes/'.NamedApproval::WORKFLOW.'@refs/heads/feature', 'GITHUB_ACTOR' => 'ana', 'GITHUB_TRIGGERING_ACTOR' => 'ana',
            OwnerConfirmation::APPROVER_ENV => 'ana',
        ];

        $this->assertSame(['approver' => 'ana', 'run' => self::RUN, 'error' => null], NamedApproval::fromEnvironment('ana', $env));
        $this->assertSame(['approver' => null, 'run' => null, 'error' => null], NamedApproval::fromEnvironment(null, $env));
        $this->assertNotNull(NamedApproval::fromEnvironment('ana', ['GITHUB_ACTIONS' => 'false'] + $env)['error']);
        $this->assertNotNull(NamedApproval::fromEnvironment('ana', ['GITHUB_RUN_ID' => '42; rm -rf'] + $env)['error']);
        $this->assertNotNull(NamedApproval::fromEnvironment('', $env)['error']);
    }
}
