<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Declaration;
use Innoboxrr\LarapackGenerator\Support\Import\Actions;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Cómo llega la forma declarada a los comandos y al manifiesto.
 */
final class DeclaredShapeCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library());
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode($this->project->read('.larapack/manifest.json'), true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     */
    private function laraimport(array $models): string
    {
        $path = $this->project->path . '/laraimport.json';

        file_put_contents($path, json_encode(['models' => $models]));

        return $path;
    }

    // MANIFIESTO

    /**
     * La forma de siempre no anota nada: el manifiesto de un proyecto que no
     * usa las claves nuevas sale idéntico al de antes.
     */
    public function test_un_modelo_con_la_forma_de_siempre_no_anota_declaracion(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Product']);

        $this->assertArrayNotHasKey('declaration', $this->manifest()['models']['Product']);
    }

    public function test_full_model_con_only_anota_las_acciones_que_quedan(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:full-model', [
            'name' => 'AuditEvent',
            '--only' => 'show, index',
        ]), $this->lastOutput);

        $this->assertSame(
            ['actions' => ['index', 'show']],
            $this->manifest()['models']['AuditEvent']['declaration']
        );
    }

    public function test_full_model_con_immutable_anota_que_lo_es(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'Consent', '--immutable' => true]);

        $declaration = $this->manifest()['models']['Consent']['declaration'];

        $this->assertTrue($declaration['immutable']);
        $this->assertSame(['policies', 'policy', 'index', 'show', 'create', 'export'], $declaration['actions']);
    }

    public function test_el_importador_anota_la_declaracion_de_cada_modelo(): void
    {
        $this->runCommand('larapack:import', ['jsonPath' => $this->laraimport([
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
            [
                'name' => 'ApiKey',
                'props' => [
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'token_hash', 'type' => 'string', 'secret' => true],
                ],
                'routes' => ['only' => ['index', 'create', 'delete']],
            ],
        ])]);

        $models = $this->manifest()['models'];

        $this->assertArrayNotHasKey('declaration', $models['Post']);
        $this->assertSame(
            ['actions' => ['index', 'create', 'delete'], 'secret' => ['token_hash']],
            $models['ApiKey']['declaration']
        );
    }

    /**
     * Si el registro sobreviviera al comando, el siguiente modelo generado en
     * el mismo proceso heredaría una forma que no declaró.
     */
    public function test_el_importador_no_deja_declaraciones_vivas_al_terminar(): void
    {
        $this->runCommand('larapack:import', ['jsonPath' => $this->laraimport([
            ['name' => 'AuditEvent', 'props' => [['name' => 'action', 'type' => 'string']], 'routes' => ['only' => ['index']]],
        ])]);

        $this->assertSame(Actions::ALL, Declaration::of('AuditEvent')['actions']);
    }

    public function test_en_dry_run_no_anota_nada(): void
    {
        $this->runCommand('larapack:full-model', ['name' => 'AuditEvent', '--only' => 'index', '--dry-run' => true]);

        $this->assertFalse($this->project->has('.larapack/manifest.json'));
    }

    // OPCIONES INVALIDAS

    public function test_only_y_except_juntos_fallan_sin_generar_nada(): void
    {
        $code = $this->runCommand('larapack:full-model', ['name' => 'AuditEvent', '--only' => 'index', '--except' => 'update']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('no los dos', $this->lastOutput);
        $this->assertFalse($this->project->has('src/Models/AuditEvent.php'));
    }

    public function test_una_accion_desconocida_falla_y_dice_cuales_valen(): void
    {
        $code = $this->runCommand('larapack:full-model', ['name' => 'AuditEvent', '--only' => 'index,destroy']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('destroy', $this->lastOutput);
        $this->assertStringContainsString('forceDelete', $this->lastOutput);
    }

    public function test_immutable_con_una_escritura_en_only_falla(): void
    {
        $code = $this->runCommand('larapack:full-model', ['name' => 'Consent', '--immutable' => true, '--only' => 'index,update']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('update', $this->lastOutput);
    }

    // VALIDATE

    public function test_validate_solo_muestra_los_avisos_de_interfaz_si_se_piden(): void
    {
        $path = $this->laraimport([
            ['name' => 'AuditEvent', 'props' => [['name' => 'action', 'type' => 'string']], 'routes' => ['only' => ['show']]],
        ]);

        $this->runCommand('larapack:validate', ['jsonPath' => $path, '--format' => 'json']);
        $this->assertSame(0, json_decode($this->lastOutput, true)['warnings']);

        $this->runCommand('larapack:validate', ['jsonPath' => $path, '--format' => 'json', '--vue' => true]);
        $report = json_decode($this->lastOutput, true);

        $this->assertSame(1, $report['warnings']);
        $this->assertStringContainsString('no tiene index', $report['findings'][0]['message']);
    }
}
