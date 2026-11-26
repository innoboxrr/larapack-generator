<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Commands\GuardCommand;
use Innoboxrr\LarapackGenerator\Support\Guard;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `larapack:guard`, tal como lo llama un hook de Claude Code: el JSON de la
 * herramienta por la entrada estándar, el veredicto en el código de salida.
 *
 * Lo que importa de este comando es tanto lo que bloquea como lo que no: un
 * hook que se queja de lo que no debe, o que tarda en cada edición, acaba
 * quitado del settings.json, y entonces no protege nada.
 */
final class GuardTest extends TestCase
{
    private string $stdout = '';

    private string $stderr = '';

    /**
     * @var array<int, string>
     */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Blog\\'));

        // Una bitácora que sólo se lista y se ve: cualquier escritura escrita
        // a mano es una acción que no declara, y el Verifier la ve.
        file_put_contents($this->project->path.'/laraimport.json', json_encode([
            'models' => [[
                'name' => 'Note',
                'routes' => ['only' => ['policies', 'index', 'show']],
                'props' => [['name' => 'name', 'type' => 'string']],
            ]],
        ]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $this->project->path.'/laraimport.json']),
            $this->lastOutput
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function guard(string $stdin, array $options = []): int
    {
        $tester = new CommandTester(new GuardCommand);

        // Siempre con una entrada propia: sin ella el comando leería la de
        // PHPUnit, que en una CI es una tubería que nadie cierra.
        $tester->setInputs([$stdin]);

        $code = $tester->execute($options, ['capture_stderr_separately' => true]);

        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $code;
    }

    /**
     * @param  array<string, mixed>  $toolInput
     */
    private function edit(array $toolInput, string $tool = 'Edit', ?string $cwd = null, ?string $session = null): int
    {
        return $this->guard((string) json_encode([
            'hook_event_name' => 'PostToolUse',
            'tool_name' => $tool,
            'tool_input' => $toolInput,
            'cwd' => $cwd ?? $this->project->path,
        ] + ($session === null ? [] : ['session_id' => $session])));
    }

    private function stop(?string $session = null): int
    {
        return $this->guard((string) json_encode([
            'hook_event_name' => 'Stop',
            'stop_hook_active' => false,
            'cwd' => $this->project->path,
        ] + ($session === null ? [] : ['session_id' => $session])), ['--stop' => true]);
    }

    /**
     * Un `session_id` que no choque con el de otra prueba: lo que la
     * guardia apunta de la sesión vive en el directorio temporal.
     */
    private function session(): string
    {
        $session = 'test-'.bin2hex(random_bytes(8));
        $this->sessions[] = $session;

        return $session;
    }

