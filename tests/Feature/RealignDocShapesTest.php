<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\OwnerConfirmation;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que docs/realign dice de cada salida se compara con la salida de verdad.
 *
 * Un agente lee `--format=json` siguiendo la guía: una clave que la guía
 * nombra y no existe, o una que existe y la guía calla, no falla aquí sino en
 * el proyecto de alguien. En la auditoría de la documentación de 9.4.0 la
 * guía decía que `report.json` traía `scopes` como lista (es un objeto por
 * ámbito), que `brief` no tenía `untested`, que las operaciones no traían
 * `commit` y que `playbook` no tenía formato: nadie lo había cruzado.
 *
 * Sobre un proyecto generado y desviado como se desvían los de verdad (un
 * controlador con lógica, un archivo que sólo cambió de formato, un closure en
 * las rutas), se corre cada acción y se cruza, clave por clave:
 *
 *  - la tabla «Las salidas --format=json» de plan.md con la salida de cada
 *    acción (las claves con `?`, opcionales, tienen que salir en algún caso);
 *  - los formatos anidados de diagnose.md (la salida y `report.json`) y de
 *    owner.md (`brief`), escritos en la notación `{a, b: {c}, d: [{e}]}`;
 *  - los campos de un paso de plan.md con los de `plan.json`.
 *
 * Y lo que la página de atascos (faq.md) afirma del comportamiento —lo que
 * dice el comando en cada atasco y con qué código sale— se ejecuta.
 */
final class RealignDocShapesTest extends TestCase
{
    use RealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    private const SHAPE = 'controller-shape@src/Http/Controllers/PostController.php';

    private const FORMATTED = 'src/Http/Requests/Post/ShowRequest.php';

