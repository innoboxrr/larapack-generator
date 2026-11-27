<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\SpaFallback;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:fix route-closure` sobre el routes/web.php de las aplicaciones
 * creadas con laravel-setup <= 7.2.0: el de la plantilla tal cual, el de
 * expediente (la raíz ya en un controlador y más closures propios) y el de
 * school-management (un fallback con lógica distinta, que no se toca).
 */
final class FixSpaFallbackTest extends TestCase
{
    /**
     * stubs/app/common/routes/web.php de laravel-setup 7.2.0.
     */
    private const SETUP_720 = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

/*
 * La aplicación es una SPA: la misma vista atiende cualquier pantalla, y el
 * router del front decide cuál toca.
 *
 * Va como fallback y no como una ruta `{any}` a propósito. Una ruta fallback se
 * evalúa siempre la última, así que las del paquete de autenticación, las de la
 * API, log-viewer o el editor del .env ganan sin importar en qué orden se
 * registren. Una petición a la API que no existe sigue siendo un 404 en JSON.
 */

Route::get('/', fn () => view('app'))->name('app');

Route::fallback(function () {
    abort_if(request()->is('api/*') || request()->expectsJson(), 404);

    return view('app');
});

PHP;

    /**
     * Lo que deja el arreglo: el código de laravel-setup 7.2.1 con los
     * comentarios de la 7.2.0, que no se tocan.
     */
    private const FIXED_720 = <<<'PHP'
<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
 * La aplicación es una SPA: la misma vista atiende cualquier pantalla, y el
 * router del front decide cuál toca.
 *
 * Va como fallback y no como una ruta `{any}` a propósito. Una ruta fallback se
 * evalúa siempre la última, así que las del paquete de autenticación, las de la
 * API, log-viewer o el editor del .env ganan sin importar en qué orden se
 * registren. Una petición a la API que no existe sigue siendo un 404 en JSON.
 */

Route::view('/', 'app')->name('app');

Route::fallback(SpaController::class);

PHP;

    /**
     * La forma de expediente: la raíz en un controlador, otros closures con
     * lógica propia y el fallback de laravel-setup al final.
     */
    private const EXPEDIENTE = <<<'PHP'
<?php

use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\PublicPagesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPagesController::class, 'home'])->name('app');

Route::get('/exportaciones/descargar', ExportDownloadController::class)
    ->name('exports.download');

Route::post('/csp-report', function (Request $request) {
    Log::warning('csp', (array) json_decode($request->getContent(), true));

    return response()->noContent();
})->name('csp.report');

Route::fallback(function () {
    abort_if(request()->is('api/*') || request()->expectsJson(), 404);

    return view('app');
});

PHP;

    /**
     * La forma de school-management: el fallback recibe un servicio y pasa
     * datos a la vista. Es lógica del proyecto.
     */
    private const SCHOOL = <<<'PHP'
<?php

use App\Support\Site\HostLandings;
use Illuminate\Support\Facades\Route;

Route::fallback(function (HostLandings $hosts) {
    abort_if(request()->is('api/*') || request()->expectsJson(), 404);

    return view('app', ['hostLanding' => $hosts->forRequest(request())]);
});

PHP;

    private const BASE_CONTROLLER = <<<'PHP'
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}

PHP;

    private const WEB = 'routes/web.php';

    private const CONTROLLER = 'app/Http/Controllers/SpaController.php';

    private const REQUEST = 'app/Http/Requests/SpaRequest.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::application());
        $this->write('app/Http/Controllers/Controller.php', self::BASE_CONTROLLER);
    }

    private function write(string $relative, string $code): void
    {
        $file = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $code);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function fix(array $options = []): array
    {
        $code = $this->runCommand('larapack:fix', ['checks' => ['route-closure'], '--format' => 'json'] + $options);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return [$code, $report];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function verify(array $options = []): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json'] + $options);
        $report = json_decode($this->lastOutput, true);

        $this->assertIsArray($report, $this->lastOutput);

        return [$code, $report['findings']];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    private static function closures(array $findings): array
    {
        return array_values(array_filter($findings, fn (array $f): bool => $f['check'] === 'route-closure'));
    }

    private function lint(string $relative): void
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->project->path.'/'.$relative).' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));
    }

    /**
     * El caso entero: verify --strict en rojo sólo por el fallback, con un
     * mensaje que dice que tiene arreglo; el arreglo deja los archivos de
     * laravel-setup 7.2.1, y verify --strict queda limpio.
     */
    public function test_la_aplicacion_de_laravel_setup_7_2_0_queda_limpia_con_strict(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', ['name' => 'Product']), $this->lastOutput);
        $this->write(self::WEB, self::SETUP_720);

        [$code, $findings] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(['route-closure'], array_values(array_unique(array_column($findings, 'check'))), json_encode($findings));
        $this->assertCount(1, $findings);
        $this->assertSame(self::WEB, $findings[0]['file']);
        $this->assertStringContainsString('línea 17', $findings[0]['message']);
        $this->assertStringContainsString('larapack:fix lo corrige', $findings[0]['message']);
        $this->assertStringNotContainsString('Llévala a un controlador', $findings[0]['message']);

        [$code, $report] = $this->fix();

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertTrue($report['ok']);
        $this->assertSame(
            [['file' => self::WEB], ['file' => self::CONTROLLER], ['file' => self::REQUEST]],
            $report['checks']['route-closure']['fixed']
        );
        $this->assertSame([], $report['checks']['route-closure']['skipped']);
        // Las rutas que generó full-model no tienen closures: ya estaban bien.
        $this->assertSame(count(array_filter($this->project->phpFiles(), fn (string $file): bool => str_starts_with($file, 'routes/'))) - 1, $report['checks']['route-closure']['unchanged']);

        $this->assertSame(self::FIXED_720, $this->project->read(self::WEB));
        $this->assertSame(SpaFallback::CONTROLLER_CODE, $this->project->read(self::CONTROLLER));
        $this->assertSame(SpaFallback::REQUEST_CODE, $this->project->read(self::REQUEST));

        foreach ([self::WEB, self::CONTROLLER, self::REQUEST] as $file) {
            $this->lint($file);
        }

        [$code, $findings] = $this->verify(['--strict' => true]);

        $this->assertSame(Command::SUCCESS, $code, json_encode($findings));
        $this->assertSame([], $findings);
    }

    /**
     * El controlador y la request que se crean son los de laravel-setup
     * 7.2.1, y la forma que se exige: delegan.
     */
    public function test_lo_creado_tiene_la_forma_que_verify_exige(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        $this->fix();

        [, $findings] = $this->verify(['--check' => ['controller-shape', 'request-handle', 'controller-concern', 'hand-rolled', 'route-closure']]);

        $this->assertSame([], $findings);
    }

    public function test_correrlo_dos_veces_no_cambia_nada_la_segunda(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        $this->fix();
        $first = array_map(fn (string $file): string => $this->project->read($file), [self::WEB, self::CONTROLLER, self::REQUEST]);

        [$code, $report] = $this->fix();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame(['fixed' => [], 'skipped' => [], 'unchanged' => 1], $report['checks']['route-closure']);
        $this->assertSame($first, array_map(fn (string $file): string => $this->project->read($file), [self::WEB, self::CONTROLLER, self::REQUEST]));
    }

    public function test_dry_run_ensena_el_diff_y_no_escribe(): void
    {
        $this->write(self::WEB, self::SETUP_720);

        [$code, $report] = $this->fix(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertTrue($report['dry_run']);
        $fixed = $report['checks']['route-closure']['fixed'];
        $this->assertSame([self::WEB, self::CONTROLLER, self::REQUEST], array_column($fixed, 'file'));
        $this->assertStringContainsString("-Route::fallback(function () {\n", $fixed[0]['diff']);
        $this->assertStringContainsString("+Route::fallback(SpaController::class);\n", $fixed[0]['diff']);
        $this->assertStringContainsString("+use App\\Http\\Controllers\\SpaController;\n", $fixed[0]['diff']);
        $this->assertStringContainsString("+Route::view('/', 'app')->name('app');\n", $fixed[0]['diff']);
        $this->assertStringContainsString('+class SpaController extends Controller', $fixed[1]['diff']);
        $this->assertStringContainsString('+class SpaRequest extends FormRequest', $fixed[2]['diff']);

        $this->assertSame(self::SETUP_720, $this->project->read(self::WEB));
        $this->assertFalse($this->project->has(self::CONTROLLER));
        $this->assertFalse($this->project->has(self::REQUEST));
    }

    /**
     * Si el proyecto ya copió los archivos de 7.2.1 —también con saltos de
     * línea de Windows—, se usan y sólo se corrige la ruta.
     */
    public function test_si_ya_estan_los_archivos_de_7_2_1_se_usan(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        $windows = str_replace("\n", "\r\n", SpaFallback::CONTROLLER_CODE);
        $this->write(self::CONTROLLER, $windows);

        [$code, $report] = $this->fix();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame([['file' => self::WEB], ['file' => self::REQUEST]], $report['checks']['route-closure']['fixed']);
        $this->assertSame($windows, $this->project->read(self::CONTROLLER));
        $this->assertSame(self::FIXED_720, $this->project->read(self::WEB));
    }

    /**
     * Un SpaController (o SpaRequest) que no es el de laravel-setup es del
     * proyecto: no se sobrescribe, y sin él no se toca la ruta.
     */
    public function test_un_controlador_o_una_request_distintos_no_se_pisan(): void
    {
        foreach ([self::CONTROLLER => SpaFallback::CONTROLLER_CODE, self::REQUEST => SpaFallback::REQUEST_CODE] as $file => $stub) {
            $this->write(self::WEB, self::SETUP_720);
            $mine = str_replace("view('app')", "view('spa')", str_replace('return $request->handle();', 'return $request->handle(); // propio', $stub));
            $this->assertNotSame($stub, $mine);
            $this->write($file, $mine);

            [$code, $report] = $this->fix();

            $this->assertSame(Command::FAILURE, $code, $file);
            $this->assertSame([], $report['checks']['route-closure']['fixed'], $file);
            $this->assertCount(1, $report['checks']['route-closure']['skipped']);
            $this->assertSame(self::WEB, $report['checks']['route-closure']['skipped'][0]['file']);
            $this->assertStringContainsString("ya existe {$file} y no es el de laravel-setup 7.2.1: no se sobrescribe", $report['checks']['route-closure']['skipped'][0]['reason']);
            $this->assertSame(self::SETUP_720, $this->project->read(self::WEB));
            $this->assertSame($mine, $this->project->read($file));

            unlink($this->project->path.'/'.$file);
            $this->assertFalse($this->project->has(self::CONTROLLER), $file);
            $this->assertFalse($this->project->has(self::REQUEST), $file);
        }
    }

    /**
     * expediente: el fallback se corrige, la raíz —ya en un controlador— no
     * se toca, y el closure con lógica propia se salta con el mensaje de
     * verify. El `use` va en su sitio alfabético.
     */
    public function test_con_mas_closures_corrige_el_fallback_y_salta_el_resto(): void
    {
        $this->write(self::WEB, self::EXPEDIENTE);

        [$code, $report] = $this->fix();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([self::WEB, self::CONTROLLER, self::REQUEST], array_column($report['checks']['route-closure']['fixed'], 'file'));
        $skipped = $report['checks']['route-closure']['skipped'];
        $this->assertCount(1, $skipped);
        $this->assertSame(self::WEB, $skipped[0]['file']);
        $this->assertStringStartsWith('La ruta de la línea 14 lleva su acción en un closure (post())', $skipped[0]['reason']);
        $this->assertStringContainsString('Llévala a un controlador', $skipped[0]['reason']);

        $web = $this->project->read(self::WEB);
        $this->assertStringContainsString(
            "use App\\Http\\Controllers\\PublicPagesController;\nuse App\\Http\\Controllers\\SpaController;\nuse Illuminate\\Http\\Request;\n",
            $web
        );
        $this->assertStringContainsString("Route::get('/', [PublicPagesController::class, 'home'])->name('app');", $web);
        $this->assertStringContainsString("})->name('csp.report');\n\nRoute::fallback(SpaController::class);\n", $web);
        $this->lint(self::WEB);

        [, $findings] = $this->verify(['--check' => ['route-closure']]);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('post()', $findings[0]['message']);
    }

    /**
     * Otro closure en la misma línea que el fallback no se toma por él: lo
     * que se corrige es la llamada a fallback().
     */
    public function test_otro_closure_en_la_misma_linea_no_es_el_fallback(): void
    {
        $this->write(self::WEB, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n"
            ."Route::get('/x', function () { return response('x'); }); Route::fallback(function () { abort_if(request()->is('api/*') || request()->expectsJson(), 404); return view('app'); });\n");

        [, $findings] = $this->verify(['--check' => ['route-closure']]);
        $this->assertSame([false, true], array_map(fn (array $f): bool => str_contains($f['message'], 'larapack:fix lo corrige'), $findings));

        [$code, $report] = $this->fix();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([self::WEB, self::CONTROLLER, self::REQUEST], array_column($report['checks']['route-closure']['fixed'], 'file'));
        $this->assertSame([['file' => self::WEB, 'reason' => $findings[0]['message']]], $report['checks']['route-closure']['skipped']);
        $this->assertStringContainsString("Route::get('/x', function () { return response('x'); }); Route::fallback(SpaController::class);\n", $this->project->read(self::WEB));
    }

    /**
     * school-management: el fallback recibe un servicio y pasa datos a la
     * vista. Se parece, pero hace otra cosa: ni verify ofrece el arreglo ni
     * fix lo toca.
     */
    public function test_un_fallback_con_otra_logica_no_se_toca(): void
    {
        $this->write(self::WEB, self::SCHOOL);

        [, $findings] = $this->verify(['--check' => ['route-closure']]);
        $this->assertCount(1, $findings);
        $this->assertStringNotContainsString('larapack:fix', $findings[0]['message']);

        [$code, $report] = $this->fix();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $report['checks']['route-closure']['fixed']);
        $this->assertSame([['file' => self::WEB, 'reason' => $findings[0]['message']]], $report['checks']['route-closure']['skipped']);
        $this->assertSame(self::SCHOOL, $this->project->read(self::WEB));
        $this->assertFalse($this->project->has(self::CONTROLLER));
    }

    /**
     * En un paquete local el fallback de laravel-setup no se reconoce: allí
     * no lo escribía nadie, y SpaController no sería del paquete.
     */
    public function test_en_las_rutas_de_un_paquete_no_se_reconoce(): void
    {
        $this->write('packages/blog/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Blog\\' => 'src/']]]));
        $this->write('packages/blog/routes/web.php', self::SETUP_720);

        [, $findings] = $this->verify(['--check' => ['route-closure']]);
        $this->assertStringNotContainsString('larapack:fix', $findings[0]['message']);

        [$code, $report] = $this->fix(['--package' => ['blog']]);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([['file' => 'packages/blog/routes/web.php', 'reason' => $findings[0]['message']]], $report['checks']['route-closure']['skipped']);
        $this->assertSame(self::SETUP_720, $this->project->read('packages/blog/routes/web.php'));
    }

    /**
     * --path y --package acotan por el archivo de rutas, como en las demás
     * correcciones.
     */
    public function test_el_alcance_es_el_del_archivo_de_rutas(): void
    {
        $this->write(self::WEB, self::SETUP_720);

        [$code, $report] = $this->fix(['--path' => 'app', '--dry-run' => true]);
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame(['fixed' => [], 'skipped' => [], 'unchanged' => 0], $report['checks']['route-closure']);

        // Las rutas del paquete, que van antes, quedan fuera; las de la raíz no.
        $this->write('packages/blog/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Blog\\' => 'src/']]]));
        $this->write('packages/blog/routes/web.php', self::SCHOOL);

        [, $report] = $this->fix(['--package' => ['app'], '--dry-run' => true]);
        $this->assertCount(3, $report['checks']['route-closure']['fixed']);
        $this->assertSame([], $report['checks']['route-closure']['skipped']);
    }

    /**
     * Con el fallback saltado, los closures que vienen detrás se siguen
     * informando.
     */
    public function test_un_fallback_saltado_no_corta_el_resto(): void
    {
        $this->write(self::WEB, self::SETUP_720."\nRoute::post('/csp-report', function () {\n    return response()->noContent();\n});\n");
        $this->write(self::CONTROLLER, '<?php // otro');

        [, $report] = $this->fix();

        $skipped = $report['checks']['route-closure']['skipped'];
        $this->assertCount(2, $skipped);
        $this->assertStringContainsString('ya existe '.self::CONTROLLER, $skipped[0]['reason']);
        $this->assertStringStartsWith('La ruta de la línea 23 lleva su acción en un closure (post())', $skipped[1]['reason']);
    }

    /**
     * El directorio de App\ sale del composer.json, también si es una lista
     * de rutas, como lo admite Composer: vale la primera.
     */
    public function test_el_directorio_de_la_aplicacion_sale_de_composer(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        $this->write('src/Http/Controllers/Controller.php', self::BASE_CONTROLLER);
        $this->write('composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => ['src/', 'app/']]]]));

        [$code, $report] = $this->fix();

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertSame([self::WEB, 'src/Http/Controllers/SpaController.php', 'src/Http/Requests/SpaRequest.php'], array_column($report['checks']['route-closure']['fixed'], 'file'));
        $this->assertFalse($this->project->has(self::CONTROLLER));

        // La raíz del proyecto no es un directorio de la aplicación.
        $this->write(self::WEB, self::SETUP_720);
        $this->write('composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => '/']]]));

        [, $report] = $this->fix();

        $this->assertStringContainsString('no mapea App\ a un directorio en autoload.psr-4', $report['checks']['route-closure']['skipped'][0]['reason']);
    }

    public function test_sin_el_controlador_base_o_sin_app_en_composer_se_salta(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        unlink($this->project->path.'/app/Http/Controllers/Controller.php');

        [$code, $report] = $this->fix();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(
            'Línea 17, el fallback de la SPA de laravel-setup 7.2.0: no existe App\Http\Controllers\Controller, de la que extiende SpaController.',
            $report['checks']['route-closure']['skipped'][0]['reason']
        );

        $this->write('app/Http/Controllers/Controller.php', self::BASE_CONTROLLER);
        $this->write('composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]));

        [, $report] = $this->fix();

        $this->assertStringContainsString('no mapea App\ a un directorio en autoload.psr-4', $report['checks']['route-closure']['skipped'][0]['reason']);
        $this->assertSame(self::SETUP_720, $this->project->read(self::WEB));
    }

    public function test_pasa_el_pint_del_proyecto_y_sigue_limpio(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        putenv('LARAPACK_PINT='.dirname(__DIR__, 2).'/vendor/laravel/pint/builds/pint');

        try {
            [$code, $report] = $this->fix();
        } finally {
            putenv('LARAPACK_PINT=off'); // lo de la suite: phpunit.xml.dist
        }

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertTrue($report['formatted']);
        $this->assertSame(self::FIXED_720, $this->project->read(self::WEB));
        $this->assertTrue(SpaFallback::same($this->project->read(self::CONTROLLER), SpaFallback::CONTROLLER_CODE));
        $this->assertTrue(SpaFallback::same($this->project->read(self::REQUEST), SpaFallback::REQUEST_CODE));

        [, $findings] = $this->verify(['--check' => ['controller-shape', 'request-handle', 'route-closure']]);
        $this->assertSame([], $findings);
    }

    /**
     * Si tras formatear uno de los tres no parsea, se devuelven los tres: el
     * routes/web.php a su original, y los creados se borran.
     */
    public function test_si_pint_rompe_uno_se_devuelven_los_tres(): void
    {
        $this->write(self::WEB, self::SETUP_720);
        $pint = $this->project->path.'/broken-pint.php';
        file_put_contents($pint, "<?php\nforeach (array_slice(\$argv, 1) as \$file) {\n    if (str_ends_with(\$file, 'SpaRequest.php')) {\n        file_put_contents(\$file, \"<?php\\nclass {\\n\");\n    }\n}\n");
        putenv('LARAPACK_PINT='.$pint);

        try {
            [$code, $report] = $this->fix();
        } finally {
            putenv('LARAPACK_PINT=off'); // lo de la suite: phpunit.xml.dist
        }

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame([], $report['checks']['route-closure']['fixed']);
        $reason = 'Después de formatearlo no parseaba, y se devolvió el original.';
        $this->assertEqualsCanonicalizing(
            [['file' => self::WEB, 'reason' => $reason], ['file' => self::CONTROLLER, 'reason' => $reason], ['file' => self::REQUEST, 'reason' => $reason]],
            $report['checks']['route-closure']['skipped']
        );
        $this->assertSame(self::SETUP_720, $this->project->read(self::WEB));
        $this->assertFalse($this->project->has(self::CONTROLLER));
        $this->assertFalse($this->project->has(self::REQUEST));
    }

    /**
     * Junto con las otras correcciones: cada una informa de lo suyo.
     */
    public function test_con_las_demas_correcciones_y_en_texto(): void
    {
        $this->write(self::WEB, self::SETUP_720);

        $code = $this->runCommand('larapack:fix', ['checks' => ['index-managed', 'resource-appends', 'route-closure']]);

        $this->assertSame(Command::SUCCESS, $code, $this->lastOutput);
        $this->assertStringContainsString("route-closure\n  arreglado  routes/web.php\n  arreglado  app/Http/Controllers/SpaController.php\n  arreglado  app/Http/Requests/SpaRequest.php\n  3 arreglados, 0 saltados, 0 ya estaban bien", $this->lastOutput);
    }

    public function test_una_correccion_desconocida_lista_route_closure(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:fix', ['checks' => ['route-closures']]));
        $this->assertStringContainsString('Disponibles: index-managed, resource-appends, route-closure.', $this->lastOutput);
    }
}
