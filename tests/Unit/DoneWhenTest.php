<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\ImportDocument;
use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Innoboxrr\LarapackGenerator\Tests\Support\SmallRealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;

/**
 * `DoneWhen::check()`: lo que decide que un paso está hecho. Cada tipo de
 * comprobación (`kind`) con lo que da por bueno y, sobre todo, con lo que no:
 * una decisión sin tomar, una excepción pedida y sin aprobar, un archivo que
 * se parece pero el manifiesto no registra, un `kind` que no se conoce.
 *
 * Se llama a la clase sobre un directorio con lo justo, sin pasar por
 * `larapack:realign check`. Lo que compara con lo regenerado (`kind:
 * classification` con un contrato) está en DoneWhenClassificationTest, que
 * necesita un paquete generado.
 */
final class DoneWhenTest extends TestCase
{
    use SmallRealignProject;

    private const REPORT = 'src/Http/Controllers/Admin/ReportController.php';

    private const AUDIT = 'src/Http/Controllers/Admin/AuditController.php';

    private const AUDIT_REQUEST = 'src/Http/Requests/Admin/AuditRequest.php';

    /**
     * Código escrito a mano: un controlador con lógica, otro que delega en
     * una request sin handle() y una ruta con closure.
     */
    private function handwritten(): void
    {
        $this->write(self::REPORT, <<<'PHP'
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
        $this->write(self::AUDIT, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Controllers\Admin;

            use Acme\Blog\Http\Requests\Admin\AuditRequest;

            class AuditController
            {
                public function index(AuditRequest $request)
                {
                    return $request->handle();
                }
            }
            PHP);
        $this->write(self::AUDIT_REQUEST, <<<'PHP'
            <?php

            namespace Acme\Blog\Http\Requests\Admin;

            use Illuminate\Foundation\Http\FormRequest;

            class AuditRequest extends FormRequest
            {
                public function authorize(): bool
                {
                    return true;
                }
            }
            PHP);
        $this->write('routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', function () {\n    return response()->json(['ok' => true]);\n});\n");
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{done: bool, remaining: list<string>, kind: string}
     */
    private function check(array $step): array
    {
        return (new DoneWhen($this->project->path))->check($step);
    }

    /**
     * Una decisión con las opciones de su tipo, que se da por hecha con que
     * esté decidida (`kind: decision`): aísla lo que piden las excepciones.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function decision(string $type, array $files, array $extra = []): array
    {
        return $extra + ['id' => $type.'@.', 'type' => $type, 'category' => Category::DECISION, 'files' => $files,
            'options' => StepTypes::TYPES[$type]['options'], 'done_when' => ['kind' => 'decision']];
    }

    public function test_lo_que_no_se_sabe_comprobar_no_se_da_por_hecho(): void
    {
        $this->bare();

        $this->assertSame(['verify', 'classification', 'unmanaged', 'contract', 'foreign-keys', 'decision'], DoneWhen::KINDS);
        $this->assertSame(
            ['done' => false, 'remaining' => ['No se sabe comprobar `telepatia`: actualiza innoboxrr/larapack-generator.'], 'kind' => 'telepatia'],
            $this->check(['id' => 'x@.', 'done_when' => ['kind' => 'telepatia']])
        );
        // Sin done_when, o con uno que no es una comprobación, tampoco.
        $this->assertSame(['done' => false, 'remaining' => ['No se sabe comprobar ``: actualiza innoboxrr/larapack-generator.'], 'kind' => ''], $this->check(['id' => 'x@.']));
        $this->assertFalse($this->check(['id' => 'x@.', 'done_when' => 'decision'])['done']);
    }

    public function test_una_decision_no_se_da_por_hecha_hasta_que_se_decide(): void
    {
        $this->bare();
        $step = $this->decision('missing', ['a.php', 'b.php']);

        $this->assertSame(
            ['done' => false, 'remaining' => ['Falta decidir: larapack:realign decide missing@. <opción>.'], 'kind' => 'decision'],
            $this->check($step)
        );
        // Decidida entera con una opción que no deja nada como está: hecha.
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'decision'], $this->check($step + ['decision' => ['option' => 'restore']]));
        $this->assertTrue($this->check($step + ['decisions' => ['a.php' => ['option' => 'restore'], 'b.php' => ['option' => 'restore']]])['done']);

        // Por elemento y a medias: cuáles faltan, y cómo se decide cada uno.
        $this->assertSame(
            ['b.php: falta decidir: larapack:realign decide missing@. <opción> --target=b.php.'],
            $this->check($step + ['decisions' => ['a.php' => ['option' => 'restore']]])['remaining']
        );
        $this->assertSame(
            [
                'a.php: falta decidir: larapack:realign decide missing@. <opción> --target=a.php.',
                'b.php: falta decidir: larapack:realign decide missing@. <opción> --target=b.php.',
            ],
            $this->check($step + ['decisions' => ['c.php' => ['option' => 'restore']]])['remaining']
        );

        // Sin elementos sólo se decide entera, aunque alguien apunte uno.
        $scope = $this->decision('no-contract', []);
        $this->assertSame(['Falta decidir: larapack:realign decide no-contract@. <opción>.'], $this->check($scope + ['decisions' => ['x' => ['option' => 'infer']]])['remaining']);
        $this->assertSame(['Falta decidir: larapack:realign decide <id> <opción>.'], $this->check(['id' => null] + $scope)['remaining']);
        $this->assertTrue($this->check($scope + ['decision' => ['option' => 'infer']])['done']);

        // Lo que no es una decisión no tiene nada que decidir.
        $this->assertTrue($this->check(['category' => Category::GUIDED] + $step)['done']);
    }

    public function test_lo_que_falta_decidir_va_antes_que_lo_demas_que_falta(): void
    {
        $this->bare();
        $step = ['done_when' => ['kind' => 'telepatia']] + $this->decision('missing', ['a.php', 'b.php']);

        $this->assertSame([
            'Falta decidir: larapack:realign decide missing@. <opción>.',
            'No se sabe comprobar `telepatia`: actualiza innoboxrr/larapack-generator.',
        ], $this->check($step)['remaining']);
        $this->assertSame([
            'b.php: falta decidir: larapack:realign decide missing@. <opción> --target=b.php.',
            'No se sabe comprobar `telepatia`: actualiza innoboxrr/larapack-generator.',
        ], $this->check($step + ['decisions' => ['a.php' => ['option' => 'restore']]])['remaining']);
    }

    public function test_decidir_dejarlo_como_esta_no_lo_cierra_hasta_que_el_dueno_aprueba(): void
    {
        $this->bare();
        $this->handwritten();
        $step = $this->decision('unmanaged-http', [self::REPORT], ['decision' => ['option' => 'keep']]);
        $target = 'realign:unmanaged-http:'.self::REPORT;

        // Decidir no es pedir.
        $this->assertSame(
            ['Se decidió dejarlo como está y falta pedir la excepción: larapack:realign except unmanaged-http@. --option reason="por qué".'],
            $this->check($step)['remaining']
        );

        // Pedir no es aprobar: eso lo hace el dueño.
        $this->request($target, 'unmanaged-http@.');
        $this->assertSame(
            [$target.': excepción solicitada y sin aprobar. La aprueba el dueño del proyecto (larapack:hand-rolled approve), no un agente.'],
            $this->check($step)['remaining']
        );

        // La solicitud de otro paso no es la de éste.
        $this->assertSame(
            ['Se decidió dejarlo como está y falta pedir la excepción: larapack:realign except otro@. --option reason="por qué".'],
            $this->check(['id' => 'otro@.'] + $step)['remaining']
        );

        $this->approve([$target => 'Se queda así.']);
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'decision'], $this->check($step));

        // Aprobada con otra razón que la que se pide ahora.
        $this->approve([$target => 'Otra razón.']);
        $this->assertSame([$target.': se aprobó con otra razón que la que se pide ahora; el dueño la vuelve a aprobar.'], $this->check($step)['remaining']);

        // Aprobada sobre otro código: la huella caduca.
        $this->approve([$target => 'Se queda así.']);
        $this->write(self::REPORT, str_replace('required|date', 'required', $this->project->read(self::REPORT)));
        $this->assertSame([$target.': se aprobó sobre otro código; el dueño la vuelve a aprobar si sigue valiendo.'], $this->check($step)['remaining']);

        // Aprobada sobre algo que ya no está.
        unlink($this->project->path.'/'.self::REPORT);
        $this->assertSame([$target.': lo aprobado ya no existe; el dueño la retira (larapack:hand-rolled revoke).'], $this->check($step)['remaining']);
    }

    public function test_la_solicitud_de_un_elemento_que_se_decidio_de_otra_forma_ya_no_cuenta(): void
    {
        $this->bare();
        $this->handwritten();
        $kept = 'realign:unmanaged-http:'.self::REPORT;
        $declared = 'realign:unmanaged-http:'.self::AUDIT;
        $step = $this->decision('unmanaged-http', [self::REPORT, self::AUDIT], [
            'decisions' => [self::REPORT => ['option' => 'keep'], self::AUDIT => ['option' => 'declare']],
        ]);

        $this->request($kept, 'unmanaged-http@.');
        $this->request($declared, 'unmanaged-http@.');
        $this->approve([$kept => 'Se queda así.']);

        // AuditController se pidió antes de decidir declararlo: su solicitud
        // sin aprobar no detiene el paso.
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'decision'], $this->check($step));

        // Si se vuelve a decidir dejarlo, vuelve a contar.
        $step['decisions'][self::AUDIT] = ['option' => 'keep'];
        $this->assertSame([$declared.': excepción solicitada y sin aprobar. La aprueba el dueño del proyecto (larapack:hand-rolled approve), no un agente.'], $this->check($step)['remaining']);

        // Decidido por elemento sin dejar ninguno como está: nada que pedir.
        $step['decisions'] = [self::REPORT => ['option' => 'declare'], self::AUDIT => ['option' => 'declare']];
        $this->assertTrue($this->check($step)['done']);
    }

    public function test_un_paso_que_no_pasa_nombra_las_excepciones_que_esperan_al_dueno(): void
    {
        $this->bare();
        $this->handwritten();
        $target = 'realign:security:'.self::REPORT;
        $this->request($target, 'security@.');
        $pending = $target.': excepción solicitada y sin aprobar. La aprueba el dueño del proyecto (larapack:hand-rolled approve), no un agente.';

        // No pasa: además de lo suyo, a quién le toca.
        $this->assertSame(
            ['No se sabe comprobar `telepatia`: actualiza innoboxrr/larapack-generator.', $pending],
            $this->check(['id' => 'security@.', 'type' => 'security', 'category' => Category::GUIDED, 'done_when' => ['kind' => 'telepatia']])['remaining']
        );
        // Pasa: una solicitud sin aprobar de algo que ya se arregló no lo reabre.
        $this->assertTrue($this->check(['id' => 'security@.', 'type' => 'security', 'category' => Category::GUIDED, 'done_when' => ['kind' => 'decision']])['done']);
        // No pasa y no hay ninguna pedida: sólo lo suyo.
        $this->assertSame(
            ['No se sabe comprobar `telepatia`: actualiza innoboxrr/larapack-generator.'],
            $this->check(['id' => 'otro@.', 'type' => 'security', 'category' => Category::GUIDED, 'done_when' => ['kind' => 'telepatia']])['remaining']
        );
    }

    public function test_verify_acotado_a_las_comprobaciones_y_los_archivos_del_paso(): void
    {
        $this->bare();
        $this->handwritten();
        $condition = fn (array $files, array $checks = ['controller-shape', 'request-handle']): array => ['kind' => 'verify', 'checks' => $checks, 'package' => null, 'files' => $files];
        $step = fn (array $files, string $controller): array => ['id' => 'controller-shape@'.$controller, 'type' => 'controller-shape', 'category' => Category::GUIDED, 'done_when' => $condition($files)];

        $report = $this->check($step([self::REPORT], self::REPORT));
        $this->assertFalse($report['done']);
        $this->assertSame('verify', $report['kind']);
        $this->assertCount(1, $report['remaining']);
        $this->assertStringStartsWith('controller-shape: '.self::REPORT.': ReportController::index() (línea 9) no delega', $report['remaining'][0]);

        // El hallazgo de request-handle está en la request, que el plan no
        // conoce: es de este paso porque su mensaje nombra el controlador.
        $audit = $this->check($step([self::AUDIT], self::AUDIT));
        $this->assertCount(1, $audit['remaining']);
        $this->assertStringStartsWith('request-handle: '.self::AUDIT_REQUEST.': Acme\\Blog\\Http\\Requests\\Admin\\AuditRequest no define handle()', $audit['remaining'][0]);
        // Sólo request-handle se busca por el mensaje.
        $this->assertTrue($this->check(['id' => 'x', 'done_when' => $condition([self::AUDIT], ['controller-shape'])])['done']);

        // Un archivo sin hallazgos está hecho; sin archivos, cuentan todos.
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'verify'], $this->check($step(['src/Http/Controllers/Admin/Nadie.php'], 'x')));
        $this->assertCount(2, $this->check($step([], 'x'))['remaining']);

        // Las comprobaciones son las del paso: aquí ninguna mira las rutas.
        $closure = ['id' => 'route-closure@.', 'type' => 'route-closure', 'category' => Category::GUIDED, 'done_when' => $condition(['routes/web.php'], ['route-closure'])];
        $remaining = $this->check($closure)['remaining'];
        $this->assertCount(1, $remaining);
        $this->assertStringStartsWith('route-closure: routes/web.php: La ruta de la línea 5', $remaining[0]);
        $this->assertTrue($this->check($step(['routes/web.php'], 'x'))['done']);
    }

    public function test_un_hallazgo_que_pide_otro_tipo_de_paso_no_es_de_este(): void
    {
        $this->bare();
        $this->handwritten();
        $condition = ['kind' => 'verify', 'checks' => ['route-closure'], 'package' => null, 'files' => ['routes/web.php']];

        // El `fix` del fallback de la SPA está hecho aunque en routes/web.php
        // quede otro closure: ése es una decisión, otro paso.
        $this->assertTrue($this->check(['id' => 'fix:route-closure@.', 'type' => 'fix', 'done_when' => $condition])['done']);
        $this->assertFalse($this->check(['id' => 'route-closure@.', 'type' => 'route-closure', 'done_when' => $condition])['done']);
        // Un paso sin tipo, o de uno que el catálogo no conoce, no filtra.
        $this->assertFalse($this->check(['id' => 'x', 'done_when' => $condition])['done']);
        $this->assertFalse($this->check(['id' => 'x', 'type' => 'inventado', 'done_when' => $condition])['done']);
    }

    public function test_verify_sin_comprobaciones_o_de_un_paquete_que_ya_no_existe_no_da_nada_por_bueno(): void
    {
        $this->bare();
        $this->handwritten();

        $this->assertSame(['El paso no dice qué comprobaciones de verify mirar.'], $this->check(['id' => 'x', 'done_when' => ['kind' => 'verify', 'checks' => []]])['remaining']);
        $this->assertSame(['El paso no dice qué comprobaciones de verify mirar.'], $this->check(['id' => 'x', 'done_when' => ['kind' => 'verify']])['remaining']);
        // Una comprobación que verify no tiene no cuenta como comprobación.
        $this->assertSame(['El paso no dice qué comprobaciones de verify mirar.'], $this->check(['id' => 'x', 'done_when' => ['kind' => 'verify', 'checks' => ['inventada']]])['remaining']);
        $this->assertCount(1, $this->check(['id' => 'x', 'done_when' => ['kind' => 'verify', 'checks' => ['inventada', 'controller-shape']]])['remaining']);

        $this->assertSame(
            ['El paquete acme/retirado ya no existe.'],
            $this->check(['id' => 'x', 'done_when' => ['kind' => 'verify', 'checks' => ['controller-shape'], 'package' => 'acme/retirado']])['remaining']
        );
        // El núcleo se nombra `app`.
        $this->assertCount(1, $this->check(['id' => 'x', 'done_when' => ['kind' => 'verify', 'checks' => ['controller-shape'], 'package' => 'app']])['remaining']);
    }

    public function test_un_controlador_que_no_delega_no_lo_cubre_una_excepcion_del_realineamiento(): void
    {
        // controller-shape tiene su propio mecanismo (#[HandRolled] en el
        // código) y `except` no lo acepta: una entrada `realign:controller-shape`
        // escrita en el candado no puede dar el paso por hecho, igual que
        // Exceptions::review() no la cuenta.
        $this->bare();
        $this->handwritten();
        $step = ['id' => 'x', 'done_when' => ['kind' => 'verify', 'checks' => ['controller-shape'], 'files' => [self::REPORT]]];
        $target = ExceptionTargets::forFile('controller-shape', 'controller-shape', self::REPORT);

        $this->assertFalse($this->check($step)['done']);

        $this->approve([$target => 'Se queda así.']);
        $this->assertFalse($this->check($step)['done']);
    }

    public function test_el_codigo_sin_gestionar_deja_de_serlo_cuando_desaparece_o_se_aprueba(): void
    {
        $this->bare();
        $this->handwritten();
        $this->write('src/Models/Legacy.php', "<?php\n\nnamespace Acme\\Blog\\Models;\n\nclass Legacy {}\n");
        $step = fn (array $files): array => ['id' => 'unmanaged-http@.', 'type' => 'unmanaged-http', 'done_when' => ['kind' => 'unmanaged', 'files' => $files]];

        $this->assertSame(
            ['done' => false, 'remaining' => [
                self::REPORT.': sigue escrito a mano, sin declarar ni aprobar.',
                'routes/web.php: sigue escrito a mano, sin declarar ni aprobar.',
                'src/Models/Legacy.php: sigue escrito a mano, sin declarar ni aprobar.',
            ], 'kind' => 'unmanaged'],
            $this->check($step([self::REPORT, 'src/Http/Controllers/Admin/YaNoEsta.php', 'routes/web.php', 'src/Models/Legacy.php']))
        );
        // Sólo se miran los archivos del paso.
        $this->assertTrue($this->check($step(['src/Http/Controllers/Admin/YaNoEsta.php']))['done']);
        $this->assertTrue($this->check($step([]))['done']);

        // Aprobado como excepción sobre el código de hoy: ya no cuenta.
        $this->approve(['realign:unmanaged-http:'.self::REPORT => 'Es un informe interno.']);
        $this->assertSame([self::AUDIT.': sigue escrito a mano, sin declarar ni aprobar.'], $this->check($step([self::REPORT, self::AUDIT]))['remaining']);

        unlink($this->project->path.'/'.self::AUDIT);
        $this->assertTrue($this->check($step([self::REPORT, self::AUDIT]))['done']);
    }

    public function test_el_contrato_del_ambito_tiene_que_validar(): void
    {
        $this->bare();
        $step = fn (string $scope): array => ['id' => 'contract-invalid@'.$scope, 'done_when' => ['kind' => 'contract', 'scope' => $scope]];

        // Sin contrato no valida.
        $missing = $this->check($step('.'));
        $this->assertFalse($missing['done']);
        $this->assertSame('contract', $missing['kind']);
        $this->assertSame(['./laraimport.json /: No se encontró el archivo '.$this->project->path.'/laraimport.json.'], $missing['remaining']);

        $this->write('laraimport.json', '{"models": "no es una lista"}');
        $invalid = $this->check($step('.'));
        $this->assertFalse($invalid['done']);
        $this->assertStringStartsWith('./laraimport.json ', $invalid['remaining'][0]);

        $this->write('laraimport.json', (string) json_encode(self::smallContract()));
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'contract'], $this->check($step('.')));
        // Sin ámbito dicho, el de la raíz.
        $this->assertTrue($this->check(['id' => 'x', 'done_when' => ['kind' => 'contract']])['done']);

        // Cada ámbito, el suyo.
        $this->write('packages/pay/laraimport.json', '{"models": "no es una lista"}');
        $this->assertStringStartsWith('packages/pay/laraimport.json ', $this->check($step('packages/pay'))['remaining'][0]);
        $this->assertSame(
            ['packages/otro/laraimport.json /: No se encontró el archivo '.$this->project->path.'/packages/otro/laraimport.json.'],
            $this->check($step('packages/otro'))['remaining']
        );
    }

    public function test_un_contrato_con_avisos_y_sin_errores_valida(): void
    {
        $this->bare();
        copy(dirname(__DIR__).'/Fixtures/laraimport.json', $this->project->path.'/laraimport.json');

        $levels = array_unique(array_column(ImportDocument::fromFile($this->project->path.'/laraimport.json')['errors'], 'level'));
        $this->assertSame([SemanticValidator::WARNING], array_values($levels), 'El contrato de las pruebas ya no trae sólo avisos.');

        $this->assertTrue($this->check(['id' => 'x', 'done_when' => ['kind' => 'contract', 'scope' => '.']])['done']);
    }

    public function test_el_contrato_tiene_que_declarar_la_accion_de_cada_llave(): void
    {
        $this->bare();
        $columns = [
            ['model' => 'Post', 'column' => 'user_id', 'on_delete' => 'cascade', 'on_update' => 'restrict'],
            ['model' => 'Post', 'column' => 'team_id', 'on_delete' => 'set null'],
            ['model' => 'Comment', 'column' => 'post_id', 'on_update' => 'cascade'],
            ['model' => 'Tag', 'column' => 'post_id', 'on_delete' => 'cascade', 'on_update' => 'cascade'],
            // Sin acción que fijar no pide nada, aunque el contrato no la tenga.
            ['model' => 'Nadie', 'column' => 'x_id'],
            'no es una columna',
        ];
        $step = fn (string $scope = '.'): array => ['id' => 'pin-foreign-keys@'.$scope, 'done_when' => ['kind' => 'foreign-keys', 'scope' => $scope, 'columns' => $columns]];

        $this->assertSame(['done' => false, 'remaining' => ['./laraimport.json no se puede leer.'], 'kind' => 'foreign-keys'], $this->check($step()));
        $this->write('laraimport.json', 'esto no es JSON');
        $this->assertSame(['./laraimport.json no se puede leer.'], $this->check($step())['remaining']);

        $contract = fn (array $user, array $team, array $post): string => (string) json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title'], ['name' => 'user_id'] + $user, ['name' => 'team_id'] + $team, 'suelto', ['sin' => 'nombre']]],
            ['name' => 'Comment', 'props' => [['name' => 'post_id'] + $post]],
            'suelto',
            ['name' => 'SinProps'],
        ]]);

        $this->write('laraimport.json', $contract([], [], []));
        $this->assertSame([
            'Post.user_id: no declara on_delete (la migración hace cascade).',
            'Post.user_id: no declara on_update (la migración hace restrict).',
            'Post.team_id: no declara on_delete (la migración hace set null).',
            'Comment.post_id: no declara on_update (la migración hace cascade).',
            // Una sola vez, aunque se pidieran las dos acciones.
            'Tag.post_id: el contrato ya no la declara.',
        ], $this->check($step())['remaining']);

        // El valor que declare es suyo: callar era lo que había que resolver.
        $this->write('laraimport.json', $contract(['on_delete' => 'restrict', 'on_update' => null], ['on_delete' => 'cascade'], ['on_delete' => 'cascade']));
        $this->assertSame([
            'Comment.post_id: no declara on_update (la migración hace cascade).',
            'Tag.post_id: el contrato ya no la declara.',
        ], $this->check($step())['remaining']);

        // El ámbito de un paquete, en su laraimport.
        $this->assertSame(['packages/pay/laraimport.json no se puede leer.'], $this->check($step('packages/pay'))['remaining']);
        $this->write('packages/pay/laraimport.json', (string) json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'user_id', 'on_delete' => 'cascade', 'on_update' => 'cascade'], ['name' => 'team_id', 'on_delete' => 'cascade']]],
            ['name' => 'Comment', 'props' => [['name' => 'post_id', 'on_update' => 'cascade']]],
            ['name' => 'Tag', 'props' => [['name' => 'post_id', 'on_delete' => 'cascade', 'on_update' => 'cascade']]],
        ]]));
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'foreign-keys'], $this->check($step('packages/pay')));

        // Sin columnas que fijar, y con un contrato sin modelos, nada falta.
        $this->write('laraimport.json', '{"models": "x"}');
        $this->assertTrue($this->check(['id' => 'x', 'done_when' => ['kind' => 'foreign-keys']])['done']);
        $this->assertSame(['Post.user_id: el contrato ya no la declara.'], $this->check(['id' => 'x', 'done_when' => ['kind' => 'foreign-keys', 'columns' => [$columns[0]]]])['remaining']);
    }

    public function test_sin_contrato_no_hay_con_que_comparar_un_archivo(): void
    {
        $this->bare();
        $this->handwritten();

        $this->assertSame(
            ['done' => false, 'remaining' => [self::REPORT.': no se pudo regenerar el contrato de su ámbito para compararlo.'], 'kind' => 'classification'],
            $this->check(['id' => 'adopt@.', 'done_when' => ['kind' => 'classification', 'files' => [self::REPORT], 'accept' => [FileClass::IDENTICAL]]])
        );
        // Sin archivos no hay nada que comparar.
        $this->assertTrue($this->check(['id' => 'adopt@.', 'done_when' => ['kind' => 'classification', 'files' => [], 'accept' => [FileClass::IDENTICAL]]])['done']);
    }
}
