<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Las dos mitades de una excepción del realineamiento, con los archivos
 * escritos a mano: la solicitud (que no cubre nada) y la aprobación del
 * candado (que cubre sólo lo que nombra, y sólo mientras su huella sea la del
 * código de hoy).
 *
 * De esto depende que un hallazgo deje de contar: cada caso de aquí en el que
 * algo «cubre» sin que el dueño lo aprobara sobre ese código sería un
 * hallazgo que desaparece solo.
 */
final class ExceptionsTest extends TestCase
{
    private const CONTROLLER = 'src/Http/Controllers/ReportController.php';

    private const TARGET = 'realign:unmanaged-http:'.self::CONTROLLER;

    private const STATEMENT = 'No tiene componente Factory y todas las demás entidades sí.';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-exceptions-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/src/Http/Controllers', 0777, true);
        file_put_contents($this->root.'/'.self::CONTROLLER, "<?php\n\nclass ReportController\n{\n    public function index() { return 1; }\n}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function exceptions(): Exceptions
    {
        return Exceptions::forRoot($this->root);
    }

    /**
     * @param  array<string, array{0: string, 1: string|null}>  $approved  objetivo => [razón, huella]
     */
    private function lock(array $approved): void
    {
        HandRolledLock::write($this->root, array_map(fn (array $entry): array => ['reason' => $entry[0], 'fingerprint' => $entry[1]], $approved));
    }

    private function today(string $target = self::TARGET): string
    {
        return (string) ExceptionTargets::fingerprint($this->root, $target);
    }

    /**
     * @param  array<int, mixed>  $requests
     */
    private function requestsFile(array $requests, mixed $version = 1): void
    {
        @mkdir($this->root.'/.larapack/realign', 0777, true);
        file_put_contents($this->root.'/.larapack/realign/exceptions.json', (string) json_encode(['format_version' => $version, 'requests' => $requests]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function request(string $target = self::TARGET, string $reason = 'Es un informe interno.', array $extra = []): array
    {
        return $extra + ['target' => $target, 'reason' => $reason];
    }

    private function refused(callable $read): string
    {
        try {
            $read();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('Tenía que negarse.');
    }

    public function test_el_objetivo_de_cada_elemento_dice_exactamente_que_cubre(): void
    {
        $exceptions = $this->exceptions();

        $this->assertSame(self::TARGET, $exceptions->targetOf(['type' => 'unmanaged-http', 'scope' => '.', 'file' => self::CONTROLLER]));
        $this->assertSame('realign:fix/index-managed:src/A.php', $exceptions->targetOf(['type' => 'fix', 'check' => 'index-managed', 'file' => './src/A.php']));
        // Dejar un ámbito sin contrato cubre que su laraimport no esté.
        $this->assertSame('realign:no-contract:laraimport.json', $exceptions->targetOf(['type' => 'no-contract', 'scope' => '.', 'file' => 'lo que sea']));
        $this->assertSame('realign:no-contract:packages/pay/laraimport.json', $exceptions->targetOf(['type' => 'no-contract', 'scope' => 'packages/pay']));
        $this->assertSame('realign:no-contract:laraimport.json', $exceptions->targetOf(['type' => 'no-contract']));
        // El hallazgo de una entidad, sin archivo: su modelo y su mensaje.
        $this->assertSame(
            ExceptionTargets::forStatement('inconsistent-entity', 'inconsistent-entity', 'User', self::STATEMENT),
            $exceptions->targetOf(['type' => 'inconsistent-entity', 'check' => 'inconsistent-entity', 'file' => null, 'model' => 'User', 'message' => self::STATEMENT])
        );
        $this->assertSame(
            $exceptions->targetOf(['type' => 'inconsistent-entity', 'model' => 'User', 'message' => self::STATEMENT]),
            $exceptions->targetOf(['type' => 'inconsistent-entity', 'file' => '', 'model' => 'User', 'message' => self::STATEMENT])
        );

        // Lo que no se puede nombrar con exactitud no tiene objetivo: un
        // closure sin saber cuál, un elemento sin tipo, uno sin archivo ni modelo.
        $this->assertNull($exceptions->targetOf(['type' => 'route-closure', 'file' => 'routes/web.php']));
        $this->assertNull($exceptions->targetOf(['file' => self::CONTROLLER]));
        $this->assertNull($exceptions->targetOf(['type' => '', 'file' => self::CONTROLLER]));
        $this->assertNull($exceptions->targetOf(['type' => null, 'file' => self::CONTROLLER]));
        $this->assertNull($exceptions->targetOf(['type' => 'security']));
        $this->assertNull($exceptions->targetOf(['type' => 'security', 'model' => 'User']));
        $this->assertNull($exceptions->targetOf(['type' => 'security', 'message' => 'x']));
    }

    public function test_solo_cubre_lo_aprobado_sobre_el_codigo_de_hoy(): void
    {
        $item = ['type' => 'unmanaged-http', 'scope' => '.', 'file' => self::CONTROLLER];

        // Pedida y sin aprobar no cubre nada.
        $this->requestsFile([$this->request()]);
        $this->assertFalse($this->exceptions()->covers($item));
        $this->assertNull($this->exceptions()->approvedFor($item));
        $this->assertNull($this->exceptions()->expiredFor($item));

        $this->lock([self::TARGET => ['Es un informe interno.', $this->today()], 'App\\Http\\Controllers\\X::store' => ['De #[HandRolled].', $this->today()]]);
        $exceptions = $this->exceptions();

        $this->assertTrue($exceptions->covers($item));
        $this->assertSame(['target' => self::TARGET, 'reason' => 'Es un informe interno.'], $exceptions->approvedFor($item));
        $this->assertNull($exceptions->expiredFor($item));
        // Sólo lo que nombra: otro archivo, otro tipo.
        $this->assertFalse($exceptions->covers(['type' => 'unmanaged-http', 'file' => 'src/Http/Controllers/Other.php']));
        $this->assertFalse($exceptions->covers(['type' => 'unmanaged-models', 'file' => self::CONTROLLER]));
        $this->assertFalse($exceptions->covers(['file' => self::CONTROLLER]));
        // Las de #[HandRolled] no son del realineamiento.
        $this->assertSame([self::TARGET], array_keys($exceptions->approvals()));
        $this->assertNull($exceptions->lockError());

        // El formato no cambia la huella; el código, sí: deja de cubrir y se dice.
        file_put_contents($this->root.'/'.self::CONTROLLER, "<?php\n\n// nota\nclass ReportController\n{\n    public function index()\n    {\n        return 1;\n    }\n}\n");
        $this->assertTrue($this->exceptions()->covers($item));

        file_put_contents($this->root.'/'.self::CONTROLLER, "<?php\n\nclass ReportController\n{\n    public function index() { return 2; }\n}\n");
        $changed = $this->exceptions();
        $this->assertFalse($changed->covers($item));
        $this->assertSame(self::TARGET, $changed->expiredFor($item));
        $this->assertNull($changed->expiredFor(['type' => 'unmanaged-http', 'file' => 'src/Http/Controllers/Other.php']));
        $this->assertNull($changed->expiredFor(['file' => self::CONTROLLER]));

        // Una entrada del candado sin huella no dice qué código se aprobó.
        $this->lock([self::TARGET => ['Es un informe interno.', null]]);
        $this->assertFalse($this->exceptions()->covers($item));
        $this->assertSame(self::TARGET, $this->exceptions()->expiredFor($item));
    }

    public function test_el_estado_de_cada_excepcion(): void
    {
        $gone = 'realign:unmanaged-http:src/Http/Controllers/Gone.php';
        $absent = 'realign:missing:src/Policies/PostPolicy.php';

        $this->requestsFile([$this->request(), $this->request($gone), $this->request($absent, 'Las políticas van en otro paquete.')]);
        $this->assertSame(Exceptions::UNAPPROVED, $this->exceptions()->status(self::TARGET));
        $this->assertSame(Exceptions::UNAPPROVED, $this->exceptions()->status('realign:unmanaged-http:nadie.php'));
        $this->assertSame([], $this->exceptions()->all());

        $this->lock([
            self::TARGET => ['Es un informe interno.', $this->today()],
            $gone => ['Es un informe interno.', 'sha256:'.str_repeat('a', 64)],
            // Que un archivo no esté tiene su huella: vale mientras no vuelva.
            $absent => ['Las políticas van en otro paquete.', ExceptionTargets::absentFingerprint('src/Policies/PostPolicy.php')],
        ]);
        $exceptions = $this->exceptions();

        $this->assertSame(Exceptions::APPROVED, $exceptions->status(self::TARGET));
        // Lo aprobado ya no existe.
        $this->assertSame(Exceptions::STALE, $exceptions->status($gone));
        $this->assertSame(Exceptions::APPROVED, $exceptions->status($absent));
        $this->assertSame([
            ['target' => $absent, 'reason' => 'Las políticas van en otro paquete.', 'fingerprint' => ExceptionTargets::absentFingerprint('src/Policies/PostPolicy.php'), 'status' => Exceptions::APPROVED],
            ['target' => $gone, 'reason' => 'Es un informe interno.', 'fingerprint' => 'sha256:'.str_repeat('a', 64), 'status' => Exceptions::STALE],
            ['target' => self::TARGET, 'reason' => 'Es un informe interno.', 'fingerprint' => $this->today(), 'status' => Exceptions::APPROVED],
        ], $exceptions->all());

        // El archivo vuelve: ya no es lo que se aprobó.
        mkdir($this->root.'/src/Policies');
        file_put_contents($this->root.'/src/Policies/PostPolicy.php', "<?php\n");
        $this->assertSame(Exceptions::CODE_CHANGED, $this->exceptions()->status($absent));

        // El código cambia: la huella caduca.
        file_put_contents($this->root.'/'.self::CONTROLLER, "<?php\n\nclass ReportController\n{\n    public function index() { return 2; }\n}\n");
        $this->assertSame(Exceptions::CODE_CHANGED, $this->exceptions()->status(self::TARGET));

        // Aprobada con otra razón que la que se pide ahora: gana a lo demás.
        $this->lock([self::TARGET => ['Otra razón.', $this->today()], $gone => ['Otra razón.', $this->today()]]);
        $this->assertSame(Exceptions::REASON_CHANGED, $this->exceptions()->status(self::TARGET));
        $this->assertSame(Exceptions::REASON_CHANGED, $this->exceptions()->status($gone));
        // Sin solicitud no hay razón con la que comparar.
        $this->requestsFile([]);
        $this->assertSame(Exceptions::APPROVED, $this->exceptions()->status(self::TARGET));
    }

    public function test_una_solicitud_mal_escrita_no_cuenta_y_un_archivo_que_no_se_entiende_se_dice(): void
    {
        $this->assertSame([], $this->exceptions()->requests(), 'sin archivo');
        $this->assertSame($this->root.'/.larapack/realign/exceptions.json', $this->exceptions()->requestsPath());

        $this->requestsFile([
            $this->request('realign:route-closure:routes/web.php#get(\'/csp\')', "  Es   legítima.\n", ['check' => 'route-closure', 'scope' => 'packages/pay', 'line' => 84, 'step' => 'route-closure@.',
                'message' => 'La ruta…', 'fingerprint' => 'sha256:x', 'requested_at' => '2026-01-01', 'type' => 'lo que diga', 'file' => 'otro.php']),
            $this->request(self::TARGET, 'Es un informe interno.', ['check' => 7, 'scope' => null, 'line' => '84', 'step' => ['x'], 'message' => 1, 'fingerprint' => false, 'requested_at' => null]),
            // Sin razón no se puede revisar; sin objetivo del realineamiento no dice qué cubriría.
            $this->request('realign:missing:a.php', "  \n "),
            ['target' => 'realign:missing:b.php'],
            ['target' => 'realign:missing:c.php', 'reason' => ['x']],
            $this->request('App\\Http\\Controllers\\X::store'),
            ['reason' => 'sin objetivo'],
            ['target' => 7, 'reason' => 'x'],
            'suelta',
        ]);

        $requests = $this->exceptions()->requests();

        // Por objetivo, ordenadas; el tipo, el archivo y el sujeto son los del objetivo.
        $this->assertSame([
            "realign:route-closure:routes/web.php#get('/csp')" => [
                'target' => "realign:route-closure:routes/web.php#get('/csp')", 'type' => 'route-closure', 'check' => 'route-closure', 'scope' => 'packages/pay',
                'file' => 'routes/web.php', 'subject' => "get('/csp')", 'line' => 84, 'step' => 'route-closure@.', 'message' => 'La ruta…',
                'reason' => 'Es legítima.', 'fingerprint' => 'sha256:x', 'requested_at' => '2026-01-01',
            ],
            self::TARGET => [
                'target' => self::TARGET, 'type' => 'unmanaged-http', 'check' => null, 'scope' => '.', 'file' => self::CONTROLLER, 'subject' => null,
                'line' => null, 'step' => null, 'message' => null, 'reason' => 'Es un informe interno.', 'fingerprint' => null, 'requested_at' => '',
            ],
        ], $requests);

        $path = $this->root.'/.larapack/realign/exceptions.json';
        $shape = "{$path} no tiene la forma de las solicitudes de excepción del realineamiento (un objeto con una lista `requests`). Corrígelo o bórralo y vuelve a pedirlas con `larapack:realign except`.";
        $newer = "{$path} está en la versión 2 del formato, y este LaraPack entiende hasta la 1: lo escribió una versión posterior. Actualiza innoboxrr/larapack-generator.";
        $version = "{$path} no dice su `format_version` (un entero; hoy, 1): no es un archivo que haya escrito `larapack:realign`. Corrígelo, o bórralo y vuelve a generarlo.";

        file_put_contents($path, 'no es JSON');
        $this->assertSame($shape, $this->refused(fn () => $this->exceptions()->requests()));
        file_put_contents($path, '{"format_version": 1, "requests": "no es una lista"}');
        $this->assertSame($shape, $this->refused(fn () => $this->exceptions()->requests()));
        $this->requestsFile([], 2);
        $this->assertSame($newer, $this->refused(fn () => $this->exceptions()->requests()));
        $this->requestsFile([], '1');
        $this->assertSame($version, $this->refused(fn () => $this->exceptions()->requests()));
        file_put_contents($path, '{"requests": []}');
        $this->assertSame($version, $this->refused(fn () => $this->exceptions()->requests()));
        // Un objeto sin solicitudes es que no hay ninguna.
        file_put_contents($path, '{"format_version": 1}');
        $this->assertSame([], $this->exceptions()->requests());
        $this->requestsFile([], 0);
        $this->assertSame([], $this->exceptions()->requests());

        // Cubrir no necesita las solicitudes: una mal escrita no tumba verify.
        file_put_contents($path, 'no es JSON');
        $this->lock([self::TARGET => ['Es un informe interno.', $this->today()]]);
        $this->assertTrue($this->exceptions()->covers(['type' => 'unmanaged-http', 'file' => self::CONTROLLER]));
    }

    public function test_pedir_escribe_la_solicitud_y_no_aprueba_nada(): void
    {
        $exceptions = $this->exceptions();
        $request = fn (string $target, string $reason): array => ['target' => $target, 'type' => 'unmanaged-http', 'check' => null, 'scope' => '.', 'file' => 'x', 'subject' => null,
            'line' => null, 'step' => 'unmanaged-http@.', 'message' => null, 'reason' => $reason, 'fingerprint' => null, 'requested_at' => '2026-01-01T00:00:00+00:00'];

        $exceptions->saveRequests([$request('realign:unmanaged-http:src/B.php', 'B se queda.'), $request('realign:unmanaged-http:src/A.php', 'A se queda.')]);

        // Quien las pidió ya las ve, ordenadas por objetivo.
        $this->assertSame(['realign:unmanaged-http:src/A.php', 'realign:unmanaged-http:src/B.php'], array_keys($exceptions->requests()));
        $this->assertFileExists($this->root.'/.larapack/realign/.gitignore');
        $this->assertFileDoesNotExist($this->root.'/'.HandRolledLock::PATH);
        $this->assertStringEndsWith("\n", (string) file_get_contents($exceptions->requestsPath()));

        // Pedir otra vez la misma le cambia la razón; las demás siguen.
        $this->exceptions()->saveRequests([$request('realign:unmanaged-http:src/A.php', 'A se queda, por otra razón.')]);
        $saved = $this->exceptions()->requests();
        $this->assertSame(['realign:unmanaged-http:src/A.php', 'realign:unmanaged-http:src/B.php'], array_keys($saved));
        $this->assertSame('A se queda, por otra razón.', $saved['realign:unmanaged-http:src/A.php']['reason']);
        $this->assertSame('B se queda.', $saved['realign:unmanaged-http:src/B.php']['reason']);
        $this->assertSame('unmanaged-http@.', $saved['realign:unmanaged-http:src/B.php']['step']);
        $this->assertSame(1, json_decode((string) file_get_contents($exceptions->requestsPath()), true)['format_version']);

        $this->assertSame(Exceptions::UNAPPROVED, $this->exceptions()->status('realign:unmanaged-http:src/A.php'));
    }

    public function test_un_candado_ilegible_no_aprueba_nada_y_se_dice(): void
    {
        mkdir($this->root.'/.larapack');
        file_put_contents($this->root.'/'.HandRolledLock::PATH, '{"no": "es una lista"}');

        $exceptions = $this->exceptions();

        $this->assertSame([], $exceptions->approvals());
        $this->assertFalse($exceptions->covers(['type' => 'unmanaged-http', 'file' => self::CONTROLLER]));
        $this->assertSame(HandRolledLock::PATH.' no es una lista JSON de {"target", "reason", "fingerprint"}.', $exceptions->lockError());
    }

    public function test_la_razon_va_en_una_linea_y_sin_caracteres_invisibles(): void
    {
        $this->assertSame('Es legítima: CSP.', Exceptions::sanitise("  Es   legítima:\n\tCSP.  "));
        // Sin caracteres de control ni de formato (un RLO que da la vuelta al texto).
        $this->assertSame('aprobado por rev isor', Exceptions::sanitise("aprobado\u{0007} por\u{202E} rev\u{200B}isor"));
        $this->assertSame('', Exceptions::sanitise(" \n\u{200B}\t "));
        $this->assertSame('a?b', Exceptions::sanitise("a\xFFb"));
    }

    public function test_verify_baja_a_info_lo_que_cubre_una_aprobada_y_avisa_de_la_que_caduco(): void
    {
        $finding = fn (string $check, ?string $file, string $level = Verifier::ERROR, ?string $model = null, string $message = 'Lo que dice verify.'): array => ['level' => $level, 'check' => $check, 'model' => $model, 'file' => $file, 'message' => $message];
        $findings = [
            $finding('secret-exposed', 'src/Http/Resources/PostResource.php'),
            $finding('immutable-write', 'src/Models/Post.php', Verifier::WARNING),
            $finding('route-prefix', 'routes/api.php'),
            // Lo que tiene su propio mecanismo no pasa por aquí, aunque haya una aprobada con ese nombre.
            $finding('controller-shape', self::CONTROLLER),
            $finding('secret-exposed', 'src/Http/Resources/PostResource.php', Verifier::INFO),
            $finding('inconsistent-entity', null, Verifier::WARNING, 'User', self::STATEMENT),
        ];
        file_put_contents($this->root.'/src/Http/Resources.php', '<?php');
        mkdir($this->root.'/src/Http/Resources');
        mkdir($this->root.'/src/Models');
        file_put_contents($this->root.'/src/Http/Resources/PostResource.php', "<?php\nreturn 1;\n");
        file_put_contents($this->root.'/src/Models/Post.php', "<?php\nreturn 1;\n");

        // Sin nada aprobado, nada cambia.
        $this->assertSame([], $this->exceptions()->review($findings));
        $this->assertSame($findings, $this->exceptions()->applyTo($findings));

        $covered = 'realign:security/secret-exposed:src/Http/Resources/PostResource.php';
        $expired = 'realign:security/immutable-write:src/Models/Post.php';
        $statement = ExceptionTargets::forStatement('inconsistent-entity', 'inconsistent-entity', 'User', self::STATEMENT);
        $this->lock([
            $covered => ["El token es\n público.", $this->today($covered)],
            $expired => ['Se escribe en una migración.', 'sha256:'.str_repeat('b', 64)],
            'realign:controller-shape:'.self::CONTROLLER => ['No.', $this->today('realign:controller-shape:'.self::CONTROLLER)],
            $statement => ['El User es el de Laravel.', ExceptionTargets::statementFingerprint(self::STATEMENT)],
        ]);
        $exceptions = $this->exceptions();

        $this->assertSame([
            0 => ['level' => Verifier::INFO, 'message' => 'Lo que dice verify. Excepción aprobada por el dueño del proyecto en '.HandRolledLock::PATH.': «El token es público.» ('.$covered.').'],
            // La que caducó no baja de nivel: lo dice.
            1 => ['level' => Verifier::WARNING, 'message' => "Lo que dice verify. La excepción aprobada {$expired} era de otro código: ya no lo cubre. El dueño del proyecto la vuelve a aprobar (larapack:hand-rolled approve) o se corrige lo que dice este hallazgo."],
            // La de una entidad cubre mientras verify diga lo mismo.
            5 => ['level' => Verifier::INFO, 'message' => self::STATEMENT.' Excepción aprobada por el dueño del proyecto en '.HandRolledLock::PATH.': «El User es el de Laravel.» ('.$statement.').'],
        ], $exceptions->review($findings));

        $applied = $this->exceptions()->applyTo($findings);
        $this->assertSame([Verifier::INFO, Verifier::WARNING, Verifier::ERROR, Verifier::ERROR, Verifier::INFO, Verifier::INFO], array_column($applied, 'level'));
        $this->assertSame('secret-exposed', $applied[0]['check']);
        $this->assertSame($findings[2], $applied[2]);
        $this->assertSame($findings[3], $applied[3]);

        // Si verify dice otra cosa de esa entidad, la aprobada ya no lo cubre.
        $other = $finding('inconsistent-entity', null, Verifier::WARNING, 'User', 'No tiene componente Policy.');
        $this->assertSame([], $this->exceptions()->review([$other]));
    }

    public function test_lo_aprobado_que_ya_no_cubre_ningun_hallazgo_avisa_en_el_candado(): void
    {
        $used = 'realign:security/secret-exposed:src/A.php';
        $unused = 'realign:security/secret-exposed:src/B.php';
        $elsewhere = 'realign:security/secret-exposed:packages/pay/src/C.php';
        $statement = ExceptionTargets::forStatement('inconsistent-entity', null, 'User', self::STATEMENT);
        file_put_contents($this->root.'/src/A.php', '<?php');
        $this->lock([
            $used => ['Se usa.', $this->today($used)],
            $unused => ['Ya no hace falta.', 'sha256:'.str_repeat('c', 64)],
            $elsewhere => ['De otro paquete.', 'sha256:'.str_repeat('c', 64)],
            $statement => ['El User es el de Laravel.', ExceptionTargets::statementFingerprint(self::STATEMENT)],
            // No cubren un hallazgo de verify (código sin gestionar), o tienen su propio mecanismo.
            self::TARGET => ['Informe interno.', $this->today()],
            'realign:route-closure:routes/web.php#get(\'/x\')' => ['CSP.', 'sha256:'.str_repeat('c', 64)],
        ]);
        $exceptions = $this->exceptions();
        $exceptions->applyTo([['level' => Verifier::ERROR, 'check' => 'secret-exposed', 'model' => null, 'file' => 'src/A.php', 'message' => 'Sale el token.']]);

        $stale = fn (string $target): array => ['level' => Verifier::WARNING, 'check' => 'hand-rolled-stale', 'model' => null, 'file' => HandRolledLock::PATH,
            'message' => "Aprueba la excepción {$target}, que ya no cubre ningún hallazgo de verify (se corrigió, o el código cambió). El dueño del proyecto la retira con larapack:hand-rolled revoke."];
        $inRoot = fn (string $file): bool => ! str_starts_with($file, 'packages/');

        $this->assertSame([$stale($statement), $stale($unused)], $exceptions->staleFindings(['secret-exposed', 'inconsistent-entity', 'route-closure'], $inRoot));
        // De lo que no se midió no se dice nada: ni de otro paquete ni de una
        // comprobación que no se corrió.
        $this->assertSame([$stale($elsewhere), $stale($unused)], $exceptions->staleFindings(['secret-exposed'], fn (string $file): bool => true));
        $this->assertSame([$stale($statement)], $exceptions->staleFindings(['inconsistent-entity'], fn (string $file): bool => false));
        $this->assertSame([], $exceptions->staleFindings(['immutable-write'], $inRoot));
        $this->assertSame([], $exceptions->staleFindings([], $inRoot));
    }

    public function test_la_huella_de_un_hallazgo_sin_archivo_es_la_de_lo_que_verify_dice_hoy(): void
    {
        $target = ExceptionTargets::forStatement('inconsistent-entity', 'inconsistent-entity', 'User', self::STATEMENT);
        $this->lock([$target => ['El User es el de Laravel.', ExceptionTargets::statementFingerprint(self::STATEMENT)]]);

        $this->assertTrue(Exceptions::isStatement($target));
        $this->assertFalse(Exceptions::isStatement(self::TARGET));
        $this->assertFalse(Exceptions::isStatement('no es un objetivo'));

        // Verify acaba de decirlo: la aprobada sigue cubriendo.
        $said = $this->exceptions();
        $said->observe([
            ['level' => Verifier::WARNING, 'check' => 'inconsistent-entity', 'model' => 'User', 'file' => null, 'message' => self::STATEMENT],
            // Los hallazgos con archivo no son de una entidad.
            ['level' => Verifier::WARNING, 'check' => 'inconsistent-entity', 'model' => 'Post', 'file' => 'src/Models/Post.php', 'message' => 'Otro.'],
        ], ['inconsistent-entity']);
        $this->assertSame(self::STATEMENT, $said->statement($target));
        $this->assertSame(ExceptionTargets::statementFingerprint(self::STATEMENT), $said->fingerprint($target));
        $this->assertSame(Exceptions::APPROVED, $said->status($target));

        // Verify corrió esa comprobación y ya no lo dice: lo aprobado ya no existe.
        $silent = $this->exceptions();
        $silent->observe([], ['inconsistent-entity']);
        $this->assertNull($silent->statement($target));
        $this->assertNull($silent->fingerprint($target));
        $this->assertSame(Exceptions::STALE, $silent->status($target));

        // Dice otra cosa: es otro objetivo, y el aprobado se queda sin hallazgo.
        $other = $this->exceptions();
        $other->observe([['level' => Verifier::WARNING, 'check' => 'inconsistent-entity', 'model' => 'User', 'file' => '', 'message' => 'No tiene componente Policy.']], ['inconsistent-entity']);
        $this->assertNull($other->statement($target));
        $this->assertSame('No tiene componente Policy.', $other->statement(ExceptionTargets::forStatement('inconsistent-entity', 'inconsistent-entity', 'User', 'No tiene componente Policy.')));

        // Lo que no es el objetivo de una entidad, o de una comprobación que verify no tiene, no tiene mensaje.
        $this->assertNull($said->statement(self::TARGET));
        $this->assertNull($said->statement('realign:inventada:model:User#abcdef012345'));
        // La huella de un archivo se calcula del archivo.
        $this->assertSame($this->today(), $said->fingerprint(self::TARGET));
        $this->assertNull($said->fingerprint('realign:unmanaged-http:src/Nadie.php'));
    }

    /**
     * La lista que hace fallar a `done` y que `brief` le presenta al dueño
     * es una sola (Navigation::pendingExceptions()).
     */
    public function test_lo_pendiente_de_aprobar_es_una_sola_lista(): void
    {
        $other = 'src/Http/Controllers/OtherController.php';
        $declared = 'src/Http/Controllers/DeclaredController.php';
        $old = 'realign:unmanaged-http:src/Http/Controllers/OldController.php';

        foreach ([$other, $declared] as $file) {
            file_put_contents($this->root.'/'.$file, "<?php\n\nclass X\n{\n}\n");
        }

        $this->requestsFile([
            $this->request(extra: ['step' => 'unmanaged-http@.', 'scope' => '.']),
            $this->request('realign:unmanaged-http:'.$other, 'Otro informe.', ['step' => 'unmanaged-http@.', 'scope' => 'packages/blog']),
            $this->request('realign:unmanaged-http:'.$declared, 'Se iba a dejar.', ['step' => 'unmanaged-http@.', 'scope' => '.']),
        ]);
        // Una aprobada sobre el código de hoy, y una de antes sobre algo que ya no existe.
        $this->lock([self::TARGET => ['Es un informe interno.', $this->today()], $old => ['De otra rama.', 'sha256:'.str_repeat('a', 64)]]);

        $step = ['id' => 'unmanaged-http@.', 'category' => Category::DECISION, 'files' => [self::CONTROLLER, $other, $declared],
            'options' => [['key' => 'keep', 'run' => 'larapack:realign except unmanaged-http@. --option reason="…"'], ['key' => 'declare']],
            'decision' => ['option' => 'keep'], 'decisions' => [$declared => ['option' => 'declare']]];
        $byId = ['unmanaged-http@.' => $step];

        $pending = Navigation::pendingExceptions($this->exceptions(), [], $byId);

        // La aprobada no está; la que se decidió declarar ya no se pide; la de antes, caducada, sí.
        $this->assertSame(['realign:unmanaged-http:'.$other, $old], array_column($pending, 'target'));
        $this->assertNotSame(Exceptions::APPROVED, $pending[0]['status']);
        $this->assertSame('Otro informe.', $pending[0]['reason']);
        $this->assertSame('unmanaged-http@.', $pending[0]['request']['step'] ?? null);
        $this->assertNull($pending[1]['request'], 'aprobada sin solicitud');
        $this->assertSame('De otra rama.', $pending[1]['reason']);
        $this->assertSame(Exceptions::STALE, $pending[1]['status']);

        // Sin el plan no se sabe que se decidió declararla: se sigue pidiendo.
        $this->assertSame(['realign:unmanaged-http:'.$declared, 'realign:unmanaged-http:'.$other, $old], array_column(Navigation::pendingExceptions($this->exceptions(), [], []), 'target'));

        // Con --package, sólo las de ese ámbito; las aprobadas sin solicitud no se sabe de cuál son.
        $this->assertSame(['realign:unmanaged-http:'.$other], array_column(Navigation::pendingExceptions($this->exceptions(), ['packages/blog'], $byId), 'target'));
        $this->assertSame([], Navigation::pendingExceptions($this->exceptions(), ['packages/otro'], $byId));
    }
}
