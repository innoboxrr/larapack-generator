<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:verify` contra la forma declarada.
 *
 * Cada test parte de un proyecto recién generado, sin desviaciones, y hace a
 * mano justo lo que la comprobación tiene que detectar.
 */
final class VerifyDeclaredShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProject(FakeProject::library('Acme\\Ledger\\'));

        $path = $this->project->path.'/laraimport.json';

        file_put_contents($path, json_encode(['models' => [
            ['name' => 'Post', 'props' => [['name' => 'title', 'type' => 'string']]],
            ['name' => 'Category', 'props' => [['name' => 'title', 'type' => 'string']]],
            ['name' => 'AuditEvent', 'props' => [['name' => 'action', 'type' => 'string']], 'routes' => ['only' => ['policies', 'index', 'show']]],
            ['name' => 'Consent', 'props' => [['name' => 'accepted', 'type' => 'boolean']], 'immutable' => true],
            ['name' => 'ApiKey', 'props' => [
                ['name' => 'label', 'type' => 'string', 'datatable' => true],
                ['name' => 'token_hash', 'type' => 'string', 'secret' => true],
            ]],
        ]]));

        $this->assertSame(
            Command::SUCCESS,
            $this->runCommand('larapack:import', ['jsonPath' => $path, '--vue' => true, '--react' => true]),
            "larapack:import terminó con error:\n".$this->lastOutput
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(bool $strict = false): array
    {
        $code = $this->runCommand('larapack:verify', ['--format' => 'json'] + ($strict ? ['--strict' => true] : []));

        return [$code, json_decode($this->lastOutput, true)];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, mixed>>
     */
    private function findingsOf(array $report, string $check): array
    {
        return array_values(array_filter($report['findings'], fn (array $f): bool => $f['check'] === $check));
    }

    private function edit(string $relative, callable $change): void
    {
        $file = $this->project->path.'/'.$relative;

        file_put_contents($file, $change((string) file_get_contents($file)));
    }

    /**
     * Es el arreglo que hacía falta antes que nada: con la comparación de
     * antes, AuditEvent salía marcado una vez por cada componente que no debe
     * tener.
     */
    public function test_un_proyecto_recien_generado_con_formas_distintas_no_tiene_desviaciones(): void
    {
        [$code, $report] = $this->verify(strict: true);

        $this->assertSame(Command::SUCCESS, $code, json_encode($report['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->findingsOf($report, 'inconsistent-entity'));
    }

    /**
     * Pero dentro de la misma forma la comprobación sigue viva.
     */
    public function test_la_consistencia_se_sigue_exigiendo_entre_modelos_de_la_misma_forma(): void
    {
        unlink($this->project->path.'/src/Policies/CategoryPolicy.php');

        $this->runCommand('larapack:model', ['name' => 'Tag']);

        [, $report] = $this->verify(strict: true);

        $flagged = array_unique(array_column($this->findingsOf($report, 'inconsistent-entity'), 'model'));

        $this->assertSame(['Tag'], array_values($flagged));
    }

    // ROUTE-NOT-DECLARED

    public function test_una_ruta_escrita_a_mano_para_una_accion_no_declarada_falla(): void
    {
        $this->edit('routes/api/models/audit_event.php', fn (string $php): string => $php
            ."\nRoute::put('update', [AuditEventController::class, 'update'])\n\t->name('update');\n");

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'route-not-declared');

        $this->assertCount(1, $findings);
        $this->assertSame('AuditEvent', $findings[0]['model']);
        $this->assertStringContainsString('ruta para update', $findings[0]['message']);
    }

    public function test_un_formulario_escrito_a_mano_para_una_accion_no_declarada_falla(): void
    {
        file_put_contents($this->project->path.'/resources/vue/src/models/audit-event/forms/EditForm.vue', "<template/>\n");

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame(
            'resources/vue/src/models/audit-event/forms/EditForm.vue',
            $this->findingsOf($report, 'route-not-declared')[0]['file']
        );
    }

    // IMMUTABLE-WRITE

    /**
     * El criterio de aceptación literal: una ruta PUT añadida a mano a un
     * modelo inmutable.
     */
    public function test_una_ruta_put_en_un_modelo_inmutable_falla_con_immutable_write(): void
    {
        $this->edit('routes/api/models/consent.php', fn (string $php): string => $php
            ."\nRoute::put('update', [ConsentController::class, 'update'])\n\t->name('update');\n");

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'immutable-write');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('es inmutable', $findings[0]['message']);

        // No se cuenta dos veces.
        $this->assertSame([], $this->findingsOf($report, 'route-not-declared'));
    }

    public function test_quitar_la_guarda_del_modelo_inmutable_falla(): void
    {
        $this->edit('src/Models/Consent.php', fn (string $php): string => str_replace('static::deleting($refuse);', '', $php));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('ya no rechaza modificaciones', $this->findingsOf($report, 'immutable-write')[0]['message']);
    }

    // SECRET-EXPOSED

    /**
     * El criterio de aceptación literal: la propiedad añadida a mano al
     * Resource.
     */
    public function test_un_secreto_anadido_a_mano_al_resource_falla(): void
    {
        $this->edit('src/Http/Resources/Models/ApiKeyResource.php', fn (string $php): string => str_replace(
            "'actions' => \$this->actions(\$request)",
            "'actions' => \$this->actions(\$request),\n            'token_hash' => \$this->token_hash",
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);

        $findings = $this->findingsOf($report, 'secret-exposed');

        $this->assertCount(1, $findings);
        $this->assertSame('src/Http/Resources/Models/ApiKeyResource.php', $findings[0]['file']);
    }

    public function test_sacar_el_secreto_de_hidden_falla(): void
    {
        $this->edit('src/Models/ApiKey.php', fn (string $php): string => preg_replace(
            "/(protected \\\$hidden = \\[)\\s*'token_hash'\\s*(\\];)/",
            '$1$2',
            $php
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('no está en $hidden', $this->findingsOf($report, 'secret-exposed')[0]['message']);
    }

    public function test_un_secreto_convertido_en_columna_de_la_tabla_falla(): void
    {
        $this->edit('resources/react/src/models/api-key/index.js', fn (string $js): string => str_replace(
            "        id: 'label',",
            "        id: 'token_hash',\n        value: 'Token',\n    },\n    {\n        id: 'label',",
            $js
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertSame('resources/react/src/models/api-key/index.js', $this->findingsOf($report, 'secret-exposed')[0]['file']);
    }

    // ROUTE-PREFIX

    /**
     * El criterio de aceptación: la comprobación del prefijo sigue funcionando
     * sobre un modelo con rutas parciales.
     */
    public function test_el_prefijo_de_rutas_se_sigue_comprobando_con_rutas_parciales(): void
    {
        $this->edit('resources/vue/src/models/audit-event/index.js', fn (string $js): string => str_replace(
            "'api.acme.ledger.audit_event.'",
            "'api.otra.cosa.'",
            $js
        ));

        [$code, $report] = $this->verify();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertCount(1, $this->findingsOf($report, 'route-prefix'));
    }

    // ENDPOINT-TESTS-OUTDATED

    /**
     * Un `<Modelo>EndpointsTest` de antes de 9.1: se copió una vez y no se
     * regenera, así que sin este aviso un proyecto que sube de versión nunca
     * tendría las pruebas que cierran la autorización.
     */
    public function test_un_test_de_modelo_sin_las_pruebas_de_autorizacion_avisa(): void
    {
        $this->edit('tests/Feature/Models/PostEndpointsTest.php', fn (string $php): string => str_replace(
            ['test_post_cada_accion_requiere_sesion', 'test_post_index_pasa_por_su_managed_filter'],
            ['test_post_index_requiere_sesion', 'test_post_otra_cosa'],
            $php
        ));

        [$code, $report] = $this->verify();
        $findings = $this->findingsOf($report, 'endpoint-tests-outdated');

        // Es un aviso: sin --strict, verify pasa.
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertCount(1, $findings, json_encode($report['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame('warning', $findings[0]['level']);
        $this->assertSame('Post', $findings[0]['model']);
        $this->assertSame('tests/Feature/Models/PostEndpointsTest.php', $findings[0]['file']);
        $this->assertStringContainsString('test_…_cada_accion_requiere_sesion, test_…_index_pasa_por_su_managed_filter', $findings[0]['message']);
        $this->assertStringNotContainsString('pregunta_a_la_politica', $findings[0]['message']);
        $this->assertStringContainsString('larapack:import', $findings[0]['message']);

        [$code] = $this->verify(strict: true);
        $this->assertSame(Command::FAILURE, $code);
    }

    /**
     * Lo que se mira es el nombre del método y lo que el modelo declara: sin
     * listado no hay prueba del ManagedFilter que echar en falta, y un test
     * que se borró no se reclama.
     */
    public function test_el_aviso_sigue_lo_que_el_modelo_declara(): void
    {
        $manifest = $this->project->path.'/.larapack/manifest.json';
        $data = json_decode((string) file_get_contents($manifest), true);
        $data['models']['AuditEvent']['declaration']['actions'] = ['policies', 'show'];
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->edit('tests/Feature/Models/AuditEventEndpointsTest.php', fn (string $php): string => str_replace(
            'test_audit_event_index_pasa_por_su_managed_filter',
            'test_audit_event_otra_cosa',
            $php
        ));

        unlink($this->project->path.'/tests/Feature/Models/CategoryEndpointsTest.php');

        $this->verify();

        $this->assertSame([], $this->findingsOf(json_decode($this->lastOutput, true), 'endpoint-tests-outdated'));
    }
}
