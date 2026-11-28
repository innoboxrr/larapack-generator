<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Navigation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use PHPUnit\Framework\TestCase;

/**
 * El plan sale de un informe y de nada más: aquí se le da el informe a mano,
 * sin proyecto ni comando, y se mira lo que decide. Es lo que hace que el
 * realineamiento se pueda retomar: un paso con el mismo id conserva lo que se
 * hizo y se decidió, uno hecho que vuelve a salir se reabre, y uno que cambia
 * comportamiento no se da por hecho porque el diagnóstico deje de verlo.
 *
 * Las pruebas de punta a punta (RealignPlanTest, RealignNavigationTest,
 * RealignUntestedTest) recorren esto con un proyecto generado; cada una tarda
 * segundos y sólo ve los casos que ese proyecto produce.
 */
final class RealignPlannerTest extends TestCase
{
    private const CONTROLLER = 'src/Http/Controllers/ReportController.php';

    /**
     * Un informe como el de Diagnosis, con lo justo.
     *
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    private function report(array $parts = [], ?string $id = 'informe-1'): array
    {
        return $parts + [
            'generator' => '9.4.0',
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'id' => $id,
            'scopes' => ['.' => ['package' => null, 'contract' => true, 'total' => 10, 'conforming' => 8, 'regeneration' => ['ok' => true]]],
            'files' => [],
            'unmanaged' => [],
            'findings' => [],
            'contract' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function file(string $type, string $file, array $extra = []): array
    {
        return $extra + ['type' => $type, 'scope' => '.', 'file' => $file, 'class' => FileClass::IDENTICAL, 'reason' => 'porque sí',
            'category' => StepTypes::get($type)['category']];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function finding(string $type, string $check, ?string $file, array $extra = []): array
    {
        return $extra + ['type' => $type, 'scope' => '.', 'check' => $check, 'file' => $file, 'level' => 'error', 'model' => null,
            'message' => 'Lo que dice verify.', 'package' => null, 'category' => StepTypes::get($type)['category']];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $plan): array
    {
        return array_column($plan['steps'], null, 'id');
    }

    /**
     * El plan anterior con un paso cambiado, como lo dejan `check`, `decide`
     * o `defer`.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function with(array $plan, string $id, array $changes): array
    {
        foreach ($plan['steps'] as $index => $step) {
            if ($step['id'] === $id) {
                $plan['steps'][$index] = $changes + $step;

                return $plan;
            }
        }

        $this->fail("No hay un paso {$id}.");
    }

    public function test_lo_que_se_resuelve_junto_es_un_paso_con_id_estable(): void
    {
        $plan = Planner::build($this->report([
            'files' => [
                $this->file('adopt', 'src/B.php'),
                $this->file('adopt', 'src/A.php'),
                // Lo editado fuera, por familia de plantilla; sin plantilla, junto.
                $this->file('edited-outside', 'src/Http/Requests/Post/CreateRequest.php', ['stub' => 'Requests/CreateRequest.txt']),
                $this->file('edited-outside', 'src/Http/Requests/Post/UpdateRequest.php', ['stub' => 'Requests/UpdateRequest.txt']),
                $this->file('edited-outside', 'src/Policies/PostPolicy.php', ['stub' => 'Policy/PolicyTemplate.txt']),
                $this->file('edited-outside', 'src/Suelto.php'),
                // Lo que cumple no trae tipo: no pide paso.
                ['type' => null, 'scope' => '.', 'file' => 'src/Cumple.php'],
            ],
            'unmanaged' => [['type' => 'unmanaged-http', 'scope' => '.', 'file' => 'routes/web.php', 'kind' => 'routes']],
            'findings' => [
                // Un controlador por paso; la deuda de fix, por comprobación.
                $this->finding('controller-shape', 'controller-shape', self::CONTROLLER),
                $this->finding('controller-shape', 'request-handle', self::CONTROLLER),
                $this->finding('controller-shape', 'controller-shape', 'src/Http/Controllers/OtherController.php'),
                $this->finding('fix', 'index-managed', 'src/Http/Requests/Post/IndexRequest.php'),
                $this->finding('fix', 'resource-appends', 'src/Http/Resources/PostResource.php'),
                $this->finding('security', 'secret-exposed', 'src/Http/Resources/PostResource.php'),
                $this->finding('security', 'immutable-write', 'src/Models/Post.php'),
            ],
            'contract' => [['type' => 'pin-foreign-keys', 'scope' => '.', 'file' => 'laraimport.json', 'model' => 'Post', 'column' => 'user_id', 'on_delete' => 'cascade']],
        ]));

        $steps = $this->byId($plan);
        $ids = array_keys($steps);
        sort($ids);

        $this->assertSame([
            'adopt@.',
            'controller-shape@src/Http/Controllers/OtherController.php',
            'controller-shape@'.self::CONTROLLER,
            'edited-outside:Policy@.',
            'edited-outside:Requests@.',
            'edited-outside@.',
            'fix:index-managed@.',
            'fix:resource-appends@.',
            'pin-foreign-keys@.',
            'security@.',
            'unmanaged-http@.',
        ], $ids);

        // Los archivos, ordenados y sin repetir.
        $this->assertSame(['src/A.php', 'src/B.php'], $steps['adopt@.']['files']);
        $this->assertSame([self::CONTROLLER], $steps['controller-shape@'.self::CONTROLLER]['files']);
        $this->assertSame(['src/Http/Requests/Post/CreateRequest.php', 'src/Http/Requests/Post/UpdateRequest.php'], $steps['edited-outside:Requests@.']['files']);
        $this->assertSame('Requests', $steps['edited-outside:Requests@.']['subject']);
        $this->assertNull($steps['edited-outside@.']['subject']);
        $this->assertSame('index-managed', $steps['fix:index-managed@.']['subject']);
        $this->assertSame(['src/Http/Resources/PostResource.php', 'src/Models/Post.php'], $steps['security@.']['files']);

        // El orden se numera desde 1 y sin saltos, en el orden de la lista.
        $this->assertSame(range(1, count($plan['steps'])), array_column($plan['steps'], 'order'));
        $this->assertSame('informe-1', $plan['report_id']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $plan['report_generated_at']);
        $this->assertSame('9.4.0', $plan['generator']);
    }

    public function test_un_ambito_sin_contrato_o_con_uno_que_no_se_regenera_es_una_decision(): void
    {
        $plan = Planner::build($this->report(['scopes' => [
            '.' => ['package' => null, 'contract' => true, 'regeneration' => ['ok' => true]],
            'packages/sin' => ['package' => 'acme/sin', 'contract' => false],
            // Declarado fuera con una excepción aprobada: ya no se pregunta.
            'packages/fuera' => ['package' => 'acme/fuera', 'contract' => false, 'excepted' => true],
            'packages/roto' => ['package' => 'acme/roto', 'contract' => true, 'regeneration' => ['ok' => false]],
            // Con contrato y sin regenerar todavía (nada que decir de él).
            'packages/nuevo' => ['package' => 'acme/nuevo', 'contract' => true],
            'packages/raro' => 'no es un ámbito',
            // Sin decir si tiene contrato, no lo tiene; con contrato y una
            // regeneración que no dice que saliera bien, no salió.
            'packages/calla' => ['package' => 'acme/calla'],
            'packages/a-medias' => ['package' => 'acme/a-medias', 'contract' => true, 'regeneration' => []],
        ]]));

        $steps = $this->byId($plan);

        $this->assertSame(['contract-invalid@packages/a-medias', 'contract-invalid@packages/roto', 'no-contract@packages/calla', 'no-contract@packages/sin'], array_keys($steps));
        $this->assertSame(Category::DECISION, $steps['no-contract@packages/sin']['category']);
        $this->assertSame(['package' => 'acme/sin', 'path' => 'packages/sin'], $steps['no-contract@packages/sin']['scope']);
        $this->assertSame(['kind' => 'decision'], $steps['no-contract@packages/sin']['done_when']);
        $this->assertSame(['kind' => 'contract', 'scope' => 'packages/roto'], $steps['contract-invalid@packages/roto']['done_when']);
        // Sin archivos: sólo se decide entero.
        $this->assertSame([], $steps['no-contract@packages/sin']['files']);
    }

    public function test_el_orden_lo_que_desbloquea_las_decisiones_que_condicionan_lo_guiado_y_las_demas(): void
    {
        $plan = Planner::build($this->report([
            'files' => [
                $this->file('missing', 'src/Models/Borrado.php'),
                $this->file('regenerate', 'src/R.php'),
                $this->file('adopt', 'src/A.php'),
                $this->file('relink', 'database/migrations/x.php'),
                $this->file('edited-outside', 'src/Policies/PostPolicy.php', ['stub' => 'Policy/PolicyTemplate.txt']),
            ],
            'unmanaged' => [
                ['type' => 'unmanaged-models', 'scope' => '.', 'file' => 'src/Models/Legacy.php', 'kind' => 'model'],
                ['type' => 'unmanaged-http', 'scope' => '.', 'file' => self::CONTROLLER, 'kind' => 'controller'],
            ],
            'findings' => [
                $this->finding('controller-shape', 'controller-shape', self::CONTROLLER),
                $this->finding('fix', 'index-managed', 'src/I.php'),
            ],
        ]));

        // unmanaged-http toca el controlador que hay que llevar a su request:
        // va antes que lo guiado. unmanaged-models también condiciona, pero
        // aquí no toca nada guiado: va con las demás decisiones, al final.
        $this->assertSame([
            'relink@.',
            'adopt@.',
            'regenerate@.',
            'fix:index-managed@.',
            'unmanaged-http@.',
            'controller-shape@'.self::CONTROLLER,
            'edited-outside:Policy@.',
            'missing@.',
            'unmanaged-models@.',
        ], array_column($plan['steps'], 'id'));
    }

    public function test_lo_guiado_va_por_ambito_del_que_mas_cumple_al_que_menos(): void
    {
        $guided = fn (string $scope): array => $this->finding('security', 'secret-exposed', $scope.'/src/A.php', ['scope' => $scope]);
        $routes = fn (string $scope): array => $this->finding('declared-routes', 'route-not-declared', $scope.'/src/B.php', ['scope' => $scope]);
        $edited = fn (string $scope, string $file): array => $this->file('edited-outside', $scope.'/src/Policies/'.$file, ['scope' => $scope, 'stub' => 'Policy/PolicyTemplate.txt']);
        $scope = fn (array $counts): array => ['package' => null, 'contract' => true] + $counts;

        $plan = Planner::build($this->report([
            'scopes' => [
                'packages/mal' => $scope(['total' => 10, 'conforming' => 5]),
                'packages/bien' => $scope(['total' => 10, 'conforming' => 9]),
                'packages/poco' => $scope(['total' => 20, 'conforming' => 1]),
                // Sin archivos que medir —o sin decir cuántos— cumple del todo.
                'packages/vacio' => $scope(['total' => 0, 'conforming' => 0]),
                'packages/calla' => $scope([]),
                'packages/raro' => 'no es un ámbito',
                'packages/entero' => $scope(['total' => 4, 'conforming' => 4]),
                // A igualdad, el que menos guiado tiene (hallazgos y archivos), y después por ruta.
                'packages/doble' => $scope(['total' => 3, 'conforming' => 3]),
                'packages/archivos' => $scope(['total' => 2, 'conforming' => 2]),
                // Sin decir cuántos cumplen, ninguno: como el que dice cero.
                'packages/z-cero' => $scope(['total' => 10, 'conforming' => 0]),
                'packages/a-sin-cuenta' => $scope(['total' => 10]),
            ],
            'files' => [
                $edited('packages/archivos', 'A.php'),
                $edited('packages/archivos', 'B.php'),
                $edited('packages/archivos', 'C.php'),
            ],
            'findings' => [
                $guided('packages/mal'),
                $guided('packages/bien'),
                $guided('packages/poco'),
                $guided('packages/vacio'),
                $guided('packages/calla'),
                $guided('packages/raro'),
                $guided('packages/entero'),
                $guided('packages/doble'),
                $routes('packages/doble'),
                $guided('packages/z-cero'),
                $guided('packages/a-sin-cuenta'),
                // Un ámbito que el informe no lista cuenta como que cumple del todo.
                $guided('packages/ausente'),
                $routes('packages/ausente'),
            ],
        ]));

        $this->assertSame([
            'security@packages/calla',
            'security@packages/entero',
            'security@packages/raro',
            'security@packages/vacio',
            'declared-routes@packages/ausente',
            'security@packages/ausente',
            'declared-routes@packages/doble',
            'security@packages/doble',
            'edited-outside:Policy@packages/archivos',
            'security@packages/bien',
            'security@packages/mal',
            'security@packages/poco',
            'security@packages/a-sin-cuenta',
            'security@packages/z-cero',
        ], array_column($plan['steps'], 'id'));
    }

    public function test_lo_guiado_de_cualquier_ambito_va_antes_que_las_decisiones_que_no_lo_condicionan(): void
    {
        $plan = Planner::build($this->report([
            'scopes' => [
                '.' => ['package' => null, 'contract' => true, 'total' => 5, 'conforming' => 5],
                'packages/a' => ['package' => 'acme/a', 'contract' => true, 'total' => 5, 'conforming' => 5],
                'packages/b' => ['package' => 'acme/b', 'contract' => true, 'total' => 10, 'conforming' => 1],
            ],
            'files' => [$this->file('missing', 'src/Borrado.php')],
            'findings' => [
                $this->finding('security', 'secret-exposed', 'packages/b/src/R.php', ['scope' => 'packages/b']),
                $this->finding('security', 'secret-exposed', 'packages/a/src/R.php', ['scope' => 'packages/a']),
            ],
        ]));

        // El ámbito que menos cumple va el último de lo guiado, no detrás de
        // las decisiones.
        $this->assertSame(['security@packages/a', 'security@packages/b', 'missing@.'], array_column($plan['steps'], 'id'));
    }

    public function test_un_informe_con_piezas_sueltas_o_que_no_son_listas_no_rompe_el_plan(): void
    {
        $plan = Planner::build([
            'id' => 'informe-raro',
            'scopes' => 'no es un mapa',
            'files' => ['suelto', ['type' => null, 'scope' => '.', 'file' => 'src/Cumple.php'], $this->file('adopt', 'src/A.php')],
            'unmanaged' => 'nada',
            'findings' => [null, $this->finding('controller-shape', 'controller-shape', 'src/X.php', ['scope' => ''])],
            'contract' => 7,
        ]);

        $this->assertSame(['adopt@.', 'controller-shape@src/X.php'], array_column($plan['steps'], 'id'));
        $this->assertSame(['package' => null, 'path' => '.'], $plan['steps'][1]['scope']);
        $this->assertNull($plan['generator']);
        $this->assertNull($plan['report_generated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $plan['planned_at']);

        // Sin nada, un plan sin pasos.
        $this->assertSame([], Planner::build([])['steps']);
        $this->assertNull(Planner::build([])['report_id']);
    }

    public function test_la_comprobacion_de_verify_no_repite_comprobaciones_ni_archivos(): void
    {
        $plan = Planner::build($this->report(['findings' => [
            $this->finding('controller-shape', 'controller-concern', self::CONTROLLER),
            $this->finding('controller-shape', 'controller-concern', self::CONTROLLER),
            $this->finding('controller-shape', 'request-handle', self::CONTROLLER),
            $this->finding('security', 'secret-exposed', 'src/A.php'),
            $this->finding('security', 'secret-exposed', 'src/A.php'),
            $this->finding('security', 'immutable-write', 'src/B.php'),
            $this->finding('security', 'immutable-write', null),
        ]]));
        $steps = $this->byId($plan);

        $this->assertSame(
            ['kind' => 'verify', 'checks' => ['controller-concern', 'request-handle', 'controller-shape'], 'package' => null, 'files' => [self::CONTROLLER]],
            $steps['controller-shape@'.self::CONTROLLER]['done_when']
        );
        $this->assertSame(
            ['kind' => 'verify', 'checks' => ['secret-exposed', 'immutable-write'], 'package' => null, 'files' => ['src/A.php', 'src/B.php']],
            $steps['security@.']['done_when']
        );
        // El hallazgo sin archivo cuenta como elemento, no como archivo.
        $this->assertSame(['src/A.php', 'src/B.php'], $steps['security@.']['files']);
        $this->assertSame('4 elementos: hallazgos de verify.', $steps['security@.']['description']);
    }

    public function test_cada_tipo_lleva_la_comprobacion_que_lo_da_por_hecho(): void
    {
        $plan = Planner::build($this->report([
            'files' => [
                $this->file('relink', 'a.php'),
                $this->file('prune', 'b.php'),
                $this->file('adopt', 'c.php'),
                $this->file('regenerate', 'd.php'),
                $this->file('edited-outside', 'e.php'),
                $this->file('missing', 'f.php'),
                $this->file('untracked-edited', 'g.php'),
            ],
            'unmanaged' => [
                ['type' => 'unmanaged-http', 'scope' => '.', 'file' => 'h.php', 'kind' => 'controller'],
                ['type' => 'unmanaged-models', 'scope' => '.', 'file' => 'i.php', 'kind' => 'model'],
            ],
            'findings' => [
                $this->finding('route-closure', 'route-closure', 'routes/web.php'),
                $this->finding('route-closure', 'route-closure', 'routes/web.php'),
                $this->finding('inconsistent-entity', 'inconsistent-entity', null, ['model' => 'User']),
                $this->finding('controller-shape', 'request-handle', self::CONTROLLER, ['package' => 'acme/blog']),
            ],
            'contract' => [
                ['type' => 'pin-foreign-keys', 'scope' => '.', 'file' => 'laraimport.json', 'model' => 'Post', 'column' => 'user_id', 'table' => 'posts',
                    'on_delete' => 'cascade', 'on_update' => 'restrict', 'migration' => 'database/migrations/x.php'],
            ],
        ]));
        $done = array_map(fn (array $step): array => $step['done_when'], $this->byId($plan));

        $this->assertSame(['kind' => 'classification', 'files' => ['a.php'], 'reject' => [FileClass::RENAMED, FileClass::MISSING]], $done['relink@.']);
        $this->assertSame(['kind' => 'classification', 'files' => ['b.php'], 'accept' => [FileClass::ABSENT, FileClass::RENAMED]], $done['prune@.']);
        // Adoptar no es sólo que se parezca: el manifiesto lo registra con el hash de hoy.
        $this->assertSame(['kind' => 'classification', 'files' => ['c.php'], 'accept' => [FileClass::IDENTICAL, FileClass::FORMAT_ONLY], 'tracked' => true, 'hash_current' => true], $done['adopt@.']);
        $this->assertSame(['kind' => 'classification', 'files' => ['d.php'], 'accept' => [FileClass::IDENTICAL, FileClass::FORMAT_ONLY]], $done['regenerate@.']);
        $this->assertSame(['kind' => 'classification', 'files' => ['e.php'], 'accept' => FileClass::CONFORMING], $done['edited-outside@.']);
        $this->assertSame(['kind' => 'classification', 'files' => ['f.php'], 'resolved' => true], $done['missing@.']);
        $this->assertSame(['kind' => 'classification', 'files' => ['g.php'], 'resolved' => true], $done['untracked-edited@.']);
        $this->assertSame(['kind' => 'unmanaged', 'files' => ['h.php']], $done['unmanaged-http@.']);
        $this->assertSame(['kind' => 'unmanaged', 'files' => ['i.php']], $done['unmanaged-models@.']);

        // Los hallazgos de verify: sus comprobaciones y sus archivos, sin repetir.
        $this->assertSame(['kind' => 'verify', 'checks' => ['route-closure'], 'package' => null, 'files' => ['routes/web.php']], $done['route-closure@.']);
        $this->assertSame(['kind' => 'verify', 'checks' => ['inconsistent-entity'], 'package' => null, 'files' => []], $done['inconsistent-entity@.']);
        // Un controlador está hecho cuando delega Y la request tiene handle(),
        // aunque hoy sólo falle una de las dos.
        $this->assertSame(
            ['kind' => 'verify', 'checks' => ['request-handle', 'controller-shape'], 'package' => 'acme/blog', 'files' => [self::CONTROLLER]],
            $done['controller-shape@'.self::CONTROLLER]
        );
        // Lo del contrato: las columnas, sin lo que no es de la comprobación.
        $this->assertSame(
            ['kind' => 'foreign-keys', 'scope' => '.', 'columns' => [['model' => 'Post', 'column' => 'user_id', 'on_delete' => 'cascade', 'on_update' => 'restrict']]],
            $done['pin-foreign-keys@.']
        );

        $steps = $this->byId($plan);

        // Y lo que hay detrás de cada elemento, sin lo que ya dice el paso.
        $this->assertSame([['file' => 'h.php', 'kind' => 'controller']], $steps['unmanaged-http@.']['details']['unmanaged']);
        $this->assertSame(
            [['file' => 'laraimport.json', 'model' => 'Post', 'column' => 'user_id', 'table' => 'posts', 'on_delete' => 'cascade', 'on_update' => 'restrict', 'migration' => 'database/migrations/x.php']],
            $steps['pin-foreign-keys@.']['details']['contract']
        );
        $this->assertSame(['laraimport.json'], $steps['pin-foreign-keys@.']['files']);
        $this->assertArrayNotHasKey('contract', $steps['unmanaged-http@.']['details']);
        $this->assertSame([], $steps['inconsistent-entity@.']['files']);

        $this->assertSame([
            'relink@.' => '1 elemento: archivos renombrados que el manifiesto conoce con otra ruta.',
            'prune@.' => '1 elemento: registros del manifiesto de archivos que ya no existen y el contrato no volvería a generar.',
            'adopt@.' => '1 elemento: archivos iguales a lo que generaría el contrato que el manifiesto da por editados o no conoce.',
            'regenerate@.' => '1 elemento: archivos sin editar que difieren de lo que genera hoy el contrato, o que el contrato pide y faltan.',
            'pin-foreign-keys@.' => '1 elemento: llaves foráneas cuya acción hacen las migraciones y el contrato no declara.',
            'controller-shape@'.self::CONTROLLER => '1 elemento: hallazgos de verify.',
            'edited-outside@.' => '1 elemento: archivos generados editados fuera de sus huecos.',
            'missing@.' => '1 elemento: archivos generados que se borraron y el contrato sigue pidiendo.',
            'untracked-edited@.' => '1 elemento: archivos escritos a mano en la ruta de uno generado.',
            'route-closure@.' => '2 elementos: hallazgos de verify.',
            'inconsistent-entity@.' => '1 elemento: hallazgos de verify.',
            'unmanaged-http@.' => '1 elemento: controladores y archivos de rutas escritos a mano.',
            'unmanaged-models@.' => '1 elemento: modelos escritos a mano.',
        ], array_map(fn (array $step): string => $step['description'], $steps));
    }

    public function test_lo_determinista_que_sale_de_verify_dice_que_agrupa(): void
    {
        $steps = $this->byId(Planner::build($this->report(['findings' => [
            $this->finding('model-lists', 'model-lists-outdated', 'src/Models/Post.php'),
            $this->finding('endpoint-tests', 'endpoint-tests-outdated', 'tests/Feature/PostTest.php'),
            $this->finding('controller-concern', 'controller-concern', 'src/Http/Controllers/Concerns/A.php'),
            $this->finding('controller-concern', 'controller-concern', 'src/Http/Controllers/Concerns/B.php'),
        ], 'scopes' => [
            '.' => ['package' => null, 'contract' => true],
            'packages/sin' => ['package' => 'acme/sin', 'contract' => false],
            'packages/roto' => ['package' => 'acme/roto', 'contract' => true, 'regeneration' => ['ok' => false]],
        ]])));

        $this->assertSame('1 elemento: modelos cuyas listas no tienen lo que declara el contrato.', $steps['model-lists@.']['description']);
        $this->assertSame('1 elemento: tests de endpoints a los que les faltan pruebas que trae hoy la plantilla.', $steps['endpoint-tests@.']['description']);
        $this->assertSame('2 elementos: traits de Http/Controllers/Concerns que sólo usan requests.', $steps['controller-concern@.']['description']);
        $this->assertStringEndsWith(': el ámbito entero.', $steps['no-contract@packages/sin']['description']);
        $this->assertStringEndsWith(': el ámbito entero.', $steps['contract-invalid@packages/roto']['description']);
        // Determinista con playbook: la orden, y el playbook para lo que se salte.
        $this->assertSame('larapack:realign apply model-lists@.', $steps['model-lists@.']['command']);
        $this->assertSame('model-lists', $steps['model-lists@.']['playbook']);
    }

    public function test_cada_paso_lleva_lo_necesario_para_hacerlo_sin_contexto(): void
    {
        $plan = Planner::build($this->report([
            'scopes' => [
                '.' => ['package' => null, 'contract' => true],
                'packages/blog' => ['package' => 'acme/blog', 'contract' => true],
            ],
            'files' => [
                $this->file('adopt', 'packages/blog/src/A.php', ['scope' => 'packages/blog', 'stub' => 'Model/ModelTemplate.txt', 'model' => 'Post',
                    'tracked' => false, 'hash_current' => null, 'members' => ['outside'], 'cause' => 'x', 'to' => 'y', 'regenerated' => 'z', 'path' => 'src/A.php', 'generator' => '9.0.0']),
                $this->file('missing', 'src/Models/Borrado.php'),
            ],
            'findings' => [
                $this->finding('fix', 'index-managed', 'packages/blog/src/I.php', ['scope' => 'packages/blog']),
                $this->finding('fix', 'index-managed', 'packages/blog/src/J.php', ['scope' => 'packages/blog']),
                $this->finding('fix', 'relation-missing', 'src/K.php'),
                $this->finding('controller-shape', 'controller-shape', self::CONTROLLER, [
                    'message' => 'store() valida en el controlador con validate() y recibe Illuminate\\Http\\Request.', 'model' => 'Report']),
            ],
        ]));
        $steps = $this->byId($plan);

        $adopt = $steps['adopt@packages/blog'];
        $this->assertSame(Category::DETERMINISTIC, $adopt['category']);
        $this->assertSame(StepTypes::TYPES['adopt']['title'], $adopt['title']);
        $this->assertSame('1 elemento: archivos iguales a lo que generaría el contrato que el manifiesto da por editados o no conoce.', $adopt['description']);
        $this->assertSame(['package' => 'acme/blog', 'path' => 'packages/blog'], $adopt['scope']);
        $this->assertSame(['level' => StepTypes::LOW, 'why' => StepTypes::TYPES['adopt']['why']], $adopt['risk']);
        $this->assertTrue($adopt['reversible']);
        $this->assertSame('larapack:realign adopt adopt@packages/blog', $adopt['command']);
        $this->assertSame(Planner::PENDING, $adopt['status']);
        $this->assertSame([], $adopt['history']);
        $this->assertArrayNotHasKey('playbook', $adopt);
        $this->assertArrayNotHasKey('options', $adopt);
        // De cada archivo, lo que un agente necesita; ni el ámbito ni la ruta
        // relativa, que ya los dice el paso.
        $this->assertEquals([[
            'file' => 'packages/blog/src/A.php', 'class' => FileClass::IDENTICAL, 'reason' => 'porque sí', 'stub' => 'Model/ModelTemplate.txt', 'model' => 'Post',
            'tracked' => false, 'hash_current' => null, 'members' => ['outside'], 'cause' => 'x', 'to' => 'y', 'regenerated' => 'z',
        ]], $adopt['details']['files']);
        $this->assertSame(['files', 'findings', 'unmanaged'], array_keys($adopt['details']));

        // `larapack:fix` con su comprobación y su paquete; el núcleo es `app`.
        $this->assertSame('larapack:fix index-managed --package=acme/blog', $steps['fix:index-managed@packages/blog']['command']);
        $this->assertSame('2 elementos: hallazgos de index-managed que corrige `larapack:fix index-managed`.', $steps['fix:index-managed@packages/blog']['description']);
        $this->assertSame('larapack:fix relation-missing --package=app', $steps['fix:relation-missing@.']['command']);

        // Una decisión: sus opciones, con la orden de cada una rellenada.
        $missing = $steps['missing@.'];
        $this->assertSame(['restore', 'forget'], array_column($missing['options'], 'key'));
        $this->assertSame('larapack:realign apply missing@.', $missing['options'][0]['run']);
        $this->assertSame('larapack:realign except missing@. --option reason="por qué"', $missing['options'][1]['run']);
        $this->assertSame(StepTypes::TYPES['missing']['options'][0]['label'], $missing['options'][0]['label']);
        $this->assertArrayNotHasKey('command', $missing);

        // Un guiado: su playbook y los anexos que piden sus hallazgos.
        $controller = $steps['controller-shape@'.self::CONTROLLER];
        $this->assertSame('controller-to-request', $controller['playbook']);
        $this->assertSame(['validate-to-rules', 'raw-request-to-formrequest'], $controller['annexes']);
        $this->assertEquals([[
            'level' => 'error', 'check' => 'controller-shape', 'model' => 'Report', 'file' => self::CONTROLLER,
            'message' => 'store() valida en el controlador con validate() y recibe Illuminate\\Http\\Request.', 'package' => null,
        ]], $controller['details']['findings']);
    }

    public function test_un_paso_por_controlador_tiene_el_ambito_de_su_paquete(): void
    {
        $plan = Planner::build($this->report([
            'scopes' => [
                '.' => ['package' => null, 'contract' => true],
                'packages/a/sub' => ['package' => 'acme/sub', 'contract' => true],
                'packages/a' => ['package' => 'acme/a', 'contract' => true],
            ],
            'findings' => [
                // El hallazgo dice su ámbito: es ése, aunque el archivo esté en otro sitio.
                $this->finding('controller-shape', 'controller-shape', 'packages/a/src/X.php', ['scope' => 'packages/a/sub']),
                // No lo dice: el ámbito más largo que contiene el archivo…
                $this->finding('controller-shape', 'controller-shape', 'packages/a/sub/src/Y.php', ['scope' => '']),
                // …y uno que sólo empieza igual no lo contiene.
                $this->finding('controller-shape', 'controller-shape', 'packages/abc/src/Z.php', ['scope' => '', 'package' => 'acme/abc']),
            ],
        ]));
        $steps = $this->byId($plan);

        $this->assertSame(['package' => 'acme/sub', 'path' => 'packages/a/sub'], $steps['controller-shape@packages/a/src/X.php']['scope']);
        $this->assertSame(['package' => 'acme/sub', 'path' => 'packages/a/sub'], $steps['controller-shape@packages/a/sub/src/Y.php']['scope']);
        // En el núcleo, el paquete es el que diga el hallazgo.
        $this->assertSame(['package' => 'acme/abc', 'path' => '.'], $steps['controller-shape@packages/abc/src/Z.php']['scope']);
    }

    public function test_lo_guiado_y_las_decisiones_esperan_a_que_el_manifiesto_diga_la_verdad(): void
    {
        $plan = Planner::build($this->report([
            'scopes' => [
                '.' => ['package' => null, 'contract' => true],
                'packages/otro' => ['package' => 'acme/otro', 'contract' => true],
            ],
            'files' => [
                $this->file('relink', 'a.php'),
                $this->file('prune', 'b.php'),
                $this->file('adopt', 'c.php'),
                $this->file('regenerate', 'd.php'),
                $this->file('edited-outside', 'src/Policies/PostPolicy.php', ['stub' => 'Policy/PolicyTemplate.txt']),
                $this->file('missing', 'f.php'),
                $this->file('adopt', 'packages/otro/src/A.php', ['scope' => 'packages/otro']),
            ],
            'unmanaged' => [['type' => 'unmanaged-http', 'scope' => '.', 'file' => self::CONTROLLER, 'kind' => 'controller']],
            'findings' => [
                $this->finding('fix', 'index-managed', 'src/I.php'),
                $this->finding('model-lists', 'model-lists-outdated', 'src/Models/Post.php'),
                $this->finding('security', 'secret-exposed', 'src/Http/Resources/PostResource.php'),
                $this->finding('controller-shape', 'controller-shape', self::CONTROLLER),
                $this->finding('security', 'secret-exposed', 'packages/otro/src/R.php', ['scope' => 'packages/otro']),
            ],
        ]));
        $depends = array_map(fn (array $step): array => $step['depends_on'], $this->byId($plan));

        // Lo determinista no espera a nadie.
        foreach (['relink@.', 'prune@.', 'adopt@.', 'regenerate@.', 'fix:index-managed@.', 'model-lists@.', 'adopt@packages/otro'] as $id) {
            $this->assertSame([], $depends[$id], $id);
        }

        $this->assertSame(['relink@.', 'prune@.', 'adopt@.'], $depends['security@.']);
        $this->assertSame(['relink@.', 'prune@.', 'adopt@.'], $depends['missing@.']);
        $this->assertSame(['relink@.', 'prune@.', 'adopt@.'], $depends['unmanaged-http@.']);
        // Devolver código a sus huecos espera además a lo que lo reescribe.
        $this->assertSame(['relink@.', 'prune@.', 'adopt@.', 'regenerate@.', 'fix:index-managed@.', 'model-lists@.'], $depends['edited-outside:Policy@.']);
        // Y lo guiado, a la decisión que toca sus mismos archivos.
        $this->assertSame(['relink@.', 'prune@.', 'adopt@.', 'unmanaged-http@.'], $depends['controller-shape@'.self::CONTROLLER]);
        // Cada ámbito, lo suyo.
        $this->assertSame(['adopt@packages/otro'], $depends['security@packages/otro']);
    }

    public function test_volver_a_planificar_conserva_la_historia_la_decision_y_lo_aplazado(): void
    {
        $report = $this->report([
            'files' => [$this->file('missing', 'f.php'), $this->file('untracked-edited', 'g.php'), $this->file('untracked-edited', 'h.php')],
            'findings' => [$this->finding('security', 'secret-exposed', 'src/R.php')],
        ]);
        $previous = Planner::build($report);
        $history = [['at' => 'antes', 'event' => 'checked', 'detail' => 'Falta.']];
        $decision = ['option' => 'restore', 'note' => 'Se quiere de vuelta.', 'at' => 'antes'];
        $decisions = ['g.php' => ['option' => 'adopt', 'note' => null, 'at' => 'antes']];
        $deferred = ['note' => 'Después del release.', 'at' => 'antes'];

        $previous = $this->with($previous, 'security@.', ['history' => $history, 'deferred' => $deferred, 'last_check' => ['id' => 'security@.', 'done' => false]]);
        $previous = $this->with($previous, 'missing@.', ['decision' => $decision]);
        $previous = $this->with($previous, 'untracked-edited@.', ['decisions' => $decisions, 'history' => 'no es una lista']);
        // Lo que no es un paso no rompe nada.
        $previous['steps'][] = 'basura';
        $previous['steps'][] = ['sin' => 'id'];

        // Un paso nuevo, que va el primero, no impide conservar los demás.
        $files = [...$report['files'], $this->file('adopt', 'c.php')];
        $plan = Planner::build($this->report(['files' => $files, 'findings' => $report['findings']], 'informe-2'), $previous);
        $steps = $this->byId($plan);

        $this->assertSame('adopt@.', $plan['steps'][0]['id']);
        $this->assertCount(4, $steps);
        $this->assertSame($history, $steps['security@.']['history']);
        $this->assertSame($deferred, $steps['security@.']['deferred']);
        $this->assertSame(Planner::PENDING, $steps['security@.']['status']);
        // La última comprobación era de otro diagnóstico: no se arrastra.
        $this->assertArrayNotHasKey('last_check', $steps['security@.']);
        $this->assertSame($decision, $steps['missing@.']['decision']);
        $this->assertArrayNotHasKey('decisions', $steps['missing@.']);
        $this->assertArrayNotHasKey('deferred', $steps['missing@.']);
        $this->assertSame($decisions, $steps['untracked-edited@.']['decisions']);
        $this->assertSame([], $steps['untracked-edited@.']['history']);
    }

    public function test_un_paso_hecho_que_el_diagnostico_vuelve_a_encontrar_se_reabre(): void
    {
        $report = $this->report(['files' => [$this->file('adopt', 'c.php')], 'findings' => [$this->finding('security', 'secret-exposed', 'src/R.php')]]);
        $previous = Planner::build($report);
        $previous = $this->with($previous, 'adopt@.', ['status' => Planner::DONE, 'history' => [['at' => 'antes', 'event' => 'checked']], 'last_check' => ['id' => 'adopt@.', 'done' => true]]);
        // Hecho a falta de su prueba también: lo que vuelve a salir no está hecho.
        $previous = $this->with($previous, 'security@.', ['status' => Planner::UNTESTED]);

        $steps = $this->byId(Planner::build($this->report(['files' => $report['files'], 'findings' => $report['findings']], 'informe-2'), $previous));

        foreach (['adopt@.', 'security@.'] as $id) {
            $this->assertSame(Planner::PENDING, $steps[$id]['status'], $id);
            $last = end($steps[$id]['history']);
            $this->assertSame('reopened', $last['event'], $id);
            $this->assertSame('El diagnóstico lo vuelve a encontrar.', $last['detail']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $last['at']);
            $this->assertArrayNotHasKey('last_check', $steps[$id]);
        }

        $this->assertCount(2, $steps['adopt@.']['history']);
        $this->assertSame('checked', $steps['adopt@.']['history'][0]['event']);
    }

    public function test_planificar_otra_vez_sobre_el_mismo_informe_no_reabre_lo_hecho(): void
    {
        $report = $this->report(['files' => [$this->file('adopt', 'c.php'), $this->file('regenerate', 'd.php')]]);
        $previous = Planner::build($report);
        $check = ['id' => 'adopt@.', 'at' => 'antes', 'done' => true, 'remaining' => 0];
        $previous = $this->with($previous, 'adopt@.', ['status' => Planner::DONE, 'last_check' => $check]);
        $previous = $this->with($previous, 'regenerate@.', ['status' => Planner::DONE]);

        $this->assertSame('informe-1', $previous['report_id']);
        $steps = $this->byId(Planner::build($report, $previous));

        // El informe es el que lo pedía: claro que lo sigue listando.
        $this->assertSame(Planner::DONE, $steps['adopt@.']['status']);
        $this->assertSame([], $steps['adopt@.']['history']);
        $this->assertSame($check, $steps['adopt@.']['last_check']);
        $this->assertSame(Planner::DONE, $steps['regenerate@.']['status']);
        $this->assertArrayNotHasKey('last_check', $steps['regenerate@.']);

        // Un informe sin id no es «el mismo» que otro sin id: se reabre.
        $anonymous = $this->report(['files' => $report['files']], null);
        $previous['report_id'] = null;
        $steps = $this->byId(Planner::build($anonymous, $previous));
        $this->assertSame(Planner::PENDING, $steps['adopt@.']['status']);
        $this->assertSame('reopened', end($steps['adopt@.']['history'])['event']);
    }

    /**
     * `check` deja `untested` un paso que cambia comportamiento y cuya prueba
     * nadie registró. Volver a planificar sobre el mismo informe —que lo
     * sigue listando: es el que lo pedía— lo dejaba `done`: bastaba correr
     * `plan` otra vez para que lo no probado acabara hecho, y `done` lo daba
     * por terminado. Lo aceptado sin probar por el dueño y la prueba
     * registrada tampoco se pierden por planificar dos veces.
     */
    public function test_planificar_otra_vez_sobre_el_mismo_informe_no_da_por_probado_lo_que_no_lo_esta(): void
    {
        $report = $this->report(['findings' => [
            $this->finding('security', 'secret-exposed', 'src/R.php'),
            $this->finding('declared-routes', 'route-not-declared', 'src/S.php'),
            $this->finding('controller-shape', 'controller-shape', self::CONTROLLER),
        ], 'files' => [$this->file('missing', 'f.php')]]);
        $previous = Planner::build($report);
        $accepted = ['by' => 'la dueña', 'note' => 'No hay entorno de pruebas.', 'at' => 'antes'];
        $tested = ['command' => 'php artisan test', 'at' => 'antes'];
        $previous = $this->with($previous, 'security@.', ['status' => Planner::UNTESTED]);
        $previous = $this->with($previous, 'declared-routes@.', ['status' => Planner::DONE, 'untested_accepted' => $accepted]);
        $previous = $this->with($previous, 'controller-shape@'.self::CONTROLLER, ['status' => Planner::DONE, 'tested' => $tested]);

        // Una decisión tomada y cumplida, igual: el mismo informe no la reabre.
        $check = ['id' => 'missing@.', 'at' => 'antes', 'done' => true, 'remaining' => 0];
        $previous = $this->with($previous, 'missing@.', ['status' => Planner::DONE, 'decision' => ['option' => 'restore'], 'last_check' => $check]);

        $steps = $this->byId(Planner::build($report, $previous));

        $this->assertSame(Planner::UNTESTED, $steps['security@.']['status']);
        $this->assertSame([], $steps['security@.']['history']);
        $this->assertSame(Planner::DONE, $steps['declared-routes@.']['status']);
        $this->assertSame($accepted, $steps['declared-routes@.']['untested_accepted']);
        $this->assertSame(Planner::DONE, $steps['controller-shape@'.self::CONTROLLER]['status']);
        $this->assertSame($tested, $steps['controller-shape@'.self::CONTROLLER]['tested']);
        $this->assertSame(Planner::DONE, $steps['missing@.']['status']);
        $this->assertSame($check, $steps['missing@.']['last_check']);
        $this->assertSame(['option' => 'restore'], $steps['missing@.']['decision']);
    }

