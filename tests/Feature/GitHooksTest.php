<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\GitHooks;
use Innoboxrr\LarapackGenerator\Tests\Support\HookProject;
use PHPUnit\Framework\TestCase;

/**
 * El hook de git, en repositorios de verdad: git lanza el script, el script
 * lanza la guardia, y lo que se mira es si el commit (o el push) entra.
 *
 * Es la capa que no depende del agente, así que se prueba como la usa
 * cualquiera: `git commit` y `git push`, sin terminal —como corre un agente—
 * salvo donde la prueba es justo la terminal.
 */
final class GitHooksTest extends TestCase
{
    private HookProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = new HookProject;
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    private function install(): void
    {
        $result = GitHooks::install($this->project->path);

        $this->assertSame('set', $result['hooks_path'], implode("\n", $result['instructions']));
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function commit(string $message = 'cambio', string ...$options): array
    {
        return $this->project->run(['git', 'commit', '--quiet', '-m', $message, ...$options], '', [], true);
    }

    private function commitWithoutHooks(string $message): void
    {
        $this->project->git('add', '--all');
        $this->project->git('commit', '--quiet', '--no-verify', '-m', $message);
    }

    public function test_instala_los_dos_hooks_y_le_dice_a_git_que_los_use(): void
    {
        $this->install();

        foreach (GitHooks::HOOKS as $hook) {
            $file = $this->project->path.'/.githooks/'.$hook;

            $this->assertFileExists($file);
            $this->assertStringContainsString(GitHooks::SIGNATURE.$hook, (string) file_get_contents($file));
            $this->assertStringStartsWith('#!/bin/sh', (string) file_get_contents($file));

            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertTrue(is_executable($file), "{$hook} debería ser ejecutable: git no corre un hook que no lo es.");
            }
        }

        $this->assertSame('.githooks', trim($this->project->git('config', '--get', 'core.hooksPath')));
        $this->assertTrue(GitHooks::status($this->project->path)['active']);
    }

    public function test_reinstalarlo_no_cambia_nada(): void
    {
        $this->install();

        $again = GitHooks::install($this->project->path);

        $this->assertSame([], $again['written']);
        $this->assertCount(2, $again['current']);
        $this->assertSame('present', $again['hooks_path']);
    }

    /**
     * core.hooksPath ya puesto (husky, otro gestor) es del proyecto: no se
     * cambia, se dice cómo encadenar.
     */
    public function test_no_cambia_un_core_hooks_path_que_ya_existe(): void
    {
        $this->project->git('config', 'core.hooksPath', '.husky/_');

        $result = GitHooks::install($this->project->path);

        $this->assertSame('manual', $result['hooks_path']);
        $this->assertSame('.husky/_', trim($this->project->git('config', '--get', 'core.hooksPath')));
        $this->assertStringContainsString('sh .githooks/<hook>', implode("\n", $result['instructions']));
        $this->assertFalse(GitHooks::status($this->project->path)['active']);
    }