    /**
     * El proyecto como repositorio git, con todo lo que tiene confirmado.
     */
    private function commitAll(): void
    {
        $git = fn (string ...$arguments): string => (string) shell_exec(
            'git -C '.escapeshellarg($this->project->path).' -c user.name=larapack -c user.email=larapack@example.test -c commit.gpgsign=false '
            .implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1'
        );

        if (! is_dir($this->project->path.'/.git')) {
            $git('init', '--quiet');
        }

        $git('add', '--all');
        $git('commit', '--quiet', '--message', 'fotografía');

        $this->assertSame('', trim($git('status', '--porcelain')), 'El proyecto debería quedar sin cambios.');
    }

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            @unlink(sys_get_temp_dir().'/larapack-guard/'.sha1($session).'.txt');
        }

        parent::tearDown();
    }

    private function file(string $relative): string
    {
        return $this->project->path.'/'.$relative;
    }

    /**
     * Lo que haría un agente que pierde la guía: añadir al controlador
     * generado una acción que el modelo no declara.
     */
    private function addUndeclaredAction(): void
    {
        $controller = $this->file('src/Http/Controllers/NoteController.php');
        $content = (string) file_get_contents($controller);
        $content = substr($content, 0, (int) strrpos($content, '}'))
            ."    public function update(\\Illuminate\\Http\\Request \$request)\n    {\n        return response()->json(\$request->all());\n    }\n}\n";

        file_put_contents($controller, $content);
    }

    public function test_sin_entrada_sale_limpio(): void
    {
        $this->assertSame(Command::SUCCESS, $this->guard(''));
        $this->assertSame(Command::SUCCESS, $this->guard("  \n"));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    public function test_una_entrada_que_no_es_un_objeto_json_sale_limpio(): void
    {
        foreach (['no es json', '{"tool_input":', '[]', '"texto"', '42', '{}'] as $stdin) {
            $this->assertSame(Command::SUCCESS, $this->guard($stdin), $stdin);
            $this->assertSame('', $this->stdout.$this->stderr, $stdin);
        }
    }

    public function test_sin_ruta_de_archivo_sale_limpio(): void
    {
        $this->assertSame(Command::SUCCESS, $this->edit(['command' => 'ls'], 'Bash'));
        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => ['no', 'es', 'texto']]));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    public function test_un_archivo_que_no_es_arquitectura_no_se_comprueba(): void
    {
        $this->addUndeclaredAction();

        foreach (['README.md', 'laraimport.json', 'src/Models/Traits/Operations/NoteOperations.php'] as $file) {
            touch($this->file($file));

            $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $this->file($file)]), $file);
            $this->assertSame('', $this->stdout.$this->stderr, $file);
        }
    }

    public function test_un_controlador_generado_e_intacto_pasa_sin_decir_nada(): void
    {
        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $this->file('src/Http/Controllers/NoteController.php')]));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    /**
     * El caso que motivó el comando: el error vuelve al agente con la regla,
     * con el código que Claude Code trata como bloqueante.
     */
    public function test_bloquea_un_controlador_que_se_sale_del_contrato_y_recuerda_la_regla(): void
    {
        $this->addUndeclaredAction();

        $code = $this->edit(['file_path' => $this->file('src/Http/Controllers/NoteController.php')]);

        $this->assertSame(GuardCommand::BLOCK, $code);
        $this->assertSame(2, GuardCommand::BLOCK);
        $this->assertStringContainsString('src/Http/Controllers/NoteController.php', $this->stderr);
        $this->assertStringContainsString('route-not-declared', $this->stderr);
        $this->assertStringContainsString('laraimport.json', $this->stderr);
        $this->assertStringContainsString('return $request->handle();', $this->stderr);
        $this->assertStringContainsString('php vendor/bin/builder larapack:verify --format=json', $this->stderr);
        $this->assertSame('', $this->stdout, 'Lo bloqueante va por stderr: es lo que Claude Code le enseña al modelo.');
    }

    /**
     * Lo que se escapaba: lógica dentro de una acción que el modelo sí
     * declara, en un controlador generado. No es una acción de más, así que
     * route-not-declared no lo ve; lo ve controller-shape.
     */
    public function test_bloquea_logica_dentro_de_una_accion_declarada_de_un_controlador_generado(): void
    {
        $controller = $this->file('src/Http/Controllers/NoteController.php');
        $content = (string) file_get_contents($controller);
        $content = preg_replace(
            '/public function index\(IndexRequest \$request\)\s*\{\s*return \$request->handle\(\);\s*\}/',
            "public function index(IndexRequest \$request)\n    {\n        \$request->validate(['q' => 'string']);\n\n        return response()->json(\\Illuminate\\Support\\Facades\\DB::table('notes')->get());\n    }",
            $content,
            1,
            $count
        );

        $this->assertSame(1, $count, 'El controlador generado debería tener index().');
        file_put_contents($controller, $content);

        $this->assertSame(GuardCommand::BLOCK, $this->edit(['file_path' => $controller]));
        $this->assertStringContainsString('[controller-shape] src/Http/Controllers/NoteController.php', $this->stderr);
        $this->assertStringContainsString('NoteController::index()', $this->stderr);
    }

    public function test_bloquea_una_request_de_una_accion_que_el_modelo_no_declara(): void
    {
        $request = $this->file('src/Http/Requests/Note/UpdateRequest.php');
        file_put_contents($request, "<?php\n");

        $this->assertSame(GuardCommand::BLOCK, $this->edit(['file_path' => $request, 'content' => "<?php\n"], 'Write'));
        $this->assertStringContainsString('src/Http/Requests/Note/UpdateRequest.php', $this->stderr);
    }

    /**
     * Un error en otro archivo no es de esta edición: bloquear aquí mandaría
     * al agente a arreglar algo que no tocó, en mitad de otra cosa. Eso lo
     * dice el hook de fin de turno.
     */
    public function test_solo_cuenta_los_errores_del_archivo_editado(): void
    {
        $this->addUndeclaredAction();

        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $this->file('src/Policies/NotePolicy.php')]));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    public function test_resuelve_rutas_relativas_contra_el_cwd_del_hook(): void
    {
        $this->addUndeclaredAction();

        $this->assertSame(GuardCommand::BLOCK, $this->edit(['file_path' => 'Http/Controllers/NoteController.php'], cwd: $this->file('src')));
    }

    public function test_revisa_cada_archivo_de_una_edicion_multiple(): void
    {
        $this->addUndeclaredAction();

        $this->assertSame(GuardCommand::BLOCK, $this->edit([
            'file_path' => $this->file('src/Policies/NotePolicy.php'),
            'edits' => [
                ['file_path' => $this->file('src/Http/Controllers/NoteController.php'), 'old_string' => 'a', 'new_string' => 'b'],
                'no es una edición',
            ],
        ], 'MultiEdit'));
    }

    /**
     * Lo que se perdió en school-management: un controlador que el generador
     * no escribió. Mientras el Verifier no tenga nada que decir de él, el
     * agente recibe la regla como contexto, sin bloquear.
     */
    public function test_a_un_controlador_escrito_a_mano_le_recuerda_el_patron_sin_bloquear(): void
    {
        $controller = $this->file('src/Http/Controllers/Platform/InstalledModulesController.php');
        mkdir(dirname($controller), 0777, true);
        file_put_contents($controller, "<?php\n");

        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $controller], 'Write'));
        $this->assertSame('', $this->stderr);

        $document = json_decode($this->stdout, true);

        $this->assertSame('PostToolUse', $document['hookSpecificOutput']['hookEventName']);
        $context = $document['hookSpecificOutput']['additionalContext'];

        $this->assertStringContainsString('Platform/InstalledModulesController.php', $context);
        $this->assertStringContainsString('return $request->handle();', $context);
        $this->assertStringContainsString('Http/Requests/Concerns', $context);

        // Una exigencia, no un visto bueno: que hoy no haya errores no dice
        // que el archivo cumpla.
        $this->assertStringContainsString('DEBE seguir el mismo patrón', $context);
        $this->assertStringNotContainsString('y sigue el mismo patrón', $context);
    }

    /**
     * Un trait no tiene acciones: lo que se le recuerda es que su sitio no es
     * Http/Controllers.
     */
    public function test_a_un_trait_de_http_controllers_le_recuerda_su_sitio_y_no_el_patron_de_las_acciones(): void
    {
        $trait = $this->file('src/Http/Controllers/Concerns/ScopesModules.php');
        mkdir(dirname($trait), 0777, true);
        file_put_contents($trait, "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers\\Concerns;\n\ntrait ScopesModules\n{\n}\n");

        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $trait], 'Write'));

        $context = json_decode($this->stdout, true)['hookSpecificOutput']['additionalContext'];

        $this->assertStringContainsString('es un trait dentro de Http/Controllers', $context);
        $this->assertStringContainsString('Http/Requests/Concerns', $context);
        $this->assertStringNotContainsString('es un controlador', $context);
    }

    public function test_el_controlador_base_no_es_un_endpoint(): void
    {
        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $this->file('src/Http/Controllers/Controller.php')]));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    public function test_un_archivo_fuera_de_cualquier_proyecto_sale_limpio(): void
    {
        $outside = FakeProject::empty();

        try {
            mkdir($outside->path.'/Http/Controllers', 0777, true);
            file_put_contents($outside->path.'/Http/Controllers/LooseController.php', "<?php\n");

            $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $outside->path.'/Http/Controllers/LooseController.php']));
            $this->assertSame('', $this->stdout.$this->stderr);
        } finally {
            $outside->cleanup();
        }
    }

    /**
     * Como en un proyecto de verdad, el paquete de vendor/ trae su propio
     * composer.json y un controlador que se sale del contrato: relativo a
     * ese paquete ya no hay ningún `vendor/` en la ruta, y aun así no es del
     * proyecto. Tampoco cuando la sesión ha hecho `cd` hasta dentro.
     */
    public function test_lo_de_vendor_no_es_del_proyecto(): void
    {
        foreach (['vendor/acme/thing', 'node_modules/acme-thing'] as $package) {
            $vendored = $this->file("{$package}/src/Http/Controllers/ThingController.php");
            mkdir(dirname($vendored), 0777, true);
            file_put_contents($this->file("{$package}/composer.json"), json_encode(['autoload' => ['psr-4' => ['Acme\\Thing\\' => 'src/']]]));
            file_put_contents($vendored, "<?php\n\nnamespace Acme\\Thing\\Http\\Controllers;\n\nclass ThingController\n{\n    public function index(\\Illuminate\\Http\\Request \$request)\n    {\n        return response()->json(\$request->all());\n    }\n}\n");

            foreach ([$this->project->path, dirname($vendored)] as $cwd) {
                $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $vendored], cwd: $cwd), "{$package} desde {$cwd}");
                $this->assertSame('', $this->stdout.$this->stderr, "{$package} desde {$cwd}");
            }
        }
    }

    /**
     * Sin manifiesto, sólo lo que `verify` comprueba igual sin él: la forma
     * de los controladores. Lo demás no es de LaraPack todavía.
     */
    public function test_sin_manifiesto_solo_vigila_la_forma_de_los_controladores(): void
    {
        $bare = FakeProject::library('Acme\\Shop\\');

        try {
            $controller = $bare->path.'/src/Http/Controllers/ShopController.php';
            $route = $bare->path.'/routes/web.php';
            mkdir(dirname($controller), 0777, true);
            mkdir(dirname($route), 0777, true);
            file_put_contents($route, "<?php\n");
            file_put_contents($controller, "<?php\n\nnamespace Acme\\Shop\\Http\\Controllers;\n\nclass ShopController\n{\n    public function index(\\Illuminate\\Http\\Request \$request)\n    {\n        return response()->json(\$request->all());\n    }\n}\n");

            $this->assertFileDoesNotExist($bare->path.'/.larapack/manifest.json');

            $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $route], cwd: $bare->path));
            $this->assertSame('', $this->stdout.$this->stderr);

            $this->assertSame(GuardCommand::BLOCK, $this->edit(['file_path' => $controller], cwd: $bare->path));
            $this->assertStringContainsString('[controller-shape] src/Http/Controllers/ShopController.php', $this->stderr);
            $this->assertStringNotContainsString('empty-manifest', $this->stderr);
        } finally {
            $bare->cleanup();
        }
    }

    public function test_un_root_que_no_existe_falla_sin_bloquear_y_lo_dice(): void
    {
        $missing = $this->project->path.'/no-existe';

        $this->assertSame(Command::FAILURE, $this->guard((string) json_encode([
            'tool_input' => ['file_path' => $this->file('src/Http/Controllers/NoteController.php')],
        ]), ['--root' => $missing]));
        $this->assertStringContainsString("--root={$missing} no existe", $this->stderr);
        $this->assertSame('', $this->stdout);
    }

    public function test_con_root_ignora_lo_que_cae_fuera(): void
    {
        $this->addUndeclaredAction();

        $other = FakeProject::library();

        try {
            $this->assertSame(Command::SUCCESS, $this->guard((string) json_encode([
                'tool_input' => ['file_path' => $this->file('src/Http/Controllers/NoteController.php')],
            ]), ['--root' => $other->path]));
        } finally {
            $other->cleanup();
        }
    }

    /**
     * Sin git ni registro de la sesión no hay forma de saber qué es de quién:
     * retiene todo, como antes.
     */
    public function test_al_terminar_el_turno_bloquea_si_el_proyecto_tiene_errores(): void
    {
        $this->addUndeclaredAction();

        $stop = (string) json_encode(['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'cwd' => $this->file('src')]);

        $this->assertSame(GuardCommand::BLOCK, $this->guard($stop, ['--stop' => true]));
        $this->assertStringContainsString('un error;', $this->stderr);
        $this->assertStringNotContainsString('esta sesión', $this->stderr);
        $this->assertStringContainsString('route-not-declared', $this->stderr);
        $this->assertStringContainsString('return $request->handle();', $this->stderr);

        // El evento lo dice el propio JSON; --stop es para quien no lo mande.
        $this->assertSame(GuardCommand::BLOCK, $this->guard($stop));
    }

    /**
     * Lo que ya estaba roto y nadie tocó en esta sesión —otra consola, un
     * error antiguo— se le dice al usuario, pero no retiene el turno.
     */
    public function test_al_terminar_el_turno_no_retiene_por_lo_que_la_sesion_no_toco(): void
    {
        $this->addUndeclaredAction();
        $this->commitAll();

        $this->assertSame(Command::SUCCESS, $this->stop($this->session()));
        $this->assertSame('', $this->stderr);

        $message = json_decode($this->stdout, true)['systemMessage'] ?? '';

        $this->assertStringContainsString('que esta sesión no tocó', $message);
        $this->assertStringContainsString('route-not-declared', $message);
    }

    /**
     * Lo que git ve cambiado es de esta sesión aunque no pasara por el hook
     * de edición (un `sed` por Bash, por ejemplo).
     */
    public function test_al_terminar_el_turno_retiene_por_lo_que_git_ve_cambiado(): void
    {
        $this->commitAll();
        $this->addUndeclaredAction();

        $this->assertSame(GuardCommand::BLOCK, $this->stop($this->session()));
        $this->assertStringContainsString('en lo que tocó esta sesión', $this->stderr);
        $this->assertStringContainsString('src/Http/Controllers/NoteController.php', $this->stderr);
    }

    /**
     * Lo que la sesión editó sigue siendo suyo después de confirmarlo; lo que
     * editó otra sesión, no.
     */
    public function test_al_terminar_el_turno_retiene_por_lo_que_edito_la_sesion_aunque_ya_este_confirmado(): void
    {
        $mine = $this->session();
        $theirs = $this->session();

        $this->addUndeclaredAction();
        touch($this->file('README.md'));
        $this->assertSame(GuardCommand::BLOCK, $this->edit(['file_path' => $this->file('src/Http/Controllers/NoteController.php')], session: $theirs));
        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $this->file('README.md')], session: $mine));
        $this->commitAll();

        $this->assertSame(GuardCommand::BLOCK, $this->stop($theirs));
        $this->assertStringContainsString('src/Http/Controllers/NoteController.php', $this->stderr);

        $this->assertSame(Command::SUCCESS, $this->stop($mine));
        $this->assertSame('', $this->stderr);
        $this->assertStringContainsString('route-not-declared', json_decode($this->stdout, true)['systemMessage'] ?? '');
    }

    /**
     * Si retiene, también dice que hay más, pero que no son suyos.
     */
    public function test_al_retener_separa_lo_suyo_de_lo_ajeno(): void
    {
        $request = $this->file('src/Http/Requests/Note/UpdateRequest.php');
        file_put_contents($request, "<?php\n");
        $this->commitAll();

        $this->addUndeclaredAction();

        $this->assertSame(GuardCommand::BLOCK, $this->stop($this->session()));
        $this->assertStringContainsString('src/Http/Controllers/NoteController.php', $this->stderr);
        $this->assertStringNotContainsString('src/Http/Requests/Note/UpdateRequest.php:', $this->stderr);
        $this->assertMatchesRegularExpression('/Además hay (un error|\d+ errores) en archivos que esta sesión no tocó/', $this->stderr);
    }

    /**
     * Si el turno ya sigue por culpa de este hook, se deja terminar: un error
     * que el agente no sabe arreglar no puede retenerlo en un bucle.
     */
    public function test_al_terminar_el_turno_respeta_stop_hook_active(): void
    {
        $this->addUndeclaredAction();

        $this->assertSame(Command::SUCCESS, $this->guard((string) json_encode([
            'hook_event_name' => 'Stop',
            'stop_hook_active' => true,
            'cwd' => $this->project->path,
        ]), ['--stop' => true]));
        $this->assertSame('', $this->stdout.$this->stderr);
    }

    public function test_al_terminar_el_turno_deja_pasar_un_proyecto_sano_o_sin_larapack(): void
    {
        $stop = ['hook_event_name' => 'Stop', 'stop_hook_active' => false];

        $this->assertSame(Command::SUCCESS, $this->guard((string) json_encode($stop + ['cwd' => $this->project->path]), ['--stop' => true]));

        $bare = FakeProject::library();

        try {
            $this->assertSame(Command::SUCCESS, $this->guard((string) json_encode($stop + ['cwd' => $bare->path]), ['--stop' => true]));
        } finally {
            $bare->cleanup();
        }

        $this->assertSame('', $this->stdout.$this->stderr);
    }

    /**
     * El filtro no sabe de checks: cualquier hallazgo de error que apunte al
     * archivo cuenta, también el de un check que el Verifier gane después.
     */
    public function test_filtra_los_hallazgos_por_archivo_sin_mirar_el_check(): void
    {
        $findings = [
            ['level' => Verifier::ERROR, 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Http/Controllers/AController.php', 'message' => 'x'],
            ['level' => Verifier::ERROR, 'check' => 'algo-futuro', 'model' => null, 'file' => './app/Http/Controllers/AController.php', 'message' => 'y'],
            ['level' => Verifier::WARNING, 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Http/Controllers/AController.php', 'message' => 'z'],
            ['level' => Verifier::ERROR, 'check' => 'controller-shape', 'model' => null, 'file' => 'app/Http/Controllers/BController.php', 'message' => 'w'],
            ['level' => Verifier::ERROR, 'check' => 'inconsistent-entity', 'model' => 'A', 'file' => null, 'message' => 'v'],
        ];

        $this->assertSame(['x', 'y'], array_column(Guard::errorsFor($findings, 'app/Http/Controllers/AController.php'), 'message'));
        $this->assertSame(['x', 'y', 'w', 'v'], array_column(Guard::errorsFor($findings), 'message'));
    }

    /**
     * De punta a punta, como lo ejecuta Claude Code: un proceso aparte, el
     * JSON por una tubería y el código de salida real.
     */
    public function test_por_el_binario_con_la_entrada_estandar_real(): void
    {
        $this->addUndeclaredAction();

        $payload = (string) json_encode([
            'hook_event_name' => 'PostToolUse',
            'tool_name' => 'Edit',
            'tool_input' => ['file_path' => $this->file('src/Http/Controllers/NoteController.php')],
            'cwd' => $this->project->path,
        ]);

        [$code, $stdout, $stderr] = $this->pipe($payload);

        $this->assertSame(2, $code, $stdout.$stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('return $request->handle();', $stderr);

        [$code, $stdout, $stderr] = $this->pipe('');

        $this->assertSame(0, $code, $stdout.$stderr);
        $this->assertSame('', $stdout.$stderr);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function pipe(string $stdin): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/builder', 'larapack:guard'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
