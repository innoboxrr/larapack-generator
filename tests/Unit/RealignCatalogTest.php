<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Commands\Realign\Action;
use Innoboxrr\LarapackGenerator\Commands\RealignCommand;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Innoboxrr\LarapackGenerator\Support\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El catálogo del realineamiento dice lo mismo en todos sus sitios: cada
 * tipo de paso tiene su categoría, su riesgo con su razón y su forma de
 * ejecutarse; cada guiado, un playbook que se puede rellenar; cada
 * comprobación de verify, un paso; y la documentación nombra todo lo que hay.
 * Es la red para quien añada un tipo, un playbook o una comprobación.
 */
final class RealignCatalogTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function types(): array
    {
        $cases = [];

        foreach (array_keys(StepTypes::TYPES) as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    #[DataProvider('types')]
    public function test_cada_tipo_dice_su_riesgo_y_como_se_hace(string $type): void
    {
        $meta = StepTypes::TYPES[$type];

        $this->assertContains($meta['category'], [Category::DETERMINISTIC, Category::GUIDED, Category::DECISION], "{$type}: lo fuera de alcance no es un paso.");
        $this->assertContains($meta['risk'], [StepTypes::LOW, StepTypes::MEDIUM, StepTypes::HIGH]);
        $this->assertNotSame('', trim($meta['why']), "{$type}: un riesgo sin razón no se puede revisar.");
        $this->assertNotSame('', trim($meta['title']));
        $this->assertContains($type, StepTypes::ORDER, "{$type} no tiene sitio en el orden del plan.");

        match ($meta['category']) {
            Category::DETERMINISTIC => $this->assertNotEmpty($meta['run'] ?? null, "{$type}: un paso determinista se ejecuta con una orden."),
            Category::GUIDED => $this->assertNotEmpty($meta['playbook'] ?? null, "{$type}: un paso guiado tiene playbook (o `generic`)."),
            default => $this->assertGreaterThanOrEqual(2, count($meta['options'] ?? []), "{$type}: una decisión con una sola opción no es una decisión."),
        };

        foreach ($meta['options'] ?? [] as $option) {
            $this->assertNotSame('', trim($option['consequences']), "{$type}/{$option['key']}: una opción dice qué pasa al elegirla.");
        }

        if (isset($meta['options'])) {
            $keys = array_column($meta['options'], 'key');
            $this->assertSame(array_values(array_unique($keys)), $keys);
        }
    }

    public function test_el_orden_no_nombra_tipos_que_no_existen(): void
    {
        $this->assertSame([], array_diff(StepTypes::ORDER, array_keys(StepTypes::TYPES)));
    }

    public function test_cada_comprobacion_de_verify_pide_un_paso_conocido(): void
    {
        foreach (Verifier::CHECKS as $check) {
            $type = StepTypes::forFinding(['check' => $check, 'message' => '']);

            $this->assertTrue($type === null || StepTypes::exists($type), "{$check} pide un paso {$type} que no existe.");
        }

        // Una comprobación nueva que nadie clasificó no se pierde: va al genérico.
        $this->assertSame('verify-other', StepTypes::forFinding(['check' => 'una-nueva', 'message' => '']));
        // El fallback de la SPA lo arregla fix; otro closure es una decisión.
        $this->assertSame('fix', StepTypes::forFinding(['check' => 'route-closure', 'message' => '… (`larapack:fix route-closure` lo lleva a SpaController…']));
        $this->assertSame('route-closure', StepTypes::forFinding(['check' => 'route-closure', 'message' => 'Otro closure.']));
    }

    public function test_las_ordenes_de_las_operaciones_nombran_una_accion_que_existe(): void
    {
        foreach (StepTypes::TYPES as $type => $meta) {
            foreach ([$meta['run'] ?? null, ...array_column($meta['options'] ?? [], 'run')] as $run) {
                if (is_string($run) && preg_match('/^larapack:realign (\w+)/', $run, $match) === 1) {
                    $this->assertContains($match[1], Operations::ACTIONS, "{$type}: `{$run}` no es una operación del realineamiento.");
                }
            }
        }
    }

    public function test_cada_playbook_usa_solo_marcadores_que_existen(): void
    {
        $this->assertContains(Playbooks::GENERIC, Playbooks::names());

        foreach (Playbooks::names() as $name) {
            $this->assertSame([], array_diff(Playbooks::placeholdersOf($name), array_keys(Playbooks::PLACEHOLDERS)), "El playbook {$name} usa marcadores que no existen.");
        }
    }

    public function test_cada_playbook_que_se_nombra_existe(): void
    {
        // El de un tipo guiado puede faltar a propósito y caer en el genérico
        // (test_un_playbook_que_no_existe_cae_en_el_generico); el de una
        // decisión o una opción, no: se nombra para mostrarlo.
        foreach (StepTypes::TYPES as $type => $meta) {
            $own = $meta['category'] === Category::GUIDED ? null : ($meta['playbook'] ?? null);

            foreach ([$own, ...array_column($meta['options'] ?? [], 'playbook')] as $playbook) {
                if ($playbook !== null) {
                    $this->assertTrue(Playbooks::exists($playbook), "{$type} nombra el playbook {$playbook}, que no está en resources/realign/playbooks.");
                }
            }
        }

        // Los anexos que puede pedir un controlador.
        $annexes = Playbooks::annexesFor('controller-shape', [
            ['message' => 'X::a() (línea 3) no delega: recibe Illuminate\\Http\\Request, valida en el controlador con validate(). …'],
            ['message' => 'X::b() (línea 9) tipa $request con illuminate\\http\\request, que no tiene handle(). …'],
        ]);
        $this->assertSame(['raw-request-to-formrequest', 'validate-to-rules'], $this->sorted($annexes));

        foreach ($annexes as $annex) {
            $this->assertTrue(Playbooks::exists($annex));
        }

        $this->assertSame([], Playbooks::annexesFor('controller-shape', [['message' => 'X::a() (línea 3) no delega: hace algo más que `return $request->handle();`.']]));
        $this->assertSame([], Playbooks::annexesFor('security', [['message' => 'valida en el controlador con validate()']]));
    }

    public function test_la_guia_de_los_pasos_guiados_nombra_cada_playbook(): void
    {
        $guide = (string) file_get_contents(dirname(__DIR__, 2).'/docs/realign/guided.md');

        foreach (Playbooks::names() as $name) {
            $this->assertStringContainsString('`'.$name.'`', $guide, "docs/realign/guided.md no nombra el playbook `{$name}`.");
        }
    }

    public function test_un_playbook_se_lee_sin_un_paso(): void
    {
        $described = Playbooks::describe('controller-to-request');

        $this->assertStringNotContainsString('{{', $described);
        $this->assertStringContainsString('<la orden que comprueba que está hecho>', $described);
    }

    public function test_un_playbook_que_no_existe_cae_en_el_generico(): void
    {
        $this->assertSame(Playbooks::GENERIC, Playbooks::resolve('no-existe'));
        $this->assertSame(Playbooks::GENERIC, Playbooks::resolve('../../etc/passwd'));
        $this->assertSame('controller-to-request', Playbooks::resolve('controller-to-request'));

        $rendered = Playbooks::render(['id' => 'x@.', 'playbook' => 'no-existe', 'title' => 'T', 'scope' => ['package' => null, 'path' => '.'],
            'risk' => ['level' => 'low', 'why' => 'w'], 'done_when' => ['kind' => 'decision'], 'details' => ['files' => [], 'findings' => [], 'unmanaged' => []]]);

        $this->assertStringContainsString('larapack:realign check x@.', $rendered);
        $this->assertStringContainsString('app (núcleo)', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    /**
     * Cada nombre del catálogo, en la guía que lo explica: las categorías y
     * las acciones en la de entrada, las clases de archivo en la del
     * diagnóstico, los tipos de paso y las comprobaciones de terminado en la
     * del plan.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function documentedNames(): array
    {
        return [
            'categorías' => ['README.md', Category::ALL],
            'acciones' => ['README.md', Operations::ACTIONS],
            'clases de archivo' => ['diagnose.md', FileClass::ALL],
            'tipos de paso' => ['plan.md', array_keys(StepTypes::TYPES)],
            'comprobaciones de terminado' => ['plan.md', DoneWhen::KINDS],
        ];
    }

    /**
     * @param  list<string>  $names
     */
    #[DataProvider('documentedNames')]
    public function test_la_documentacion_nombra_categorias_clases_tipos_y_comprobaciones(string $page, array $names): void
    {
        $guide = (string) file_get_contents(dirname(__DIR__, 2).'/docs/realign/'.$page);

        foreach ($names as $name) {
            $this->assertStringContainsString('`'.$name.'`', $guide, "docs/realign/{$page} no nombra `{$name}`.");
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Una acción descrita y sin clase respondería «acción desconocida»; una
     * clase sin descripción no saldría en la ayuda ni tendría sus opciones.
     */
    public function test_cada_accion_descrita_tiene_quien_la_haga(): void
    {
        $described = array_keys(RealignActions::ALL);
        $handled = [...array_keys(RealignCommand::HANDLERS), ...Operations::ACTIONS];

        sort($described);
        sort($handled);

        $this->assertSame($described, $handled, 'RealignActions::ALL y RealignCommand::HANDLERS + Operations::ACTIONS no nombran las mismas acciones.');

        $counted = array_keys(RealignActions::ARGUMENTS);
        sort($counted);

        $this->assertSame($described, $counted, 'RealignActions::ARGUMENTS no dice cuántos argumentos admite cada acción.');

        foreach (RealignCommand::HANDLERS as $action => $class) {
            $this->assertTrue(is_subclass_of($class, Action::class), "{$action}: {$class} no es una Action.");
        }

        foreach (RealignActions::WITHOUT_PROJECT as $action) {
            $this->assertArrayHasKey($action, RealignActions::ALL);
        }
    }

    /**
     * ARCHITECTURE.md es el mapa de quien mantiene esto: una pieza del
     * realineamiento que no aparece en su sección es una pieza que nadie
     * sabrá que existe. Cada clase se cita con su nombre.
     */
    public function test_la_arquitectura_nombra_cada_pieza_del_realineamiento(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__, 2).'/ARCHITECTURE.md');
        $start = strpos($document, "\n## `realign`");
        $end = strpos($document, "\n## ", (int) $start + 1);

        $this->assertNotFalse($start, 'ARCHITECTURE.md ya no tiene la sección `realign`.');
        $this->assertNotFalse($end);

        $section = substr($document, $start, $end - $start);
        $missing = [];

        foreach (['src/Support/Realign', 'src/Commands/Realign'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $class = $file->getBasename('.php');

                if ($file->getExtension() === 'php' && preg_match('/\b'.preg_quote($class, '/').'\b/', $section) !== 1) {
                    $missing[] = $directory.'/…/'.$class;
                }
            }
        }

        sort($missing);

        $this->assertSame([], $missing, 'La sección `realign` de ARCHITECTURE.md no nombra estas clases.');
    }

    /**
     * El estado estático del realineamiento sobrevive de una prueba a la
     * siguiente si nadie lo limpia. Una propiedad estática nueva tiene que
     * entrar aquí a sabiendas, con su limpieza en tests/TestCase.php (o con
     * la razón por la que no la necesita).
     */
    public function test_el_estado_estatico_del_realineamiento_esta_contado(): void
    {
        $known = [
            'Operations::$registered' => 'Operations::reset',
            'ScopeReference::$cache' => 'ScopeReference::forget',
            'ConcernMove::$files' => 'ConcernMove::reset',
            // El hash de las plantillas del paquete: no cambia en un proceso.
            'Regeneration::$stubsHash' => null,
            // Las cachés enlazadas de las simulaciones en curso: cada corrida
            // quita la suya en su `finally`, así que no queda nada entre pruebas.
            'Simulation::$caches' => null,
        ];
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Support/Realign', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            preg_match_all('/^\s*(?:private|protected|public) static [^(=;]*?(\$\w+)\s*[=;]/m', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $property) {
                $found[] = $file->getBasename('.php').'::'.$property;
            }
        }

        sort($found);
        $expected = array_keys($known);
        sort($expected);

        $this->assertSame($expected, $found);

        $teardown = (string) file_get_contents(dirname(__DIR__).'/TestCase.php');

        foreach (array_filter($known) as $reset) {
            $this->assertStringContainsString($reset.'()', $teardown, "tests/TestCase.php no llama a {$reset}().");
        }
    }
}
