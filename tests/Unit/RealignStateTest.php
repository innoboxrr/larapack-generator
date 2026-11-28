<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\ProjectTests;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El estado de `.larapack/realign/` es lo que hace el realineamiento
 * reanudable, y se versiona con el proyecto: un plan a medio escribir, o uno
 * de una versión posterior leído como si fuera de ésta, es un plan que
 * miente. Aquí, que se escribe entero o no se escribe, y que lo que no se
 * entiende se dice en vez de adivinarse.
 */
final class RealignStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/larapack-state-'.bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function state(): RealignState
    {
        return new RealignState($this->root);
    }

    private function put(string $name, string $content): void
    {
        $this->state()->prepare();
        file_put_contents($this->root.'/.larapack/realign/'.$name, $content);
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

    public function test_cada_cosa_tiene_su_sitio_bajo_el_directorio_del_estado(): void
    {
        $state = $this->state();

        $this->assertSame('.larapack/realign', RealignState::DIRECTORY);
        $this->assertSame($this->root, $state->root());
        $this->assertSame($this->root.'/.larapack/realign', $state->directory());
        $this->assertSame($this->root.'/.larapack/realign/cache', $state->cacheDirectory());
        $this->assertSame($this->root.'/.larapack/realign/report.json', $state->reportPath());
        $this->assertSame($this->root.'/.larapack/realign/plan.json', $state->planPath());
        $this->assertSame($this->root.'/.larapack/realign/config.json', $state->configPath());
    }

    public function test_sin_nada_escrito_no_hay_informe_ni_plan_ni_configuracion(): void
    {
        $this->assertNull($this->state()->report());
        $this->assertNull($this->state()->plan());
        $this->assertNull($this->state()->config());
        // Leer no crea nada.
        $this->assertDirectoryDoesNotExist($this->root.'/.larapack');
    }

    public function test_lo_que_se_guarda_se_lee_igual_con_la_version_del_formato_delante(): void
    {
        $state = $this->state();
        $plan = ['steps' => [['id' => 'adopt@.', 'title' => 'Adoptar lo que ya es', 'files' => ['src/A.php']]], 'report_id' => 'x'];

        $state->savePlan($plan);
        $state->saveReport(['id' => 'x', 'files' => []]);

        $this->assertSame(['format_version' => RealignState::FORMAT_VERSION] + $plan, $state->plan());
        $this->assertSame(['format_version' => 1, 'id' => 'x', 'files' => []], $state->report());
        // Legible y versionable: con sangría, sin escapar barras ni acentos, y con su salto final.
        $this->assertSame(
            "{\n    \"format_version\": 1,\n    \"steps\": [\n        {\n            \"id\": \"adopt@.\",\n            \"title\": \"Adoptar lo que ya es\",\n            \"files\": [\n                \"src/A.php\"\n            ]\n        }\n    ],\n    \"report_id\": \"x\"\n}\n",
            file_get_contents($state->planPath())
        );
        $this->assertStringContainsString('"title": "Señal"', $this->saved(['title' => 'Señal']));

        // La versión es la de quien escribe, diga lo que diga el documento.
        $state->savePlan(['format_version' => 99, 'steps' => []]);
        $this->assertSame(RealignState::FORMAT_VERSION, $state->plan()['format_version'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function saved(array $plan): string
    {
        $this->state()->savePlan($plan);

        return (string) file_get_contents($this->state()->planPath());
    }

    public function test_guardar_crea_el_directorio_con_su_gitignore_y_no_pisa_el_que_haya(): void
    {
        $state = $this->state();
        $state->savePlan(['steps' => []]);

        $ignore = $this->root.'/.larapack/realign/.gitignore';
        $this->assertSame("# Lo regenerado para comparar: se rehace solo y no se versiona.\ncache/\n", file_get_contents($ignore));

        file_put_contents($ignore, "cache/\nbrief.md\n");
        $state->prepare();
        $state->saveReport(['id' => 'x']);
        $this->assertSame("cache/\nbrief.md\n", file_get_contents($ignore));
    }

    public function test_se_escribe_entero_o_no_se_escribe_y_no_deja_temporales(): void
    {
        $state = $this->state();
        $state->savePlan(['steps' => [['id' => 'uno']]]);
        $state->savePlan(['steps' => [['id' => 'dos']]]);

        $this->assertSame(['.gitignore', 'plan.json'], array_values(array_diff((array) scandir($state->directory()), ['.', '..'])));
        $this->assertSame('dos', $state->plan()['steps'][0]['id'] ?? null);
    }

    public function test_si_no_se_puede_escribir_lo_dice_y_no_deja_el_temporal(): void
    {
        $state = $this->state();
        $state->prepare();
        // Donde iría el informe hay un directorio con algo dentro: rename() no puede.
        mkdir($state->reportPath());
        file_put_contents($state->reportPath().'/ocupado', 'x');

        $this->assertSame("No se pudo escribir {$state->reportPath()}.", $this->refused(fn () => $state->saveReport(['id' => 'x'])));
        $this->assertSame(['.gitignore', 'report.json'], array_values(array_diff((array) scandir($state->directory()), ['.', '..'])));
    }

    public function test_un_json_que_no_se_entiende_se_dice_en_vez_de_tratarlo_como_vacio(): void
    {
        $path = $this->root.'/.larapack/realign/plan.json';

        $this->put('plan.json', '{"steps": [');
        $this->assertSame("{$path} no es JSON válido. Bórralo y vuelve a correr `larapack:realign diagnose`.", $this->refused(fn () => $this->state()->plan()));

        $this->put('plan.json', '"un texto"');
        $this->assertSame("{$path} no es JSON válido. Bórralo y vuelve a correr `larapack:realign diagnose`.", $this->refused(fn () => $this->state()->plan()));
    }

    public function test_un_formato_de_una_version_posterior_o_sin_version_no_se_lee_como_si_fuera_de_esta(): void
    {
        $path = $this->root.'/.larapack/realign/plan.json';
        $message = fn (string $version): string => "{$path} está en un formato que esta versión de LaraPack no entiende (format_version {$version}). Actualiza innoboxrr/larapack-generator.";

        $this->put('plan.json', (string) json_encode(['format_version' => RealignState::FORMAT_VERSION + 1, 'steps' => []]));
        $this->assertSame($message('2'), $this->refused(fn () => $this->state()->plan()));

        $this->put('plan.json', '{"steps": []}');
        $this->assertSame($message('null'), $this->refused(fn () => $this->state()->plan()));

        $this->put('plan.json', '{"format_version": "1", "steps": []}');
        $this->assertSame($message('"1"'), $this->refused(fn () => $this->state()->plan()));

        // La de ahora y las anteriores, sí.
        $this->put('plan.json', '{"format_version": 1, "steps": []}');
        $this->assertSame([], $this->state()->plan()['steps'] ?? null);
        $this->put('plan.json', '{"format_version": 0, "steps": []}');
        $this->assertSame([], $this->state()->plan()['steps'] ?? null);

        // El informe y la configuración, igual.
        $this->put('report.json', '{"format_version": 7}');
        $this->assertStringContainsString('report.json está en un formato', $this->refused(fn () => $this->state()->report()));
        $this->put('config.json', 'x');
        $this->assertStringContainsString('config.json no es JSON válido', $this->refused(fn () => $this->state()->config()));
    }

    public function test_la_orden_de_pruebas_del_proyecto_es_la_de_su_configuracion(): void
    {
        $tests = new ProjectTests($this->root);

        $this->assertNull($tests->command(), 'sin configuración');

        $this->put('config.json', '{"format_version": 1}');
        $this->assertNull($tests->command());
        $this->put('config.json', '{"format_version": 1, "tests": "   "}');
        $this->assertNull($tests->command());
        $this->put('config.json', '{"format_version": 1, "tests": null}');
        $this->assertNull($tests->command());

        $this->put('config.json', '{"format_version": 1, "tests": "  php artisan test  "}');
        $this->assertSame('php artisan test', $tests->command());

        // Una lista de órdenes no es la orden.
        $this->put('config.json', '{"format_version": 1, "tests": ["php", "artisan", "test"]}');
        $this->assertSame(
            '.larapack/realign/config.json: `tests` tiene que ser la orden de pruebas del proyecto, como texto.',
            $this->refused(fn () => $tests->command())
        );
        $this->put('config.json', '{"format_version": 1, "tests": false}');
        $this->assertStringContainsString('`tests` tiene que ser', $this->refused(fn () => $tests->command()));
    }

    public function test_correr_las_pruebas_da_su_codigo_de_salida_y_el_final_de_su_salida(): void
    {
        $tests = new ProjectTests($this->root);

        // En la raíz del proyecto, con la salida de errores junto a la estándar.
        file_put_contents($this->root.'/aqui.txt', 'x');
        $command = "ls aqui.txt && sh -c 'echo por-error >&2'";
        $this->assertSame(['command' => $command, 'exit' => 0, 'output' => "aqui.txt\npor-error"], $tests->run($command));

        $failed = $tests->run('echo falla; exit 3');
        $this->assertSame(3, $failed['exit']);
        $this->assertSame('falla', $failed['output']);

        // Sólo las últimas líneas: la suite entera no cabe en el plan.
        $this->assertSame(40, ProjectTests::TAIL);
        $long = $tests->run('seq 1 50; echo; echo');
        $this->assertSame(implode("\n", range(11, 50)), $long['output']);
        $this->assertSame(implode("\n", range(1, 40)), $tests->run('seq 1 40')['output']);
        $this->assertSame('', $tests->run('true')['output']);

        // No se queda esperando una entrada que nadie va a dar.
        $this->assertSame(0, $tests->run('cat')['exit']);
    }
}
