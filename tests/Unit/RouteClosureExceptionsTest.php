<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\ProjectVerification;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use Innoboxrr\LarapackGenerator\Tests\Support\SmallRealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;

/**
 * El closure de una ruta que el dueño aprobó como excepción del
 * realineamiento: lo que `verify` deja de señalar, lo que sigue señalando
 * cuando el closure cambia y lo que avisa cuando lo aprobado ya no está.
 *
 * RealignExceptionsTest lo recorre de punta a punta (pedir, aprobar,
 * cambiar). Aquí el candado se escribe a mano y se llama a verify sin
 * diagnosticar nada: es la prueba de esta rama de ControllerShape que puede
 * correr bajo Infection, donde las que regeneran el contrato dan error con
 * cualquier mutante (docs/mutacion.md).
 */
final class RouteClosureExceptionsTest extends TestCase
{
    use SmallRealignProject;

    private const ROUTES = 'routes/web.php';

    private const CODE = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Route;

        Route::get('/health', function () {
            return response()->json(['ok' => true]);
        });
        Route::POST('/csp-report', function () {
            return response()->noContent();
        });
        Route::get('/a', function () { return 1; }); Route::post('/a', function () { return 2; });
        Route::get('/other', function () {
            return 3;
        });

        PHP;

    private const UNAPPROVED = ' lleva su acción en un closure (%s()): es una acción sin controlador, y nada de lo que verify comprueba en los controladores la alcanza. Llévala a un controlador cuya acción sea `return $request->handle();` con un FormRequest propio (o usa Route::view() si sólo pinta una vista).';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bare();
        $this->write(self::ROUTES, self::CODE);
    }

    /**
     * Los closures del archivo de rutas, por la etiqueta de su objetivo.
     *
     * @return array<string, array{target: string, fingerprint: string, line: int, call: string}>
     */
    private function closures(): array
    {
        return array_column(ExceptionTargets::routeClosures(self::ROUTES, $this->project->read(self::ROUTES)), null, 'label');
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string}> nivel, comprobación, archivo y mensaje
     */
    private function verify(): array
    {
        $findings = (new ProjectVerification($this->project->path))->run(['route-closure', 'hand-rolled-stale'])['findings'];

        return array_map(fn (array $finding): array => [$finding['level'], $finding['check'], $finding['file'], $finding['message']], $findings);
    }

    private function unapproved(int $line, string $call): string
    {
        return "La ruta de la línea {$line}".sprintf(self::UNAPPROVED, $call);
    }

    public function test_sin_aprobar_cada_closure_es_un_aviso(): void
    {
        $this->assertSame(["get('/health')", "post('/csp-report')", "get('/a')", "post('/a')", "get('/other')"], array_keys($this->closures()));

        $this->assertSame([
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(5, 'get')],
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(8, 'POST')],
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(11, 'get')],
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(11, 'post')],
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(12, 'get')],
        ], $this->verify());
    }

    public function test_el_aprobado_sobre_su_codigo_de_hoy_pasa_a_info_y_los_demas_siguen(): void
    {
        $closures = $this->closures();
        $health = $closures["get('/health')"];
        $csp = $closures["post('/csp-report')"];
        $second = $closures["post('/a')"];

        HandRolledLock::write($this->project->path, [
            $health['target'] => ['reason' => "La salud\n del balanceador.", 'fingerprint' => $health['fingerprint']],
            // Con la llamada en mayúsculas en el código, el objetivo es el mismo.
            $csp['target'] => ['reason' => 'CSP.', 'fingerprint' => $csp['fingerprint']],
            // Dos en la misma línea: se aprueba el segundo, no el primero.
            $second['target'] => ['reason' => 'El segundo.', 'fingerprint' => $second['fingerprint']],
            // Una excepción del realineamiento que no es de un closure no la juzga esto.
            'realign:unmanaged-http:src/Http/Controllers/X.php' => ['reason' => 'Otra cosa.', 'fingerprint' => null],
        ]);

        $approved = fn (int $line, string $call, string $reason, string $target): string => "La ruta de la línea {$line} lleva su acción en un closure ({$call}()), escrito así a propósito: «{$reason}». Excepción aprobada por el dueño del proyecto en .larapack/hand-rolled.lock ({$target}).";

        $this->assertSame([
            [Verifier::INFO, 'route-closure', self::ROUTES, $approved(5, 'get', 'La salud del balanceador.', "realign:route-closure:routes/web.php#get('/health')")],
            [Verifier::INFO, 'route-closure', self::ROUTES, $approved(8, 'POST', 'CSP.', "realign:route-closure:routes/web.php#post('/csp-report')")],
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(11, 'get')],
            [Verifier::INFO, 'route-closure', self::ROUTES, $approved(11, 'post', 'El segundo.', "realign:route-closure:routes/web.php#post('/a')")],
            // El que va detrás de uno aprobado se sigue mirando.
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(12, 'get')],
        ], $this->verify());
    }

    public function test_si_el_closure_cambia_la_aprobada_ya_no_lo_cubre_y_se_dice(): void
    {
        $closures = $this->closures();
        $health = $closures["get('/health')"];
        $other = $closures["get('/other')"];
        HandRolledLock::write($this->project->path, [
            $health['target'] => ['reason' => 'La salud.', 'fingerprint' => $health['fingerprint']],
            $other['target'] => ['reason' => 'Otra.', 'fingerprint' => $other['fingerprint']],
        ]);

        // El formato no cambia la huella; el código, sí.
        $this->write(self::ROUTES, str_replace(["['ok' => true]", 'return 3;'], ["['ok' => true, 'db' => DB::connection()->getPdo() !== null]", "// igual\n    return   3;"], self::CODE));

        $findings = $this->verify();

        $this->assertSame(
            [Verifier::WARNING, 'route-closure', self::ROUTES, $this->unapproved(5, 'get')." La excepción aprobada {$health['target']} era de otro código: el closure cambió y ya no lo cubre. El dueño del proyecto la vuelve a aprobar (larapack:hand-rolled approve) o la ruta se lleva a un controlador."],
            $findings[0]
        );
        // Los que nadie aprobó no hablan de ninguna excepción.
        $this->assertSame($this->unapproved(8, 'POST'), $findings[1][3]);
        $this->assertSame([Verifier::INFO, 'route-closure'], [$findings[4][0], $findings[4][1]]);
        $this->assertCount(5, $findings);
    }

    public function test_lo_aprobado_que_ya_no_esta_avisa_en_el_candado(): void
    {
        $health = $this->closures()["get('/health')"];
        HandRolledLock::write($this->project->path, [
            $health['target'] => ['reason' => 'La salud.', 'fingerprint' => $health['fingerprint']],
            "realign:route-closure:routes/web.php#get('/gone')" => ['reason' => 'Ya no está.', 'fingerprint' => $health['fingerprint']],
            'App\\Http\\Controllers\\GoneController::store' => ['reason' => 'Era de #[HandRolled].', 'fingerprint' => $health['fingerprint']],
            'realign:unmanaged-http:src/Http/Controllers/X.php' => ['reason' => 'Otra cosa.', 'fingerprint' => $health['fingerprint']],
        ]);

        $stale = array_values(array_filter($this->verify(), fn (array $finding): bool => $finding[1] === 'hand-rolled-stale'));

        // Por orden de objetivo; la que no es de un closure la juzga quien ve todos los hallazgos.
        $this->assertSame([
            [Verifier::WARNING, 'hand-rolled-stale', HandRolledLock::PATH, 'Aprueba App\\Http\\Controllers\\GoneController::store, que ya no lleva #[HandRolled] en ningún controlador. El dueño del proyecto la retira con larapack:hand-rolled revoke.'],
            [Verifier::WARNING, 'hand-rolled-stale', HandRolledLock::PATH, "Aprueba la excepción realign:route-closure:routes/web.php#get('/gone'), y esa ruta ya no lleva su acción en un closure (o cambió de URI). El dueño del proyecto la retira con larapack:hand-rolled revoke."],
        ], $stale);
    }
}