    /**
     * Con hooks propios en .git/hooks, core.hooksPath los dejaría sin correr.
     */
    public function test_no_deja_sin_correr_los_hooks_propios_de_git_hooks(): void
    {
        $this->project->write('.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");

        $result = GitHooks::install($this->project->path);

        $this->assertSame('manual', $result['hooks_path']);
        $this->assertStringContainsString('pre-commit', implode("\n", $result['instructions']));
        $this->assertSame('', trim($this->project->run(['git', 'config', '--get', 'core.hooksPath'])['stdout']));
    }

    public function test_un_hook_del_proyecto_en_githooks_no_se_pisa(): void
    {
        $this->project->write('.githooks/pre-commit', "#!/bin/sh\necho propio\n");

        $result = GitHooks::install($this->project->path);

        $this->assertSame("#!/bin/sh\necho propio\n", file_get_contents($this->project->path.'/.githooks/pre-commit'));
        $this->assertFileExists($this->project->path.'/.githooks/larapack-pre-commit');
        $this->assertContains($this->project->path.'/.githooks/pre-commit', $result['foreign']);
        $this->assertStringContainsString('sh .githooks/larapack-pre-commit', implode("\n", $result['instructions']));
        $this->assertSame('foreign', GitHooks::status($this->project->path)['hooks']['pre-commit']);
    }

    public function test_un_hook_del_proyecto_cuenta_como_encadenado_solo_si_llama_al_de_larapack(): void
    {
        // Mencionar la carpeta no es llamarlo: con esto, git nunca corre la guardia.
        $this->project->write('.githooks/pre-commit', "#!/bin/sh\n# antes: .githooks/pre-commit\nrun-parts .githooks/pre-commit.d\n");
        GitHooks::install($this->project->path);
        chmod($this->project->path.'/.githooks/pre-commit', 0755);

        $this->assertSame('foreign', GitHooks::status($this->project->path)['hooks']['pre-commit']);
        $this->assertFalse(GitHooks::status($this->project->path)['active']);

        $this->project->write('.githooks/pre-commit', "#!/bin/sh\nsh .githooks/larapack-pre-commit \"$@\" || exit $?\necho propio\n");
        chmod($this->project->path.'/.githooks/pre-commit', 0755);

        $this->assertSame('chained', GitHooks::status($this->project->path)['hooks']['pre-commit']);
    }

    public function test_sin_repositorio_escribe_los_scripts_y_dice_como_activarlos(): void
    {
        $project = new HookProject(false);

        try {
            $result = GitHooks::install($project->path);

            $this->assertSame('no-repository', $result['hooks_path']);
            $this->assertFileExists($project->path.'/.githooks/pre-commit');
            $this->assertStringContainsString('git config core.hooksPath .githooks', implode("\n", $result['instructions']));
        } finally {
            $project->cleanup();
        }
    }

    /**
     * Varias consolas a la vez (9.4.0): lo que una rama no debe empujar a las
     * demás es un contrato que no corresponde a lo generado.
     */
    public function test_un_laraimport_cambiado_sin_volver_a_importar_no_entra(): void
    {
        $contract = fn (string $type): string => (string) json_encode(['models' => [['name' => 'Tag', 'props' => [['name' => 'label', 'type' => $type]]]]], JSON_PRETTY_PRINT);
        $import = fn (): array => $this->project->run([PHP_BINARY, 'vendor/bin/builder', 'larapack:import', '--root='.$this->project->path, '--force']);

        $this->install();
        $this->project->write('laraimport.json', $contract('string'));
        $this->assertSame(0, $import()['code']);
        $this->project->git('add', '--all');
        $this->assertSame(0, $this->commit('generado')['code']);

        $this->project->write('laraimport.json', $contract('text'));
        $this->project->git('add', 'laraimport.json');
        $stale = $this->commit('el contrato, sin importar');

        $this->assertSame(1, $stale['code']);
        $this->assertStringContainsString('[contract-outdated] laraimport.json', $stale['stderr']);
        $this->assertStringContainsString('larapack:import --force', $stale['stderr']);

        $this->assertSame(0, $import()['code']);
        $this->project->git('add', '--all');
        $imported = $this->commit('el contrato y lo que genera');

        $this->assertSame(0, $imported['code'], $imported['stderr']);
    }

    public function test_un_manifiesto_a_medio_fusionar_no_entra(): void
    {
        $this->install();
        $this->project->write('laraimport.json', (string) json_encode(['models' => [['name' => 'Tag', 'props' => [['name' => 'label', 'type' => 'string']]]]]));
        $this->assertSame(0, $this->project->run([PHP_BINARY, 'vendor/bin/builder', 'larapack:import', '--root='.$this->project->path])['code']);
        $this->project->git('add', '--all');
        $this->assertSame(0, $this->commit('generado')['code']);

        $manifest = (string) file_get_contents($this->project->path.'/.larapack/manifest.json');
        $this->project->write('.larapack/manifest.json', "<<<<<<< HEAD\n{$manifest}=======\n{$manifest}>>>>>>> otra\n");
        $this->project->git('add', '--all');
        $conflicted = $this->commit('con los marcadores dentro');

        $this->assertSame(1, $conflicted['code']);
        $this->assertStringContainsString('[manifest-unreadable] .larapack/manifest.json', $conflicted['stderr']);
        $this->assertStringContainsString('larapack:reconcile', $conflicted['stderr']);
    }

    public function test_un_commit_limpio_entra_y_uno_con_logica_en_el_controlador_no(): void
    {
        $this->install();
        $this->project->git('add', '--all');

        $clean = $this->commit('limpio');
        $this->assertSame(0, $clean['code'], $clean['stderr']);

        $this->project->controller(false);
        $this->project->git('add', '--all');
        $dirty = $this->commit('con lógica');

        $this->assertSame(1, $dirty['code']);
        $this->assertStringContainsString('lo que vas a comitear se sale del contrato', $dirty['stderr']);
        $this->assertStringContainsString('[controller-shape] src/Http/Controllers/NoteController.php', $dirty['stderr']);
        $this->assertStringContainsString('return $request->handle();', $dirty['stderr']);
        $this->assertSame('limpio', trim($this->project->git('log', '-1', '--format=%s')));
    }

    /**
     * Sólo lo que se comitea: un controlador roto sin preparar no frena un
     * commit que no lo lleva.
     */
    public function test_solo_comprueba_lo_que_se_va_a_comitear(): void
    {
        $this->install();
        $this->project->git('add', '--all');
        $this->assertSame(0, $this->commit('limpio')['code']);

        $this->project->controller(false);
        $this->project->write('README.md', "# notas\n");
        $this->project->git('add', 'README.md');

        $result = $this->commit('documentación');

        $this->assertSame(0, $result['code'], $result['stderr']);
    }

    /**
     * La guardia lee el disco: un controlador preparado a medias no se puede
     * comprobar tal como se comitea, y no se da por bueno.
     */
    public function test_un_archivo_vigilado_preparado_a_medias_no_se_da_por_bueno(): void
    {
        $this->install();
        $this->project->git('add', '--all');
        $this->assertSame(0, $this->commit('limpio')['code']);

        file_put_contents($this->project->path.'/src/Http/Controllers/NoteController.php', "\n// preparado\n", FILE_APPEND);
        $this->project->git('add', '--all');
        file_put_contents($this->project->path.'/src/Http/Controllers/NoteController.php', "\n// sin preparar\n", FILE_APPEND);

        $result = $this->commit('a medias');

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('cambios que no van en el commit', $result['stderr']);
        $this->assertStringContainsString('src/Http/Controllers/NoteController.php', $result['stderr']);
    }

    /**
     * Una excepción #[HandRolled] sin aprobar es lo que `verify --strict`
     * rechaza aunque sin candado sea sólo un aviso.
     */
    public function test_una_excepcion_sin_aprobar_no_entra(): void
    {
        $this->install();
        file_put_contents($this->project->path.'/src/Http/Controllers/NoteController.php', <<<'PHP'
        <?php

        namespace Acme\Notes\Http\Controllers;

        use Illuminate\Http\Request;
        use Innoboxrr\LarapackGenerator\Attributes\HandRolled;

        class NoteController
        {
            #[HandRolled('El webhook firma el cuerpo crudo')]
            public function index(Request $request)
            {
                return response()->json($request->all());
            }
        }
        PHP);
        $this->project->git('add', '--all');

        $result = $this->commit('excepción');

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('[hand-rolled]', $result['stderr']);
        $this->assertStringContainsString('larapack:hand-rolled approve', $result['stderr']);
    }

    /**
     * El candado lo confirma una persona en una terminal. Un agente corre sin
     * ella: el commit no entra.
     */
    public function test_el_candado_sin_terminal_no_entra(): void
    {
        // En Windows, PHP no puede saber si la shell de git tendrá /dev/tty;
        // ahí lo prueba guard-smoke.sh, desde Git Bash.
        if (PHP_OS_FAMILY === 'Windows' || (! HookProject::canDetach() && ! HookProject::withoutTerminal())) {
            $this->markTestSkipped('No se puede quitar la terminal a git, y el hook preguntaría en ella.');
        }

        $this->install();
        $this->project->git('add', '--all');
        $this->assertSame(0, $this->commit('limpio')['code']);

        $this->project->write('.larapack/hand-rolled.lock', "[]\n");
        $this->project->git('add', '--all');

        $result = $this->commit('candado');

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('el commit cambia .larapack/hand-rolled.lock', $result['stderr']);
        $this->assertStringContainsString('sin una terminal no hay quien lo confirme', $result['stderr']);
        $this->assertSame('limpio', trim($this->project->git('log', '-1', '--format=%s')));
    }

    /**
     * Con una terminal, el dueño lo confirma escribiendo la frase; cualquier
     * otra cosa no vale. `script` le da a git una terminal de mentira.
     */
    public function test_el_candado_con_terminal_entra_solo_si_se_confirma(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_executable('/usr/bin/script')) {
            $this->markTestSkipped('Hace falta script(1) de util-linux para darle a git una terminal.');
        }

        $this->install();
        $this->project->write('.larapack/hand-rolled.lock', "[]\n");
        $this->project->git('add', '--all');

        $refused = $this->project->run(['script', '-qec', 'git commit --quiet -m candado', '/dev/null'], "no\n");
        $this->assertNotSame(0, $refused['code']);
        $this->assertStringContainsString('no se confirmó', $refused['stdout'].$refused['stderr']);

        $approved = $this->project->run(['script', '-qec', 'git commit --quiet -m candado', '/dev/null'], GitHooks::PHRASE."\n");
        $this->assertSame(0, $approved['code'], $approved['stdout'].$approved['stderr']);
        $this->assertSame('candado', trim($this->project->git('log', '-1', '--format=%s')));
    }

