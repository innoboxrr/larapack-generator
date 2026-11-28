<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Completion;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Innoboxrr\LarapackGenerator\Tests\Support\SmallRealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;

/**
 * El criterio de terminado (`larapack:realign done`), llamando a Completion
 * sobre un proyecto pequeño: qué cuenta como que falta algo —un paso sin
 * hacer, una decisión sin tomar, lo hecho sin probar, una excepción que no
 * cubre lo que debería, validate y verify— y qué no: lo que el dueño aceptó
 * sin probar y lo que declaró fuera.
 *
 * Un `done` en verde es lo que un agente enseña como «terminado»: cada cosa
 * que aquí deja de contar es algo que se da por hecho sin estarlo.
 *
 * El proyecto no tiene contrato, y el dueño ya lo declaró fuera
 * (declaredOut()): así está terminado, y cada prueba le añade una sola cosa
 * que falta. Lo que necesita un contrato —lo editado fuera de los huecos, un
 * laraimport con avisos— está en CompletionWithContractTest.
 */
final class CompletionTest extends TestCase
{
    use SmallRealignProject;

    private const REPORT = 'src/Http/Controllers/Admin/ReportController.php';

    private const REQUEST = 'src/Http/Requests/Admin/ReportRequest.php';

    private const CONTROLLER_STEP = 'controller-shape@'.self::REPORT;

    private const OUT = 'realign:no-contract:laraimport.json';

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $roots
     * @return array<string, mixed>
     */
    private function completion(array $paths = [], array $roots = []): array
    {
        return (new Completion($this->project->path, 1))->run([], $roots, $paths);
    }

