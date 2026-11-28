<?php

namespace Innoboxrr\LarapackGenerator\Tests\EndToEnd;

use Innoboxrr\LarapackGenerator\Tests\Support\SwarmProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Varias consolas a la vez sobre el mismo proyecto: cada una en su rama, y
 * luego se integran con `git merge` de verdad.
 *
 * Es la auditoría de enjambre de 9.4.0 hecha prueba. Lo que mide:
 *
 *  - que cada rama, sola, queda como dice la guía (y que las dos que no la
 *    siguen se detectan);
 *  - que fusionar SIN reconciliar nunca pasa en silencio: resuelva el
 *    integrador el conflicto como lo resuelva, `verify --strict` lo dice;
 *  - que `larapack:reconcile` deja el árbol en verde en cualquier orden de
 *    fusión, igual que si el contrato fusionado se hubiera generado de cero,
 *    y sin perder el trabajo a mano de nadie;
 *  - que lo que no puede decidir solo se lo deja, nombrado, a una persona.
 *
 * Las ramas las describe SwarmProject::agent().
 */
final class SwarmMergeTest extends TestCase
{
    private static ?SwarmProject $seed = null;

    /**
     * @var list<SwarmProject>
     */
    private array $clones = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$seed = SwarmProject::seed();
    }

    public static function tearDownAfterClass(): void
    {
        self::$seed?->cleanup();
        self::$seed = null;

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        foreach ($this->clones as $clone) {
            $clone->cleanup();
        }

        parent::tearDown();
    }

    private function project(): SwarmProject
    {
        return $this->clones[] = self::$seed->clone();
    }

    // ─── Cada rama, sola ─────────────────────────────────────────────────────

    public function test_cada_rama_que_sigue_la_guia_pasa_verify_estricto(): void
    {
        $project = $this->project();

        foreach (array_diff(SwarmProject::BRANCHES, ['hand-bad', 'contract-only']) as $branch) {
            $project->git('checkout', '-q', $branch);

            $this->assertSame([], $project->failingChecks(), "La rama {$branch} no está en verde.");
        }
    }

    public function test_cambiar_el_laraimport_sin_volver_a_importar_se_detecta(): void
    {
        $project = $this->project();
        $project->git('checkout', '-q', 'contract-only');

        // Un `string` que pasa a `text`: ninguna lista del modelo cambia, y
        // antes de 9.4.0 validate y verify --strict salían en verde.
        $this->assertSame(['contract-outdated'], $project->failingChecks());

        $project->import();

        $this->assertSame([], $project->failingChecks());
    }

    public function test_un_controlador_con_logica_se_detecta_en_su_rama_y_despues_de_fusionar(): void
    {
        $project = $this->project();
        $project->git('checkout', '-q', 'hand-bad');

        $this->assertContains('controller-shape', $project->failingChecks());

        $project->git('checkout', '-q', 'main');
        $project->merge('order');
        $project->commit('order');
        $project->merge('hand-bad');
        $project->reconcile();
        $project->commit('hand-bad');

        $this->assertContains('controller-shape', $project->failingChecks(), 'Reconciliar no blanquea un controlador con lógica.');
    }

    // ─── Fusionar sin reconciliar ────────────────────────────────────────────

    public function test_dos_ramas_del_mismo_ambito_chocan_en_el_contrato_y_en_el_manifiesto(): void
    {
        $project = $this->project();
        $project->merge('order');
        $project->commit('order');

        $this->assertEqualsCanonicalizing(['.larapack/manifest.json', 'laraimport.json'], $project->merge('note'));
    }

    public function test_comitear_el_conflicto_sin_resolver_no_pasa_por_manifiesto_vacio(): void
    {
        $project = $this->project();
        $project->merge('order');
        $project->commit('order');
        $project->merge('note');
        $project->commit('note, con los marcadores dentro');

        $failing = $project->failingChecks();

        $this->assertContains('manifest-unreadable', $failing);
        $this->assertContains('merge-conflict', $failing);

        // Y el generador no reescribe un manifiesto que no entiende: antes lo
        // daba por vacío y perdía el registro de todo lo generado. (Con el
        // laraimport ya arreglado: con marcadores, ni lo intenta.)
        $project->write('laraimport.json', $project->git('show', 'note:laraimport.json'));
        $before = $project->read('.larapack/manifest.json');
        [$code, $output] = $project->builder('larapack:import', $project->path.'/laraimport.json', '--root='.$project->path, '--force');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('larapack:reconcile', $output);
        $this->assertSame($before, $project->read('.larapack/manifest.json'));
    }

    /**
     * Las formas en que un integrador con prisa resuelve a mano el conflicto.
     * `verify` sólo ve el árbol: si el contrato y el manifiesto que quedan
     * son coherentes entre sí —los dos de la misma rama—, lo que la otra
     * rama generó queda en disco como código que «nadie generó», y eso sólo
     * se ve mirando las dos ramas, que es lo que hace `reconcile --check`.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function manualResolutions(): array
    {
        return [
            'el manifiesto de una rama y el contrato de la otra' => ['--ours', '--theirs', true],
            'todo de la rama que entra: lo de la nuestra queda huérfano' => ['--theirs', '--theirs', false],
            'todo de la nuestra: lo que trajo la otra queda huérfano' => ['--ours', '--ours', false],
        ];
    }

    #[DataProvider('manualResolutions')]
    public function test_resolver_el_conflicto_quedandose_con_un_lado_se_detecta(string $manifest, string $contract, bool $verifySeesIt): void
    {
        $project = $this->project();
        $project->merge('order');
        $project->commit('order');
        $project->merge('note');
        $project->git('checkout', $manifest, '--', '.larapack/manifest.json');
        $project->git('checkout', $contract, '--', 'laraimport.json');
        $project->commit('note, resuelto a mano');

        if ($verifySeesIt) {
            $this->assertContains('contract-outdated', $project->failingChecks());
        }

        // La comprobación que mira las dos ramas lo ve siempre.
        [$code] = $project->reconcile('--check');
        $this->assertNotSame(0, $code);
        $this->assertSame('', trim($project->git('status', '--porcelain')), '--check no escribe.');

        // Reconciliar devuelve al manifiesto lo que se perdió, y entonces
        // `verify` dice lo que de verdad falta: un modelo que está generado y
        // el contrato ya no declara. Devolverlo al contrato es de una persona.
        $project->reconcile();
        $project->commit('reconciliado');

        $this->assertSame(['contract-outdated'], $project->failingChecks());
        [, $report] = $project->verify();
        $this->assertStringContainsString('el laraimport ya no lo declara', (string) json_encode($report['findings'], JSON_UNESCAPED_UNICODE));
    }

    public function test_dos_ramas_que_declaran_la_misma_columna_no_dejan_dos_migraciones_que_la_añaden(): void
    {
        $project = $this->project();
        $project->merge('phone');
        $project->commit('phone');
        $project->merge('phone-again');

        // Sin retirar nada: las dos alteraciones siguen ahí y `migrate` se cae.
        [$code, $report] = $project->reconcile('--keep-migrations');
        $project->commit('phone-again');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('las dos juntas tumban `migrate`', (string) json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertContains('migration-duplicate', $project->failingChecks());
    }

    public function test_reconcile_retira_la_migracion_repetida_que_trae_la_rama_que_entra(): void
    {
        $project = $this->project();
        $project->merge('phone');
        $project->commit('phone');
        $ours = glob($project->path.'/database/migrations/*_alter_customers_table.php') ?: [];

        $project->merge('phone-again');
        [$code] = $project->reconcile();
        $project->commit('phone-again');

        $this->assertSame(0, $code);
        $this->assertSame([], $project->failingChecks());

        // La nuestra, que ya estaba en la rama, no se toca.
        $this->assertFileExists($ours[0]);
        $this->assertSame(1, substr_count(implode('', array_map('file_get_contents', glob($project->path.'/database/migrations/*.php') ?: [])), "->string('phone')"));

        // Y lo que la otra rama declaraba de más (`datatable`) llegó al modelo.
        $this->assertTrue($project->contract()['models'][0]['props'][2]['datatable']);
    }

    public function test_una_migracion_escrita_a_mano_que_repite_una_generada_no_se_retira_sola(): void
    {
        $project = $this->project();
        $project->merge('manual-migration');
        $project->commit('manual-migration');
        $project->merge('phone');

        [$code] = $project->reconcile();
        $project->commit('phone');

        // La repetida es la generada, que entra: se retira; la escrita a mano
        // ya declara la columna y el contrato no pide nada más.
        $this->assertSame(0, $code);
        $this->assertSame([], $project->failingChecks());
        $this->assertTrue($project->has('database/migrations/2030_01_01_000000_add_phone_to_customers_table.php'));
        $this->assertSame([], glob($project->path.'/database/migrations/*_alter_customers_table.php') ?: []);
    }

    public function test_si_la_repetida_ya_estaba_en_nuestra_rama_decide_una_persona(): void
    {
        $project = $this->project();
        $project->merge('phone');
        $project->commit('phone');
        $project->merge('manual-migration');

        // La que entra es la escrita a mano; la generada ya estaba aquí y
        // puede haber corrido: ninguna de las dos se retira sola.
        [$code, $report] = $project->reconcile();
        $project->commit('manual-migration');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('no se retira sola', (string) json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertContains('migration-duplicate', $project->failingChecks());
    }

    // ─── Reconciliar ─────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function orders(): array
    {
        $branches = ['order', 'note', 'phone', 'vip', 'phone-again', 'close-post', 'policy', 'hand-good', 'payment', 'refund'];
        $orders = ['como llegaron' => [$branches], 'al revés' => [array_reverse($branches)]];

        // Tres órdenes más, barajados con semilla: los mismos en cada corrida.
        foreach ([7, 21, 1984] as $seed) {
            $shuffled = $branches;
            mt_srand($seed);

            for ($i = count($shuffled) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
            }

            $orders['barajado con '.$seed] = [$shuffled];
        }

        mt_srand();

        return $orders;
    }

    /**
     * @param  list<string>  $order
     */
    #[DataProvider('orders')]
    public function test_diez_ramas_fusionadas_en_cualquier_orden_quedan_como_generadas_de_cero(array $order): void
    {
        $project = $this->project();

        foreach ($order as $branch) {
            $project->merge($branch);
            [$code, $report] = $project->reconcile();

            $this->assertSame(0, $code, "Reconciliar {$branch} dejó trabajo pendiente:\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->assertSame('', trim($project->git('diff', '--name-only', '--diff-filter=U')), "Tras reconciliar {$branch} quedan archivos sin fusionar.");

            $project->commit("merge {$branch}");

            // Después de CADA fusión, no sólo al final: el integrador empuja
            // en cada una.
            $this->assertSame([], $project->failingChecks(), "Tras fusionar {$branch} (orden: ".implode(', ', $order).') verify --strict no pasa.');
        }

        [$code] = $project->builder('larapack:validate', '--strict', '--root='.$project->path);
        $this->assertSame(0, $code);

        // El contrato tiene lo de todos, una vez.
        $this->assertEqualsCanonicalizing(['Customer', 'Ticket', 'Order', 'Note'], array_column($project->contract()['models'], 'name'));
        $this->assertEqualsCanonicalizing(['name', 'email', 'phone', 'vip'], array_column($project->contract()['models'][0]['props'], 'name'));
        $this->assertEqualsCanonicalizing(['Invoice', 'Payment', 'Refund'], array_column($project->contract('packages/billing')['models'], 'name'));

        // El trabajo a mano de cada agente sigue ahí.
        $this->assertStringContainsString('agente policy: sólo el equipo de ventas', $project->read('app/Policies/CustomerPolicy.php'));
        $this->assertStringContainsString('agente close-post', $project->read('app/Models/Traits/Operations/TicketOperations.php'));
        $this->assertTrue($project->has('app/Http/Controllers/ReportController.php'));

        // Y lo generado es, archivo por archivo, lo que el contrato fusionado
        // genera de cero: sin mezclas de dos versiones ni restos de una rama.
        $this->assertSameAsRegenerated($project, edited: [
            'app/Policies/CustomerPolicy.php',
            'app/Models/Traits/Operations/TicketOperations.php',
        ]);

        // La comprobación de la CI sobre la última fusión, ya reconciliada.
        [$code, $report] = $project->reconcile('--check');
        $this->assertSame(0, $code, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Reconciliar otra vez no cambia nada.
        [$code] = $project->reconcile();
        $this->assertSame(0, $code);
        $this->assertSame('', trim($project->git('status', '--porcelain')));
    }

    public function test_el_mismo_endpoint_declarado_distinto_en_dos_ramas_lo_decide_una_persona(): void
    {
        $project = $this->project();
        $project->merge('close-post');
        $project->commit('close-post');
        $project->merge('close-put');

        [$code, $report] = $project->reconcile();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('/models/Ticket/endpoints/close/method', (string) json_encode($report, JSON_UNESCAPED_SLASHES));

        // El laraimport sigue en conflicto y nada se ha importado a medias
        // con un contrato que nadie eligió.
        $this->assertStringContainsString('<<<<<<<', $project->read('laraimport.json'));

        // La persona decide (aquí, lo nuestro) y vuelve a reconciliar.
        $project->write('laraimport.json', $project->git('show', ':2:laraimport.json'));
        [$code, $report] = $project->reconcile();

        // Queda lo que las dos escribieron en el mismo hueco: eso es suyo.
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('TicketOperations.php sigue con un conflicto de git', (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $project->write('app/Models/Traits/Operations/TicketOperations.php', $project->git('show', ':2:app/Models/Traits/Operations/TicketOperations.php'));
        [$code] = $project->reconcile();
        $project->commit('close-put');

        $this->assertSame(0, $code);
        $this->assertSame([], $project->failingChecks());
        $this->assertStringContainsString("Route::post('close'", $project->read('routes/api/models/ticket.php'));
        $this->assertStringNotContainsString("Route::put('close'", $project->read('routes/api/models/ticket.php'));
    }

    public function test_dos_agentes_en_el_mismo_hueco_es_un_conflicto_de_personas_y_verify_lo_dice(): void
    {
        $project = $this->project();
        $project->merge('policy');
        $project->commit('policy');
        $project->merge('policy-too');

        [$code, $report] = $project->reconcile();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('app/Policies/CustomerPolicy.php sigue con un conflicto de git', (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Reconciliar no elige por nadie: las dos versiones siguen en el archivo.
        $this->assertStringContainsString('agente policy: sólo el equipo de ventas', $project->read('app/Policies/CustomerPolicy.php'));
        $this->assertStringContainsString('agente policy-too: sólo administración', $project->read('app/Policies/CustomerPolicy.php'));

        $project->commit('con el conflicto dentro');

        $this->assertContains('merge-conflict', $project->failingChecks());
    }

    public function test_reconcile_arregla_una_fusion_ya_comiteada_con_un_manifiesto_que_miente(): void
    {
        $project = $this->project();

        foreach (['order', 'phone'] as $branch) {
            $project->merge($branch);
            $project->reconcile();
            $project->commit($branch);
        }

        // El integrador resuelve a mano: el contrato bien (con las dos
        // columnas), pero el manifiesto y los archivos de la rama que entra.
        $contract = $project->contract();
        $contract['models'][0]['props'][] = ['name' => 'vip', 'type' => 'boolean'];

        $project->merge('vip');
        $project->git('checkout', '--theirs', '--', '.larapack/manifest.json', 'app/Models/Customer.php', 'database/factories/CustomerFactory.php');
        $project->write('laraimport.json', SwarmProject::json($contract));
        $project->commit('vip, resuelto a mano');

        $this->assertNotSame([], $project->failingChecks());

        [$code] = $project->reconcile();
        $project->commit('reconciliado');

        $this->assertSame(0, $code);
        $this->assertSame([], $project->failingChecks());
        $this->assertSameAsRegenerated($project);
    }

    /**
     * Los archivos de idioma son uno por ámbito, los comparten todos los
     * modelos y no están en el manifiesto: cada rama añade sus claves al
     * final y chocan siempre. Lo encontró la segunda pasada de la auditoría:
     * quedaban con los marcadores dentro y ni `reconcile` ni `verify` lo veían.
     */
    public function test_con_interfaz_los_archivos_de_idioma_se_fusionan_clave_a_clave(): void
    {
        $seed = $this->clones[] = SwarmProject::seedInterface();
        $project = $this->clones[] = $seed->clone();

        $project->merge('ui-order');
        $project->reconcile();
        $project->commit('ui-order');

        $conflicts = $project->merge('ui-note');
        $locales = ['resources/react/src/locales/en.json', 'resources/react/src/locales/es.json', 'resources/vue/src/locales/en.json', 'resources/vue/src/locales/es.json'];

        $this->assertEqualsCanonicalizing(['.larapack/manifest.json', 'laraimport.json', ...$locales], $conflicts);

        // Sin reconciliar, comiteado tal cual: verify lo dice de cada uno.
        $unreconciled = $this->clones[] = $seed->clone();
        $unreconciled->merge('ui-order');
        $unreconciled->commit('ui-order');
        $unreconciled->merge('ui-note');
        $unreconciled->commit('ui-note, con los marcadores dentro');
        [, $report] = $unreconciled->verify();
        $flagged = array_column(array_filter($report['findings'], fn (array $finding): bool => $finding['check'] === 'merge-conflict'), 'file');
        $this->assertSame([], array_diff($locales, $flagged));

        [$code, $report] = $project->reconcile();
        $project->commit('ui-note');

        $this->assertSame(0, $code, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $project->failingChecks());

        // Las claves de los dos modelos, y las mismas que generando de cero.
        $fresh = $this->clones[] = $project->regenerated();

        foreach ($locales as $locale) {
            $merged = (array) json_decode($project->read($locale), true);
            $expected = (array) json_decode($fresh->read($locale), true);

            $this->assertArrayHasKey('Order', $merged, $locale);
            $this->assertArrayHasKey('Note', $merged, $locale);

            ksort($merged);
            ksort($expected);
            $this->assertSame($expected, $merged, $locale);
        }

        $this->assertSameAsRegenerated($project, edited: $locales);
    }

    public function test_una_traduccion_que_las_dos_ramas_escribieron_distinto_la_decide_una_persona(): void
    {
        $seed = $this->clones[] = SwarmProject::seedInterface();
        $locale = 'resources/react/src/locales/es.json';

        foreach (['ui-order' => 'Cliente', 'ui-note' => 'Clienta'] as $branch => $translation) {
            $seed->git('checkout', '-q', $branch);
            $seed->replaceIn($locale, '/"Customer": ""/', '"Customer": "'.$translation.'"');
            $seed->commit("{$branch}: traduce Customer");
        }

        $seed->git('checkout', '-q', 'main');
        $project = $this->clones[] = $seed->clone();
        $project->merge('ui-order');
        $project->reconcile();
        $project->commit('ui-order');
        $project->merge('ui-note');

        [$code, $report] = $project->reconcile();
        $text = (string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString($locale.': las dos ramas tradujeron distinto', $text);
        $this->assertStringContainsString('Cliente', $text);
        $this->assertStringContainsString('Clienta', $text);
    }

    public function test_el_ensayo_no_escribe_nada(): void
    {
        $project = $this->project();
        $project->merge('order');
        $project->commit('order');
        $project->merge('note');

        $before = $project->git('status', '--porcelain');
        $manifest = $project->read('.larapack/manifest.json');

        [$code, $report] = $project->reconcile('--dry-run');

        $this->assertSame(0, $code);
        $this->assertTrue($report['dryRun']);
        $this->assertSame($before, $project->git('status', '--porcelain'));
        $this->assertSame($manifest, $project->read('.larapack/manifest.json'));
    }

    /**
     * Todo lo que el contrato genera de cero está en el proyecto con el mismo
     * contenido, salvo los huecos que alguien editó y las migraciones, cuyo
     * nombre lleva la fecha (de esas se compara que haya las mismas tablas).
     *
     * @param  list<string>  $edited
     */
    private function assertSameAsRegenerated(SwarmProject $project, array $edited = []): void
    {
        $fresh = $this->clones[] = $project->regenerated();
        $different = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fresh->path, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($fresh->path) + 1));

            if (str_contains($relative, '.larapack/') || str_contains($relative, 'database/migrations/') || in_array($relative, $edited, true)) {
                continue;
            }

            if (! $project->has($relative)) {
                $different[] = "falta {$relative}";
            } elseif ($project->read($relative) !== $fresh->read($relative)) {
                $different[] = "difiere {$relative}";
            }
        }

        $this->assertSame([], $different, 'Lo fusionado no es lo que genera de cero el contrato fusionado.');

        $tables = fn (SwarmProject $in): array => array_values(array_unique(array_filter(array_map(
            fn (string $path): ?string => preg_match('/_create_(\w+)_table\.php$/', $path, $match) === 1 ? $match[1] : null,
            glob($in->path.'/{,packages/*/}database/migrations/*.php', GLOB_BRACE) ?: []
        ))));

        $this->assertEqualsCanonicalizing($tables($fresh), $tables($project));
    }
}