    /**
     * Lo que se comiteó saltándose el hook (`--no-verify`, o una herramienta
     * que comitea sin hooks, como Aider por defecto) lo frena el push.
     */
    public function test_el_push_frena_lo_que_se_comiteo_sin_el_hook(): void
    {
        $remote = $this->project->path.'-remote.git';
        mkdir($remote);
        $this->project->run(['git', 'init', '--quiet', '--bare', $remote]);

        try {
            $this->install();
            $this->project->git('remote', 'add', 'origin', $remote);
            $this->commitWithoutHooks('limpio');
            $this->assertSame(0, $this->project->run(['git', 'push', '--quiet', 'origin', 'HEAD:refs/heads/main'], '', [], true)['code']);

            $this->project->controller(false);
            $this->commitWithoutHooks('con lógica, sin hook');

            $push = $this->project->run(['git', 'push', '--quiet', 'origin', 'HEAD:refs/heads/main'], '', [], true);

            $this->assertNotSame(0, $push['code']);
            $this->assertStringContainsString('lo que vas a empujar se sale del contrato', $push['stderr']);

            // Arreglado en otro commit, el push sale: se comprueba cómo
            // quedan los archivos, no cada commit intermedio.
            $this->project->controller(true);
            $this->commitWithoutHooks('arreglado');

            $fixed = $this->project->run(['git', 'push', '--quiet', 'origin', 'HEAD:refs/heads/main'], '', [], true);
            $this->assertSame(0, $fixed['code'], $fixed['stderr']);
        } finally {
            (new HookProjectRemover)($remote);
        }
    }

