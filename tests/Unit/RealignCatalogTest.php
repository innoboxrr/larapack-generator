<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\Playbooks;
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

    public function test_la_documentacion_nombra_categorias_clases_tipos_y_comprobaciones(): void
    {
        $guide = (string) file_get_contents(dirname(__DIR__, 2).'/docs/realign/README.md');

        foreach ([...Category::ALL, ...FileClass::ALL, ...array_keys(StepTypes::TYPES), ...DoneWhen::KINDS, ...Operations::ACTIONS] as $name) {
            $this->assertStringContainsString('`'.$name.'`', $guide, "docs/realign/README.md no nombra `{$name}`.");
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
}