    public function test_una_decision_tomada_sigue_tomada_aunque_el_problema_vuelva(): void
    {
        $report = $this->report(['files' => [$this->file('missing', 'f.php'), $this->file('untracked-edited', 'g.php')], 'scopes' => [
            '.' => ['package' => null, 'contract' => true],
            'packages/roto' => ['package' => 'acme/roto', 'contract' => true, 'regeneration' => ['ok' => false]],
        ]]);
        $previous = Planner::build($report);
        $decision = ['option' => 'restore', 'note' => null, 'at' => 'antes'];
        $previous = $this->with($previous, 'missing@.', ['status' => Planner::DONE, 'decision' => $decision]);
        $previous = $this->with($previous, 'untracked-edited@.', ['status' => Planner::DONE, 'decisions' => ['g.php' => ['option' => 'adopt']]]);
        // Una decisión que se dio por hecha sin decidir nada (el contrato se
        // arregló solo) y vuelve a salir: ésa sí se reabre.
        $previous = $this->with($previous, 'contract-invalid@packages/roto', ['status' => Planner::DONE]);

        $steps = $this->byId(Planner::build($this->report(['files' => $report['files'], 'scopes' => $report['scopes']], 'informe-2'), $previous));

        foreach (['missing@.', 'untracked-edited@.'] as $id) {
            $this->assertSame(Planner::PENDING, $steps[$id]['status'], $id);
            $this->assertSame([], $steps[$id]['history'], $id);
            // No hay que volver a decidir: falta hacerlo.
            $this->assertSame(Planner::DECIDED, Planner::effectiveStatus($steps[$id], $steps), $id);
        }

        $this->assertSame($decision, $steps['missing@.']['decision']);
        $this->assertSame('reopened', end($steps['contract-invalid@packages/roto']['history'])['event']);
        $this->assertSame(Planner::AWAITING_DECISION, Planner::effectiveStatus($steps['contract-invalid@packages/roto'], $steps));
    }

