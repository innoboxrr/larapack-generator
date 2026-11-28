<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Completion;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * El criterio de terminado (`larapack:realign done`), llamando a Completion
 * sobre un proyecto pequeño: qué cuenta como que falta algo —un paso sin
 * hacer, una decisión sin tomar, lo hecho sin probar, una excepción que no
 * cubre lo que debería, validate y verify— y qué no: lo que el dueño aceptó
 * sin probar y lo que declaró fuera.
 *
 * Un `done` en verde es lo que un agente enseña como «terminado»: cada cosa
 * que aquí deja de contar es algo que se da por hecho sin estarlo.
 */
final class CompletionTest extends TestCase
{
    private const REQUEST = 'src/Http/Requests/Post/DeleteRequest.php';

    private const STEP = 'edited-outside:Requests@.';

    private const REPORT = 'src/Http/Controllers/Admin/ReportController.php';

    private const AUTHORIZE = "public function authorize(): bool\n    {";

    private const CONTRACT = ['models' => [['name' => 'Post', 'props' => [
        ['name' => 'title', 'type' => 'string', 'nullable' => false, 'fillable' => true, 'creatable' => true, 'updatable' => true],
    ]]]];

    private function bare(): FakeProject
    {
        return $this->useProject(FakeProject::library('Acme\\Blog\\'));
    }

