<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use PHPUnit\Framework\TestCase;

/**
 * El objetivo de una excepción del realineamiento dice exactamente qué
 * cubre, y su huella sigue al código y no al formato: es lo que hace que una
 * aprobación caduque cuando cambia lo aprobado, y sólo entonces.
 */
final class ExceptionTargetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-targets-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/routes', 0777, true);
        mkdir($this->root.'/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function test_un_objetivo_se_escribe_y_se_lee_igual(): void
    {
        $this->assertSame('realign:unmanaged-http:src/Http/Controllers/X.php', ExceptionTargets::forFile('unmanaged-http', null, './src/Http/Controllers/X.php'));
        // La comprobación sólo va cuando no se llama como el tipo.
        $this->assertSame('realign:fix/index-managed:src/A.php', ExceptionTargets::forFile('fix', 'index-managed', 'src/A.php'));
        $this->assertSame('realign:route-closure:routes/web.php', ExceptionTargets::forFile('route-closure', 'route-closure', 'routes/web.php'));
        $this->assertSame('realign:no-contract:packages/pay/laraimport.json', ExceptionTargets::forFile('no-contract', null, ExceptionTargets::contractOf('packages/pay')));
        $this->assertSame('laraimport.json', ExceptionTargets::contractOf('.'));
        $this->assertSame('.github/x.yml', ExceptionTargets::relative('./.github/x.yml'));

        $this->assertSame(['type' => 'route-closure', 'check' => null, 'file' => 'routes/web.php', 'subject' => "get('/a#b')"],
            ExceptionTargets::parse(ExceptionTargets::forClosure('routes/web.php', "get('/a#b')")));
        $this->assertSame(['type' => 'fix', 'check' => 'index-managed', 'file' => 'src/A.php', 'subject' => null], ExceptionTargets::parse('realign:fix/index-managed:src/A.php'));
        $this->assertNull(ExceptionTargets::parse('App\\Http\\Controllers\\X::store'));
        $this->assertFalse(ExceptionTargets::isRealign('App\\Http\\Controllers\\X'));

        $statement = ExceptionTargets::forStatement('inconsistent-entity', null, 'User', 'No tiene componente Factory y todas las demás entidades sí.');
        $this->assertMatchesRegularExpression('/^realign:inconsistent-entity:model:User#[0-9a-f]{12}$/', $statement);
        $this->assertNotSame($statement, ExceptionTargets::forStatement('inconsistent-entity', null, 'User', 'Otra frase.'));
    }

    public function test_cada_closure_se_nombra_por_su_llamada_y_su_uri(): void
    {
        $code = <<<'PHP'
            <?php

            Route::get('/', fn () => view('app'));
            Route::get('/csp-report', function (Request $request) {
                logger($request->all());

                return response()->noContent();
            });
            Route::options('/xapi/{any}', static fn () => response('', 204))->where('any', '.*');
            Route::match(['get', 'post'], '/hook', action: function () { return 1; });
            Route::fallback(function () { return response()->file(public_path('index.html')); });
            Route::get('/csp-report', function () { return 2; });
            PHP;

        $closures = ExceptionTargets::routeClosures('routes/web.php', $code);

        // El que sólo pinta una vista no se marca (ControllerShape): no
        // tiene nombre de excepción.
        $this->assertSame(["get('/csp-report')", "options('/xapi/{any}')", "match('/hook')", 'fallback()', "get('/csp-report')~2"], array_column($closures, 'label'));
        $this->assertSame([4, 9, 10, 11, 12], array_column($closures, 'line'));
        $this->assertSame("realign:route-closure:routes/web.php#get('/csp-report')", $closures[0]['target']);
        $this->assertSame(5, count(array_unique(array_column($closures, 'fingerprint'))));

        foreach ($closures as $closure) {
            $this->assertMatchesRegularExpression(HandRolledLock::FINGERPRINT, $closure['fingerprint']);
        }
    }

    public function test_la_huella_de_un_closure_sigue_al_codigo_y_no_al_formato_ni_a_la_linea(): void
    {
        $before = "<?php\n\nRoute::get('/csp-report', function (Request \$r) {\n    logger(\$r->all());\n\n    return 1;\n});\n";
        $moved = "<?php\n\nRoute::get('/otra', fn () => 1);\n\n// La de CSP.\nRoute::get('/csp-report', function (Request \$r) { logger(\$r->all()); return 1; });\n";
        $changed = "<?php\n\nRoute::get('/csp-report', function (Request \$r) {\n    logger(\$r->json());\n\n    return 1;\n});\n";
        $otherUri = "<?php\n\nRoute::get('/csp', function (Request \$r) {\n    logger(\$r->all());\n\n    return 1;\n});\n";

        $fingerprint = fn (string $code, string $label): ?string => array_column(ExceptionTargets::routeClosures('routes/web.php', $code), 'fingerprint', 'label')[$label] ?? null;

        $this->assertSame($fingerprint($before, "get('/csp-report')"), $fingerprint($moved, "get('/csp-report')"));
        $this->assertNotSame($fingerprint($before, "get('/csp-report')"), $fingerprint($changed, "get('/csp-report')"));
        $this->assertNull($fingerprint($otherUri, "get('/csp-report')"));

        // Y con un archivo en disco, por su objetivo.
        file_put_contents($this->root.'/routes/web.php', $before);
        $target = ExceptionTargets::forClosure('routes/web.php', "get('/csp-report')");
        $this->assertSame($fingerprint($before, "get('/csp-report')"), ExceptionTargets::fingerprint($this->root, $target));
        file_put_contents($this->root.'/routes/web.php', $otherUri);
        $this->assertNull(ExceptionTargets::fingerprint($this->root, $target), 'Un closure que ya no está no tiene huella: lo aprobado sobra.');
    }

    public function test_la_huella_de_un_archivo_ignora_espacios_y_comentarios_pero_no_el_codigo(): void
    {
        $target = ExceptionTargets::forFile('unmanaged-http', null, 'src/Hook.php');
        $path = $this->root.'/src/Hook.php';

        file_put_contents($path, "<?php\n\nclass Hook\n{\n    public function x() { return 1; }\n}\n");
        $original = ExceptionTargets::fingerprint($this->root, $target);

        file_put_contents($path, "<?php\n// comentario\nclass Hook {\n\n    /** doc */\n    public function x()\n    {\n        return 1;\n    }\n}\n");
        $this->assertSame($original, ExceptionTargets::fingerprint($this->root, $target));

        file_put_contents($path, "<?php\n\nclass Hook\n{\n    public function x() { return 2; }\n}\n");
        $this->assertNotSame($original, ExceptionTargets::fingerprint($this->root, $target));

        unlink($path);
        $this->assertNull(ExceptionTargets::fingerprint($this->root, $target), 'Un archivo que ya no está no tiene huella.');

        // Lo que no es PHP se compara por su contenido, salvo los saltos de línea.
        $json = ExceptionTargets::forFile('verify-other', 'algo', 'src/x.json');
        file_put_contents($this->root.'/src/x.json', "{\n}\n");
        $lf = ExceptionTargets::fingerprint($this->root, $json);
        file_put_contents($this->root.'/src/x.json', "{\r\n}\r\n");
        $this->assertSame($lf, ExceptionTargets::fingerprint($this->root, $json));
    }

    public function test_lo_que_se_aprueba_que_no_este_caduca_si_vuelve(): void
    {
        $missing = ExceptionTargets::forFile('missing', null, 'src/Gone.php');
        $absent = ExceptionTargets::fingerprint($this->root, $missing);

        $this->assertSame(ExceptionTargets::absentFingerprint('src/Gone.php'), $absent);

        file_put_contents($this->root.'/src/Gone.php', "<?php\n");
        $this->assertNotSame($absent, ExceptionTargets::fingerprint($this->root, $missing));

        $contract = ExceptionTargets::forFile('no-contract', null, ExceptionTargets::contractOf('.'));
        $without = ExceptionTargets::fingerprint($this->root, $contract);
        file_put_contents($this->root.'/laraimport.json', '{"models": []}');
        $this->assertNotSame($without, ExceptionTargets::fingerprint($this->root, $contract));

        // Una de entidad no tiene huella sin su hallazgo.
        $this->assertNull(ExceptionTargets::fingerprint($this->root, ExceptionTargets::forStatement('inconsistent-entity', null, 'User', 'x')));
    }
}
