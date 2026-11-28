<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\SpaFallback;
use Innoboxrr\LarapackGenerator\Tests\Support\FakeProject;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que encontró una prueba de autonomía sobre la copia de un proyecto 7.x:
 *
 *  - código legítimo en prepareForValidation(), que la plantilla deja vacío
 *    para el usuario, salía `edited_outside`;
 *  - diagnose clasificaba una migración `format_only`, adopt la anotaba y
 *    `check` decía que era `migration_history`: la clasificación cambiaba
 *    con lo que adopt escribe;
 *  - `fix route-closure` creaba SpaController y el diagnóstico siguiente lo
 *    contaba como código HTTP sin gestionar, y el paso salía con closures
 *    `skipped` aunque eran de otro paso;
 *  - el resumen decía «N archivos, M cumplen» junto a un `identical` mayor
 *    que M, sin decir por qué.
 */
final class RealignClassifyTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    public function test_normalizar_el_payload_en_prepare_for_validation_es_escribir_en_un_hueco(): void
    {
        $this->generatedPackage();
        $request = 'src/Http/Requests/Post/CreateRequest.php';
        $this->edit($request, "    protected function prepareForValidation(): void\n    {\n        //\n    }", "    protected function prepareForValidation(): void\n    {\n        \$this->merge(['title' => trim((string) \$this->input('title'))]);\n    }");
        $this->edit($request, "    protected function passedValidation(): void\n    {\n        //\n    }", "    protected function passedValidation(): void\n    {\n        \$this->replace(\$this->validated());\n    }");
        $this->prepare();

        // hole_edited cumple: no entra en el informe.
        $this->assertNull($this->entry($request));

        // authorize() sigue fuera del hueco.
        $this->edit($request, 'public function authorize(): bool', "public function authorize(): bool\n    {\n        return true;\n    }\n\n    public function authorizeOld(): bool");
        $this->realign('diagnose');
        $this->assertSame('edited_outside', $this->entry($request)['class'] ?? null);
    }

    public function test_una_migracion_con_otros_comentarios_es_historia_y_no_se_adopta(): void
    {
        $this->generatedPackage();
        $migration = $this->migration('create_posts_table');
        $this->edit($migration, 'return new class', "// Escrita por la versión 7.\nreturn new class");
        $this->staleHash($migration);
        $this->prepare();

        $this->assertSame('migration_history', $this->entry($migration)['class'] ?? null);
        $this->assertNull($this->entry($migration)['type']);
        $this->assertNotContains($migration, $this->stepFiles('adopt@.'));
    }

    public function test_lo_que_adopt_anota_check_y_diagnose_lo_siguen_clasificando_igual(): void
    {
        $this->generatedPackage();
        // Sólo formato (un espacio de más): los comentarios del usuario no se
        // adoptan nunca (se conservan como hole_edited), así que el caso de
        // adopción es el formato.
        $this->edit(self::CONTROLLER, 'class PostController', 'class  PostController');
        $this->staleHash(self::CONTROLLER);
        $this->prepare();
        $before = $this->manifestEntry(self::CONTROLLER);

        $entry = $this->entry(self::CONTROLLER);
        $this->assertSame(['format_only', 'adopt'], [$entry['class'] ?? null, $entry['type'] ?? null]);

        $this->realign('adopt', ['targets' => ['adopt@.']]);

        $this->assertTrue($this->realign('check', ['targets' => ['adopt@.']])['done']);
        $this->realign('diagnose');
        // Ya cumple: el mismo código, con el manifiesto al día.
        $this->assertNull($this->entry(self::CONTROLLER));

        // La entrada conserva su stub, su modelo y su namespace, y el orden
        // de sus claves: en el diff del manifiesto sólo cambian hash y versión.
        $after = $this->manifestEntry(self::CONTROLLER);
        $this->assertSame(array_keys($before['entry']), array_slice(array_keys($after['entry']), 0, count($before['entry'])));
        $this->assertSame([$before['model'], $before['namespace'], $before['entry']['stub']], [$after['model'], $after['namespace'], $after['entry']['stub']]);
        $this->assertNotSame($before['entry']['hash'], $after['entry']['hash']);
    }

    public function test_el_resumen_explica_por_que_cumplen_menos_que_los_identicos(): void
    {
        $this->generatedPackage();
        $this->staleHash(self::CONTROLLER);
        $this->prepare();

        $scope = $this->report()['scopes']['.'];
        $breakdown = $scope['breakdown'];
        $this->assertSame($scope['total'], array_sum($breakdown));
        $this->assertSame($scope['conforming'], $breakdown['conforming']);
        $this->assertSame(1, $breakdown['to_adopt']);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'diagnose', '--root' => $this->project->path]));
        $this->assertStringContainsString(sprintf('%d cumplen, 1 por adoptar', $breakdown['conforming']), $this->lastOutput);
        $this->assertStringContainsString('«cumplen»', $this->lastOutput);
    }

    public function test_lo_que_crea_fix_route_closure_no_es_codigo_sin_gestionar_y_el_resto_es_de_otro_paso(): void
    {
        $this->useProject(FakeProject::application());
        $this->write('app/Http/Controllers/Controller.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nabstract class Controller\n{\n    //\n}\n");
        $this->write('routes/web.php', <<<'PHP'
            <?php

            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\Route;

            Route::post('/csp-report', function (Request $request) {
                return response()->noContent();
            });

            Route::get('/ci/test-health', fn () => response()->json(['ok' => true]));

            Route::fallback(function () {
                abort_if(request()->is('api/*') || request()->expectsJson(), 404);

                return view('app');
            });

            PHP);
        $this->commitAll();
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();
        $unmanaged = count($this->report()['unmanaged']);

        $id = 'fix:route-closure@.';
        $applied = $this->realign('apply', ['targets' => [$id]]);

        // Los dos closures que no son el fallback son del paso de decisión,
        // no trabajo que le quede a éste: ni `skipped` ni el paso a medias.
        $this->assertSame([], $applied['skipped']);
        $deferred = array_values(array_filter($applied['unchanged'], fn (array $item): bool => ($item['deferred_to'] ?? null) === 'route-closure@.'));
        $this->assertCount(2, $deferred);
        $this->assertStringContainsString('lo resuelve route-closure@.', $deferred[0]['reason']);
        $this->assertContains('app/Http/Controllers/SpaController.php', array_column($applied['fixed'], 'file'));
        $this->assertTrue($this->realign('check', ['targets' => [$id]])['done']);

        $this->commitAll();
        $this->realign('diagnose');
        $files = array_column($this->report()['unmanaged'], 'file');
        $this->assertNotContains('app/Http/Controllers/SpaController.php', $files);
        $this->assertCount($unmanaged, $files, 'corregir el fallback no añade código sin gestionar');

        // Uno que ya no es el de laravel-setup sí es código propio.
        $this->write('app/Http/Controllers/SpaController.php', str_replace('return $request->handle();', 'return view(\'otra\');', SpaFallback::CONTROLLER_CODE));
        $this->realign('diagnose');
        $this->assertContains('app/Http/Controllers/SpaController.php', array_column($this->report()['unmanaged'], 'file'));
    }

    private function prepare(): void
    {
        $this->realign('diagnose');
        $this->realign('plan');
        $this->commitAll();
    }

    /**
     * El manifiesto registra otro hash: lo que deja un proyecto formateado
     * o editado después de generar.
     */
    private function staleHash(string $file): void
    {
        $this->changeManifest(function (array $manifest) use ($file): array {
            foreach ($manifest['models'] as $model => $bucket) {
                if (isset($bucket['files'][$file])) {
                    $manifest['models'][$model]['files'][$file]['hash'] = str_repeat('0', 64);
                }
            }

            return $manifest;
        });
    }

    /**
     * @return array{model: string, namespace: string, entry: array<string, mixed>}
     */
    private function manifestEntry(string $file): array
    {
        $manifest = (new Manifest($this->project->path))->read();

        foreach ($manifest['models'] as $model => $bucket) {
            if (isset($bucket['files'][$file])) {
                return ['model' => (string) $model, 'namespace' => (string) $bucket['namespace'], 'entry' => $bucket['files'][$file]];
            }
        }

        $this->fail("El manifiesto no registra {$file}.");
    }

    /**
     * @return list<string>
     */
    private function stepFiles(string $id): array
    {
        foreach ($this->plan()['steps'] as $step) {
            if ($step['id'] === $id) {
                return array_values((array) $step['files']);
            }
        }

        return [];
    }

    private function migration(string $suffix): string
    {
        $found = glob($this->project->path.'/database/migrations/*_'.$suffix.'.php') ?: [];
        $this->assertCount(1, $found);

        return 'database/migrations/'.basename($found[0]);
    }

    private function write(string $relative, string $code): void
    {
        $file = $this->project->path.'/'.$relative;
        is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
        file_put_contents($file, $code);
    }
}
