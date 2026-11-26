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
    private function edit(array $toolInput, string $tool = 'Edit', ?string $cwd = null): int
    {
        return $this->guard((string) json_encode([
            'hook_event_name' => 'PostToolUse',
            'tool_name' => $tool,
            'tool_input' => $toolInput,
            'cwd' => $cwd ?? $this->project->path,
        ]));
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
        $this->assertStringContainsString('Platform/InstalledModulesController.php', $document['hookSpecificOutput']['additionalContext']);
        $this->assertStringContainsString('return $request->handle();', $document['hookSpecificOutput']['additionalContext']);
        $this->assertStringContainsString('Http/Requests/Concerns', $document['hookSpecificOutput']['additionalContext']);
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

    public function test_lo_de_vendor_no_es_del_proyecto(): void
    {
        $vendored = $this->file('vendor/acme/thing/src/Http/Controllers/ThingController.php');
        mkdir(dirname($vendored), 0777, true);
        file_put_contents($vendored, "<?php\n");

        $this->assertSame(Command::SUCCESS, $this->edit(['file_path' => $vendored]));
        $this->assertSame('', $this->stdout.$this->stderr);
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

    public function test_al_terminar_el_turno_bloquea_si_el_proyecto_tiene_errores(): void
    {
        $this->addUndeclaredAction();

        $stop = (string) json_encode(['hook_event_name' => 'Stop', 'stop_hook_active' => false, 'cwd' => $this->file('src')]);

        // Dos problemas distintos, uno por comprobación: una acción que Note
        // no declara (route-not-declared) y un método que no delega
        // (controller-shape). El segundo no sale repetido con otro nombre.
        $this->assertSame(GuardCommand::BLOCK, $this->guard($stop, ['--stop' => true]));
        $this->assertStringContainsString('2 errores', $this->stderr);
        $this->assertStringContainsString('route-not-declared', $this->stderr);
        $this->assertStringContainsString('controller-shape', $this->stderr);
        $this->assertStringNotContainsString('controller-logic', $this->stderr);
        $this->assertStringContainsString('return $request->handle();', $this->stderr);

        // El evento lo dice el propio JSON; --stop es para quien no lo mande.
        $this->assertSame(GuardCommand::BLOCK, $this->guard($stop));
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