    public function test_lo_pendiente_que_el_diagnostico_ya_no_encuentra_se_da_por_hecho(): void
    {
        $report = $this->report(['files' => [$this->file('adopt', 'c.php'), $this->file('regenerate', 'd.php'), $this->file('relink', 'a.php')]]);
        $previous = Planner::build($report);
        $previous = $this->with($previous, 'adopt@.', ['history' => [['at' => 'antes', 'event' => 'applied']], 'last_check' => ['done' => false]]);
        $previous = $this->with($previous, 'relink@.', ['status' => Planner::DONE, 'history' => [['at' => 'antes', 'event' => 'checked']], 'last_check' => ['done' => true]]);

        $plan = Planner::build($this->report(['files' => [$this->file('regenerate', 'd.php')]], 'informe-2'), $previous);
        $steps = $this->byId($plan);

        // Lo que sigue saliendo va primero; lo que ya no sale, detrás, y se numera igual.
        $this->assertSame(['regenerate@.', 'relink@.', 'adopt@.'], array_column($plan['steps'], 'id'));
        $this->assertSame([1, 2, 3], array_column($plan['steps'], 'order'));

        $this->assertSame(Planner::DONE, $steps['adopt@.']['status']);
        $this->assertSame(['applied', 'resolved'], array_column($steps['adopt@.']['history'], 'event'));
        $this->assertSame('El diagnóstico ya no lo encuentra.', $steps['adopt@.']['history'][1]['detail']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $steps['adopt@.']['history'][1]['at']);
        $this->assertArrayNotHasKey('last_check', $steps['adopt@.']);
        // Conserva lo que era: sus archivos, su comprobación.
        $this->assertSame(['c.php'], $steps['adopt@.']['files']);

        // Lo que ya estaba hecho no gana otra línea de historia.
        $this->assertSame(Planner::DONE, $steps['relink@.']['status']);
        $this->assertSame(['checked'], array_column($steps['relink@.']['history'], 'event'));
        $this->assertArrayNotHasKey('last_check', $steps['relink@.']);
        $this->assertSame(Planner::PENDING, $steps['regenerate@.']['status']);
    }

