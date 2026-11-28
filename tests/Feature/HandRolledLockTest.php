<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * La aprobación de las excepciones #[HandRolled]: `.larapack/hand-rolled.lock`
 * y `larapack:hand-rolled`.
 *
 * La aplicación tiene las dos excepciones con las que school-management llegó
 * a 9.0 —un webhook marcado en la clase y el canje de un token marcado en la
 * acción— y nada más que pueda dar hallazgos de forma.
 */
final class HandRolledLockTest extends TestCase
{
    private const CHECKS = ['hand-rolled', 'hand-rolled-unapproved', 'hand-rolled-stale'];

    private const WEBHOOK = 'App\Http\Controllers\InboundWebhookController';

    private const TOKEN = 'App\Http\Controllers\Auth\TokenController::store';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());

        // El dueño, delante de su terminal, confirma lo que aprueba
        // (HandRolledOwnerTest prueba que sin él no se aprueba nada).
        $this->ownerAtTerminal();

        $this->controller('InboundWebhookController.php', 'App\Http\Controllers', <<<'PHP'
        #[HandRolled('La firma se calcula sobre el cuerpo en bruto.')]
        class InboundWebhookController
        {
            public function __invoke(Request $request)
            {
                return response()->noContent();
            }
        }
        PHP);

        $this->token('RFC 6749: los errores del canje son los de OAuth2.');
    }

    private function controller(string $file, string $namespace, string $body): void
    {
        $directory = $this->project->path.'/app/'.str_replace(['App\\', '\\'], ['', '/'], $namespace);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents("{$directory}/{$file}", "<?php\n\nnamespace {$namespace};\n\n"
            ."use Illuminate\\Http\\Request;\nuse Innoboxrr\\LarapackGenerator\\Attributes\\HandRolled;\n\n{$body}\n");
    }

    private function token(?string $reason, string $body = 'return response()->json([]);'): void
    {
        $attribute = $reason === null ? '' : "    #[HandRolled('{$reason}')]\n";

        $this->controller('TokenController.php', 'App\Http\Controllers\Auth', "class TokenController\n{\n{$attribute}"
            ."    public function store(Request \$request)\n    {\n        {$body}\n    }\n}");
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function unapproved(array $report): array
    {
        return array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === 'hand-rolled-unapproved'))[0];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(array $options = []): array
    {
        $code = $this->runCommand('larapack:verify', $options + ['--format' => 'json', '--check' => self::CHECKS]);

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function handRolled(string $action, array $arguments = []): array
    {
        $code = $this->runCommand('larapack:hand-rolled', ['action' => $action, '--format' => 'json'] + $arguments);

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function levels(array $report, string $check): array
    {
        return array_values(array_column(array_filter($report['findings'], fn (array $f): bool => $f['check'] === $check), 'level'));
    }

    private function lock(): string
    {
        return (string) file_get_contents($this->project->path.'/'.HandRolledLock::PATH);
    }

    public function test_sin_candado_es_el_comportamiento_de_9_0(): void
    {
        [$code, $report] = $this->verify();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame(['warning', 'warning'], $this->levels($report, 'hand-rolled'));
        $this->assertSame([], $this->levels($report, 'hand-rolled-unapproved'));
        $this->assertSame([], $this->levels($report, 'hand-rolled-stale'));

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);
    }

    public function test_aprobar_todo_escribe_un_candado_ordenado_y_estable(): void
    {
        [$code, $result] = $this->handRolled('approve', ['--all' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertTrue($result['changed']);
        $this->assertSame([self::TOKEN, self::WEBHOOK], array_column($result['targets'], 'target'));

        $expected = $this->lock();
        $entries = json_decode($expected, true);

        $this->assertSame([
            ['target' => self::TOKEN, 'reason' => 'RFC 6749: los errores del canje son los de OAuth2.'],
            ['target' => self::WEBHOOK, 'reason' => 'La firma se calcula sobre el cuerpo en bruto.'],
        ], array_map(fn (array $entry): array => ['target' => $entry['target'], 'reason' => $entry['reason']], $entries));

        // Cada aprobación lleva la huella del código que aprueba, y son
        // distintas: una es de un método y la otra de una clase.
        foreach ($entries as $entry) {
            $this->assertSame(['target', 'reason', 'fingerprint'], array_keys($entry));
            $this->assertMatchesRegularExpression(HandRolledLock::FINGERPRINT, $entry['fingerprint']);
        }

        $this->assertNotSame($entries[0]['fingerprint'], $entries[1]['fingerprint']);
        $this->assertStringStartsWith("[\n    {\n        \"target\": \"App\\\\Http\\\\Controllers\\\\Auth\\\\TokenController::store\",\n", $expected);
        $this->assertStringEndsWith("\"\n    }\n]\n", $expected);

        // Aprobado, informa y --strict pasa: la CI puede exigirlo.
        [$code, $report] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings']));
        $this->assertSame(['info', 'info'], $this->levels($report, 'hand-rolled'));
        $this->assertStringContainsString('Aprobada por el dueño', $report['findings'][0]['message']);

        // Otra vez, el mismo archivo byte a byte.
        [$code, $result] = $this->handRolled('approve', ['--all' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertFalse($result['changed']);
        $this->assertSame($expected, $this->lock());
    }

    public function test_una_excepcion_sin_aprobar_es_error_con_candado(): void
    {
        $this->handRolled('approve', ['targets' => ['InboundWebhookController']]);

        $this->assertStringNotContainsString('TokenController', $this->lock());

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['error'], $this->levels($report, 'hand-rolled-unapproved'));

        $error = array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === 'hand-rolled-unapproved'))[0];

        $this->assertSame('app/Http/Controllers/Auth/TokenController.php', $error['file']);
        $this->assertStringContainsString(self::TOKEN, $error['message']);
        $this->assertStringContainsString('Sólo el dueño del proyecto aprueba excepciones', $error['message']);
        $this->assertStringContainsString('No edites .larapack/hand-rolled.lock', $error['message']);

        // La forma no se cuenta dos veces: la excepción sigue excusándola.
        [, $report] = $this->verify(['--check' => ['controller-shape']]);

        $this->assertSame([], $report['findings']);
    }

    public function test_aprobar_por_el_final_del_nombre(): void
    {
        [$code, $result] = $this->handRolled('approve', ['targets' => ['TokenController::store']]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertSame([self::TOKEN], array_column($result['targets'], 'target'));

        // `Controller` no es el final de un segmento: no coincide con nada.
        [$code, $result] = $this->handRolled('approve', ['targets' => ['Controller']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('no se aprobó nada', $result['error']);
    }

    public function test_un_objetivo_ambiguo_o_desconocido_no_escribe_nada(): void
    {
        $this->controller('InboundWebhookController.php', 'App\Http\Controllers\Legacy', <<<'PHP'
        #[HandRolled('Otro webhook.')]
        class InboundWebhookController
        {
        }
        PHP);

        [$code, $result] = $this->handRolled('approve', ['targets' => ['TokenController::store', 'InboundWebhookController']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('coincide con varias', $result['error']);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);

        [$code] = $this->handRolled('approve', ['targets' => ['NoSuchController']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);

        // Entero, sí.
        [$code] = $this->handRolled('approve', ['targets' => ['\\'.self::WEBHOOK]]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
    }

    /**
     * Se aprobó una razón: si el código dice otra, ya no es lo aprobado.
     */
    public function test_cambiar_la_razon_invalida_la_aprobacion(): void
    {
        $this->handRolled('approve', ['--all' => true]);
        $this->token('Cualquier otra cosa.');

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $error = array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === 'hand-rolled-unapproved'))[0];

        $this->assertStringContainsString('se aprobó «RFC 6749: los errores del canje son los de OAuth2.» y ahora dice «Cualquier otra cosa.»', $error['message']);
        $this->assertSame([], $this->levels($report, 'hand-rolled-stale'));

        [, $list] = $this->handRolled('list');

        $this->assertSame(['reason-changed', 'approved'], array_column($list['exceptions'], 'status'));

        // El dueño la renueva.
        $this->handRolled('approve', ['targets' => ['TokenController::store']]);

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertStringContainsString('Cualquier otra cosa.', $this->lock());
    }

    /**
     * Aprobar una clase entera no es un cheque en blanco: una acción que se
     * le añade después —aquí, una que borra usuarios— no está aprobada.
     */
    public function test_anadir_una_accion_a_una_clase_aprobada_invalida_la_aprobacion(): void
    {
        $this->handRolled('approve', ['--all' => true]);
        $before = $this->lock();

        $this->controller('InboundWebhookController.php', 'App\Http\Controllers', <<<'PHP'
        #[HandRolled('La firma se calcula sobre el cuerpo en bruto.')]
        class InboundWebhookController
        {
            public function __invoke(Request $request)
            {
                return response()->noContent();
            }

            public function destroy(Request $request)
            {
                \Illuminate\Support\Facades\DB::table('users')->delete();

                return response()->noContent();
            }
        }
        PHP);

        [$code, $report] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::FAILURE, $code);

        $error = $this->unapproved($report);

        $this->assertSame('app/Http/Controllers/InboundWebhookController.php', $error['file']);
        $this->assertStringContainsString('cambió desde que se aprobó: el código aprobado ya no es el mismo', $error['message']);
        $this->assertStringContainsString('uno nuevo también', $error['message']);
        $this->assertStringContainsString(self::WEBHOOK, $error['message']);
        $this->assertStringContainsString('Sólo el dueño del proyecto aprueba excepciones', $error['message']);

        [, $list] = $this->handRolled('list');

        $this->assertSame(['approved', 'code-changed'], array_column($list['exceptions'], 'status'));

        // El dueño revisa y aprueba de nuevo: cambia la huella, y ese cambio
        // del candado es lo que CODEOWNERS le pide revisar.
        [$code, $result] = $this->handRolled('approve', ['targets' => ['InboundWebhookController']]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertTrue($result['changed']);
        $this->assertNotSame($before, $this->lock());

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
    }

    /**
     * La huella es del código, no del texto: un comentario o un cambio de
     * formato no la mueven; una sentencia más, o un parámetro, sí.
     */
    public function test_la_huella_de_un_metodo_sigue_al_codigo_y_no_al_formato(): void
    {
        $this->handRolled('approve', ['--all' => true]);

        $this->token('RFC 6749: los errores del canje son los de OAuth2.', "// Responde vacío.\n        return response()\n            ->json( [] ) ;");

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);

        $this->token('RFC 6749: los errores del canje son los de OAuth2.', "\\App\\Models\\User::query()->delete();\n\n        return response()->json([]);");

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('cambió desde que se aprobó', $this->unapproved($report)['message']);
        $this->assertStringContainsString('su firma o su cuerpo', $this->unapproved($report)['message']);

        // La firma también es parte de lo aprobado.
        $this->token('RFC 6749: los errores del canje son los de OAuth2.');
        $this->controller('TokenController.php', 'App\Http\Controllers\Auth', "class TokenController\n{\n"
            ."    #[HandRolled('RFC 6749: los errores del canje son los de OAuth2.')]\n"
            ."    public function store(Request \$request, bool \$force = true)\n    {\n        return response()->json([]);\n    }\n}");

        [$code] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
    }

    /**
     * Una entrada sin huella no dice qué código se aprobó: no cuenta.
     */
    public function test_una_aprobacion_sin_huella_no_aprueba(): void
    {
        mkdir($this->project->path.'/.larapack');
        file_put_contents($this->project->path.'/'.HandRolledLock::PATH, json_encode([
            ['target' => self::TOKEN, 'reason' => 'RFC 6749: los errores del canje son los de OAuth2.'],
            ['target' => self::WEBHOOK, 'reason' => 'La firma se calcula sobre el cuerpo en bruto.'],
        ]));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['error', 'error'], $this->levels($report, 'hand-rolled-unapproved'));
        $this->assertStringContainsString('sin la huella del código aprobado', $this->unapproved($report)['message']);

        // Aprobar de nuevo la calcula.
        [$code] = $this->handRolled('approve', ['--all' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
    }

    public function test_una_huella_mal_formada_deja_el_candado_ilegible(): void
    {
        mkdir($this->project->path.'/.larapack');
        file_put_contents($this->project->path.'/'.HandRolledLock::PATH, json_encode([
            ['target' => self::WEBHOOK, 'reason' => 'La firma se calcula sobre el cuerpo en bruto.', 'fingerprint' => 'md5:abc'],
        ]));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('sha256:<64 hex>', $this->unapproved($report)['message']);
    }

    /**
     * `approve --all` sin nada que aprobar no crea un candado vacío: eso
     * cambiaría el modo de verify sin que nadie lo decidiera.
     */
    public function test_aprobar_todo_sin_excepciones_no_crea_el_candado(): void
    {
        $this->controller('InboundWebhookController.php', 'App\Http\Controllers', "class InboundWebhookController\n{\n}");
        $this->token(null);

        [$code, $result] = $this->handRolled('approve', ['--all' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertFalse($result['changed']);
        $this->assertNull($result['lock']);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);

        $this->runCommand('larapack:hand-rolled', ['action' => 'approve', '--all' => true]);

        $this->assertStringContainsString('no se creó .larapack/hand-rolled.lock', $this->lastOutput);
        $this->assertStringNotContainsString('comitéalo', $this->lastOutput);
        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);
    }

    public function test_lo_aprobado_que_ya_no_esta_en_el_codigo_sobra_y_se_retira(): void
    {
        $this->handRolled('approve', ['--all' => true]);
        $this->token(null);

        [$code, $report] = $this->verify();

        // El token ahora no lleva atributo: no es asunto de estas
        // comprobaciones, pero el candado aprueba algo que no existe.
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame(['warning'], $this->levels($report, 'hand-rolled-stale'));

        $stale = array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === 'hand-rolled-stale'))[0];

        $this->assertSame(HandRolledLock::PATH, $stale['file']);
        $this->assertStringContainsString(self::TOKEN, $stale['message']);

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::FAILURE, $code);

        [, $list] = $this->handRolled('list');

        $this->assertSame('stale', $list['exceptions'][1]['status']);

        [$code] = $this->handRolled('revoke', ['targets' => ['TokenController::store']]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertStringNotContainsString('TokenController', $this->lock());

        [$code] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
    }

    public function test_retirar_una_aprobacion_vuelve_a_dejarla_pendiente(): void
    {
        $this->handRolled('approve', ['--all' => true]);

        [$code] = $this->handRolled('revoke', ['targets' => [self::WEBHOOK]]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['error'], $this->levels($report, 'hand-rolled-unapproved'));

        // Retirar lo que no está aprobado no inventa nada.
        [$code] = $this->handRolled('revoke', ['targets' => [self::WEBHOOK]]);

        $this->assertSame(Command::FAILURE, $code);
    }

    /**
     * Un candado roto no vuelve a 9.0: nada cuenta como aprobado, y el
     * comando no lo reescribe.
     */
    public function test_un_candado_ilegible_falla_cerrado(): void
    {
        mkdir($this->project->path.'/.larapack');
        file_put_contents($this->project->path.'/'.HandRolledLock::PATH, '{"target": "x"}');

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['error', 'error', 'error'], $this->levels($report, 'hand-rolled-unapproved'));
        $this->assertSame([], $this->levels($report, 'hand-rolled-stale'));

        [$code, $result] = $this->handRolled('approve', ['--all' => true]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('no se toca', $result['error']);
        $this->assertSame('{"target": "x"}', $this->lock());
    }

    public function test_los_argumentos_se_validan(): void
    {
        foreach ([
            ['approve', []],
            ['approve', ['--all' => true, 'targets' => ['X']]],
            ['revoke', ['--all' => true]],
            ['list', ['targets' => ['X']]],
            ['aprove', ['--all' => true]],
        ] as [$action, $arguments]) {
            [$code] = $this->handRolled($action, $arguments);

            $this->assertSame(Command::FAILURE, $code, "{$action}: {$this->lastOutput}");
        }

        $this->assertFileDoesNotExist($this->project->path.'/'.HandRolledLock::PATH);
    }

    public function test_list_sin_candado_lo_dice(): void
    {
        $this->runCommand('larapack:hand-rolled', ['action' => 'list']);

        $this->assertStringContainsString(self::WEBHOOK, $this->lastOutput);
        $this->assertStringContainsString('sin candado', $this->lastOutput);
        $this->assertStringContainsString('como en 9.0', $this->lastOutput);
    }
}