    private string $generated = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPackage();
        $this->withoutIgnoredFlags();
        $this->commitAll();
    }

    public function test_cada_salida_json_tiene_las_claves_que_dice_la_guia(): void
    {
        $outputs = [];

        // Un proyecto que cumple: el plan no tiene nada, y done termina.
        $this->realign('diagnose');
        $this->realign('plan');
        $outputs['next'][] = $this->realign('next');
        $outputs['done'][] = $this->realign('done');
        $this->assertTrue($outputs['next'][0]['done']);
        $this->assertTrue($outputs['done'][0]['done']);

        $this->deviate();

        $outputs['diagnose'][] = $diagnose = $this->realign('diagnose');
        $report = $this->report();
        $outputs['plan'][] = $this->realign('plan');
        $outputs['status'][] = $this->realign('status');
        $this->commitAll();

        $ids = array_column($this->plan()['steps'], 'id');
        sort($ids);
        $this->assertSame(['adopt@.', self::SHAPE, 'edited-outside:Controller@.', 'route-closure@.', 'unmanaged-http@.'], $ids);

        $outputs['next'][] = $this->realign('next');
        $outputs['show'][] = $this->realign('show', ['targets' => ['route-closure@.']]);
        $outputs['adopt'][] = $this->realign('adopt', ['--dry-run' => true]);
        $outputs['apply'][] = $this->realign('apply', ['--deterministic' => true]);
        $this->commitAll();
        // Aplicar no da el paso por hecho: lo cierra la pasada siguiente.
        $this->assertSame(Planner::PENDING, $this->step('adopt@.')['status']);
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::DONE, $this->step('adopt@.')['status']);
        $outputs['error'][] = $this->realign('check', ['targets' => ['no-existe@.']], Command::FAILURE);

        $outputs['decide'][] = $this->realign('decide', ['targets' => ['route-closure@.', 'except'], '--note' => 'Es la salud de la CI.']);
        $this->commitAll();
        $outputs['except'][] = $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason=Sin datos de nadie.']]);
        $this->commitAll();
        $outputs['infer'][] = $this->realign('infer', ['targets' => ['unmanaged-http@.'], '--dry-run' => true]);

        $outputs['defer'][] = $this->realign('defer', ['targets' => [self::SHAPE], '--note' => 'Espera a otro equipo.']);
        $outputs['undefer'][] = $this->realign('undefer', ['targets' => [self::SHAPE]]);

        // El controlador vuelve a delegar, y nadie corrió las pruebas.
        file_put_contents($this->project->path.'/'.self::CONTROLLER, $this->generated);
        $outputs['check'][] = $untested = $this->realign('check', ['targets' => [self::SHAPE]]);
        $outputs['check'][] = $this->realign('check', ['targets' => ['route-closure@.']], Command::FAILURE);
        $this->assertSame(Planner::UNTESTED, $untested['status']);

        $outputs['escalate'][] = $this->realign('escalate', ['targets' => ['edited-outside:Controller@.'], '--note' => '¿Se queda así?']);

        $outputs['brief'][] = $brief = $this->realign('brief');
        $this->assertNotSame([], $brief['decisions']);
        $this->assertNotSame([], $brief['exceptions']);
        $this->assertNotSame([], $brief['untested']);

        $this->ownerAtTerminal('acepto sin probar');
        $outputs['accept-untested'][] = $this->realign('accept-untested', ['targets' => [self::SHAPE], '--note' => 'Se prueba en staging.']);
        OwnerConfirmation::useTerminal(null);

        $outputs['done'][] = $done = $this->realign('done', [], Command::FAILURE);
        $this->assertFalse($done['done']);

        $outputs['playbook'][] = $this->realign('playbook');
        $outputs['playbook'][] = $this->realign('playbook', ['targets' => ['generic']]);
        $outputs['help'][] = $this->realign('help');
        $outputs['help'][] = $this->realign('help', ['targets' => ['check']]);

        // Queda trabajo y nada que hacer ahora: todo aplazado.
        foreach ($this->plan()['steps'] as $step) {
            if (! Planner::finished($step)) {
                $this->realign('defer', ['targets' => [$step['id']], '--note' => 'Más tarde.']);
            }
        }

        $outputs['next'][] = $waiting = $this->realign('next');
        $this->assertFalse($waiting['done']);
        $this->assertNull($waiting['step']);

        $this->runCommand('larapack:realign', ['action' => 'status', '--root' => $this->project->path]);
        $this->assertStringNotContainsString('No queda nada pendiente', $this->lastOutput, 'status dice que no queda nada con todos los pasos aplazados.');
        $this->assertStringContainsString('no ha terminado', $this->lastOutput);

        // ── La tabla de plan.md, fila por fila.
        $problems = [];
        $rows = $this->outputsTable();

        foreach ($rows as [$actions, $keys]) {
            $required = array_values(array_filter($keys, fn (string $key): bool => ! str_ends_with($key, '?')));
            $optional = array_map(fn (string $key): string => rtrim($key, '?'), array_values(array_diff($keys, $required)));
            $seen = [];

            foreach ($actions as $action) {
                $this->assertArrayHasKey($action, $outputs, "La prueba no produce la salida de `{$action}` que documenta plan.md.");

                foreach ($outputs[$action] as $document) {
                    $real = array_keys($document);
                    array_push($seen, ...$real);

                    foreach (array_diff($real, $required, $optional) as $key) {
                        $problems[] = "`{$action}` da `{$key}` y plan.md no la documenta";
                    }

                    foreach (array_diff($required, $real) as $key) {
                        $problems[] = "plan.md dice que `{$action}` da `{$key}` y no la da (¿es opcional?)";
                    }
                }
            }

            foreach (array_diff($optional, $seen) as $key) {
                $problems[] = '`'.implode('`, `', $actions)."`: plan.md documenta `{$key}?` y no salió en ningún caso";
            }
        }

        $documented = array_merge(...array_column($rows, 0));

        foreach (array_keys($outputs) as $action) {
            if (! in_array($action, $documented, true) && $action !== 'error') {
                $problems[] = "plan.md no documenta la salida de `{$action}`";
            }
        }

        $this->assertSame(['ok', 'error', 'format_version', 'action'], array_keys($outputs['error'][0]), 'Un error no trae las claves que dice plan.md.');
        $this->assertSame([], array_values(array_unique($problems)), 'docs/realign/plan.md «Las salidas --format=json» no coincide con la salida real.');

        // ── Los formatos anidados.
        $blocks = $this->shapeBlocks('diagnose.md');
        $this->assertCount(2, $blocks, 'diagnose.md trae dos formatos: la salida y report.json.');

        // El informe del proyecto desviado, antes de arreglar nada.
        $this->assertNotSame([], $report['files']);
        $this->assertNotSame([], $report['unmanaged']);
        $this->assertNotSame([], $report['findings']);

        $this->assertSame([], $this->differences($this->parseShape($blocks[0]), $diagnose, 'diagnose'), 'docs/realign/diagnose.md no describe la salida de diagnose.');
        $this->assertSame([], $this->differences($this->parseShape($blocks[1]), $report, 'report.json'), 'docs/realign/diagnose.md no describe report.json.');

        $blocks = $this->shapeBlocks('owner.md');
        $this->assertCount(1, $blocks);
        $this->assertSame([], $this->differences($this->parseShape($blocks[0]), $brief, 'brief'), 'docs/realign/owner.md no describe la salida de brief.');
        $this->assertSame($brief, json_decode($this->project->read('.larapack/realign/brief.json'), true) + ['ok' => true] + $brief, 'brief.json no es el documento que da brief.');

        // ── Los campos de un paso.
        preg_match('/\n## Los pasos\n(.*?)\n## /s', $this->page('plan.md'), $section);
        preg_match_all('/^\| ((?:`[a-z_]+`(?:, )?)+) \|/m', $section[1] ?? '', $cells);
        preg_match_all('/`([a-z_]+)`/', implode(' ', $cells[1]), $fields);
        $undocumented = [];

        foreach ($this->plan()['steps'] as $step) {
            array_push($undocumented, ...array_diff(array_keys($step), $fields[1]));
        }

        $this->assertSame([], array_values(array_unique($undocumented)), 'plan.json guarda campos de un paso que docs/realign/plan.md «Los pasos» no explica.');

        foreach (['id', 'category', 'type', 'title', 'scope', 'files', 'details', 'risk', 'done_when', 'status', 'history', 'order', 'subject'] as $field) {
            $this->assertContains($field, $fields[1], "plan.md «Los pasos» no explica `{$field}`.");
        }

        preg_match('/`plan\.json`: `\{([^}]+)\}`/s', $this->page('plan.md'), $match);
        $this->assertSame(array_keys($this->plan()), array_map('trim', explode(',', (string) preg_replace('/\s+/', ' ', $match[1] ?? ''))), 'plan.md no dice las claves de plan.json.');
    }

    public function test_los_atascos_de_la_guia_se_comportan_como_dice(): void
    {
        // Las frases del comando que la guía cita, sin los saltos de línea
        // del párrafo.
        $faq = (string) preg_replace('/\s+/', ' ', $this->page('faq.md'));
        $this->deviate();
        $this->realign('diagnose');
        $this->realign('plan');

        // «El árbol git está sucio»: lo que escribe se niega; simular, no.
        file_put_contents($this->project->path.'/notas.txt', 'sin comitear');
        $dirty = $this->realign('adopt', [], Command::FAILURE);
        $this->assertStringContainsString('El árbol git tiene cambios sin comitear', $dirty['error']);
        $this->assertStringContainsString('El árbol git tiene cambios sin comitear', $faq);
        $this->realign('adopt', ['--dry-run' => true]);
        unlink($this->project->path.'/notas.txt');
        // El estado en .larapack/realign/ (el plan recién escrito, sin
        // comitear) no cuenta como sucio.
        $this->assertStringContainsString('.larapack/realign/', $this->git('status', '--short'));
        $this->realign('adopt');
        $this->commitAll();

        // «Hice el paso y sigue pendiente»: aplicar no lo da por hecho, y lo
        // que espera a él sigue bloqueado hasta la pasada siguiente (o check).
        $blocked = $this->realign('decide', ['targets' => ['route-closure@.', 'except']]);
        $this->assertSame('route-closure@.', $blocked['id']);
        $this->assertStringContainsString('espera a: adopt@.', $this->realign('except', ['targets' => ['route-closure@.'], '--option' => ['reason=Salud.']], Command::FAILURE)['error']);
        $this->realign('diagnose');
        $this->realign('plan');
        $this->assertSame(Planner::DONE, $this->step('adopt@.')['status']);
        $this->realign('decide', ['targets' => ['route-closure@.', 'refactor']]);

        // «apply dice que no hay pasos pendientes y sale con error».
        $nothing = $this->realign('apply', ['--deterministic' => true], Command::FAILURE);
        $this->assertStringContainsString('No hay pasos pendientes que `apply` sepa hacer', $nothing['error']);
        $this->assertStringContainsString('No hay pasos pendientes que `apply` sepa hacer', $faq);
        $this->assertStringContainsString('No hay pasos pendientes que `apply` sepa hacer', (string) preg_replace('/\s+/', ' ', $this->page('README.md')));

        // «except se niega»: en una decisión que nadie tomó.
        $refused = $this->realign('except', ['targets' => ['unmanaged-http@.'], '--option' => ['reason=Propio.']], Command::FAILURE);
        $this->assertStringContainsString('es una decisión que nadie ha tomado', $refused['error']);
        $this->assertStringContainsString('es una decisión que nadie ha tomado', $faq);

        // «next me da una decisión y no soy el dueño»: sus opciones, y cómo
        // se decide. Con qué seguir mientras (`meanwhile`) lo da next sin id
        // (RealignNavigationTest); con un id, es ese paso y nada más.
        $decision = $this->realign('next', ['targets' => ['unmanaged-http@.']]);
        $this->assertSame('decision', $decision['step']['category']);
        $this->assertSame(['declare', 'keep'], array_column($decision['how']['options'], 'key'));
        $this->assertNull($decision['meanwhile']);

        // «Un paso no cabe en un hueco»: escalate lo convierte en una
        // decisión con refactor y dejarlo como está.
        $escalated = $this->realign('escalate', ['targets' => ['edited-outside:Controller@.'], '--note' => 'No cabe.']);
        $this->assertSame(['refactor', 'except'], array_column($escalated['options'], 'key'));
        $this->assertSame('decision', $this->step('edited-outside:Controller@.')['category']);

        // «check dice untested»: sale con 0, cuenta para seguir y no para terminar.
        file_put_contents($this->project->path.'/'.self::CONTROLLER, $this->generated);
        $check = $this->realign('check', ['targets' => [self::SHAPE]]);
        $this->assertSame([true, false, Planner::UNTESTED], [$check['ok'], $check['done'], $check['status']]);
        $this->assertNotSame(self::SHAPE, $this->realign('next')['step']['id'] ?? null);
        $this->assertContains(self::SHAPE, array_column($this->realign('done', [], Command::FAILURE)['missing']['untested'], 'id'));

        // «Soy el dueño y no tengo terminal»: aceptar sin probar se niega sin
        // ella, también nombrándose fuera del workflow de aprobación.
        OwnerConfirmation::useTerminal(fn (): null => null);
        $refused = $this->realign('accept-untested', ['targets' => [self::SHAPE], '--note' => 'x'], Command::FAILURE);
        $this->assertStringContainsString('en su terminal', $refused['error']);

        putenv(OwnerConfirmation::APPROVER_ENV.'=ana');

        try {
            $named = $this->realign('accept-untested', ['targets' => [self::SHAPE], '--note' => 'x', '--approver' => 'ana'], Command::FAILURE);
        } finally {
            putenv(OwnerConfirmation::APPROVER_ENV);
        }

        $this->assertStringContainsString('sólo vale dentro del workflow de aprobación', $named['error']);
        $this->assertSame(Planner::UNTESTED, $this->step(self::SHAPE)['status']);

        // Con las pruebas en verde, la salida es --tested.
        $tested = $this->realign('check', ['targets' => [self::SHAPE], '--tested' => 'vendor/bin/phpunit']);
        $this->assertTrue($tested['done']);

        // «El proyecto es un monorepo»: --package acota, también con `app`.
        foreach (['status', 'next', 'brief'] as $action) {
            $this->realign($action, ['--package' => ['app']]);
        }
    }

    /**
     * Lo que desvía un proyecto de verdad: lógica en un controlador generado,
     * un archivo que sólo cambió de formato y un closure en las rutas.
     */
    private function deviate(): void
    {
        $this->generated = $this->project->read(self::CONTROLLER);
        $this->edit(self::CONTROLLER, "public function show(ShowRequest \$request)\n    {\n        return \$request->handle();",
            "public function show(ShowRequest \$request)\n    {\n        \$request->validate(['post_id' => 'required']);\n\n        return \$request->handle();");
        file_put_contents($this->project->path.'/'.self::FORMATTED, str_replace('    ', "\t", $this->project->read(self::FORMATTED)));
        @mkdir($this->project->path.'/routes', 0777, true);
        file_put_contents($this->project->path.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/ci/test-health', function () {\n    return response()->json(['ok' => true]);\n});\n");
        $this->commitAll();
    }

    private function page(string $page): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/docs/realign/'.$page);
    }

    /**
     * Las filas de la tabla «Las salidas --format=json» de plan.md: las
     * acciones de cada una y sus claves (con `?` las opcionales).
     *
     * @return list<array{0: list<string>, 1: list<string>}>
     */
    private function outputsTable(): array
    {
        preg_match('/\n### Las salidas `--format=json`\n(.*?)\n### /s', $this->page('plan.md'), $section);
        $rows = [];

        foreach (explode("\n", $section[1] ?? '') as $line) {
            if (! str_starts_with($line, '| `')) {
                continue;
            }

            [$actions, $keys] = array_map('trim', explode('|', trim($line, '| ')));
            preg_match_all('/`([^`]+)`/', $actions, $names);
            preg_match_all('/`([^`]+)`/', $keys, $listed);
            $rows[] = [$names[1], $listed[1]];
        }

        $this->assertGreaterThan(10, count($rows), 'plan.md no trae la tabla de las salidas JSON.');

        return $rows;
    }

    /**
     * Los bloques de una página escritos en la notación de formatos: los que
     * empiezan por `{format_version`.
     *
     * @return list<string>
     */
    private function shapeBlocks(string $page): array
    {
        preg_match_all('/^```\n(\{format_version.*?)^```$/ms', $this->page($page), $blocks);

        return $blocks[1];
    }

    /**
     * La notación `{a, b?: {c}, d: [{e}], <clave>: {f}}` como un árbol:
     * clave => [opcional, hijos|null, es una lista]. `<…>` es cualquier clave.
     *
     * @return array<string, array{0: bool, 1: array<string, mixed>|null, 2: bool}>
     */
    private function parseShape(string $text): array
    {
        $text = trim($text);
        $position = 0;
        $shape = $this->parseObject($text, $position);

        $this->assertSame(strlen($text), $position, 'El formato no se leyó entero: sobra «'.substr($text, $position, 40).'».');

        return $shape;
    }

    /**
     * @return array<string, array{0: bool, 1: array<string, mixed>|null, 2: bool}>
     */
    private function parseObject(string $text, int &$position): array
    {
        $this->assertSame('{', $text[$position] ?? '', 'Se esperaba «{» en el formato, en «'.substr($text, $position, 40).'».');
        $position++;
        $shape = [];

        while (true) {
            $position += strspn($text, " \n\t", $position);
            $length = strcspn($text, ',:{}[]', $position);
            $key = trim(substr($text, $position, $length));
            $position += $length;
            $optional = str_ends_with($key, '?');
            $children = null;

            if (($text[$position] ?? '') === ':') {
                $position++;
                $position += strspn($text, " \n\t", $position);
                $list = ($text[$position] ?? '') === '[';

                if ($list) {
                    $position++;
                }

                $children = $this->parseObject($text, $position);

                if ($list) {
                    $this->assertSame(']', $text[$position] ?? '', 'Una lista del formato no se cierra.');
                    $position++;
                }
            }

            $shape[rtrim($key, '?')] = [$optional, $children, $list ?? false];
            unset($list);
            $position += strspn($text, " \n\t", $position);

            if (($text[$position] ?? '') === ',') {
                $position++;

                continue;
            }

            $this->assertSame('}', $text[$position] ?? '', 'Un objeto del formato no se cierra, en «'.substr($text, $position, 40).'».');
            $position++;

            return $shape;
        }
    }

    /**
     * En qué difiere un documento real de su formato documentado.
     *
     * @param  array<string, array{0: bool, 1: array<string, mixed>|null, 2: bool}>  $shape
     * @return list<string>
     */
    private function differences(array $shape, mixed $real, string $path): array
    {
        if (! is_array($real)) {
            return [];
        }

        // `<ámbito>`: cualquier clave, con esa forma.
        if (count($shape) === 1 && str_starts_with((string) array_key_first($shape), '<')) {
            $problems = [];

            foreach ($real as $item) {
                array_push($problems, ...$this->differences(current($shape)[1] ?? [], $item, $path.'.'.array_key_first($shape)));
            }

            return array_values(array_unique($problems));
        }

        $problems = [];

        foreach (array_diff(array_keys($real), array_keys($shape)) as $key) {
            $problems[] = "{$path}.{$key} existe y la guía no la documenta";
        }

        foreach ($shape as $key => [$optional, $children, $list]) {
            if (! array_key_exists($key, $real)) {
                if (! $optional) {
                    $problems[] = "{$path}.{$key} está en la guía y no en la salida";
                }

                continue;
            }

            if ($children === null) {
                continue;
            }

            // Una lista: cada elemento tiene la forma (vacía, no hay qué mirar).
            foreach ($list ? (array) $real[$key] : [$real[$key]] as $item) {
                array_push($problems, ...$this->differences($children, $item, $path.'.'.$key.($list ? '[]' : '')));
            }
        }

        return array_values(array_unique($problems));
    }
}