    public function test_lo_que_cambia_comportamiento_y_desaparece_queda_sin_probar_no_hecho(): void
    {
        $report = $this->report([
            'files' => [
                $this->file('edited-outside', 'src/Policies/PostPolicy.php', ['stub' => 'Policy/PolicyTemplate.txt']),
                $this->file('edited-outside', 'src/Models/Post.php', ['stub' => 'Model/ModelTemplate.txt']),
            ],
            'findings' => [
                $this->finding('controller-shape', 'controller-shape', self::CONTROLLER),
                $this->finding('route-closure', 'route-closure', 'routes/web.php'),
                $this->finding('route-closure', 'route-closure', 'routes/api.php', ['scope' => 'packages/api']),
                $this->finding('security', 'secret-exposed', 'src/R.php'),
            ],
        ]);
        $previous = Planner::build($report);
        // Un closure que se decidió llevar a su controlador mueve código;
        // uno que se decidió exceptuar, no.
        $previous = $this->with($previous, 'route-closure@.', ['decision' => ['option' => 'refactor']]);
        $previous = $this->with($previous, 'route-closure@packages/api', ['decision' => ['option' => 'except']]);
        // Sin probar ya estaba: sigue igual, sin otra línea.
        $previous = $this->with($previous, 'security@.', ['status' => Planner::UNTESTED]);

        $steps = $this->byId(Planner::build($this->report([], 'informe-2'), $previous));

        // Un agente sin pruebas que no corrió `check` acababa aquí con el
        // paso `done`: nadie vio pasar su prueba.
        foreach (['controller-shape@'.self::CONTROLLER, 'edited-outside:Policy@.', 'route-closure@.'] as $id) {
            $this->assertSame(Planner::UNTESTED, $steps[$id]['status'], $id);
            $last = end($steps[$id]['history']);
            $this->assertSame('resolved', $last['event']);
            $this->assertSame(
                'El diagnóstico ya no lo encuentra, pero cambia comportamiento y nadie registró su prueba: queda sin probar (larapack:realign check '.$id.' --tested="<orden>").',
                $last['detail']
            );
        }

        // Las listas de un modelo las juzga la comparación con lo regenerado.
        $this->assertSame(Planner::DONE, $steps['edited-outside:Model@.']['status']);
        $this->assertSame(Planner::DONE, $steps['route-closure@packages/api']['status']);
        $this->assertSame('El diagnóstico ya no lo encuentra.', end($steps['route-closure@packages/api']['history'])['detail']);
        $this->assertSame(Planner::UNTESTED, $steps['security@.']['status']);
        $this->assertSame([], $steps['security@.']['history']);
    }

