<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Closure;
use Innoboxrr\LarapackGenerator\Support\ProjectRoot;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\AdoptOperation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationContext;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationResult;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationRunner;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\PendingOperation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\RealignOperation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\ScopeReference;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Simulation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Lo común a toda operación que escribe, con una operación de mentira que
 * sólo apunta lo que recibe: qué pasos le llegan —nunca uno hecho, uno
 * bloqueado, uno aplazado ni una decisión que no se tomó para ella—, cuándo
 * se niega a escribir (el árbol git sucio) y qué deja anotado.
 *
 * Con el plan escrito a mano en un directorio temporal: no hace falta
 * diagnosticar ni generar nada para saber a quién deja pasar el corredor.
 */
final class OperationRunnerTest extends TestCase
{
    private string $root;

    /**
     * Los contextos que recibió la operación de mentira.
     *
     * @var list<OperationContext>
     */
    private array $received = [];

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()).'/larapack-runner-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/src', 0777, true);
        file_put_contents($this->root.'/src/A.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        Operations::reset();
        ScopeReference::forget();
        ProjectRoot::set(null);
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function step(string $id, string $type, array $extra = []): array
    {
        $meta = StepTypes::get($type);

        return $extra + ['id' => $id, 'type' => $type, 'category' => $meta['category'], 'scope' => ['package' => null, 'path' => '.'],
            'files' => ['src/A.php'], 'status' => Planner::PENDING, 'depends_on' => [], 'history' => [], 'options' => $meta['options'] ?? []];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function plan(array $steps): void
    {
        (new RealignState($this->root))->savePlan(['steps' => $steps]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function saved(): array
    {
        return array_column((new RealignState($this->root))->plan()['steps'] ?? [], null, 'id');
    }

    /**
     * Registra para `adopt` una operación que apunta su contexto y hace lo
     * que se le diga.
     *
     * @param  list<string>  $handles
     * @param  (Closure(OperationContext): OperationResult)|null  $run
     */
    private function fake(array $handles = ['adopt', 'untracked-edited'], ?Closure $run = null, string $action = 'adopt'): void
    {
        $record = function (OperationContext $context) use ($run): OperationResult {
            $this->received[] = $context;

            return $run === null ? (new OperationResult)->fixed('src/A.php', 'hecho') : $run($context);
        };

        Operations::register($action, fn (): RealignOperation => new class($action, $handles, $record) implements RealignOperation
        {
            /**
             * @param  list<string>  $handles
             */
            public function __construct(private string $name, private array $handles, private Closure $run) {}

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'de mentira';
            }

            public function handles(): array
            {
                return $this->handles;
            }

            public function run(OperationContext $context): OperationResult
            {
                return ($this->run)($context);
            }
        });
    }

    private function git(string ...$arguments): void
    {
        $process = proc_open(['git', '-C', $this->root, '-c', 'user.email=runner@test', '-c', 'user.name=runner', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
    }

    private function commitAll(): void
    {
        if (! is_dir($this->root.'/.git')) {
            $this->git('init', '-q');
        }

        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', 'estado');
    }

    /**
     * @param  list<string>  $targets
     * @param  list<string>  $packages
     * @return array<string, mixed>
     */
    private function dryRun(array $targets = [], array $packages = [], string $action = 'adopt'): array
    {
        return (new OperationRunner($this->root))->run($action, $targets, $packages, true, false);
    }

    private function refused(Closure $run): string
    {
        try {
            $run();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('La operación tenía que negarse.');
    }

    public function test_el_resultado_lleva_las_tres_listas_y_cada_elemento_dice_de_que_paso_es(): void
    {
        $result = (new OperationResult)
            ->fixed('a.php', 'cambiado', ['step' => 'adopt@.', 'diff' => '+x'])
            ->fixed('b.php', 'de varios', ['steps' => ['fix:x@.', '', 7, 'adopt@.', 'fix:x@.']])
            ->fixed('c.php', 'de uno y de varios', ['step' => 'adopt@.', 'steps' => ['fix:x@.', 'adopt@.']])
            ->fixed('d.php', 'el suyo no estaba en la lista', ['step' => 'relink@.', 'steps' => ['adopt@.']])
            ->unchanged(null, 'la versión anotada')
            ->unchanged('e.php', 'ya estaba', ['step' => ''])
            ->note('una nota');

        $this->assertFalse($result->hasSkipped());
        $this->assertSame([
            'fixed' => [
                ['file' => 'a.php', 'reason' => 'cambiado', 'step' => 'adopt@.', 'steps' => ['adopt@.'], 'diff' => '+x'],
                // Sin `step`, el primero de `steps`; sin repetidos ni vacíos.
                ['file' => 'b.php', 'reason' => 'de varios', 'step' => 'fix:x@.', 'steps' => ['fix:x@.', 'adopt@.']],
                ['file' => 'c.php', 'reason' => 'de uno y de varios', 'step' => 'adopt@.', 'steps' => ['fix:x@.', 'adopt@.']],
                ['file' => 'd.php', 'reason' => 'el suyo no estaba en la lista', 'step' => 'relink@.', 'steps' => ['relink@.', 'adopt@.']],
            ],
            'skipped' => [],
            'unchanged' => [
                // Lo que no es de ningún paso lo dice igual, con null.
                ['file' => null, 'reason' => 'la versión anotada', 'step' => null, 'steps' => []],
                ['file' => 'e.php', 'reason' => 'ya estaba', 'step' => null, 'steps' => []],
            ],
            'notes' => ['una nota'],
        ], $result->toArray());

        $other = (new OperationResult)->skipped('f.php', 'no se reconoce', ['step' => 'adopt@.'])->fixed('g.php', 'otro')->unchanged('h.php', 'igual')->note('otra nota');
        $this->assertTrue($other->hasSkipped());

        // Lo que hizo la operación en la que se delega, detrás de lo propio.
        $merged = $result->merge($other)->toArray();
        $this->assertSame(['a.php', 'b.php', 'c.php', 'd.php', 'g.php'], array_column($merged['fixed'], 'file'));
        $this->assertSame([['file' => 'f.php', 'reason' => 'no se reconoce', 'step' => 'adopt@.', 'steps' => ['adopt@.']]], $merged['skipped']);
        $this->assertSame([null, 'e.php', 'h.php'], array_column($merged['unchanged'], 'file'));
        $this->assertSame(['una nota', 'otra nota'], $merged['notes']);
        $this->assertTrue($result->hasSkipped());
    }

    public function test_una_accion_se_resuelve_por_convencion_y_se_puede_registrar_otra(): void
    {
        $this->assertSame(['adopt', 'apply', 'infer', 'except'], Operations::ACTIONS);
        $this->assertSame(Operations::ACTIONS, array_keys(Operations::DESCRIPTIONS));

        $this->assertInstanceOf(AdoptOperation::class, Operations::for('adopt'));
        $this->assertTrue(Operations::available('adopt'));
        $this->assertFalse(Operations::overridden('adopt'));

        // Una acción que no existe no hace como que hizo algo.
        $pending = Operations::for('teleport');
        $this->assertInstanceOf(PendingOperation::class, $pending);
        $this->assertSame('teleport', $pending->name());
        $this->assertSame('', $pending->description());
        $this->assertSame([], $pending->handles());
        $this->assertFalse(Operations::available('teleport'));
        $this->assertSame(Operations::DESCRIPTIONS['infer'], (new PendingOperation('infer'))->description());
        // Aunque haya una clase que se llame como pide la convención: sólo
        // las acciones declaradas.
        $this->assertSame('pending', Operations::for('pending')->name());
        $this->assertFalse(Operations::available('pending'));

        $this->assertSame(
            '`larapack:realign teleport` no está disponible en esta versión del generador. Haz el paso como lo describe `larapack:realign next` y compruébalo con `larapack:realign check`.',
            $this->refused(fn () => $pending->run(new OperationContext($this->root, new RealignState($this->root), [], true)))
        );

        $this->fake(['adopt'], null, 'teleport');
        $this->assertTrue(Operations::available('teleport'));
        $this->assertTrue(Operations::overridden('teleport'));
        $this->assertSame('de mentira', Operations::for('teleport')->description());
        $this->fake();
        $this->assertTrue(Operations::overridden('adopt'));
        $this->assertNotInstanceOf(AdoptOperation::class, Operations::for('adopt'));

        // null quita la registrada, y sólo ésa.
        Operations::register('teleport', null);
        $this->assertFalse(Operations::overridden('teleport'));
        $this->assertTrue(Operations::overridden('adopt'));

        Operations::reset();
        $this->assertFalse(Operations::overridden('adopt'));
        $this->assertInstanceOf(AdoptOperation::class, Operations::for('adopt'));
    }

    public function test_el_contexto_dice_si_hay_que_escribir_y_si_es_una_simulacion(): void
    {
        $state = new RealignState($this->root);
        $real = new OperationContext($this->root, $state, [], false, ['reason' => 'porque sí']);
        $dry = new OperationContext($this->root, $state, [], true);
        // La corrida de un --dry-run sobre la copia: escribe (en la copia) y
        // cuenta como simulada.
        $copy = new OperationContext($this->root, $state, [], false, [], [], true);

        $this->assertSame([false, false], [$real->dryRun(), $real->simulated()]);
        $this->assertSame([true, true], [$dry->dryRun(), $dry->simulated()]);
        $this->assertSame([false, true], [$copy->dryRun(), $copy->simulated()]);
        $this->assertSame('porque sí', $real->option('reason'));
        $this->assertNull($real->option('otra'));
        $this->assertSame('por defecto', $real->option('otra', 'por defecto'));
        $this->assertSame([], $real->only);
    }

    public function test_sin_plan_o_sin_implementacion_no_se_hace_nada(): void
    {
        $this->assertSame(
            '`larapack:realign teleport` no está disponible en esta versión del generador. Haz el paso como lo describe `larapack:realign next` y compruébalo con `larapack:realign check`.',
            $this->refused(fn () => $this->dryRun([], [], 'teleport'))
        );

        $this->fake();
        $this->assertSame('No hay plan: corre `larapack:realign diagnose` y `larapack:realign plan` primero.', $this->refused(fn () => $this->dryRun()));
        $this->assertSame([], $this->received);
    }

    public function test_un_paso_nombrado_se_rechaza_si_no_existe_no_es_suyo_esta_hecho_o_espera_a_otro(): void
    {
        $this->fake();
        $this->plan([
            $this->step('adopt@.', 'adopt'),
            $this->step('regenerate@.', 'regenerate'),
            $this->step('adopt@done', 'adopt', ['status' => Planner::DONE]),
            $this->step('adopt@untested', 'adopt', ['status' => Planner::UNTESTED]),
            $this->step('untracked-edited@.', 'untracked-edited', ['depends_on' => ['adopt@.', 'regenerate@.']]),
            ['id' => 'raro@.'],
            'suelto',
        ]);

        $this->assertSame('No hay un paso nadie@. en el plan. `larapack:realign status` los lista.', $this->refused(fn () => $this->dryRun(['nadie@.'])));
        $this->assertSame('El paso regenerate@. es de tipo regenerate, y `adopt` sólo hace: adopt, untracked-edited.', $this->refused(fn () => $this->dryRun(['regenerate@.'])));
        $this->assertSame('El paso raro@. es de tipo ?, y `adopt` sólo hace: adopt, untracked-edited.', $this->refused(fn () => $this->dryRun(['raro@.'])));
        $this->assertSame('El paso adopt@done ya está hecho.', $this->refused(fn () => $this->dryRun(['adopt@done'])));
        $this->assertSame('El paso adopt@untested ya está hecho (sin probar).', $this->refused(fn () => $this->dryRun(['adopt@untested'])));
        $this->assertSame('El paso untracked-edited@. espera a: adopt@., regenerate@..', $this->refused(fn () => $this->dryRun(['untracked-edited@.'])));
        // Uno bueno junto a uno malo: no se hace ninguno.
        $this->assertSame('El paso adopt@done ya está hecho.', $this->refused(fn () => $this->dryRun(['adopt@.', 'adopt@done'])));
        $this->assertSame([], $this->received);

        $run = $this->dryRun(['adopt@.']);

        $this->assertSame(['adopt@.'], $run['steps']);
        $this->assertSame('adopt', $run['action']);
        $this->assertTrue($run['dry_run']);
        $this->assertNull($run['commit']);
        $this->assertSame(['fixed' => [['file' => 'src/A.php', 'reason' => 'hecho', 'step' => null, 'steps' => []]], 'skipped' => [], 'unchanged' => [], 'notes' => []], $run['result']);
        $this->assertCount(1, $this->received);
        $this->assertSame(['adopt@.'], array_column($this->received[0]->steps, 'id'));
        $this->assertSame($this->root, $this->received[0]->root);
        $this->assertTrue($this->received[0]->dryRun());

        // Una decisión nombrada se le da aunque no se haya decidido para ella:
        // quien la nombra sabe lo que pide (y la operación, lo que hace).
        $this->plan([$this->step('untracked-edited@.', 'untracked-edited')]);
        $this->assertSame(['untracked-edited@.'], $this->dryRun(['untracked-edited@.'])['steps']);
    }

    public function test_sin_nombrar_pasos_toma_los_pendientes_que_sabe_hacer_y_nada_mas(): void
    {
        $this->fake();
        $decided = fn (string $option): array => ['decision' => ['option' => $option, 'note' => null, 'at' => 'antes']];
        $this->plan([
            $this->step('adopt@.', 'adopt'),
            $this->step('adopt@packages/pay', 'adopt', ['scope' => ['package' => 'acme/pay', 'path' => 'packages/pay']]),
            $this->step('adopt@done', 'adopt', ['status' => Planner::DONE]),
            $this->step('adopt@untested', 'adopt', ['status' => Planner::UNTESTED]),
            $this->step('adopt@deferred', 'adopt', ['deferred' => ['note' => 'después', 'at' => 'antes']]),
            $this->step('regenerate@.', 'regenerate'),
            // Una decisión sólo entra si se decidió con la opción que ejecuta esta operación.
            $this->step('untracked-edited@undecided', 'untracked-edited'),
            $this->step('untracked-edited@except', 'untracked-edited', $decided('except')),
            $this->step('untracked-edited@adopt', 'untracked-edited', $decided('adopt')),
            $this->step('untracked-edited@blocked', 'untracked-edited', $decided('adopt') + ['depends_on' => ['regenerate@.']]),
        ]);

        $this->assertSame(['adopt@.', 'adopt@packages/pay', 'untracked-edited@adopt'], $this->dryRun()['steps']);
        // --package acota por la ruta del ámbito.
        $this->assertSame(['adopt@packages/pay'], $this->dryRun([], ['packages/pay'])['steps']);
        $this->assertSame(['adopt@.', 'untracked-edited@adopt'], $this->dryRun([], ['.'])['steps']);
        $this->assertSame(['adopt@.', 'adopt@packages/pay', 'untracked-edited@adopt'], $this->dryRun([], ['.', 'packages/pay'])['steps']);

        $this->assertSame('No hay pasos pendientes que `adopt` sepa hacer en esos paquetes.', $this->refused(fn () => $this->dryRun([], ['packages/otro'])));

        $this->plan([$this->step('regenerate@.', 'regenerate'), $this->step('adopt@done', 'adopt', ['status' => Planner::DONE])]);
        $this->assertSame('No hay pasos pendientes que `adopt` sepa hacer.', $this->refused(fn () => $this->dryRun()));
        $this->plan([]);
        $this->assertSame('No hay pasos pendientes que `adopt` sepa hacer.', $this->refused(fn () => $this->dryRun()));
    }

    public function test_de_lo_decidido_por_elemento_la_operacion_solo_ve_lo_suyo(): void
    {
        $this->fake();
        $this->plan([$this->step('untracked-edited@.', 'untracked-edited', [
            'files' => ['src/A.php', 'src/B.php', 'src/C.php'],
            'decisions' => ['src/A.php' => ['option' => 'except'], 'src/B.php' => ['option' => 'adopt'], 'src/C.php' => ['option' => 'adopt']],
        ])]);

        $run = (new OperationRunner($this->root))->run('adopt', [], [], true, false, ['reason' => 'x'], ['src/B.php']);

        $this->assertSame(['untracked-edited@.'], $run['steps']);
        $this->assertSame(['src/B.php', 'src/C.php'], $this->received[0]->steps[0]['files']);
        $this->assertSame('adopt', $this->received[0]->steps[0]['decision']['option']);
        // Las opciones y --target llegan tal cual.
        $this->assertSame(['reason' => 'x'], $this->received[0]->options);
        $this->assertSame(['src/B.php'], $this->received[0]->only);
        // El plan guardado no se estrecha.
        $this->assertSame(['src/A.php', 'src/B.php', 'src/C.php'], $this->saved()['untracked-edited@.']['files']);
    }

    public function test_simular_no_exige_el_arbol_limpio_ni_deja_rastro(): void
    {
        $this->fake();
        $this->plan([$this->step('adopt@.', 'adopt')]);
        $before = file_get_contents($this->root.'/.larapack/realign/plan.json');

        // Ni siquiera es un repositorio git.
        $run = $this->dryRun(['adopt@.']);

        $this->assertNull($run['commit']);
        $this->assertSame([], $run['result']['notes']);
        $this->assertSame($before, file_get_contents($this->root.'/.larapack/realign/plan.json'));
        $this->assertFalse($this->received[0]->simulation);
    }

    public function test_escribir_exige_un_repositorio_git_con_el_arbol_limpio(): void
    {
        $this->fake();
        $this->plan([$this->step('adopt@.', 'adopt')]);
        $run = fn (bool $allowDirty = false): array => (new OperationRunner($this->root))->run('adopt', ['adopt@.'], [], false, $allowDirty);

        $this->assertSame(
            $this->root.' no es un repositorio git: lo que se escriba no se podrá deshacer con git. Con --allow-dirty se escribe igual.',
            $this->refused($run)
        );
        $this->assertSame([], $this->received);
        // Dicho, se escribe igual; y fuera de git no hay nada que comitear.
        $this->assertNull($run(true)['commit']);
        $this->assertCount(1, $this->received);

        $this->commitAll();
        file_put_contents($this->root.'/src/A.php', "<?php\n// tocado\n");
        file_put_contents($this->root.'/src/Nuevo.php', "<?php\n");

        $this->assertSame(
            'El árbol git tiene cambios sin comitear (2): M src/A.php; ?? src/Nuevo.php. Comítalos o guárdalos antes, para poder deshacer lo que escriba el realineamiento; --allow-dirty lo salta.',
            $this->refused($run)
        );
        $this->assertCount(1, $this->received);
        $this->assertFalse($this->received[0]->dryRun());

        // Con más de diez, los diez primeros y que hay más.
        foreach (range(1, 10) as $index) {
            file_put_contents($this->root.'/src/N'.$index.'.php', "<?php\n");
        }

        $message = $this->refused($run);
        $this->assertStringStartsWith('El árbol git tiene cambios sin comitear (12): M src/A.php; ?? src/N1.php; ', $message);
        $this->assertStringContainsString('?? src/N8.php…. Comítalos', $message);
        $this->assertStringNotContainsString('src/N9.php', $message);
        $this->assertStringNotContainsString('src/Nuevo.php', $message);
    }

    public function test_el_estado_del_realineamiento_no_cuenta_como_arbol_sucio(): void
    {
        $this->fake(['adopt'], fn (OperationContext $context): OperationResult => (new OperationResult)->unchanged('src/A.php', 'ya estaba'));
        $this->commitAll();
        // El plan se escribe después del commit: es estado, no código.
        $this->plan([$this->step('adopt@.', 'adopt'), $this->step('adopt@otro', 'adopt')]);
        file_put_contents($this->root.'/.larapack/realign/exceptions.json', '{}');

        $run = (new OperationRunner($this->root))->run('adopt', ['adopt@.'], [], false, false);

        // No dejó nada sin comitear: no hay nada que aconsejar.
        $this->assertNull($run['commit']);
        $this->assertSame([], $run['result']['notes']);
        $this->assertFalse($run['dry_run']);

        // Lo que hizo queda en la historia de ese paso, y sólo de ése. No lo
        // da por hecho: eso lo decide check.
        $saved = $this->saved();
        $this->assertCount(1, $saved['adopt@.']['history']);
        $this->assertSame('applied', $saved['adopt@.']['history'][0]['event']);
        $this->assertSame('adopt: 0 cambiados, 0 saltados, 1 sin cambios.', $saved['adopt@.']['history'][0]['detail']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $saved['adopt@.']['history'][0]['at']);
        $this->assertSame(Planner::PENDING, $saved['adopt@.']['status']);
        $this->assertSame([], $saved['adopt@otro']['history']);
    }

    public function test_si_deja_el_arbol_con_cambios_dice_que_se_comitee_antes_de_seguir(): void
    {
        $this->fake(['adopt'], function (OperationContext $context): OperationResult {
            file_put_contents($context->root.'/src/A.php', "<?php\n// adoptado\n");
            file_put_contents($context->root.'/src/B.php', "<?php\n");

            return (new OperationResult)->fixed('src/A.php', 'uno')->fixed('src/B.php', 'dos')->skipped('src/C.php', 'no se reconoce')->note('de la operación');
        });
        $this->commitAll();
        $this->plan(array_map(fn (int $n): array => $this->step('adopt@p'.$n, 'adopt'), range(1, 5)));

        $run = (new OperationRunner($this->root))->run('adopt', [], [], false, false);

        $command = "git add -A && git commit -m 'realign: adopt adopt@p1 adopt@p2 adopt@p3 (+2)'";
        $message = 'Comitea antes de seguir: quedan 2 archivos sin comitear, y la siguiente operación que escribe exige el árbol limpio. Corre las pruebas del proyecto y después: '.$command;
        $this->assertSame(['required' => true, 'files' => 2, 'command' => $command, 'message' => $message], $run['commit']);
        $this->assertSame(['de la operación', $message], $run['result']['notes']);
        $this->assertSame('adopt: 2 cambiados, 1 saltados, 0 sin cambios.', $this->saved()['adopt@p5']['history'][0]['detail']);

        // La siguiente se niega hasta que se comitee, como avisó.
        $this->assertStringStartsWith('El árbol git tiene cambios sin comitear (2): ', $this->refused(fn () => (new OperationRunner($this->root))->run('adopt', ['adopt@p1'], [], false, false)));

        // Con tres pasos o menos se nombran todos, sin «(+N)».
        $this->commitAll();
        file_put_contents($this->root.'/src/A.php', "<?php\n");
        $this->commitAll();
        $this->assertSame(
            "git add -A && git commit -m 'realign: adopt adopt@p1 adopt@p2 adopt@p3'",
            (new OperationRunner($this->root))->run('adopt', ['adopt@p1', 'adopt@p2', 'adopt@p3'], [], false, false)['commit']['command']
        );
    }

    public function test_una_simulacion_es_la_misma_corrida_sobre_una_copia_que_se_tira(): void
    {
        mkdir($this->root.'/vendor/acme', 0777, true);
        file_put_contents($this->root.'/vendor/acme/lib.php', "<?php\n");
        mkdir($this->root.'/resources/react/node_modules', 0777, true);
        mkdir($this->root.'/.larapack/realign/cache/root', 0777, true);
        file_put_contents($this->root.'/.larapack/realign/cache/root/x', 'regenerado');
        file_put_contents($this->root.'/.larapack/realign/plan.json', '{}');
        mkdir($this->root.'/.git');
        file_put_contents($this->root.'/.git/HEAD', 'ref');
        mkdir($this->root.'/src/.git');
        symlink('A.php', $this->root.'/src/enlace.php');
        mkdir($this->root.'/vacio');

        $seen = [];
        $operation = $this->operation(function (OperationContext $context) use (&$seen): OperationResult {
            $copy = $context->root;
            $seen = [
                'root' => $copy,
                'project_root' => ProjectRoot::path(),
                'state' => $context->state->root(),
                'flags' => [$context->dryRun(), $context->simulated(), $context->simulation],
                'steps' => array_column($context->steps, 'id'),
                'options' => $context->options,
                'only' => $context->only,
                'git' => is_dir($copy.'/.git'),
                'nested_git' => is_dir($copy.'/src/.git'),
                'vendor' => is_link($copy.'/vendor') ? readlink($copy.'/vendor') : null,
                'node_modules' => is_link($copy.'/resources/react/node_modules') ? readlink($copy.'/resources/react/node_modules') : null,
                'cache' => is_link($copy.'/.larapack/realign/cache') ? readlink($copy.'/.larapack/realign/cache') : null,
                'plan' => is_file($copy.'/.larapack/realign/plan.json') && ! is_link($copy.'/.larapack/realign/plan.json'),
                'link' => is_link($copy.'/src/enlace.php') ? readlink($copy.'/src/enlace.php') : null,
                'empty' => is_dir($copy.'/vacio'),
                'file' => file_get_contents($copy.'/src/A.php'),
            ];
            // Escribe de verdad: en la copia.
            file_put_contents($copy.'/src/A.php', "<?php\n// simulado\n");

            return (new OperationResult)
                ->fixed('src/A.php', 'reescrito en '.$copy.'/src/A.php', ['step' => 'adopt@.', 'diff' => '--- '.$copy.'/src/A.php', 'n' => 3, 'nested' => ['path' => $copy.'/x']])
                ->note('copia en '.$copy);
        });

        $result = Simulation::run($this->root.'/', $operation, [$this->step('adopt@.', 'adopt')], ['reason' => 'x'], ['src/A.php']);

        // El proyecto no se tocó, y la copia ya no está.
        $this->assertSame("<?php\n", file_get_contents($this->root.'/src/A.php'));
        $this->assertNotSame($this->root, $seen['root']);
        $this->assertDirectoryDoesNotExist($seen['root']);
        $this->assertFileExists($this->root.'/vendor/acme/lib.php');
        $this->assertSame('regenerado', file_get_contents($this->root.'/.larapack/realign/cache/root/x'));

        // La operación corrió sobre la copia, como una corrida de verdad.
        $this->assertSame($seen['root'], $seen['project_root']);
        $this->assertSame($seen['root'], $seen['state']);
        $this->assertSame([false, true, true], $seen['flags']);
        $this->assertSame(['adopt@.'], $seen['steps']);
        $this->assertSame(['reason' => 'x'], $seen['options']);
        $this->assertSame(['src/A.php'], $seen['only']);
        $this->assertSame("<?php\n", $seen['file']);
        // Todo menos .git (el de la raíz); lo que sólo se lee, enlazado.
        $this->assertFalse($seen['git']);
        $this->assertTrue($seen['nested_git']);
        $this->assertSame($this->root.'/vendor', $seen['vendor']);
        $this->assertSame($this->root.'/resources/react/node_modules', $seen['node_modules']);
        $this->assertSame($this->root.'/.larapack/realign/cache', $seen['cache']);
        $this->assertTrue($seen['plan']);
        $this->assertSame('A.php', $seen['link']);
        $this->assertTrue($seen['empty']);

        // Las rutas de la copia vuelven como rutas del proyecto.
        $this->assertSame([
            'fixed' => [['file' => 'src/A.php', 'reason' => 'reescrito en '.$this->root.'/src/A.php', 'step' => 'adopt@.', 'steps' => ['adopt@.'],
                'diff' => '--- '.$this->root.'/src/A.php', 'n' => 3, 'nested' => ['path' => $this->root.'/x']]],
            'skipped' => [],
            'unchanged' => [],
            'notes' => ['copia en '.$this->root],
        ], $result);
    }

    public function test_la_copia_de_una_simulacion_se_tira_aunque_la_operacion_falle(): void
    {
        $copy = null;
        $operation = $this->operation(function (OperationContext $context) use (&$copy): OperationResult {
            $copy = $context->root;

            throw new RuntimeException('se rompió');
        });

        $this->assertSame('se rompió', $this->refused(fn () => Simulation::run($this->root, $operation, [], [])));
        $this->assertIsString($copy);
        $this->assertDirectoryDoesNotExist($copy);
        $this->assertSame(['apply', 'adopt'], Simulation::ACTIONS);
    }

    /**
     * @param  Closure(OperationContext): OperationResult  $run
     */
    private function operation(Closure $run): RealignOperation
    {
        $this->fake(['adopt'], $run, 'simulated');
        $operation = Operations::for('simulated');
        Operations::register('simulated', null);

        return $operation;
    }
}
