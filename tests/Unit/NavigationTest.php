<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use PHPUnit\Framework\TestCase;

/**
 * Lo que Navigation contesta de un paso, con el paso escrito a mano: qué se
 * decidió de cada elemento, qué ve cada operación, cuándo una decisión deja
 * seguir a lo guiado y cuándo una solicitud de excepción deja de contar.
 *
 * De estas respuestas depende que no se rehaga trabajo (lo guiado antes de
 * una decisión que lo tira) y, sobre todo, que no se pida ni se dé por
 * pedida una excepción de lo que se decidió no dejar como está.
 */
final class NavigationTest extends TestCase
{
    private const A = 'src/Http/Controllers/AController.php';

    private const B = 'src/Http/Controllers/BController.php';

    private const C = 'routes/web.php';

    /**
     * Una decisión como la escribe Planner, con las opciones de su tipo.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function decision(string $type, array $files, array $extra = []): array
    {
        return $extra + ['id' => $type.'@.', 'type' => $type, 'category' => Category::DECISION, 'files' => $files,
            'options' => StepTypes::TYPES[$type]['options'] ?? [], 'status' => Planner::PENDING];
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function guided(string $id, array $files, array $extra = []): array
    {
        return $extra + ['id' => $id, 'type' => 'controller-shape', 'category' => Category::GUIDED, 'files' => $files, 'status' => Planner::PENDING];
    }

    public function test_que_decisiones_condicionan_lo_guiado(): void
    {
        foreach (['unmanaged-http', 'unmanaged-models', 'hand-rolled', 'route-closure'] as $type) {
            $this->assertTrue(Navigation::conditions($type), $type);
        }

        foreach (['missing', 'no-contract', 'controller-shape', ''] as $type) {
            $this->assertFalse(Navigation::conditions($type), $type);
        }
    }

    public function test_los_archivos_de_un_paso_son_sus_rutas_y_nada_mas(): void
    {
        $this->assertSame([self::A, self::B], Navigation::files(['files' => [3 => self::A, 'x' => null, 7 => ['no'], 9 => self::B]]));
        $this->assertSame([], Navigation::files([]));
        $this->assertSame([self::A], Navigation::files(['files' => self::A]));
    }

    public function test_la_opcion_de_un_elemento_es_la_suya_y_si_no_la_del_paso(): void
    {
        $step = ['decision' => ['option' => 'keep'], 'decisions' => [self::A => ['option' => 'declare'], self::B => ['option' => 7]]];

        $this->assertSame('declare', Navigation::optionFor($step, self::A));
        // Una opción por elemento que no es un nombre no cuenta: rige la del paso.
        $this->assertSame('keep', Navigation::optionFor($step, self::B));
        $this->assertSame('keep', Navigation::optionFor($step, self::C));
        $this->assertNull(Navigation::optionFor(['decisions' => [self::A => ['option' => 'declare']]], self::B));
        $this->assertNull(Navigation::optionFor(['decision' => ['option' => null]], self::A));
        $this->assertNull(Navigation::optionFor([], self::A));
    }

    public function test_lo_que_falta_decidir_elemento_por_elemento(): void
    {
        $step = $this->decision('unmanaged-http', [self::A, self::B, self::C]);

        $this->assertSame([self::A, self::B, self::C], Navigation::undecided($step));
        $this->assertFalse(Navigation::decided($step));

        $partial = $step + ['decisions' => [self::B => ['option' => 'keep']]];
        $this->assertSame([self::A, self::C], Navigation::undecided($partial));
        $this->assertFalse(Navigation::decided($partial));

        $each = $step + ['decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'declare'], self::C => ['option' => 'keep']]];
        $this->assertSame([], Navigation::undecided($each));
        $this->assertTrue(Navigation::decided($each));

        // Decidido entero: no falta nada, aunque no diga nada de cada uno.
        $this->assertSame([], Navigation::undecided($step + ['decision' => ['option' => 'keep']]));
        $this->assertTrue(Navigation::decided($step + ['decision' => ['option' => 'keep']]));
        // Una `decision` que no es una decisión no decide nada.
        $this->assertSame([self::A, self::B, self::C], Navigation::undecided($step + ['decision' => 'keep']));
    }

    public function test_un_paso_sin_archivos_solo_se_decide_entero(): void
    {
        $scope = $this->decision('no-contract', []);

        $this->assertSame(['*'], Navigation::undecided($scope));
        $this->assertFalse(Navigation::decided($scope));
        // Decidir «por elemento» algo que no tiene elementos no lo decide.
        $this->assertSame(['*'], Navigation::undecided($scope + ['decisions' => ['x' => ['option' => 'keep']]]));
        $this->assertTrue(Navigation::decided($scope + ['decision' => ['option' => 'keep']]));
    }

    public function test_los_elementos_por_opcion_y_las_opciones_elegidas(): void
    {
        $step = $this->decision('unmanaged-http', [self::A, self::B, self::C], [
            'decisions' => [self::A => ['option' => 'keep'], self::C => ['option' => 'keep'], self::B => ['option' => 'declare']],
        ]);

        $this->assertSame(['keep' => [self::A, self::C], 'declare' => [self::B]], Navigation::byOption($step));
        $this->assertSame(['keep', 'declare'], Navigation::chosen($step));

        // Decidido a medias: lo que falta no sale en ninguna opción.
        $partial = $this->decision('unmanaged-http', [self::A, self::B], ['decisions' => [self::B => ['option' => 'declare']]]);
        $this->assertSame(['declare' => [self::B]], Navigation::byOption($partial));
        $this->assertSame(['declare'], Navigation::chosen($partial));

        // Entero: todos sus elementos van con esa opción.
        $whole = $this->decision('unmanaged-http', [self::A, self::B], ['decision' => ['option' => 'keep']]);
        $this->assertSame(['keep' => [self::A, self::B]], Navigation::byOption($whole));
        $this->assertSame(['keep'], Navigation::chosen($whole));

        // Sin elementos, la opción del paso cuenta igual.
        $this->assertSame([], Navigation::byOption($this->decision('no-contract', [], ['decision' => ['option' => 'infer']])));
        $this->assertSame(['infer'], Navigation::chosen($this->decision('no-contract', [], ['decision' => ['option' => 'infer']])));
        $this->assertSame([], Navigation::chosen($this->decision('no-contract', [])));

        // Entero y, encima, cada elemento con otra: las dos se eligieron.
        $both = $this->decision('unmanaged-http', [self::A], ['decision' => ['option' => 'keep'], 'decisions' => [self::A => ['option' => 'declare']]]);
        $this->assertSame(['declare', 'keep'], Navigation::chosen($both));
    }

    public function test_una_decision_deja_seguir_a_lo_guiado_solo_si_cada_archivo_compartido_lo_permite(): void
    {
        $guided = $this->guided('controller-shape@'.self::A, [self::A, self::B]);
        $decision = $this->decision('unmanaged-http', [self::A, self::B, self::C]);

        $this->assertFalse(Navigation::allows($guided, $decision), 'sin decidir');
        $this->assertTrue(Navigation::allows($guided, $decision + ['decision' => ['option' => 'keep']]));
        $this->assertFalse(Navigation::allows($guided, $decision + ['decision' => ['option' => 'declare']]));
        // Lo que no comparten no importa; lo que comparten, todo.
        $this->assertTrue(Navigation::allows($guided, $decision + ['decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'keep'], self::C => ['option' => 'declare']]]));
        $this->assertFalse(Navigation::allows($guided, $decision + ['decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'declare']]]));
        $this->assertFalse(Navigation::allows($guided, $decision + ['decisions' => [self::A => ['option' => 'declare'], self::B => ['option' => 'keep']]]));
        $this->assertFalse(Navigation::allows($guided, $decision + ['decisions' => [self::A => ['option' => 'keep']]]));

        // Sin archivos en común no hay nada que permitir.
        $this->assertFalse(Navigation::allows($this->guided('x', ['src/Otro.php']), $decision + ['decision' => ['option' => 'keep']]));
        // Cada tipo, sus opciones: con un #[HandRolled] es «llevarlo a su request».
        $handRolled = $this->decision('hand-rolled', [self::A]);
        $this->assertTrue(Navigation::allows($guided, $handRolled + ['decision' => ['option' => 'refactor']]));
        $this->assertFalse(Navigation::allows($guided, $handRolled + ['decision' => ['option' => 'keep']]));
        // Una decisión que no condiciona lo guiado no lo deja seguir con nada.
        $this->assertFalse(Navigation::allows($guided, $this->decision('missing', [self::A], ['decision' => ['option' => 'keep']])));
    }

    public function test_los_guiados_que_condiciona_una_decision_son_los_que_tocan_sus_archivos(): void
    {
        $steps = [
            $this->guided('controller-shape@'.self::A, [self::A]),
            $this->guided('security@.', ['src/Otro.php', self::C]),
            $this->guided('security@x', ['src/Otro.php']),
            // Comparte el archivo, pero no es guiado.
            ['id' => 'fix:x@.', 'type' => 'fix', 'category' => Category::DETERMINISTIC, 'files' => [self::A]],
            $this->decision('route-closure', [self::C]),
        ];

        $this->assertSame(['controller-shape@'.self::A, 'security@.'], Navigation::dependents($this->decision('unmanaged-http', [self::A, self::C]), $steps));
        $this->assertSame([], Navigation::dependents($this->decision('unmanaged-http', ['src/Nadie.php']), $steps));
        // Una decisión que no condiciona no tiene dependientes aunque comparta archivos.
        $this->assertSame([], Navigation::dependents($this->decision('missing', [self::A, self::C]), $steps));
        $this->assertSame([], Navigation::dependents(['files' => [self::A]], $steps));
    }

    public function test_una_decision_tomada_espera_a_lo_guiado_que_sigue_valiendo(): void
    {
        $decision = $this->decision('unmanaged-http', [self::A, self::B], ['decision' => ['option' => 'keep']]);
        $pending = $this->guided('controller-shape@'.self::A, [self::A], ['depends_on' => ['adopt@.', 'unmanaged-http@.']]);
        $byId = fn (array ...$steps): array => array_column([$decision, ...$steps], null, 'id');

        // La excepción se pide sobre el código ya llevado a su request.
        $this->assertTrue(Navigation::waitsForGuided($decision, $byId($pending)));
        // Hecho (o hecho a falta de su prueba) ya no hay a qué esperar.
        $this->assertFalse(Navigation::waitsForGuided($decision, $byId(['status' => Planner::DONE] + $pending)));
        $this->assertFalse(Navigation::waitsForGuided($decision, $byId(['status' => Planner::UNTESTED] + $pending)));
        $this->assertFalse(Navigation::waitsForGuided($decision, $byId()));
        // Uno que no depende de ella, o que no es guiado, no cuenta.
        $this->assertFalse(Navigation::waitsForGuided($decision, $byId(['depends_on' => ['adopt@.']] + $pending)));
        $this->assertFalse(Navigation::waitsForGuided($decision, $byId(['category' => Category::DECISION] + $pending)));
        $this->assertFalse(Navigation::waitsForGuided($decision, $byId(['depends_on' => null] + $pending)));
        // Una sola dependencia escrita sin lista sigue siendo una dependencia.
        $this->assertTrue(Navigation::waitsForGuided($decision, $byId(['depends_on' => 'unmanaged-http@.'] + $pending)));

        // Sin decidir no espera a nadie: es ella la que falta.
        $undecided = $this->decision('unmanaged-http', [self::A, self::B]);
        $this->assertFalse(Navigation::waitsForGuided($undecided, array_column([$undecided, $pending], null, 'id')));
        // Y una que no condiciona, tampoco.
        $other = $this->decision('missing', [self::A], ['id' => 'unmanaged-http@.', 'decision' => ['option' => 'keep']]);
        $this->assertFalse(Navigation::waitsForGuided($other, array_column([$other, $pending], null, 'id')));
    }

    public function test_si_algun_guiado_espera_a_que_la_decision_se_cumpla_se_cumple_ya(): void
    {
        // A se deja como código propio (lo guiado sigue); B se declara (lo
        // guiado sobre B espera a que se cumpla). Si la decisión esperara a
        // lo de A, B y ella se esperarían el uno al otro.
        $decision = $this->decision('unmanaged-http', [self::A, self::B], ['decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'declare']]]);
        $onA = $this->guided('controller-shape@'.self::A, [self::A], ['depends_on' => ['unmanaged-http@.']]);
        $onB = $this->guided('controller-shape@'.self::B, [self::B], ['depends_on' => ['unmanaged-http@.']]);

        $this->assertTrue(Navigation::waitsForGuided($decision, array_column([$decision, $onA], null, 'id')));
        $this->assertFalse(Navigation::waitsForGuided($decision, array_column([$decision, $onB], null, 'id')));
        $this->assertFalse(Navigation::waitsForGuided($decision, array_column([$decision, $onA, $onB], null, 'id')));
        $this->assertFalse(Navigation::waitsForGuided($decision, array_column([$decision, $onB, $onA], null, 'id')));
    }

    public function test_una_decision_cuya_orden_ya_se_corrio_es_del_dueno(): void
    {
        $step = $this->decision('unmanaged-http', [self::A], ['decision' => ['option' => 'keep']]);
        $decided = ['at' => 't1', 'event' => 'decided'];
        $applied = ['at' => 't2', 'event' => 'applied'];

        $this->assertFalse(Navigation::handedOver($step), 'sin historia');
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$decided]]));
        $this->assertTrue(Navigation::handedOver($step + ['history' => [$decided, $applied]]));
        // Comprobarla no es correr su orden, y lo que no es un evento no cuenta.
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$decided, ['at' => 't2', 'event' => 'checked']]]));
        $this->assertFalse(Navigation::handedOver($step + ['history' => ['basura', $applied]]));
        $this->assertFalse(Navigation::handedOver($step + ['history' => 'no es una lista']));
        $this->assertTrue(Navigation::handedOver($step + ['history' => [$decided, ['event' => 'checked'], 'basura', $applied]]));
        // Una orden que se corrió antes de decidir no es la de la decisión.
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$applied, $decided]]));
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$applied]]));

        // Sin decidir del todo, o si no es una decisión, no es del dueño.
        $this->assertFalse(Navigation::handedOver($this->decision('unmanaged-http', [self::A], ['history' => [$decided, $applied]])));
        $this->assertFalse(Navigation::handedOver(['category' => Category::GUIDED] + $step + ['history' => [$decided, $applied]]));
    }

    public function test_decidir_otra_vez_despues_de_aplicar_devuelve_el_paso(): void
    {
        // La orden que se corrió era la de la decisión anterior: la nueva
        // todavía no se ha ejecutado, y `next` tiene que volver a darla.
        $step = $this->decision('unmanaged-http', [self::A], ['decision' => ['option' => 'keep']]);
        $decided = ['at' => 't1', 'event' => 'decided'];
        $except = ['at' => 't2', 'event' => 'applied', 'operation' => 'except'];
        $infer = ['at' => 't2', 'event' => 'applied', 'operation' => 'infer'];

        $this->assertTrue(Navigation::handedOver($step + ['history' => [$decided, $except]]));
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$decided, $except, $decided]]));
        $this->assertTrue(Navigation::handedOver($step + ['history' => [$decided, $except, $decided, $except]]));
        // La orden de otra opción no es la de la decidida.
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$decided, $infer]]));
    }

    public function test_con_decisiones_por_elemento_tienen_que_correr_todas_sus_ordenes(): void
    {
        $step = $this->decision('unmanaged-http', [self::A, self::B], ['decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'declare']]]);
        $decided = ['at' => 't1', 'event' => 'decided'];
        $except = ['at' => 't2', 'event' => 'applied', 'operation' => 'except'];
        $infer = ['at' => 't3', 'event' => 'applied', 'operation' => 'infer'];

        $this->assertFalse(Navigation::handedOver($step + ['history' => [$decided, $except]]));
        $this->assertFalse(Navigation::handedOver($step + ['history' => [$decided, $infer]]));
        $this->assertTrue(Navigation::handedOver($step + ['history' => [$decided, $except, $infer]]));
        // Un plan de antes de anotar la operación: cualquier `applied` vale.
        $this->assertTrue(Navigation::handedOver($step + ['history' => [$decided, ['at' => 't2', 'event' => 'applied']]]));
    }

    public function test_que_ordenes_cuentan_como_las_de_la_opcion_elegida(): void
    {
        // Opciones escritas a mano: sólo cuenta lo que empieza por
        // `larapack:realign <operación>`.
        $step = fn (array $options, array $history): array => $this->decision('unmanaged-http', [self::A], [
            'options' => $options, 'decision' => ['option' => 'keep'], 'history' => $history,
        ]);
        $decided = ['at' => 't1', 'event' => 'decided'];
        $infer = ['at' => 't2', 'event' => 'applied', 'operation' => 'infer'];

        // Decidida y sin nada aplicado: no es del dueño, aunque la opción no
        // ejecute ninguna orden.
        $this->assertFalse(Navigation::handedOver($step([['key' => 'keep']], [$decided])));
        // Una orden que no es de realign no es una operación que falte.
        $this->assertTrue(Navigation::handedOver($step([['key' => 'keep', 'run' => 'echo larapack:realign except posts']], [$decided, $infer])));
        $this->assertFalse(Navigation::handedOver($step([['key' => 'keep', 'run' => 'larapack:realign except posts']], [$decided, $infer])));
        // Dos opciones que corren la misma operación: con correrla una vez basta.
        $this->assertTrue(Navigation::handedOver($step([['key' => 'keep', 'run' => 'larapack:realign infer a'], ['key' => 'keep', 'run' => 'larapack:realign infer b']], [$decided, $infer])));
    }

    public function test_la_clave_de_una_opcion_se_compara_como_texto(): void
    {
        $step = $this->decision('unmanaged-http', [self::A], [
            'options' => [['key' => 1, 'run' => 'larapack:realign except posts']],
            'decision' => ['option' => '1'],
        ]);

        $this->assertFalse(Navigation::handedOver($step + ['history' => [['at' => 't1', 'event' => 'decided'], ['at' => 't2', 'event' => 'applied', 'operation' => 'infer']]]));
        $this->assertTrue(Navigation::handedOver($step + ['history' => [['at' => 't1', 'event' => 'decided'], ['at' => 't2', 'event' => 'applied', 'operation' => 'except']]]));
    }

    public function test_una_operacion_solo_ve_los_elementos_que_se_decidio_que_hiciera(): void
    {
        $step = $this->decision('unmanaged-http', [self::A, self::B, self::C], [
            'decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'declare'], self::C => ['option' => 'keep']],
            'details' => [
                'files' => [['file' => self::A, 'class' => 'x'], ['file' => self::B, 'class' => 'y']],
                'findings' => [['file' => self::A, 'check' => 'x'], ['file' => self::B, 'check' => 'y'], ['file' => null, 'check' => 'sin archivo'], 'suelto'],
                'unmanaged' => [['file' => self::A, 'kind' => 'controller'], ['file' => self::B, 'kind' => 'controller'], ['file' => self::C, 'kind' => 'routes']],
                'contract' => [['file' => self::B, 'model' => 'Post']],
                'otra' => [['file' => self::B]],
            ],
        ]);

        $except = Navigation::narrow($step, 'except');

        // `except` pide excepciones sólo de lo que se decidió dejar.
        $this->assertSame([self::A, self::C], $except['files']);
        $this->assertSame(['option' => 'keep', 'note' => null, 'at' => null], $except['decision']);
        $this->assertSame([['file' => self::A, 'kind' => 'controller'], ['file' => self::C, 'kind' => 'routes']], $except['details']['unmanaged']);
        // Lo que no dice de qué archivo es no se quita.
        $this->assertSame([['file' => self::A, 'check' => 'x'], ['file' => null, 'check' => 'sin archivo'], 'suelto'], $except['details']['findings']);
        $this->assertSame([], $except['details']['contract']);
        $this->assertSame([['file' => self::A, 'class' => 'x']], $except['details']['files']);
        $this->assertSame([['file' => self::B]], $except['details']['otra']);
        $this->assertSame($step['decisions'], $except['decisions']);

        $infer = Navigation::narrow($step, 'infer');
        $this->assertSame([self::B], $infer['files']);
        $this->assertSame('declare', $infer['decision']['option']);
        $this->assertSame([['file' => self::B, 'kind' => 'controller']], $infer['details']['unmanaged']);

        // Una operación que ninguna opción ejecuta no ve nada, ni decisión.
        $adopt = Navigation::narrow($step, 'adopt');
        $this->assertSame([], $adopt['files']);
        $this->assertArrayNotHasKey('decision', $adopt);
        $this->assertSame([], $adopt['details']['unmanaged']);
    }

    public function test_un_paso_decidido_entero_llega_a_la_operacion_tal_cual(): void
    {
        $whole = $this->decision('unmanaged-http', [self::A, self::B], ['decision' => ['option' => 'declare', 'note' => 'n', 'at' => 't']]);

        $this->assertSame($whole, Navigation::narrow($whole, 'except'));
        $this->assertSame($whole + ['decisions' => []], Navigation::narrow($whole + ['decisions' => []], 'except'));
        $this->assertSame($whole + ['decisions' => 'x'], Navigation::narrow($whole + ['decisions' => 'x'], 'except'));
    }

    public function test_si_algun_elemento_se_decidio_con_una_opcion_que_ejecuta_la_operacion(): void
    {
        $step = $this->decision('unmanaged-http', [self::A, self::B]);

        $this->assertFalse(Navigation::decidedFor($step, 'except'), 'sin decidir');
        $this->assertTrue(Navigation::decidedFor($step + ['decision' => ['option' => 'keep']], 'except'));
        $this->assertFalse(Navigation::decidedFor($step + ['decision' => ['option' => 'keep']], 'infer'));
        $this->assertTrue(Navigation::decidedFor($step + ['decision' => ['option' => 'declare']], 'infer'));
        // Por elemento, basta con uno.
        $mixed = $step + ['decisions' => [self::A => ['option' => 'keep'], self::B => ['option' => 'declare']]];
        $this->assertTrue(Navigation::decidedFor($mixed, 'except'));
        $this->assertTrue(Navigation::decidedFor($mixed, 'infer'));
        $this->assertFalse(Navigation::decidedFor($mixed, 'adopt'));
        // El nombre de la acción, entero: `exc` no es `except`.
        $this->assertFalse(Navigation::decidedFor($step + ['decision' => ['option' => 'keep']], 'exc'));
        // Una opción sin orden (se hace a mano) no la ejecuta ninguna operación.
        $invalid = $this->decision('contract-invalid', [], ['decision' => ['option' => 'fix']]);
        $this->assertFalse(Navigation::decidedFor($invalid, 'except'));
        // Dos opciones pueden ejecutar la misma operación: cuenta cualquiera de las dos.
        $two = ['id' => 'x@.', 'category' => Category::DECISION, 'files' => [self::A, self::B], 'options' => [
            ['key' => 'except', 'run' => 'larapack:realign except {id} --option reason="por qué"'],
            ['key' => 'except-forever', 'run' => 'larapack:realign except {id} --option reason="por qué" --option forever=1'],
        ], 'decisions' => [self::A => ['option' => 'except-forever'], self::B => ['option' => 'except']]];
        $this->assertTrue(Navigation::decidedFor(['decision' => ['option' => 'except-forever']] + $two, 'except'));
        $this->assertSame([self::A, self::B], Navigation::narrow($two, 'except')['files']);
        $this->assertTrue(Navigation::stillRequested($two, ['file' => self::A]));
        // Sólo cuentan las órdenes de larapack:realign.
        $handRolled = $this->decision('hand-rolled', [self::A], ['decision' => ['option' => 'approve']]);
        $this->assertFalse(Navigation::decidedFor($handRolled, 'approve'));
        $this->assertFalse(Navigation::decidedFor(['options' => ['suelta', ['key' => 'x', 'run' => 7]], 'decision' => ['option' => 'x']], 'except'));
        // Unas opciones que no son una lista (un plan tocado a mano) no ejecutan nada.
        $this->assertFalse(Navigation::decidedFor(['options' => 'no es una lista', 'decision' => ['option' => 'x']], 'except'));
    }

    public function test_un_guiado_que_se_pasa_al_dueno_es_una_decision_con_dos_salidas(): void
    {
        $step = $this->guided('security@.', [self::A], ['type' => 'security', 'playbook' => 'security']);

        $escalated = Navigation::escalate($step, ['note' => 'No está claro.', 'at' => 'ahora']);

        $this->assertSame(Category::DECISION, $escalated['category']);
        $this->assertSame(['from' => Category::GUIDED, 'note' => 'No está claro.', 'at' => 'ahora'], $escalated['escalated']);
        $this->assertSame(['refactor', 'except'], array_column($escalated['options'], 'key'));
        $this->assertSame('security', $escalated['options'][0]['playbook']);
        $this->assertStringContainsString('(security)', $escalated['options'][0]['consequences']);
        // Dejarlo como está es pedir una excepción de ESE paso.
        $this->assertSame('larapack:realign except security@. --option reason="por qué"', $escalated['options'][1]['run']);
        $this->assertTrue(Navigation::decidedFor($escalated + ['decision' => ['option' => 'except']], 'except'));
        $this->assertFalse(Navigation::decidedFor($escalated + ['decision' => ['option' => 'refactor']], 'except'));
        // Lo demás del paso no cambia.
        $this->assertSame('security@.', $escalated['id']);
        $this->assertSame([self::A], $escalated['files']);

        // Sin playbook propio, el genérico.
        $generic = Navigation::escalate($this->guided('verify-other@.', [], ['type' => 'verify-other', 'playbook' => null]), ['note' => '', 'at' => 'ahora']);
        $this->assertSame(Playbooks::GENERIC, $generic['options'][0]['playbook']);
        $this->assertSame(['refactor', 'except'], array_column($generic['options'], 'key'));

        // Un controlador no se deja con una excepción del realineamiento:
        // se marca #[HandRolled] y lo aprueba el dueño.
        $controller = Navigation::escalate($this->guided('controller-shape@'.self::A, [self::A], ['playbook' => 'controller-to-request']), ['note' => 'x', 'at' => 'ahora']);
        $this->assertSame(['refactor', 'hand-rolled'], array_column($controller['options'], 'key'));
        $this->assertArrayNotHasKey('run', $controller['options'][1]);
        $this->assertFalse(Navigation::decidedFor($controller + ['decision' => ['option' => 'hand-rolled']], 'except'));
    }

    public function test_una_solicitud_deja_de_contar_cuando_su_elemento_se_decide_de_otra_forma(): void
    {
        $request = ['target' => 'realign:unmanaged-http:'.self::A, 'file' => self::A, 'step' => 'unmanaged-http@.'];
        $step = $this->decision('unmanaged-http', [self::A, self::B]);

        // Se pidió y todavía no se ha decidido nada de él: se sigue pidiendo.
        $this->assertTrue(Navigation::stillRequested($step, $request));
        $this->assertTrue(Navigation::stillRequested($step + ['decision' => ['option' => 'keep']], $request));
        // Se decidió declararlo: no hay que aprobar una excepción que no se va a dar.
        $this->assertFalse(Navigation::stillRequested($step + ['decision' => ['option' => 'declare']], $request));
        $this->assertFalse(Navigation::stillRequested($step + ['decision' => ['option' => 'keep'], 'decisions' => [self::A => ['option' => 'declare']]], $request));
        $this->assertTrue(Navigation::stillRequested($step + ['decision' => ['option' => 'declare'], 'decisions' => [self::A => ['option' => 'keep']]], $request));
        $this->assertTrue(Navigation::stillRequested($step + ['decisions' => [self::B => ['option' => 'declare']]], $request));
        // Una solicitud sin archivo se rige por la decisión del paso.
        $this->assertFalse(Navigation::stillRequested($step + ['decision' => ['option' => 'declare']], ['target' => 'x']));

        // Un paso que no es una decisión la sigue pidiendo siempre.
        $this->assertTrue(Navigation::stillRequested(['category' => Category::GUIDED] + $step + ['decision' => ['option' => 'declare']], $request));
        $this->assertTrue(Navigation::stillRequested(['decision' => ['option' => 'declare']], $request));
    }
}
