<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\GeneratorVersion;
use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\Apply\PhpMembers;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * `larapack:realign apply`: los pasos deterministas que reescriben, con un
 * proyecto generado de verdad y desviado como los reales. Cada uno con su
 * simulación, su `done_when` y lo que no toca.
 */
final class RealignApplyTest extends TestCase
{
    use RealignProject;

    private const SHOW = 'src/Http/Requests/Post/ShowRequest.php';

    private const POLICY = 'src/Policies/PostPolicy.php';

    private const TEST = 'tests/Feature/Models/PostEndpointsTest.php';

    private const MODEL = 'src/Models/Post.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
    }

    public function test_regenera_lo_que_nadie_edito_y_quedo_atras(): void
    {
        $original = $this->project->read(self::SHOW);
        $this->olderTemplate(self::SHOW, '        //', '        // la plantilla de antes');
        $this->prepare();

        $this->assertSame('version_delta', $this->entry(self::SHOW)['class'] ?? null);

        $simulated = $this->realign('apply', ['targets' => ['regenerate@.'], '--dry-run' => true]);
        $this->assertStringContainsString('-        // la plantilla de antes', $simulated['fixed'][0]['diff']);
        $this->assertStringContainsString('la plantilla de antes', $this->project->read(self::SHOW));

        $this->realign('apply', ['targets' => ['regenerate@.']]);

        $this->assertSame($original, $this->project->read(self::SHOW));
        $entry = (new Manifest($this->project->path))->entryOf(self::SHOW);
        $this->assertSame(GeneratorVersion::current(), $entry['generator']);
        $this->assertSame(Manifest::GENERATED, $entry['state']);
        $this->assertTrue($this->realign('check', ['targets' => ['regenerate@.']])['done']);
    }

    public function test_no_regenera_lo_que_alguien_edito_despues_del_diagnostico(): void
    {
        $this->olderTemplate(self::SHOW, '        //', '        // la plantilla de antes');
        $this->prepare();
        $this->edit(self::SHOW, '// la plantilla de antes', '// y además lo edité');
        $this->commitAll();

        $document = $this->realign('apply', ['targets' => ['regenerate@.']], Command::FAILURE);

        $this->assertStringContainsString('Ya no es lo que se regenera sin perder nada', $document['skipped'][0]['reason']);
        $this->assertStringContainsString('y además lo edité', $this->project->read(self::SHOW));
    }

    public function test_crea_lo_que_el_contrato_pide_y_nunca_se_genero(): void
    {
        $content = $this->project->read(self::POLICY);
        unlink($this->project->path.'/'.self::POLICY);
        $this->changeManifest(function (array $manifest): array {
            unset($manifest['models']['Post']['files'][self::POLICY]);

            return $manifest;
        });
        $this->prepare();

        $this->realign('apply', ['targets' => ['regenerate@.']]);

        $this->assertSame($content, $this->project->read(self::POLICY));
        $this->assertSame('Policy/PolicyTemplate.txt', (new Manifest($this->project->path))->entryOf(self::POLICY)['stub'] ?? null);
    }

    public function test_restaura_lo_borrado_solo_si_se_decide(): void
    {
        $content = $this->project->read(self::POLICY);
        unlink($this->project->path.'/'.self::POLICY);
        $this->prepare();

        $id = 'missing@.';
        $undecided = $this->realign('apply', ['targets' => [$id]], Command::FAILURE);
        $this->assertStringContainsString('falta decidir', $undecided['skipped'][0]['reason']);

        $this->realign('decide', ['targets' => [$id, 'restore']]);
        $this->realign('apply', ['targets' => [$id]]);

        $this->assertSame($content, $this->project->read(self::POLICY));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
    }

    public function test_paga_la_deuda_de_fix_con_las_mismas_correcciones(): void
    {
        $trait = 'src/Models/Traits/Relations/PostRelations.php';
        $this->removeMethod($trait, 'user');
        $this->prepare();

        $id = 'fix:relation-missing@.';
        $this->assertSame('larapack:fix relation-missing --package=app', $this->step($id)['command']);

        $this->realign('apply', ['targets' => [$id]]);

        $this->assertStringContainsString('public function user()', $this->project->read($trait));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
    }

    public function test_completa_el_test_de_endpoints_sin_tocar_lo_escrito_a_mano(): void
    {
        $original = $this->project->read(self::TEST);

        foreach (['test_post_cada_accion_requiere_sesion', 'test_post_cada_accion_pregunta_a_la_politica', 'test_post_index_pasa_por_su_managed_filter'] as $method) {
            $this->removeMethod(self::TEST, $method);
        }

        $this->edit(self::TEST, "use Innoboxrr\\SearchSurge\\Search\\Support\\FilterRegistry;\n", '');
        $this->addMethod(self::TEST, "public function test_lo_mio(): void\n    {\n        \$this->assertTrue(true);\n    }");
        $this->prepare();

        $id = 'endpoint-tests@.';
        $this->assertSame('deterministic', $this->step($id)['category']);
        $this->assertSame('larapack:realign apply endpoint-tests@.', $this->step($id)['command']);

        // next da la orden y, para lo que salte, el playbook rellenado.
        $next = $this->realign('next');
        $this->assertSame($id, $next['step']['id']);
        $this->assertTrue($next['how']['available']);
        $this->assertSame('endpoint-tests', $next['how']['fallback']['playbook']);
        $this->assertStringContainsString('larapack:realign apply endpoint-tests@. --dry-run', $next['how']['fallback']['instructions']);

        $this->realign('apply', ['targets' => [$id]]);

        $content = $this->project->read(self::TEST);
        $members = PhpMembers::of($content);
        $this->assertNotNull($members);

        foreach (['test_lo_mio', 'test_post_cada_accion_requiere_sesion', 'test_post_cada_accion_pregunta_a_la_politica', 'test_post_index_pasa_por_su_managed_filter', 'test_post_index_endpoint'] as $method) {
            $this->assertArrayHasKey($method, $members->methods, "Falta {$method}.");
        }

        $this->assertStringContainsString('use Innoboxrr\\SearchSurge\\Search\\Support\\FilterRegistry;', $content);
        $this->assertSame(PhpMembers::of($original)?->methods['test_post_cada_accion_requiere_sesion']['code'], $members->methods['test_post_cada_accion_requiere_sesion']['code']);
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);

        // Otra vez: ya no falta nada.
        $again = $this->realign('apply', ['targets' => [$id], '--allow-dirty' => true], Command::FAILURE);
        $this->assertStringContainsString('ya está hecho', $again['error']);
    }

    public function test_mueve_el_trait_que_solo_usan_requests_con_sus_use(): void
    {
        $trait = 'src/Http/Controllers/Concerns/ResolvesPost.php';
        $this->put($trait, "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers\\Concerns;\n\ntrait ResolvesPost\n{\n    protected function resolvePost(): int\n    {\n        return 1;\n    }\n}\n");
        $this->edit(self::SHOW, "use Illuminate\\Validation\\Rule;\n", "use Illuminate\\Validation\\Rule;\nuse Acme\\Blog\\Http\\Controllers\\Concerns\\ResolvesPost;\n");
        $this->edit(self::SHOW, "class ShowRequest extends FormRequest\n{\n", "class ShowRequest extends FormRequest\n{\n    use ResolvesPost;\n");
        $this->prepare();

        $id = 'controller-concern@.';
        $this->assertSame('deterministic', $this->step($id)['category']);

        $simulated = $this->realign('apply', ['targets' => [$id], '--dry-run' => true]);
        $this->assertSame('src/Http/Requests/Concerns/ResolvesPost.php', $simulated['fixed'][0]['to']);
        $this->assertFileExists($this->project->path.'/'.$trait);

        $this->realign('apply', ['targets' => [$id]]);

        $this->assertFileDoesNotExist($this->project->path.'/'.$trait);
        $this->assertStringContainsString('namespace Acme\\Blog\\Http\\Requests\\Concerns;', $this->project->read('src/Http/Requests/Concerns/ResolvesPost.php'));
        $this->assertStringContainsString('use Acme\\Blog\\Http\\Requests\\Concerns\\ResolvesPost;', $this->project->read(self::SHOW));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
    }

    public function test_no_mueve_un_trait_que_se_nombra_en_una_cadena(): void
    {
        $this->put('src/Http/Controllers/Concerns/ResolvesPost.php', "<?php\n\nnamespace Acme\\Blog\\Http\\Controllers\\Concerns;\n\ntrait ResolvesPost\n{\n    protected function resolvePost(): int\n    {\n        return 1;\n    }\n}\n");
        $this->put('config/traits.php', "<?php\n\nreturn ['Acme\\\\Blog\\\\Http\\\\Controllers\\\\Concerns\\\\ResolvesPost'];\n");
        $this->prepare();

        $document = $this->realign('apply', ['targets' => ['controller-concern@.']], Command::FAILURE);

        $this->assertStringContainsString('config/traits.php: lo nombra en una cadena', $document['skipped'][0]['reason']);
        $this->assertFileExists($this->project->path.'/src/Http/Controllers/Concerns/ResolvesPost.php');
    }

    public function test_completa_las_listas_de_un_modelo_editado(): void
    {
        $this->edit(self::MODEL, "protected \$fillable = [\n        'title', 'published', 'user_id'", "protected \$fillable = [\n        'title', 'user_id'");
        $this->prepare();

        $id = 'model-lists@.';
        $this->assertSame('deterministic', $this->step($id)['category']);

        $this->realign('apply', ['targets' => [$id]]);

        $this->assertMatchesRegularExpression("/protected \\\$fillable = \\[[^\\]]*'published'/", $this->project->read(self::MODEL));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
    }

    public function test_fija_en_el_contrato_la_cascada_de_las_migraciones(): void
    {
        $migration = $this->migration('create_posts_table');
        $this->edit($migration, "->onUpdate('restrict')->onDelete('restrict')", "->onUpdate('cascade')->onDelete('cascade')");
        $before = $this->project->read('laraimport.json');
        $this->prepare();

        $id = 'pin-foreign-keys@.';
        $step = $this->step($id);
        $this->assertSame('deterministic', $step['category']);
        $this->assertSame([['model' => 'Post', 'column' => 'user_id', 'on_delete' => 'cascade', 'on_update' => 'cascade']], $step['done_when']['columns']);

        $this->realign('apply', ['targets' => [$id]]);

        $after = $this->project->read('laraimport.json');
        $prop = $this->propOf(json_decode($after, true), 'Post', 'user_id');
        $this->assertSame('cascade', $prop['on_delete']);
        $this->assertSame('cascade', $prop['on_update']);
        // Sólo cambian las líneas de la columna: lo demás queda byte a byte.
        $this->assertSame(2, count(explode("\n", $after)) - count(explode("\n", $before)));
        $this->assertSame($before, preg_replace('/,\n\s*"on_delete": "cascade",\n\s*"on_update": "cascade"/', '', $after));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);
        // Y la migración no se toca.
        $this->assertStringContainsString("->onDelete('cascade')", $this->project->read($migration));
    }

    public function test_deterministic_hace_todos_los_deterministas_y_sin_ids_hay_que_pedirlo(): void
    {
        file_put_contents($this->project->path.'/src/Http/Controllers/PostController.php', str_replace('    ', "\t", $this->project->read('src/Http/Controllers/PostController.php')));
        $this->olderTemplate(self::SHOW, '        //', '        // la plantilla de antes');
        $this->removeMethod('src/Models/Traits/Relations/PostRelations.php', 'user');
        $this->prepare();

        $refused = $this->realign('apply', [], Command::FAILURE);
        $this->assertStringContainsString('--deterministic', $refused['error']);

        $document = $this->realign('apply', ['--deterministic' => true]);

        $this->assertSame(['adopt@.', 'regenerate@.', 'fix:relation-missing@.'], $document['steps']);

        foreach ($document['steps'] as $id) {
            $this->assertTrue($this->realign('check', ['targets' => [$id]])['done'], "{$id} no quedó hecho.");
        }

        $this->realign('diagnose');
        $plan = $this->realign('plan');
        $this->assertSame([], array_values(array_filter(
            $this->plan()['steps'],
            fn (array $step): bool => $step['category'] === 'deterministic' && $step['status'] !== 'done'
        )), (string) json_encode($plan));
    }

    private function prepare(): void
    {
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();
    }

    /**
     * Deja un archivo como lo habría escrito una versión anterior: distinto,
     * y con el manifiesto diciendo que nadie lo editó.
     */
    private function olderTemplate(string $file, string $search, string $replace): void
    {
        $this->edit($file, $search, $replace);
        $hash = hash('sha256', $this->project->read($file));

        $this->changeManifest(function (array $manifest) use ($file, $hash): array {
            $manifest['models']['Post']['files'][$file]['hash'] = $hash;
            $manifest['models']['Post']['files'][$file]['generator'] = '8.0.0';

            return $manifest;
        });
    }

    private function removeMethod(string $file, string $method): void
    {
        $content = $this->project->read($file);
        $members = PhpMembers::of($content);
        $this->assertNotNull($members);
        $found = $members->methods[strtolower($method)];

        file_put_contents($this->project->path.'/'.$file, substr($content, 0, $found['start']).ltrim(substr($content, $found['end']), "\n"));
    }

    private function addMethod(string $file, string $method): void
    {
        $content = $this->project->read($file);
        file_put_contents($this->project->path.'/'.$file, rtrim(substr($content, 0, (int) strrpos($content, '}')))."\n\n    {$method}\n}\n");
    }

    private function put(string $file, string $content): void
    {
        if (! is_dir(dirname($this->project->path.'/'.$file))) {
            mkdir(dirname($this->project->path.'/'.$file), 0777, true);
        }

        file_put_contents($this->project->path.'/'.$file, $content);
    }

    private function migration(string $suffix): string
    {
        $found = glob($this->project->path.'/database/migrations/*_'.$suffix.'.php') ?: [];
        $this->assertCount(1, $found);

        return 'database/migrations/'.basename($found[0]);
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function propOf(array $contract, string $model, string $column): array
    {
        foreach ($contract['models'] as $entry) {
            foreach ($entry['name'] === $model ? $entry['props'] : [] as $prop) {
                if ($prop['name'] === $column) {
                    return $prop;
                }
            }
        }

        $this->fail("{$model}.{$column} no está en el contrato.");
    }
}
