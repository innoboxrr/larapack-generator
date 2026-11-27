<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign infer`: un laraimport BORRADOR desde el código, sin tocar
 * el código, sin sobrescribir el contrato, diciendo de dónde salió cada cosa
 * y lo que no se pudo inferir. Los dos perfiles de los proyectos reales: sin
 * contrato (brandai) y con contrato y código a mano al lado (expediente).
 */
final class RealignInferTest extends TestCase
{
    use RealignProject;

    public function test_sin_contrato_escribe_un_borrador_que_valida_y_no_toca_el_codigo(): void
    {
        $this->projectWithoutContract();
        $step = $this->stepOfType('no-contract');
        $before = $this->snapshot();

        $simulated = $this->realign('infer', ['targets' => [$step], '--dry-run' => true]);
        $this->assertTrue($simulated['dry_run']);
        $this->assertStringContainsString('escribiría', $simulated['fixed'][0]['reason']);
        $this->assertFalse($this->project->has('laraimport.inferred.json'), 'Simular escribió el borrador.');

        $document = $this->realign('infer', ['targets' => [$step]]);

        $this->assertSame('laraimport.inferred.json', $document['fixed'][0]['file']);
        $this->assertFalse($this->project->has('laraimport.json'), 'infer escribió el contrato sin pedirlo.');
        $this->assertSame($before, $this->snapshot(), 'infer cambió código del proyecto.');

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:validate', ['jsonPath' => $this->project->path.'/laraimport.inferred.json', '--format' => 'json']), $this->lastOutput);
    }

    public function test_el_borrador_conserva_medidas_indices_llaves_relaciones_y_rutas(): void
    {
        $this->projectWithoutContract();
        $this->realign('infer', ['targets' => [$this->stepOfType('no-contract')]]);
        $draft = json_decode($this->project->read('laraimport.inferred.json'), true);
        $models = array_column($draft['models'], null, 'name');

        $workspace = array_column($models['Workspace']['props'], null, 'name');
        $this->assertSame(100, $workspace['name']['length']);
        $this->assertSame([12, 4], [$workspace['credits']['precision'], $workspace['credits']['scale']]);
        $this->assertSame('decimal:4', $workspace['credits']['cast']);
        $this->assertTrue($workspace['settings']['secret'], '$hidden es secret.');
        $this->assertArrayNotHasKey('default', $workspace['settings'], 'Un default que es una expresión no se inventa.');
        $this->assertSame([['columns' => ['owner_id', 'slug'], 'unique' => true]], $models['Workspace']['indexes']);
        $this->assertSame([['type' => 'hasMany', 'name' => 'sites', 'related' => 'Site']], $models['Workspace']['load_relations']);

        $site = array_column($models['Site']['props'], null, 'name');
        $this->assertSame(['name' => 'workspace_id', 'type' => 'foreignId', 'constraint' => 'workspaces', 'on_delete' => 'cascade', 'on_update' => 'restrict'], $site['workspace_id']);
        $this->assertSame(255, $site['subdomain']['length']);
        $this->assertFalse($site['ssl_status']['fillable'], 'Lo que $guarded protege no es asignable.');
        $this->assertContains(['columns' => ['subdomain'], 'unique' => true], $models['Site']['indexes']);

        $comment = array_column($models['Comment']['props'], null, 'name');
        $this->assertSame('morphs', $comment['commentable']['type']);
        $this->assertSame(['draft' => 'draft', 'published' => 'published'], $comment['status']['enum']);
        $this->assertTrue($comment['seen_at']['use_current']);
        $this->assertSame('set null', $comment['site_id']['on_delete']);
        $this->assertSame('unsignedSmallInteger', $comment['position']['type']);
        $this->assertContains(['columns' => ['site_id', 'created_at'], 'name' => 'comments_site_recent_index'], $models['Comment']['indexes']);
        $this->assertContains(['type' => 'morphTo', 'name' => 'commentable'], $models['Comment']['load_relations'], 'El morphTo del trait de relaciones.');
        $this->assertArrayNotHasKey('retired_reason', $comment, 'Lo que pone un bucle no se sabe si existe: no se infiere.');

        $this->assertSame([['name' => 'site_tag', 'props' => [
            ['name' => 'site_id', 'type' => 'foreignId', 'constraint' => 'sites', 'on_delete' => 'cascade', 'on_update' => 'restrict'],
            ['name' => 'tag_id', 'type' => 'foreignId', 'constraint' => 'tags', 'on_delete' => 'restrict', 'on_update' => 'restrict'],
        ]]], $draft['pivots']);

        $controllers = array_column($draft['controllers'], null, 'name');
        $this->assertSame([
            ['name' => 'dictate', 'method' => 'post', 'uri' => 'dictate', 'route_name' => 'dictate', 'authorize' => 'authenticated'],
            ['name' => 'today', 'method' => 'get', 'uri' => 'today', 'route_name' => 'today', 'authorize' => 'authenticated'],
            ['name' => 'scribeIndex', 'method' => 'get', 'uri' => 'index', 'route_name' => 'list', 'authorize' => 'authenticated'],
        ], $controllers['Scribe']['endpoints']);
        $this->assertSame([['name' => 'enviar', 'method' => 'post', 'uri' => 'contacto', 'route_name' => 'contacto', 'public' => true, 'middleware' => ['throttle:6,1']]], $controllers['Publico']['endpoints']);
        $this->assertSame([['name' => 'publish', 'method' => 'post', 'uri' => '{site_id}/publish', 'route_name' => 'publish', 'target' => 'record']], $models['Site']['endpoints']);
    }

    public function test_el_informe_dice_de_donde_salio_cada_cosa_lo_que_se_pierde_y_lo_que_hay_que_decidir(): void
    {
        $this->projectWithoutContract();
        $document = $this->realign('infer', ['targets' => [$this->stepOfType('no-contract')]]);
        $report = json_decode($this->project->read('.larapack/realign/infer/app.json'), true);

        $this->assertSame('.larapack/realign/infer/app.json', $document['fixed'][0]['report']);
        $this->assertSame(1, $report['format_version']);
        $this->assertTrue($report['validation']['ok']);
        $this->assertContains(['model' => 'Site', 'prop' => 'subdomain', 'key' => 'schema', 'source' => '2026_02_24_000016_create_sites_table.php', 'confidence' => 'high'], $report['provenance']);
        $this->assertContains(['model' => 'Comment', 'prop' => 'commentable', 'key' => 'type', 'source' => '2026_03_01_000001_create_comments_table.php: commentable_type y commentable_id', 'confidence' => 'medium'], $report['provenance']);

        $lost = array_column($report['lost'], 'what');
        $this->assertContains("el default DB::raw('(JSON_OBJECT())'), que es una expresión", $lost);
        $this->assertContains('->comment()', $lost);
        $schema = array_values(array_filter($report['not_inferred'], fn (array $item): bool => $item['key'] === 'schema'));
        $this->assertSame('Comment', $schema[0]['model']);
        $this->assertStringContainsString('2026_09_17_170120_comment_retired_columns.php', $schema[0]['reason'], 'Lo que añade un bucle no se infiere, y se dice dónde.');

        $decisions = array_column($report['decisions'], 'subject', 'type');
        $this->assertSame('Tag', $decisions['duplicate-model-name']);
        $this->assertSame('api.app.panel', $decisions['route-not-preservable']);
        $this->assertSame('routes/web.php', $decisions['route-file-prefix']);
        $this->assertContains(['model' => 'Site', 'key' => 'model', 'reason' => 'app/Models/Site.php: visitsThroughAssets(): hasManyThrough, que el laraimport no sabe declarar'], $report['not_inferred']);
        $this->assertContains('display', array_column($report['not_inferred'], 'key'));
        $this->assertContains('(rutas)', array_column($report['lost'], 'model'), 'La ruta con closure se dice.');
    }

    public function test_con_write_contract_escribe_el_contrato_solo_si_no_existe(): void
    {
        $this->projectWithoutContract();
        $step = $this->stepOfType('no-contract');

        $this->realign('infer', ['targets' => [$step], '--option' => ['write=contract']]);
        $this->assertTrue($this->project->has('laraimport.json'));
        $this->assertFalse($this->project->has('laraimport.inferred.json'));

        // Con el contrato ya escrito, nunca se pisa: el borrador va aparte.
        file_put_contents($this->project->path.'/laraimport.json', '{"models": [{"name": "Tag", "props": [{"name": "label", "type": "string"}]}]}');
        $this->realign('infer', ['targets' => [$step], '--option' => ['write=contract'], '--allow-dirty' => true]);

        $this->assertSame('{"models": [{"name": "Tag", "props": [{"name": "label", "type": "string"}]}]}', $this->project->read('laraimport.json'));
        $draft = json_decode($this->project->read('laraimport.inferred.json'), true);
        $this->assertSame('Tag', $draft['models'][0]['name'], 'Lo declarado se queda como está, el primero.');
        $this->assertSame(['label'], array_column($draft['models'][0]['props'], 'name'));
        $this->assertContains('Workspace', array_column($draft['models'], 'name'));
    }

    public function test_adoptar_el_borrador_no_crea_ninguna_tabla_que_ya_existe(): void
    {
        $this->projectWithoutContract();
        $this->realign('infer', ['targets' => [$this->stepOfType('no-contract')]]);
        rename($this->project->path.'/laraimport.inferred.json', $this->project->path.'/laraimport.json');
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--dry-run' => true, '--format' => 'json']), $this->lastOutput);
        $plan = json_decode($this->lastOutput, true);
        $this->assertIsArray($plan, $this->lastOutput);

        // Ni una creación ni una alteración: las tablas (también el pivote)
        // ya las crean sus migraciones, y el borrador dice lo que dicen ellas.
        $migrations = array_values(array_filter($plan['files'], fn (array $file): bool => str_starts_with((string) $file['file'], 'database/migrations/')));
        $written = array_values(array_filter($migrations, fn (array $file): bool => $file['action'] !== 'skipped'));

        $this->assertNotSame([], $migrations);
        $this->assertSame([], $written, "Adoptar el borrador escribiría migraciones:\n".json_encode($written, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_con_contrato_mide_lo_inferido_contra_lo_declarado_y_anade_solo_lo_que_falta(): void
    {
        $this->generatedPackage();
        file_put_contents($this->project->path.'/src/Models/Badge.php', "<?php\n\nnamespace Acme\\Blog\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Badge extends Model\n{\n    protected \$fillable = ['label'];\n}\n");
        file_put_contents($this->project->path.'/database/migrations/2099_01_01_000000_create_badges_table.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('badges', function (Blueprint \$table) {\n            \$table->id();\n            \$table->string('label', 40)->unique();\n            \$table->timestamps();\n        });\n    }\n};\n");
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();
        $contract = $this->project->read('laraimport.json');

        $this->realign('infer', ['targets' => [$this->stepOfType('unmanaged-models')]]);

        $this->assertSame($contract, $this->project->read('laraimport.json'), 'infer tocó el contrato.');
        $draft = json_decode($this->project->read('laraimport.inferred.json'), true);
        $declared = json_decode($contract, true);
        $this->assertSame([...array_column($declared['models'], 'name'), 'Badge'], array_column($draft['models'], 'name'));
        $this->assertSame([['name' => 'label', 'type' => 'string', 'length' => 40]], end($draft['models'])['props']);

        $report = json_decode($this->project->read('.larapack/realign/infer/app.json'), true);
        $this->assertSame(['models/Badge'], $report['added']);
        $this->assertTrue($report['validation']['ok'], json_encode($report['validation']['findings']) ?: '');
    }

    public function test_sobre_un_proyecto_generado_lo_inferido_coincide_con_el_contrato(): void
    {
        $this->generatedPackage();
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();

        // Un paso no-contract a mano: lo que se mide es el inferidor contra
        // un contrato que generó todo el código.
        $plan = $this->plan();
        $plan['steps'][] = ['id' => 'medir', 'type' => 'no-contract', 'category' => 'decision', 'scope' => ['package' => null, 'path' => '.'], 'files' => [], 'details' => [], 'done_when' => ['kind' => 'decision'], 'status' => 'pending', 'history' => []];
        file_put_contents($this->project->path.'/.larapack/realign/plan.json', json_encode($plan));

        $this->realign('infer', ['targets' => ['medir']]);
        $stats = json_decode($this->project->read('.larapack/realign/infer/app.json'), true)['comparison']['stats'];

        $this->assertSame($stats['models'], $stats['models_found']);
        $this->assertSame($stats['props'], $stats['props_found']);

        foreach (['type', 'nullable', 'constraint', 'default'] as $field) {
            $this->assertSame($stats['props'], $stats[$field.'_equal'], "{$field} no coincide en todas las props.");
        }

        $this->assertSame($stats['relations'], $stats['relations_found']);
    }

    private function projectWithoutContract(): void
    {
        $project = $this->useProject(FakeProject::application());
        self::copyDirectory(dirname(__DIR__).'/Fixtures/infer/sin-contrato', $project->path);
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();
    }

    private function stepOfType(string $type): string
    {
        foreach ($this->plan()['steps'] as $step) {
            if ($step['type'] === $type) {
                return $step['id'];
            }
        }

        Assert::fail("No hay un paso {$type}: ".implode(', ', array_column($this->plan()['steps'], 'id')));
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];

        foreach ($this->project->phpFiles() as $file) {
            $files[$file] = (string) md5_file($this->project->path.'/'.$file);
        }

        ksort($files);

        return $files;
    }

    private static function copyDirectory(string $from, string $to): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $target = $to.'/'.substr((string) $item, strlen($from) + 1);

            if ($item->isDir()) {
                @mkdir($target, 0777, true);
            } else {
                copy((string) $item, $target);
            }
        }
    }
}
