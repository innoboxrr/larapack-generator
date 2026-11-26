<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\CiGate;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * `larapack:ci` y el job de los paquetes nuevos: `larapack:verify --strict`
 * como check de la rama.
 *
 * Lo que importa del workflow es lo que la protección de la rama va a exigir:
 * un job con el nombre «LaraPack verify» que corra verify con --strict. Por
 * eso se lee como YAML, no como texto.
 */
final class CiCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Notes\\'));
    }

    private function ci(array $options = []): int
    {
        return $this->runCommand('larapack:ci', $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(string $relative = CiGate::WORKFLOW): array
    {
        return Yaml::parse($this->project->read($relative));
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed> el job que corre verify
     */
    private function verifyJob(array $workflow): array
    {
        foreach ($workflow['jobs'] as $job) {
            if (($job['name'] ?? null) === CiGate::JOB) {
                return $job;
            }
        }

        $this->fail('No hay un job «'.CiGate::JOB.'».');
    }

    /**
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    private function runs(array $job): array
    {
        return array_values(array_filter(array_column($job['steps'], 'run')));
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function setupPhp(array $job): array
    {
        foreach ($job['steps'] as $step) {
            if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
                return $step['with'];
            }
        }

        $this->fail('El job no instala PHP.');
    }

    private function git(string ...$arguments): void
    {
        exec('git -C '.escapeshellarg($this->project->path).' -c init.defaultBranch=trunk '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_en_un_paquete_crea_el_workflow_con_el_job_de_verify(): void
    {
        $this->assertSame(Command::SUCCESS, $this->ci(), $this->lastOutput);

        $workflow = $this->workflow();
        $job = $this->verifyJob($workflow);

        $this->assertSame(['main', 'master'], $workflow['on']['push']['branches']);
        $this->assertArrayHasKey('pull_request', $workflow['on']);
        $this->assertSame('ubuntu-latest', $job['runs-on']);
        $this->assertSame([
            'composer update --prefer-dist --no-interaction --no-progress',
            'php vendor/bin/builder larapack:verify --strict --format=json',
        ], $this->runs($job));

        // composer.json no declara php: la línea base.
        $this->assertSame('8.3', $this->setupPhp($job)['php-version']);
        $this->assertStringContainsString('creado', $this->lastOutput);
    }

    public function test_en_una_aplicacion_usa_artisan_el_php_del_lock_y_sus_extensiones(): void
    {
        $this->project->cleanup();
        $this->useProject(FakeProject::application());

        touch($this->project->path.'/artisan');
        file_put_contents($this->project->path.'/.env.example', "APP_NAME=Laravel\n");
        file_put_contents($this->project->path.'/composer.json', (string) json_encode([
            'name' => 'acme/school',
            'require' => ['php' => '^8.3', 'ext-mbstring' => '*', 'ext-bcmath' => '*', 'laravel/framework' => '^13.0'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ]));
        // Resuelto en 8.4: Symfony 8 la pide, así que en 8.3 no se instala.
        file_put_contents($this->project->path.'/composer.lock', (string) json_encode([
            'packages' => [
                ['name' => 'symfony/console', 'require' => ['php' => '>=8.4.1']],
                ['name' => 'nette/utils', 'require' => ['php' => '8.2 - 8.5']],
            ],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'require' => ['php' => '>=8.3']]],
        ]));

        $this->assertSame(Command::SUCCESS, $this->ci(), $this->lastOutput);

        $job = $this->verifyJob($this->workflow());
        $php = $this->setupPhp($job);

        $this->assertSame('8.4', $php['php-version']);
        $this->assertSame('bcmath, mbstring', $php['extensions']);
        $this->assertSame([
            'test -f .env || cp .env.example .env',
            'composer install --prefer-dist --no-interaction --no-progress',
            'php artisan larapack:verify --strict --format=json',
        ], $this->runs($job));

        // config.platform.php manda: es contra lo que resolvió Composer.
        $composer = json_decode($this->project->read('composer.json'), true);
        $composer['config']['platform']['php'] = '8.5.1';
        file_put_contents($this->project->path.'/composer.json', (string) json_encode($composer));

        $this->assertSame(Command::SUCCESS, $this->ci(['--force' => true]), $this->lastOutput);
        $this->assertSame('8.5', $this->setupPhp($this->verifyJob($this->workflow()))['php-version']);
    }

    public function test_reejecutarlo_no_cambia_nada(): void
    {
        $this->ci();
        $before = $this->project->read(CiGate::WORKFLOW);

        $this->assertSame(Command::SUCCESS, $this->ci());
        $this->assertSame($before, $this->project->read(CiGate::WORKFLOW));
        $this->assertStringContainsString('sin cambios', $this->lastOutput);
    }

    /**
     * Un workflow que alguien cambió es suyo: no se pisa sin --force, y se
     * dice qué cambiaría.
     */
    public function test_no_pisa_un_workflow_que_difiere_sin_force(): void
    {
        $this->ci();
        $edited = str_replace("coverage: none\n", "coverage: none\n                  tools: composer:v2\n", $this->project->read(CiGate::WORKFLOW));
        file_put_contents($this->project->path.'/'.CiGate::WORKFLOW, $edited);

        $this->assertSame(Command::FAILURE, $this->ci());
        $this->assertSame($edited, $this->project->read(CiGate::WORKFLOW));
        $this->assertStringContainsString('no se pisa sin --force', $this->lastOutput);
        $this->assertStringContainsString('-                  tools: composer:v2', $this->lastOutput);

        $this->assertSame(Command::SUCCESS, $this->ci(['--force' => true]));
        $this->assertStringNotContainsString('tools: composer:v2', $this->project->read(CiGate::WORKFLOW));
    }

    public function test_no_edita_otros_workflows(): void
    {
        mkdir($this->project->path.'/.github/workflows', 0777, true);
        file_put_contents($this->project->path.'/.github/workflows/tests.yml', "name: Tests\n");

        $this->ci(['--owner' => ['@acme']]);

        $this->assertSame("name: Tests\n", $this->project->read('.github/workflows/tests.yml'));
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->assertSame(Command::SUCCESS, $this->ci(['--dry-run' => true, '--owner' => ['@acme/arquitectura']]), $this->lastOutput);

        $this->assertFalse($this->project->has(CiGate::WORKFLOW));
        $this->assertFalse($this->project->has('.github/CODEOWNERS'));
        $this->assertStringContainsString('crearía', $this->lastOutput);
        $this->assertStringContainsString('+        name: LaraPack verify', $this->lastOutput);
        $this->assertStringContainsString('añadiría', $this->lastOutput);
    }

    public function test_sin_owner_no_inventa_un_dueno(): void
    {
        $this->ci();

        $this->assertFalse($this->project->has('.github/CODEOWNERS'));
        $this->assertStringContainsString('no se inventa quién aprueba', $this->lastOutput);
        $this->assertStringContainsString('/.larapack/hand-rolled.lock', $this->lastOutput);
    }

    public function test_con_owner_crea_codeowners_y_reejecutarlo_no_duplica(): void
    {
        $this->assertSame(Command::SUCCESS, $this->ci(['--owner' => ['@acme/arquitectura', 'dueno@acme.test']]), $this->lastOutput);

        $codeowners = $this->project->read('.github/CODEOWNERS');

        $this->assertStringContainsString("/.larapack/hand-rolled.lock @acme/arquitectura dueno@acme.test\n", $codeowners);
        $this->assertStringContainsString("/.github/workflows/larapack.yml @acme/arquitectura dueno@acme.test\n", $codeowners);
        $this->assertStringContainsString("/.github/CODEOWNERS @acme/arquitectura dueno@acme.test\n", $codeowners);

        $this->ci(['--owner' => ['@acme/arquitectura']]);

        $this->assertSame($codeowners, $this->project->read('.github/CODEOWNERS'));
        $this->assertStringContainsString('ya está', $this->lastOutput);
    }

    /**
     * Lo que ya había se queda, y un patrón que ya tiene otro dueño no se
     * toca: en CODEOWNERS gana la última línea, y añadir otra se lo quitaría.
     */
    public function test_fusiona_sin_borrar_lineas_ajenas(): void
    {
        mkdir($this->project->path.'/.github', 0777, true);
        $before = "# Equipo\n*       @acme/todos\n.larapack/hand-rolled.lock @alguien # lo decidió el comité\n/docs/ @acme/docs";
        file_put_contents($this->project->path.'/.github/CODEOWNERS', $before);

        $this->assertSame(Command::SUCCESS, $this->ci(['--owner' => ['@acme/arquitectura']]), $this->lastOutput);

        $after = $this->project->read('.github/CODEOWNERS');

        $this->assertStringStartsWith($before."\n\n", $after);
        $this->assertSame(1, substr_count($after, 'hand-rolled.lock'));
        $this->assertStringContainsString("/.github/workflows/larapack.yml @acme/arquitectura\n", $after);
        $this->assertStringContainsString('otro dueño', $this->lastOutput);
        $this->assertStringContainsString('@alguien', $this->lastOutput);
    }

    /**
     * GitHub usa el primer CODEOWNERS que encuentra: crear uno en .github/
     * dejaría sin efecto el de la raíz.
     */
    public function test_usa_el_codeowners_que_ya_lee_github(): void
    {
        file_put_contents($this->project->path.'/CODEOWNERS', "* @acme/todos\n");

        $this->ci(['--owner' => ['@acme/arquitectura']]);

        $this->assertFalse($this->project->has('.github/CODEOWNERS'));
        $this->assertStringContainsString("/CODEOWNERS @acme/arquitectura\n", $this->project->read('CODEOWNERS'));
        $this->assertStringStartsWith("* @acme/todos\n", $this->project->read('CODEOWNERS'));
    }

    public function test_un_owner_invalido_no_escribe_nada(): void
    {
        $this->assertSame(Command::FAILURE, $this->ci(['--owner' => ['acme']]));

        $this->assertFalse($this->project->has(CiGate::WORKFLOW));
        $this->assertFalse($this->project->has('.github/CODEOWNERS'));
    }

    /**
     * Lo que no puede hacer lo imprime para el dueño, con el repositorio y la
     * rama que dice git.
     */
    public function test_imprime_como_proteger_la_rama_sin_ejecutarlo(): void
    {
        $this->git('init', '--quiet');
        $this->git('remote', 'add', 'origin', 'git@github.com:acme/notes.git');
        $this->git('symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/trunk');

        $this->assertSame(Command::SUCCESS, $this->ci(), $this->lastOutput);

        $this->assertSame(['trunk'], $this->workflow()['on']['push']['branches']);
        $this->assertStringContainsString(
            "gh api --method PUT repos/acme/notes/branches/trunk/protection -F 'required_status_checks[strict]=false' -f 'required_status_checks[contexts][]=LaraPack verify' -F 'enforce_admins=false' -F 'required_pull_request_reviews[require_code_owner_reviews]=true' -F 'required_pull_request_reviews[required_approving_review_count]=1' -F 'restrictions=null'",
            $this->lastOutput
        );
        $this->assertStringContainsString('Require review from Code Owners', $this->lastOutput);
        $this->assertStringContainsString('larapack:hand-rolled approve --all', $this->lastOutput);

        // Sin remoto conocido no se inventa: se deja para que se rellene.
        $this->project->cleanup();
        $this->useProject(FakeProject::library());
        $this->ci();

        $this->assertStringContainsString('repos/DUEÑO/REPOSITORIO/branches/RAMA/protection', $this->lastOutput);
    }

    /**
     * El paquete nuevo trae el mismo check en su tests.yml: la protección de
     * la rama exige el mismo nombre en los dos sitios.
     */
    public function test_el_paquete_nuevo_trae_el_mismo_job(): void
    {
        $this->project->cleanup();
        $this->useProject(FakeProject::empty());

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:new', [
            'name' => 'acme/shop-catalog',
            'directory' => $this->project->path,
        ]), $this->lastOutput);

        $workflow = $this->workflow('.github/workflows/tests.yml');
        $job = $this->verifyJob($workflow);

        $this->assertSame('verify', array_search($job, $workflow['jobs'], true));
        $this->assertContains('php vendor/bin/builder larapack:verify --strict --format=json', $this->runs($job));
        $this->assertSame('innoboxrr/.github/.github/workflows/php-tests.yml@main', $workflow['jobs']['tests']['uses']);

        // Y lo que ese job corre pasa en el paquete recién creado.
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:verify', ['--strict' => true, '--format' => 'json']), $this->lastOutput);
    }
}