    public function test_un_guiado_que_se_le_paso_al_dueno_sigue_siendo_su_decision(): void
    {
        $report = $this->report(['files' => [$this->file('missing', 'f.php')], 'findings' => [$this->finding('security', 'secret-exposed', 'src/R.php')]]);
        $previous = Planner::build($report);

        foreach ($previous['steps'] as $index => $step) {
            if ($step['id'] === 'security@.') {
                $previous['steps'][$index] = Navigation::escalate($step, ['note' => 'No sé si se puede cerrar.', 'at' => 'entonces']);
            }
        }

        // Una marca de escalado en lo que ya era una decisión no cambia sus opciones.
        $previous = $this->with($previous, 'missing@.', ['escalated' => ['from' => Category::GUIDED, 'note' => 'x', 'at' => 'y']]);

        $steps = $this->byId(Planner::build($this->report(['files' => $report['files'], 'findings' => $report['findings']], 'informe-2'), $previous));

        $this->assertSame(Category::DECISION, $steps['security@.']['category']);
        $this->assertSame(['from' => Category::GUIDED, 'note' => 'No sé si se puede cerrar.', 'at' => 'entonces'], $steps['security@.']['escalated']);
        $this->assertSame(['refactor', 'except'], array_column($steps['security@.']['options'], 'key'));
        $this->assertSame(Planner::AWAITING_DECISION, Planner::effectiveStatus($steps['security@.'], $steps));

        $this->assertArrayNotHasKey('escalated', $steps['missing@.']);
        $this->assertSame(['restore', 'forget'], array_column($steps['missing@.']['options'], 'key'));
    }