    /**
     * Un paquete sin contrato que el dueño decidió dejar fuera de LaraPack,
     * con su excepción aprobada: terminado.
     */
    private function declaredOut(): void
    {
        $this->bare();
        $this->completion();
        $this->changeStep('no-contract@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $this->request(self::OUT, 'no-contract@.', 'Es una biblioteca de soporte.');
        $this->approve([self::OUT => 'Es una biblioteca de soporte.']);
    }

    /**
     * Un controlador escrito a mano: con lógica dentro, o delegando en su
     * request.
     */
    private function controller(bool $delegates): void
    {
        $this->write(self::REPORT, $delegates
            ? <<<'PHP'
                <?php

                namespace Acme\Blog\Http\Controllers\Admin;

                use Acme\Blog\Http\Requests\Admin\ReportRequest;

                class ReportController
                {
                    public function index(ReportRequest $request)
                    {
                        return $request->handle();
                    }
                }
                PHP
            : <<<'PHP'
                <?php

                namespace Acme\Blog\Http\Controllers\Admin;

                use Illuminate\Http\Request;

                class ReportController
                {
                    public function index(Request $request)
                    {
                        $request->validate(['from' => 'required|date']);

                        return response()->json(['from' => $request->input('from')]);
                    }
                }
                PHP);
        $this->write(self::REQUEST, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Requests\Admin;

            use Illuminate\Foundation\Http\FormRequest;

            class ReportRequest extends FormRequest
            {
                public function authorize(): bool
                {
                    return true;
                }

                public function handle()
                {
                    return response()->json([]);
                }
            }
            PHP);
    }

    /**
     * Los controladores a mano se quedan como código propio, con la
     * excepción aprobada sobre el código de hoy.
     */
    private function keepHandwritten(): void
    {
        $target = 'realign:unmanaged-http:'.self::REPORT;
        $this->changeStep('unmanaged-http@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $this->request($target, 'unmanaged-http@.');
        $this->approve([self::OUT => 'Es una biblioteca de soporte.', $target => 'Se queda así.']);
    }

    public function test_una_linea_por_cada_cosa_que_falta_y_ninguna_por_lo_que_no(): void
    {
        $this->assertSame(['steps', 'decisions', 'untested', 'exceptions', 'validate', 'verify'], Completion::KEYS);
        $this->assertSame([], Completion::reasons([]));
        $this->assertSame([], Completion::reasons(array_fill_keys(Completion::KEYS, 0)));

        $this->assertSame([
            '1 pasos del plan sin hacer (larapack:realign next)',
            '2 decisiones sin tomar: las toma el dueño (larapack:realign decide)',
            '3 pasos hechos sin probar: cambian comportamiento y nadie registró su prueba (larapack:realign check <id> --tested=…, o los acepta el dueño con accept-untested)',
            '4 excepciones sin aprobar o que ya no cubren lo aprobado: las aprueba el dueño (larapack:hand-rolled approve)',
            '5 errores o avisos de larapack:validate --strict',
            '6 errores o avisos de larapack:verify --strict',
        ], Completion::reasons(['verify' => 6, 'validate' => 5, 'exceptions' => 4, 'untested' => 3, 'decisions' => 2, 'steps' => 1]));

        $this->assertSame(['1 errores o avisos de larapack:verify --strict'], Completion::reasons(['steps' => 0, 'verify' => 1, 'otra' => 9]));
    }

    public function test_un_ambito_declarado_fuera_no_tiene_contrato_que_validar_ni_manifiesto_que_verificar(): void
    {
        $this->bare();

        // Sin contrato y sin decidir: falta la decisión, y validate y verify lo dicen.
        $result = $this->completion();
        $this->assertFalse($result['done']);
        $this->assertSame([[
            'id' => 'no-contract@.', 'title' => StepTypes::TYPES['no-contract']['title'], 'category' => Category::DECISION, 'status' => Planner::AWAITING_DECISION, 'scope' => '.',
            'decide' => 'larapack:realign decide no-contract@. <opción> --note="por qué"',
        ]], $result['missing']['decisions']);
        $this->assertSame(['steps' => 0, 'decisions' => 1, 'untested' => 0, 'exceptions' => 0, 'validate' => 1, 'verify' => 1], $result['counts']);
        $this->assertSame([
            '1 decisiones sin tomar: las toma el dueño (larapack:realign decide)',
            '1 errores o avisos de larapack:validate --strict',
            '1 errores o avisos de larapack:verify --strict',
        ], $result['reasons']);
        $this->assertSame('error', $result['missing']['validate'][0]['level']);
        $this->assertSame('empty-manifest', $result['missing']['verify'][0]['check']);
        $this->assertSame('warning', $result['missing']['verify'][0]['level']);
        $this->assertSame('.', $result['missing']['verify'][0]['scope']);
        $this->assertSame([], $result['declared']);
        $this->assertSame([], $result['accepted_untested']);

        // Diagnostica y planifica otra vez, y lo deja escrito.
        $state = new RealignState($this->project->path);
        $this->assertIsString($result['diagnosed_at']);
        $this->assertSame($state->report()['generated_at'] ?? null, $result['diagnosed_at']);
        $this->assertSame($state->report()['id'] ?? null, $state->plan()['report_id'] ?? '');

        // Decidida: ya no es del dueño decidir, pero falta cumplirla.
        $this->changeStep('no-contract@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $result = $this->completion();
        $this->assertSame([], $result['missing']['decisions']);
        $this->assertSame([[
            'id' => 'no-contract@.', 'title' => StepTypes::TYPES['no-contract']['title'], 'category' => Category::DECISION, 'status' => Planner::DECIDED, 'scope' => '.',
            'check' => 'larapack:realign check no-contract@.',
        ]], $result['missing']['steps']);

        // Pedida la excepción: cuenta además como excepción sin aprobar.
        $this->request(self::OUT, 'no-contract@.', 'Es una biblioteca de soporte.');
        $result = $this->completion();
        $this->assertFalse($result['done']);
        $this->assertSame([['target' => self::OUT, 'step' => 'no-contract@.', 'scope' => '.', 'status' => Exceptions::UNAPPROVED]], $result['missing']['exceptions']);
        $this->assertSame(['steps' => 1, 'decisions' => 0, 'untested' => 0, 'exceptions' => 1, 'validate' => 1, 'verify' => 1], $result['counts']);

        // El dueño la aprueba: el ámbito queda fuera, y terminado.
        $this->approve([self::OUT => 'Es una biblioteca de soporte.']);
        $result = $this->completion();

        $this->assertTrue($result['done'], (string) json_encode($result['missing']));
        $this->assertSame(array_fill_keys(Completion::KEYS, []), $result['missing']);
        $this->assertSame(array_fill_keys(Completion::KEYS, 0), $result['counts']);
        $this->assertSame([], $result['reasons']);
        // Fuera por decisión, no por olvido: se ve.
        $this->assertSame([
            ['source' => 'validate', 'scope' => '.', 'step' => 'no-contract@.', 'message' => 'No se encontró el archivo '.$this->project->path.'/laraimport.json.'],
            ['source' => 'verify', 'scope' => '.', 'step' => 'no-contract@.', 'message' => $result['declared'][1]['message']],
        ], $result['declared']);
        $this->assertStringContainsString('No hay nada registrado', $result['declared'][1]['message']);
    }

    public function test_un_paso_sin_hacer_y_una_decision_sin_tomar_faltan_cada_uno_en_su_lista(): void
    {
        $this->declaredOut();
        $this->controller(false);

        $result = $this->completion();

        $this->assertFalse($result['done']);
        // El controlador a mano: decidir si se declara, y mientras, llevarlo
        // a su request espera a esa decisión.
        $this->assertSame([[
            'id' => 'unmanaged-http@.', 'title' => StepTypes::TYPES['unmanaged-http']['title'], 'category' => Category::DECISION, 'status' => Planner::AWAITING_DECISION, 'scope' => '.',
            'decide' => 'larapack:realign decide unmanaged-http@. <opción> --note="por qué"',
        ]], $result['missing']['decisions']);
        $this->assertSame([[
            'id' => self::CONTROLLER_STEP, 'title' => StepTypes::TYPES['controller-shape']['title'], 'category' => Category::GUIDED, 'status' => Planner::BLOCKED, 'scope' => '.',
            'check' => 'larapack:realign check '.self::CONTROLLER_STEP,
        ]], $result['missing']['steps']);
        $this->assertSame([], $result['missing']['untested']);
        // Y verify estricto lo dice por su lado, con el ámbito del hallazgo.
        $this->assertSame(['controller-shape'], array_column($result['missing']['verify'], 'check'));
        $this->assertSame(self::REPORT, $result['missing']['verify'][0]['file']);
        $this->assertSame('.', $result['missing']['verify'][0]['scope']);
        $this->assertSame(['steps' => 1, 'decisions' => 1, 'untested' => 0, 'exceptions' => 0, 'validate' => 0, 'verify' => 1], $result['counts']);
        // Lo declarado fuera sigue fuera.
        $this->assertSame(['validate', 'verify'], array_column($result['declared'], 'source'));

        // --package acota los pasos a los ámbitos que se nombran.
        $this->assertSame(['steps' => 1, 'decisions' => 1], array_intersect_key($this->completion(['.'])['counts'], ['steps' => 0, 'decisions' => 0]));
        $other = $this->completion(['packages/otro']);
        $this->assertSame([], $other['missing']['steps']);
        $this->assertSame([], $other['missing']['decisions']);
    }

    public function test_lo_hecho_sin_probar_falta_hasta_que_se_prueba_o_el_dueno_lo_acepta(): void
    {
        $this->declaredOut();
        $this->controller(false);
        $this->completion();
        $this->keepHandwritten();

        // Alguien lo lleva a su request sin correr check ni las pruebas.
        $this->controller(true);
        $this->keepHandwritten();
        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertSame([[
            'id' => self::CONTROLLER_STEP, 'title' => StepTypes::TYPES['controller-shape']['title'], 'category' => Category::GUIDED, 'status' => Planner::UNTESTED, 'scope' => '.',
            'check' => 'larapack:realign check '.self::CONTROLLER_STEP.' --tested="<orden>"',
            'accept' => 'larapack:realign accept-untested '.self::CONTROLLER_STEP.' --note="por qué"',
        ]], $result['missing']['untested']);
        // Sin probar no es «sin hacer»: va aparte, y es lo único que falta.
        $this->assertSame(['steps' => 0, 'decisions' => 0, 'untested' => 1, 'exceptions' => 0, 'validate' => 0, 'verify' => 0], $result['counts']);
        $this->assertSame([], $result['accepted_untested']);
        $this->assertSame(
            ['1 pasos hechos sin probar: cambian comportamiento y nadie registró su prueba (larapack:realign check <id> --tested=…, o los acepta el dueño con accept-untested)'],
            $result['reasons']
        );

        // El dueño lo acepta sin probar: ya no falta, y queda a la vista.
        $accepted = ['by' => 'la dueña', 'note' => 'No hay entorno de pruebas.', 'at' => '2026-01-02T00:00:00+00:00'];
        $this->changeStep(self::CONTROLLER_STEP, ['status' => Planner::DONE, 'untested_accepted' => $accepted]);
        $result = $this->completion();

        $this->assertTrue($result['done'], (string) json_encode($result['missing']));
        $this->assertSame([], $result['missing']['untested']);
        $this->assertSame([['id' => self::CONTROLLER_STEP, 'title' => StepTypes::TYPES['controller-shape']['title'], 'scope' => '.'] + $accepted], $result['accepted_untested']);
        // Con --package de otro ámbito no es de lo que se pregunta.
        $this->assertSame([], $this->completion(['packages/otro'])['accepted_untested']);

        // Probado de verdad no sale como aceptado.
        $this->changeStep(self::CONTROLLER_STEP, ['status' => Planner::DONE, 'untested_accepted' => null, 'tested' => ['command' => 'phpunit']]);
        $result = $this->completion();
        $this->assertTrue($result['done']);
        $this->assertSame([], $result['accepted_untested']);

        // Y sin probar y sin aceptar, por mucho que alguien apunte la aceptación a medias.
        $this->changeStep(self::CONTROLLER_STEP, ['status' => Planner::UNTESTED, 'untested_accepted' => $accepted]);
        $result = $this->completion();
        $this->assertFalse($result['done']);
        $this->assertSame([], $result['accepted_untested']);
        $this->assertSame([self::CONTROLLER_STEP], array_column($result['missing']['untested'], 'id'));
    }

    public function test_la_solicitud_de_lo_que_se_decidio_de_otra_forma_ya_no_cuenta(): void
    {
        $this->declaredOut();
        $this->controller(true);
        $this->completion();
        $target = 'realign:unmanaged-http:'.self::REPORT;
        $this->request($target, 'unmanaged-http@.');

        $this->changeStep('unmanaged-http@.', ['decisions' => [self::REPORT => ['option' => 'declare', 'note' => null, 'at' => 'antes']]]);
        $this->assertSame([], $this->completion()['missing']['exceptions']);

        $this->changeStep('unmanaged-http@.', ['decisions' => [self::REPORT => ['option' => 'keep', 'note' => null, 'at' => 'antes']]]);
        $result = $this->completion();
        $this->assertSame([['target' => $target, 'step' => 'unmanaged-http@.', 'scope' => '.', 'status' => Exceptions::UNAPPROVED]], $result['missing']['exceptions']);
        // Decidida y sin cumplir: falta el paso, no la decisión.
        $this->assertSame([Planner::DECIDED], array_column($result['missing']['steps'], 'status'));

        // Aprobada por el dueño: el diagnóstico ya no lo encuentra y está terminado.
        $this->keepHandwritten();
        $result = $this->completion();
        $this->assertTrue($result['done'], (string) json_encode($result['missing']));
    }

    public function test_una_excepcion_que_no_cubre_lo_que_deberia_cuenta_con_su_estado(): void
    {
        $this->declaredOut();
        $this->write('src/Models/Legacy.php', "<?php\n\nnamespace Acme\\Blog\\Models;\n\nclass Legacy {}\n");
        $pending = 'realign:missing:src/Policies/PostPolicy.php';
        $changed = 'realign:missing:src/Observers/PostObserver.php';
        $elsewhere = 'realign:missing:packages/otro/src/A.php';
        $stale = 'realign:unmanaged-http:src/Http/Controllers/YaNoEsta.php';
        $fine = 'realign:unmanaged-models:src/Models/Legacy.php';

        $this->request($pending, 'missing@.');
        $this->request($changed, 'missing@.', 'La razón que se pide ahora.');
        $this->request($elsewhere, 'missing@packages/otro', 'Se queda así.', 'packages/otro');
        $this->approve([self::OUT => 'Es una biblioteca de soporte.', $changed => 'La razón que se aprobó.', $stale => 'Era de otro tiempo.', $fine => 'Fuera.']);

        $result = $this->completion();

        $this->assertFalse($result['done']);
        // Las pedidas, con su paso; la aprobada sin solicitud que ya no cubre
        // nada, sin él. Las que siguen cubriendo lo suyo no salen.
        $this->assertSame([
            ['target' => $elsewhere, 'step' => 'missing@packages/otro', 'scope' => 'packages/otro', 'status' => Exceptions::UNAPPROVED],
            ['target' => $changed, 'step' => 'missing@.', 'scope' => '.', 'status' => Exceptions::REASON_CHANGED],
            ['target' => $pending, 'step' => 'missing@.', 'scope' => '.', 'status' => Exceptions::UNAPPROVED],
            ['target' => $stale, 'step' => null, 'scope' => null, 'status' => Exceptions::STALE],
        ], $result['missing']['exceptions']);
        $this->assertSame(['steps' => 0, 'decisions' => 0, 'untested' => 0, 'exceptions' => 4, 'validate' => 0, 'verify' => 0], $result['counts']);
        $this->assertSame(['4 excepciones sin aprobar o que ya no cubren lo aprobado: las aprueba el dueño (larapack:hand-rolled approve)'], $result['reasons']);

        // Con --package, sólo las solicitudes de ese ámbito; de las
        // aprobadas sin solicitud no se sabe de qué ámbito son.
        $this->assertSame([$changed, $pending], array_column($this->completion(['.'])['missing']['exceptions'], 'target'));
        $this->assertSame([$elsewhere], array_column($this->completion(['packages/otro'])['missing']['exceptions'], 'target'));
        $this->assertSame([], $this->completion(['packages/nadie'])['missing']['exceptions']);
    }

    public function test_cada_paquete_es_su_ambito_y_se_puede_preguntar_por_uno_solo(): void
    {
        $this->declaredOut();
        $this->write('packages/pay/composer.json', (string) json_encode(['name' => 'acme/pay', 'autoload' => ['psr-4' => ['Acme\\Pay\\' => 'src/']]]));
        $this->write('packages/pay/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', function () {\n    return response()->json(['ok' => true]);\n});\n");
        $this->write('routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/ping', function () {\n    return response()->json(['ok' => true]);\n});\n");

        $result = $this->completion();

        // Cada hallazgo de verify, con la ruta de su ámbito.
        $closures = array_values(array_filter($result['missing']['verify'], fn (array $finding): bool => $finding['check'] === 'route-closure'));
        $this->assertEquals(
            ['.' => 'routes/web.php', 'packages/pay' => 'packages/pay/routes/web.php'],
            array_column($closures, 'file', 'scope')
        );
        $this->assertContains('no-contract@packages/pay', array_column($result['missing']['decisions'], 'id'));
        $this->assertContains('packages/pay', array_column($result['missing']['decisions'], 'scope'));

        // Sólo el paquete: sus pasos, su validate y su verify.
        $only = $this->completion(['packages/pay'], [$this->project->path.'/packages/pay']);
        $this->assertSame(['packages/pay'], array_values(array_unique(array_column([...$only['missing']['decisions'], ...$only['missing']['steps']], 'scope'))));
        $this->assertSame(['packages/pay'], array_values(array_unique(array_column($only['missing']['verify'], 'scope'))));
        $this->assertNotContains('routes/web.php', array_column($only['missing']['verify'], 'file'));
        $this->assertSame([], $only['declared']);
    }
}
