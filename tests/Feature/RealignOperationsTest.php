<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationContext;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\OperationResult;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\PendingOperation;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\RealignOperation;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que el núcleo garantiza a toda operación que escribe, antes de que
 * exista ninguna: que se enchufa por convención o por registro, que sin
 * `--dry-run` exige poder deshacerse con git, que sólo recibe pasos que sabe
 * hacer y no bloqueados, y que lo que hace queda en la historia del paso
 * sin darlo por hecho.
 */
final class RealignOperationsTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    /**
     * @var list<OperationContext>
     */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        file_put_contents($this->project->path.'/'.self::CONTROLLER, str_replace('    ', "\t", $this->project->read(self::CONTROLLER)));
        $this->edit('src/Http/Requests/Post/ShowRequest.php', "public function authorize(): bool\n    {", "public function authorize(): bool\n    {\n        if (true) {\n            return true;\n        }");
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();

        Operations::register('adopt', fn (): RealignOperation => $this->fakeAdopt());
    }

    public function test_una_accion_sin_implementacion_lo_dice(): void
    {
        Operations::register('adopt', null);
        // Desde 9.4.0 `infer` tiene implementación: se fuerza la pendiente
        // para probar el contrato con una acción que no la tenga.
        Operations::register('infer', fn (): RealignOperation => new PendingOperation('infer'));

        $this->assertInstanceOf(PendingOperation::class, Operations::for('infer'));
        $this->assertFalse(Operations::available('infer'));

        $document = $this->realign('infer', [], Command::FAILURE);
        $this->assertStringContainsString('no está disponible en esta versión', $document['error']);
        $this->assertSame('infer', $document['action']);
    }

    public function test_simular_no_exige_arbol_limpio_ni_toca_la_historia(): void
    {
        file_put_contents($this->project->path.'/sucio.txt', 'x');

        $document = $this->realign('adopt', ['targets' => ['adopt@.'], '--dry-run' => true]);

        $this->assertTrue($document['dry_run']);
        $this->assertSame(['adopt@.'], $document['steps']);
        $this->assertSame([['file' => self::CONTROLLER, 'reason' => 'simulado', 'step' => null, 'steps' => []]], $document['fixed']);
        $this->assertTrue($this->calls[0]->dryRun());
        $this->assertSame([], $this->step('adopt@.')['history']);
    }

    public function test_escribir_exige_el_arbol_limpio_salvo_que_se_diga(): void
    {
        file_put_contents($this->project->path.'/sucio.txt', 'x');

        $refused = $this->realign('adopt', ['targets' => ['adopt@.']], Command::FAILURE);
        $this->assertStringContainsString('sucio.txt', $refused['error']);
        $this->assertStringContainsString('--allow-dirty', $refused['error']);
        $this->assertSame([], $this->calls);

        $allowed = $this->realign('adopt', ['targets' => ['adopt@.'], '--allow-dirty' => true]);
        $this->assertSame([['file' => self::CONTROLLER, 'reason' => 'hecho', 'step' => null, 'steps' => []]], $allowed['fixed']);
    }

    public function test_el_estado_del_realineamiento_no_ensucia_el_arbol(): void
    {
        // diagnose y plan escriben en .larapack/realign/ después del commit.
        $this->realign('diagnose');
        $this->realign('plan');

        $document = $this->realign('adopt', ['targets' => ['adopt@.']]);

        $this->assertFalse($document['dry_run']);
        $this->assertSame('applied', end($this->step('adopt@.')['history'])['event']);
        // Hacerlo no es darlo por hecho: eso lo dice check.
        $this->assertSame('pending', $this->step('adopt@.')['status']);
    }

    public function test_fuera_de_git_no_se_escribe_sin_decirlo(): void
    {
        $this->removeGit();

        $refused = $this->realign('adopt', ['targets' => ['adopt@.']], Command::FAILURE);
        $this->assertStringContainsString('no es un repositorio git', $refused['error']);

        $this->realign('adopt', ['targets' => ['adopt@.'], '--allow-dirty' => true]);
    }

    public function test_solo_recibe_pasos_que_sabe_hacer_y_no_bloqueados(): void
    {
        $other = $this->realign('adopt', ['targets' => ['edited-outside:Requests@.']], Command::FAILURE);
        $this->assertStringContainsString('sólo hace: adopt, relink, prune', $other['error']);

        $unknown = $this->realign('adopt', ['targets' => ['nada@.']], Command::FAILURE);
        $this->assertStringContainsString('No hay un paso nada@.', $unknown['error']);

        Operations::register('apply', fn (): RealignOperation => $this->fakeAdopt('apply', ['edited-outside']));
        $blocked = $this->realign('apply', ['targets' => ['edited-outside:Requests@.']], Command::FAILURE);
        $this->assertStringContainsString('espera a: adopt@.', $blocked['error']);
    }

    public function test_sin_pasos_nombrados_toma_los_pendientes_que_sabe_hacer(): void
    {
        $document = $this->realign('adopt');

        $this->assertSame(['adopt@.'], $document['steps']);
        $this->assertSame(['adopt@.'], array_map(fn (array $step): string => $step['id'], $this->calls[0]->steps));
    }

    public function test_lo_saltado_termina_con_error_y_las_opciones_llegan(): void
    {
        Operations::register('adopt', fn (): RealignOperation => $this->fakeAdopt('adopt', ['adopt'], skip: true));

        $document = $this->realign('adopt', ['targets' => ['adopt@.'], '--option' => ['modo=estricto']], Command::FAILURE);

        $this->assertFalse($document['ok']);
        $this->assertSame('no lo reconozco', $document['skipped'][0]['reason']);
        $this->assertSame('estricto', $this->calls[0]->option('modo'));
        $this->assertStringContainsString('clave=valor', $this->realign('adopt', ['--option' => ['sinigual']], Command::FAILURE)['error']);
    }

    /**
     * @param  list<string>  $handles
     */
    private function fakeAdopt(string $name = 'adopt', array $handles = ['adopt', 'relink', 'prune'], bool $skip = false): RealignOperation
    {
        $calls = &$this->calls;

        return new class($name, $handles, $skip, $calls) implements RealignOperation
        {
            /**
             * @param  list<string>  $handles
             * @param  list<OperationContext>  $calls
             */
            public function __construct(private string $name, private array $handles, private bool $skip, private array &$calls) {}

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'De prueba.';
            }

            public function handles(): array
            {
                return $this->handles;
            }

            public function run(OperationContext $context): OperationResult
            {
                $this->calls[] = $context;
                $result = new OperationResult;

                foreach ($context->steps as $step) {
                    foreach ($step['files'] as $file) {
                        $this->skip
                            ? $result->skipped($file, 'no lo reconozco')
                            : $result->fixed($file, $context->dryRun() ? 'simulado' : 'hecho');
                    }
                }

                return $result;
            }
        };
    }

    private function removeGit(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project->path.'/.git', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->project->path.'/.git');
    }
}
