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

    public function test_un_playbook_que_no_existe_cae_en_el_generico(): void
    {
        $this->assertSame(Playbooks::GENERIC, Playbooks::resolve('no-existe'));
        $this->assertSame(Playbooks::GENERIC, Playbooks::resolve('../../etc/passwd'));
        $this->assertSame('controller-shape', Playbooks::resolve('controller-shape'));

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
}