    /**
     * Auditoría de seguridad de 9.4.0. El push miraba «los commits que no
     * tiene ningún remoto»: empujados antes a otra rama —que por no ser HEAD
     * no se comprueba—, al empujar la de verdad ya los tenía un remoto y
     * salía sin mirar nada.
     */
    public function test_el_push_frena_lo_que_ya_se_empujo_a_otra_rama_del_remoto(): void
    {
        $remote = $this->project->path.'-remote.git';
        mkdir($remote);
        $this->project->run(['git', 'init', '--quiet', '--bare', $remote]);

        try {
            $this->install();
            $this->project->git('remote', 'add', 'origin', $remote);
            $this->commitWithoutHooks('limpio');
            $this->project->git('branch', '-M', 'main');
            $this->assertSame(0, $this->project->run(['git', 'push', '--quiet', 'origin', 'main'], '', [], true)['code']);
            $this->project->git('fetch', '--quiet', 'origin');

            // El commit malo, en otra rama; se empuja estando en main.
            $this->project->git('checkout', '--quiet', '-b', 'aparte');
            $this->project->controller(false);
            $this->commitWithoutHooks('con lógica, sin hook');
            $this->project->git('checkout', '--quiet', 'main');
            $aside = $this->project->run(['git', 'push', '--quiet', 'origin', 'aparte'], '', [], true);
            $this->assertSame(0, $aside['code'], $aside['stderr']);
            $this->project->git('fetch', '--quiet', 'origin');

            // Ahora un remoto ya lo tiene. main avanza hasta él y se empuja.
            $this->project->git('merge', '--quiet', '--ff-only', 'aparte');
            $push = $this->project->run(['git', 'push', '--quiet', 'origin', 'main'], '', [], true);

            $this->assertNotSame(0, $push['code']);
            $this->assertStringContainsString('lo que vas a empujar se sale del contrato', $push['stderr']);
        } finally {
            (new HookProjectRemover)($remote);
        }
    }