    public function test_un_paso_hecho_o_sin_probar_ya_no_es_trabajo_de_quien_sigue_el_plan(): void
    {
        $this->assertTrue(Planner::finished(['status' => Planner::DONE]));
        $this->assertTrue(Planner::finished(['status' => Planner::UNTESTED]));
        $this->assertFalse(Planner::finished(['status' => Planner::PENDING]));
        $this->assertFalse(Planner::finished([]));

        $this->assertSame(Planner::DONE, Planner::effectiveStatus(['status' => Planner::DONE, 'category' => Category::DECISION, 'depends_on' => ['x']], ['x' => ['status' => Planner::PENDING]]));
        $this->assertSame(Planner::UNTESTED, Planner::effectiveStatus(['status' => Planner::UNTESTED, 'category' => Category::GUIDED, 'depends_on' => ['x']], ['x' => ['status' => Planner::PENDING]]));
    }

    public function test_un_paso_espera_a_lo_que_depende_y_una_decision_a_que_se_decida(): void
    {
        $guided = ['id' => 'g', 'category' => Category::GUIDED, 'status' => Planner::PENDING, 'depends_on' => ['adopt@.'], 'files' => ['a.php']];
        $byId = fn (string $status): array => ['adopt@.' => ['id' => 'adopt@.', 'type' => 'adopt', 'status' => $status]];

        $this->assertSame(Planner::BLOCKED, Planner::effectiveStatus($guided, $byId(Planner::PENDING)));
        $this->assertSame(Planner::PENDING, Planner::effectiveStatus($guided, $byId(Planner::DONE)));
        // Hecho a falta de su prueba no detiene a nadie.
        $this->assertSame(Planner::PENDING, Planner::effectiveStatus($guided, $byId(Planner::UNTESTED)));
        // Una dependencia que ya no está en el plan tampoco.
        $this->assertSame(Planner::PENDING, Planner::effectiveStatus($guided, []));
        // Sin estado escrito, pendiente.
        $this->assertSame(Planner::PENDING, Planner::effectiveStatus(['category' => Category::GUIDED], []));
        $this->assertSame(Planner::BLOCKED, Planner::effectiveStatus(['category' => Category::GUIDED, 'depends_on' => ['adopt@.']], $byId(Planner::PENDING)));
        $this->assertSame(Planner::BLOCKED, Planner::effectiveStatus(['category' => Category::GUIDED, 'depends_on' => 'adopt@.'], $byId(Planner::PENDING)));

        $decision = ['id' => 'd', 'type' => 'missing', 'category' => Category::DECISION, 'status' => Planner::PENDING, 'files' => ['f.php', 'g.php'], 'depends_on' => ['adopt@.']];

        $this->assertSame(Planner::BLOCKED, Planner::effectiveStatus($decision, $byId(Planner::PENDING)));
        $this->assertSame(Planner::AWAITING_DECISION, Planner::effectiveStatus($decision, $byId(Planner::DONE)));
        $this->assertSame(Planner::AWAITING_DECISION, Planner::effectiveStatus($decision + ['decisions' => ['f.php' => ['option' => 'restore']]], $byId(Planner::DONE)));
        $this->assertSame(Planner::DECIDED, Planner::effectiveStatus($decision + ['decision' => ['option' => 'restore']], $byId(Planner::DONE)));
        // Decidida y bloqueada: bloqueada, que es lo que impide hacerla.
        $this->assertSame(Planner::BLOCKED, Planner::effectiveStatus($decision + ['decision' => ['option' => 'restore']], $byId(Planner::PENDING)));
    }