    /**
     * Un paquete generado desde un contrato de un modelo: está terminado.
     */
    private function generated(): FakeProject
    {
        $project = $this->bare();
        $this->write('laraimport.json', (string) json_encode(self::CONTRACT, JSON_PRETTY_PRINT));

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--format' => 'json']), $this->lastOutput);

        return $project;
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * La autorización editada fuera de su hueco: verify no lo ve, el
     * diagnóstico sí, y devolverla cambia lo que responde la acción.
     */
    private function editAuthorize(): string
    {
        $generated = $this->project->read(self::REQUEST);
        $this->assertStringContainsString(self::AUTHORIZE, $generated);
        $this->write(self::REQUEST, str_replace(self::AUTHORIZE, self::AUTHORIZE."\n        if (\$this->user()?->isAdmin()) {\n            return true;\n        }\n", $generated));

        return $generated;
    }

    /**
     * Un controlador escrito a mano que delega: sólo pide decidir si se
     * declara en el contrato.
     */
    private function handwritten(): void
    {
        $this->write(self::REPORT, <<<'PHP'
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
            PHP);
        $this->write('src/Http/Requests/Admin/ReportRequest.php', <<<'PHP'
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
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function completion(array $paths = []): array
    {
        return (new Completion($this->project->path, 1))->run([], [], $paths);
    }

    /**
     * Cambia un paso del plan guardado, como lo dejan `decide` o
     * `accept-untested`.
     *
     * @param  array<string, mixed>  $changes
     */
    private function changeStep(string $id, array $changes): void
    {
        $state = new RealignState($this->project->path);
        $plan = (array) $state->plan();

        foreach ($plan['steps'] as $index => $step) {
            if ($step['id'] === $id) {
                $plan['steps'][$index] = $changes + $step;
                $state->savePlan($plan);

                return;
            }
        }

        $this->fail("No hay un paso {$id}. Hay: ".implode(', ', array_column($plan['steps'], 'id')));
    }

    private function request(string $target, ?string $step, string $reason = 'Se queda así.', string $scope = '.'): void
    {
        $parts = (array) ExceptionTargets::parse($target);

        Exceptions::forRoot($this->project->path)->saveRequests([[
            'target' => $target, 'type' => (string) $parts['type'], 'check' => null, 'scope' => $scope, 'file' => (string) $parts['file'], 'subject' => null,
            'line' => null, 'step' => $step, 'message' => null, 'reason' => $reason, 'fingerprint' => null, 'requested_at' => '2026-01-01T00:00:00+00:00',
        ]]);
    }

    /**
     * @param  array<string, string>  $targets  objetivo => razón
     */
    private function approve(array $targets): void
    {
        $approved = [];

        foreach ($targets as $target => $reason) {
            $approved[$target] = ['reason' => $reason, 'fingerprint' => ExceptionTargets::fingerprint($this->project->path, $target) ?? 'sha256:'.str_repeat('0', 64)];
        }

        HandRolledLock::write($this->project->path, $approved);
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

    public function test_un_paquete_generado_y_sin_tocar_esta_terminado(): void
    {
        $this->generated();

        $result = $this->completion();

        $this->assertTrue($result['done']);
        $this->assertSame(array_fill_keys(Completion::KEYS, []), $result['missing']);
        $this->assertSame(array_fill_keys(Completion::KEYS, 0), $result['counts']);
        $this->assertSame([], $result['reasons']);
        $this->assertSame([], $result['declared']);
        $this->assertSame([], $result['accepted_untested']);

        // Diagnostica y planifica otra vez, y lo deja escrito.
        $state = new RealignState($this->project->path);
        $this->assertSame($state->report()['generated_at'] ?? null, $result['diagnosed_at']);
        $this->assertIsString($result['diagnosed_at']);
        $this->assertSame($state->report()['id'] ?? null, $state->plan()['report_id'] ?? '');
    }

    public function test_un_paso_sin_hacer_falta_aunque_verify_estricto_pase(): void
    {
        $this->generated();
        $this->editAuthorize();

        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertSame([[
            'id' => self::STEP, 'title' => StepTypes::TYPES['edited-outside']['title'], 'category' => Category::GUIDED, 'status' => Planner::PENDING, 'scope' => '.',
            'check' => 'larapack:realign check '.self::STEP,
        ]], $result['missing']['steps']);
        $this->assertSame([], $result['missing']['verify']);
        $this->assertSame(['steps' => 1, 'decisions' => 0, 'untested' => 0, 'exceptions' => 0, 'validate' => 0, 'verify' => 0], $result['counts']);
        $this->assertSame(['1 pasos del plan sin hacer (larapack:realign next)'], $result['reasons']);

        // --package acota los pasos a los ámbitos que se nombran.
        $this->assertCount(1, $this->completion(['.'])['missing']['steps']);
        $other = $this->completion(['packages/otro']);
        $this->assertSame([], $other['missing']['steps']);
        $this->assertTrue($other['done']);
    }

    public function test_lo_hecho_sin_probar_falta_hasta_que_se_prueba_o_el_dueno_lo_acepta(): void
    {
        $this->generated();
        $generated = $this->editAuthorize();
        $this->completion();

        // Alguien lo arregla sin correr check ni las pruebas.
        $this->write(self::REQUEST, $generated);
        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertSame([[
            'id' => self::STEP, 'title' => StepTypes::TYPES['edited-outside']['title'], 'category' => Category::GUIDED, 'status' => Planner::UNTESTED, 'scope' => '.',
            'check' => 'larapack:realign check '.self::STEP.' --tested="<orden>"',
            'accept' => 'larapack:realign accept-untested '.self::STEP.' --note="por qué"',
        ]], $result['missing']['untested']);
        // Sin probar no es «sin hacer»: va aparte.
        $this->assertSame([], $result['missing']['steps']);
        $this->assertSame(1, $result['counts']['untested']);
        $this->assertSame([], $result['accepted_untested']);

        // El dueño lo acepta sin probar: ya no falta, y queda a la vista.
        $accepted = ['by' => 'la dueña', 'note' => 'No hay entorno de pruebas.', 'at' => '2026-01-02T00:00:00+00:00'];
        $this->changeStep(self::STEP, ['status' => Planner::DONE, 'untested_accepted' => $accepted]);
        $result = $this->completion();

        $this->assertTrue($result['done']);
        $this->assertSame([], $result['missing']['untested']);
        $this->assertSame([['id' => self::STEP, 'title' => StepTypes::TYPES['edited-outside']['title'], 'scope' => '.'] + $accepted], $result['accepted_untested']);

        // Probado de verdad no sale como aceptado.
        $this->changeStep(self::STEP, ['status' => Planner::DONE, 'untested_accepted' => null, 'tested' => ['command' => 'phpunit']]);
        $result = $this->completion();
        $this->assertTrue($result['done']);
        $this->assertSame([], $result['accepted_untested']);
    }

    public function test_una_decision_sin_tomar_va_aparte_y_tomada_falta_hacerla(): void
    {
        $this->generated();
        $this->handwritten();

        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertSame([[
            'id' => 'unmanaged-http@.', 'title' => StepTypes::TYPES['unmanaged-http']['title'], 'category' => Category::DECISION, 'status' => Planner::AWAITING_DECISION, 'scope' => '.',
            'decide' => 'larapack:realign decide unmanaged-http@. <opción> --note="por qué"',
        ]], $result['missing']['decisions']);
        $this->assertSame([], $result['missing']['steps']);
        $this->assertSame(['1 decisiones sin tomar: las toma el dueño (larapack:realign decide)'], $result['reasons']);

        // Decidida: ya no es del dueño decidir, pero falta cumplirla.
        $this->changeStep('unmanaged-http@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $result = $this->completion();

        $this->assertSame([], $result['missing']['decisions']);
        $this->assertSame([[
            'id' => 'unmanaged-http@.', 'title' => StepTypes::TYPES['unmanaged-http']['title'], 'category' => Category::DECISION, 'status' => Planner::DECIDED, 'scope' => '.',
            'check' => 'larapack:realign check unmanaged-http@.',
        ]], $result['missing']['steps']);

        // Pedida la excepción: cuenta además como excepción sin aprobar.
        $target = 'realign:unmanaged-http:'.self::REPORT;
        $this->request($target, 'unmanaged-http@.');
        $result = $this->completion();
        $this->assertSame([['target' => $target, 'step' => 'unmanaged-http@.', 'scope' => '.', 'status' => Exceptions::UNAPPROVED]], $result['missing']['exceptions']);
        $this->assertSame(1, $result['counts']['steps']);

        // Aprobada por el dueño: el diagnóstico ya no lo encuentra y está terminado.
        $this->approve([$target => 'Se queda así.']);
        $result = $this->completion();
        $this->assertTrue($result['done'], (string) json_encode($result['missing']));
    }

    public function test_la_solicitud_de_lo_que_se_decidio_de_otra_forma_ya_no_cuenta(): void
    {
        $this->generated();
        $this->handwritten();
        $this->completion();
        $target = 'realign:unmanaged-http:'.self::REPORT;
        $this->request($target, 'unmanaged-http@.');

        $this->changeStep('unmanaged-http@.', ['decisions' => [self::REPORT => ['option' => 'declare', 'note' => null, 'at' => 'antes']]]);
        $this->assertSame([], $this->completion()['missing']['exceptions']);

        $this->changeStep('unmanaged-http@.', ['decisions' => [self::REPORT => ['option' => 'keep', 'note' => null, 'at' => 'antes']]]);
        $this->assertSame([$target], array_column($this->completion()['missing']['exceptions'], 'target'));
    }

    public function test_una_excepcion_que_no_cubre_lo_que_deberia_cuenta_con_su_estado(): void
    {
        $this->generated();
        $pending = 'realign:missing:src/Policies/PostPolicy.php';
        $changed = 'realign:missing:src/Observers/PostObserver.php';
        $stale = 'realign:unmanaged-http:src/Http/Controllers/YaNoEsta.php';
        $fine = 'realign:no-contract:packages/otro/laraimport.json';

        $this->request($pending, 'missing@.');
        $this->request($changed, 'missing@.', 'La razón que se pide ahora.');
        $this->approve([$changed => 'La razón que se aprobó.', $stale => 'Era de otro tiempo.', $fine => 'Fuera.']);

        $result = $this->completion();

        $this->assertFalse($result['done']);
        // Las pedidas, con su paso; la aprobada sin solicitud que ya no cubre
        // nada, sin él. La que sigue cubriendo lo suyo no sale.
        $this->assertSame([
            ['target' => $changed, 'step' => 'missing@.', 'scope' => '.', 'status' => Exceptions::REASON_CHANGED],
            ['target' => $pending, 'step' => 'missing@.', 'scope' => '.', 'status' => Exceptions::UNAPPROVED],
            ['target' => $stale, 'step' => null, 'scope' => null, 'status' => Exceptions::STALE],
        ], $result['missing']['exceptions']);
        $this->assertSame(3, $result['counts']['exceptions']);
        $this->assertSame(['3 excepciones sin aprobar o que ya no cubren lo aprobado: las aprueba el dueño (larapack:hand-rolled approve)'], $result['reasons']);

        // Con --package, sólo las solicitudes de ese ámbito; de las
        // aprobadas sin solicitud no se sabe de qué ámbito son.
        $this->assertSame([$changed, $pending], array_column($this->completion(['.'])['missing']['exceptions'], 'target'));
        $this->assertSame([], $this->completion(['packages/otro'])['missing']['exceptions']);
    }

    public function test_validate_estricto_cuenta_los_avisos_como_los_errores(): void
    {
        $this->bare();
        copy(dirname(__DIR__).'/Fixtures/laraimport.json', $this->project->path.'/laraimport.json');

        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertNotSame([], $result['missing']['validate']);
        $this->assertSame([SemanticValidator::WARNING], array_values(array_unique(array_column($result['missing']['validate'], 'level'))));
        $this->assertSame(count($result['missing']['validate']), $result['counts']['validate']);
        $this->assertContains($result['counts']['validate'].' errores o avisos de larapack:validate --strict', $result['reasons']);
    }

    public function test_verify_estricto_cuenta_con_el_ambito_de_cada_hallazgo(): void
    {
        $this->generated();
        $this->write('routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', function () {\n    return response()->json(['ok' => true]);\n});\n");

        $result = $this->completion();

        $this->assertFalse($result['done']);
        $closures = array_values(array_filter($result['missing']['verify'], fn (array $finding): bool => $finding['check'] === 'route-closure'));
        $this->assertCount(1, $closures);
        $this->assertSame('routes/web.php', $closures[0]['file']);
        $this->assertSame('warning', $closures[0]['level']);
        $this->assertSame('.', $closures[0]['scope']);
        $this->assertSame(count($result['missing']['verify']), $result['counts']['verify']);
    }

    public function test_un_ambito_declarado_fuera_no_tiene_contrato_que_validar_ni_manifiesto_que_verificar(): void
    {
        $this->bare();

        // Sin contrato y sin decidir: falta la decisión, y validate y verify lo dicen.
        $result = $this->completion();
        $this->assertFalse($result['done']);
        $this->assertSame(['no-contract@.'], array_column($result['missing']['decisions'], 'id'));
        $this->assertSame(['steps' => 0, 'decisions' => 1, 'untested' => 0, 'exceptions' => 0, 'validate' => 1, 'verify' => 1], $result['counts']);
        $this->assertSame('empty-manifest', $result['missing']['verify'][0]['check']);
        $this->assertSame([], $result['declared']);

        // El dueño decide dejarlo fuera y aprueba la excepción.
        $target = 'realign:no-contract:laraimport.json';
        $this->changeStep('no-contract@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $this->request($target, 'no-contract@.', 'Es una biblioteca de soporte.');
        $this->assertFalse($this->completion()['done']);
        $this->approve([$target => 'Es una biblioteca de soporte.']);

        $result = $this->completion();

        $this->assertTrue($result['done'], (string) json_encode($result['missing']));
        $this->assertSame(array_fill_keys(Completion::KEYS, 0), $result['counts']);
        // Fuera por decisión, no por olvido: se ve.
        $this->assertSame([
            ['source' => 'validate', 'scope' => '.', 'step' => 'no-contract@.', 'message' => 'No se encontró el archivo '.$this->project->path.'/laraimport.json.'],
            ['source' => 'verify', 'scope' => '.', 'step' => 'no-contract@.', 'message' => $result['declared'][1]['message']],
        ], $result['declared']);
        $this->assertStringContainsString('No hay nada registrado', $result['declared'][1]['message']);
    }

    public function test_un_contrato_que_aparece_en_lo_declarado_fuera_si_se_valida(): void
    {
        $this->bare();
        $this->completion();
        $target = 'realign:no-contract:laraimport.json';
        $this->changeStep('no-contract@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $this->request($target, 'no-contract@.', 'Fuera.');
        $this->approve([$target => 'Fuera.']);
        $this->assertTrue($this->completion()['done']);

        // Alguien escribe un laraimport que no vale: ya no es «no tiene contrato».
        $this->write('laraimport.json', '{"models": "no es una lista"}');
        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertNotSame([], $result['missing']['validate']);
        $this->assertSame([], array_values(array_filter($result['declared'], fn (array $row): bool => $row['source'] === 'validate')));
    }
}