    public function test_el_push_de_una_rama_que_no_es_head_no_se_comprueba_aqui_y_lo_dice(): void
    {
        $this->commitWithoutHooks('limpio');
        $this->project->git('branch', 'otra');
        $this->project->controller(false);
        $this->commitWithoutHooks('con lógica');
        $sha = trim($this->project->git('rev-parse', 'HEAD'));
        $this->project->git('checkout', '--quiet', 'otra');

        $result = GitHooks::check('pre-push', $this->project->path, "refs/heads/main {$sha} refs/heads/main ".str_repeat('0', 40)."\n");

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('no es lo que tienes en el árbol de trabajo', $result['message']);
    }

    /**
     * Antes del composer install no hay nada que ejecutar: el commit entra y
     * se dice que no se comprobó.
     */
    public function test_sin_vendor_el_commit_entra_avisando(): void
    {
        $this->install();
        unlink($this->project->path.'/vendor/bin/builder');
        $this->project->controller(false);
        $this->project->git('add', '--all');

        $result = $this->commit('sin vendor');

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('no se comprobó el contrato', $result['stderr']);
    }

    /**
     * En un monorepo el hook va en la raíz del repositorio y entra al
     * proyecto: un paquete de packages/ se comprueba contra lo suyo.
     */
    public function test_en_un_monorepo_el_hook_va_en_la_raiz_y_entra_al_proyecto(): void
    {
        $script = GitHooks::script('pre-commit', 'apps/notas');

        $this->assertStringContainsString('for dir in "$top/apps/notas" "$top"; do', $script);
        $this->assertStringContainsString('for dir in "$top" "$top"; do', GitHooks::script('pre-commit'));

        $this->expectExceptionMessage('tiene caracteres que no se pueden poner en el hook de git');
        GitHooks::script('pre-commit', 'apps/$(rm -rf ~)');
    }

    /**
     * Un paquete de un monorepo sin vendor/ propio usa el de la raíz: el
     * hook no puede quedarse en «sin vendor» y no comprobar nunca nada.
     */
    public function test_un_paquete_sin_vendor_propio_usa_el_de_la_raiz(): void
    {
        mkdir($this->project->path.'/packages/blog', 0777, true);
        file_put_contents($this->project->path.'/packages/blog/composer.json', '{"name":"acme/blog"}');
        $this->project->write('.githooks/pre-commit', GitHooks::script('pre-commit', 'packages/blog'));
        chmod($this->project->path.'/.githooks/pre-commit', 0755);
        $this->project->git('config', 'core.hooksPath', '.githooks');

        $this->project->controller(false);
        $this->project->git('add', '--all');
        $result = $this->commit('con lógica');

        $this->assertSame(1, $result['code'], $result['stderr']);
        $this->assertStringContainsString('controller-shape', $result['stderr']);
    }

    /**
     * En pre-push git le pasa al hook el remoto y su URL: no pueden acabar
     * como la orden que se ejecuta cuando el proyecto no tiene vendor/.
     */
    public function test_los_argumentos_de_git_no_se_ejecutan(): void
    {
        $this->install();
        unlink($this->project->path.'/vendor/bin/builder');

        $result = $this->project->run(['sh', '.githooks/pre-push', 'origin', 'https://example.test/x.git'], '');

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('no se comprobó el contrato', $result['stderr']);
    }
}

/**
 * Borra el remoto desnudo de una prueba, sin seguir enlaces.
 */
final class HookProjectRemover
{
    public function __invoke(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }

        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
