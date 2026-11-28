<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign done`: el criterio de terminado, uno y comprobable.
 *
 * En una prueba de autonomía un agente dio por terminado con `verify
 * --strict` en verde y el plan con pasos `edited-outside` pendientes:
 * `verify` no ve lo editado fuera de los huecos, y la guía mezclaba «el plan
 * sin pendientes» con «verify --strict». `done` mira todo y dice qué falta.
 */
final class RealignDoneTest extends TestCase
{
    use RealignProject;

    private const MODEL = 'src/Models/Post.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();

        $this->withoutIgnoredFlags();
        $this->commitAll();
    }

    public function test_un_proyecto_generado_y_sin_tocar_esta_terminado(): void
    {
        $done = $this->realign('done');

        $this->assertTrue($done['ok']);
        $this->assertTrue($done['done']);
        $this->assertSame(['steps' => 0, 'decisions' => 0, 'untested' => 0, 'exceptions' => 0, 'validate' => 0, 'verify' => 0], $done['counts']);
        $this->assertSame([], $done['reasons']);
    }

    public function test_lo_editado_fuera_de_los_huecos_no_esta_terminado_aunque_verify_strict_pase(): void
    {
        // El orden de una lista: fuera de los huecos, y verify no lo ve.
        $this->edit(self::MODEL, "'title', 'published', 'user_id'", "'user_id', 'title', 'published'");
        $this->commitAll();

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:verify', ['--root' => $this->project->path, '--strict' => true]), $this->lastOutput);

        $done = $this->realign('done', [], Command::FAILURE);

        $this->assertFalse($done['ok']);
        $this->assertFalse($done['done']);
        $this->assertSame(['edited-outside:Model@.'], array_column($done['missing']['steps'], 'id'));
        $this->assertSame('larapack:realign check edited-outside:Model@.', $done['missing']['steps'][0]['check']);
        $this->assertSame(1, $done['counts']['steps']);
        $this->assertSame(0, $done['counts']['verify']);
        $this->assertStringContainsString('1 pasos del plan sin hacer', $done['error']);
        // Diagnosticó y planificó otra vez: el plan en disco lo tiene.
        $this->assertSame('pending', $this->step('edited-outside:Model@.')['status']);

        // Text: lo mismo, con la orden de cada cosa.
        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:realign', ['action' => 'done', '--root' => $this->project->path]));
        $this->assertStringContainsString('No ha terminado', $this->lastOutput);
        $this->assertStringContainsString('larapack:realign check edited-outside:Model@.', $this->lastOutput);
    }

    public function test_lo_que_se_desvia_despues_del_ultimo_diagnostico_tambien_cuenta(): void
    {
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertTrue($this->realign('next')['done']);

        // Después del diagnóstico: `status` y `next` seguirían diciendo que no queda nada.
        $this->edit(self::MODEL, "'title', 'published', 'user_id'", "'user_id', 'title', 'published'");
        $this->commitAll();

        $done = $this->realign('done', [], Command::FAILURE);
        $this->assertContains('edited-outside:Model@.', array_column($done['missing']['steps'], 'id'));
    }

    public function test_verify_y_validate_estrictos_cuentan(): void
    {
        unlink($this->project->path.'/src/Policies/PostPolicy.php');
        $contract = json_decode($this->project->read('laraimport.json'), true);
        $contract['models'][0]['props'][] = ['name' => 'secreto', 'type' => 'string', 'secret' => true, 'exports_cols' => true];
        file_put_contents($this->project->path.'/laraimport.json', json_encode($contract, JSON_PRETTY_PRINT));
        $this->commitAll();

        $done = $this->realign('done', [], Command::FAILURE);

        $this->assertContains('missing-file', array_column($done['missing']['verify'], 'check'));
        $this->assertNotSame([], $done['missing']['validate'], json_encode($done['missing']));
        $this->assertSame('laraimport.json', $done['missing']['validate'][0]['file']);
    }

    public function test_una_decision_sin_tomar_y_una_excepcion_sin_aprobar_cuentan(): void
    {
        $controller = 'src/Http/Controllers/ReportController.php';
        file_put_contents($this->project->path.'/'.$controller, "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers;\n\nclass ReportController extends Controller\n{\n    public function index()\n    {\n        return response()->json([]);\n    }\n}\n");
        $this->commitAll();

        $done = $this->realign('done', [], Command::FAILURE);
        $decisions = array_column($done['missing']['decisions'], 'id');
        $this->assertNotSame([], $decisions, json_encode($done['missing']));
        $this->assertStringContainsString('larapack:realign decide', $done['missing']['decisions'][0]['decide']);

        // Decidido dejarlo así y pedida la excepción: sin aprobar, falta.
        $id = $decisions[0];
        $keep = null;

        foreach ($this->step($id)['options'] as $option) {
            if (str_starts_with((string) ($option['run'] ?? ''), 'larapack:realign except')) {
                $keep = $option['key'];
            }
        }

        $this->assertNotNull($keep, "{$id} no tiene una opción de dejarlo como está.");
        $this->realign('decide', ['targets' => [$id, $keep], '--note' => 'se queda']);
        $this->realign('except', ['targets' => [$id], '--option' => ['reason=Se queda a mano.']]);
        $this->commitAll();

        $done = $this->realign('done', [], Command::FAILURE);
        $this->assertNotSame([], $done['missing']['exceptions'], json_encode($done['missing']));
        $this->assertSame('unapproved', $done['missing']['exceptions'][0]['status']);
        $this->assertStringContainsString('las aprueba el dueño', $done['error']);
    }

    public function test_package_acota_y_help_lo_explica(): void
    {
        $this->edit(self::MODEL, "'title', 'published', 'user_id'", "'user_id', 'title', 'published'");
        $this->commitAll();

        $this->assertFalse($this->realign('done', ['--package' => ['app']], Command::FAILURE)['done']);

        $help = $this->realign('help', ['targets' => ['done']]);
        $this->assertSame('docs/realign/verify.md', $help['guide']);
        $this->assertContains('package', $help['options']);

        $refused = $this->realign('done', ['--dry-run' => true], Command::FAILURE);
        $this->assertStringContainsString('--dry-run', $refused['error']);
    }
}