    public function test_lo_guiado_no_espera_a_una_decision_que_lo_deja_seguir(): void
    {
        $guided = ['id' => 'controller-shape@'.self::CONTROLLER, 'category' => Category::GUIDED, 'status' => Planner::PENDING,
            'depends_on' => ['unmanaged-http@.'], 'files' => [self::CONTROLLER]];
        $decision = ['id' => 'unmanaged-http@.', 'type' => 'unmanaged-http', 'category' => Category::DECISION, 'status' => Planner::PENDING,
            'files' => [self::CONTROLLER, 'routes/web.php']];
        $status = fn (array $decision): string => Planner::effectiveStatus($guided, ['unmanaged-http@.' => $decision]);

        // Sin decidir: si el dueño lo declara en el contrato, el trabajo se tira.
        $this->assertSame(Planner::BLOCKED, $status($decision));
        $this->assertSame(Planner::BLOCKED, $status($decision + ['decision' => ['option' => 'declare']]));
        // «Dejarlo como código propio»: lo guiado sigue valiendo y se hace ya,
        // aunque la decisión no esté cumplida.
        $this->assertSame(Planner::PENDING, $status($decision + ['decision' => ['option' => 'keep']]));
        $this->assertSame(Planner::PENDING, $status($decision + ['decisions' => [self::CONTROLLER => ['option' => 'keep']]]));
        $this->assertSame(Planner::BLOCKED, $status($decision + ['decisions' => ['routes/web.php' => ['option' => 'keep']]]));
        // Una decisión que no condiciona lo guiado no lo suelta por estar decidida.
        $this->assertSame(Planner::BLOCKED, $status(['type' => 'missing', 'decision' => ['option' => 'keep']] + $decision));
        $this->assertSame(Planner::PENDING, $status(['status' => Planner::DONE] + $decision));
    }
}
