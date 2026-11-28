<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Import\SemanticValidator;
use Innoboxrr\LarapackGenerator\Support\Realign\Category;
use Innoboxrr\LarapackGenerator\Support\Realign\Completion;
use Innoboxrr\LarapackGenerator\Support\Realign\Planner;
use Innoboxrr\LarapackGenerator\Support\Realign\StepTypes;
use Innoboxrr\LarapackGenerator\Tests\Support\SmallRealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;

/**
 * Completion sobre un ámbito con contrato: lo que sólo se ve comparando con
 * lo regenerado (lo editado fuera de los huecos, que verify no encuentra) y
 * lo que dice validate de un laraimport.
 *
 * Va aparte de CompletionTest porque regenera el contrato en un proceso hijo
 * (Regeneration), y por eso no está en el `--filter` de infection.json5: bajo
 * Infection ese `proc_open` falla siempre y la prueba «mataría» cualquier
 * mutante que recorra (docs/mutacion.md).
 */
final class CompletionWithContractTest extends TestCase
{
    use SmallRealignProject;

    private const REQUEST = 'src/Http/Requests/Post/DeleteRequest.php';

    private const STEP = 'edited-outside:Requests@.';

    private const AUTHORIZE = "public function authorize(): bool\n    {";

    /**
     * @return array<string, mixed>
     */
    private function completion(): array
    {
        return (new Completion($this->project->path, 1))->run();
    }

    public function test_un_paquete_generado_y_sin_tocar_esta_terminado(): void
    {
        $this->generated();

        $result = $this->completion();

        $this->assertTrue($result['done']);
        $this->assertSame(array_fill_keys(Completion::KEYS, []), $result['missing']);
        $this->assertSame(array_fill_keys(Completion::KEYS, 0), $result['counts']);
        $this->assertSame([], $result['reasons']);
        $this->assertSame([], $result['declared']);
        $this->assertSame([], $result['accepted_untested']);
    }

    public function test_lo_editado_fuera_de_un_hueco_falta_aunque_verify_estricto_pase_y_devuelto_queda_sin_probar(): void
    {
        $this->generated();
        // La autorización editada fuera de su hueco: verify no lo ve, el
        // diagnóstico sí, y devolverla cambia lo que responde la acción.
        $generated = $this->project->read(self::REQUEST);
        $this->assertStringContainsString(self::AUTHORIZE, $generated);
        $this->write(self::REQUEST, str_replace(self::AUTHORIZE, self::AUTHORIZE."\n        if (\$this->user()?->isAdmin()) {\n            return true;\n        }\n", $generated));

        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertSame([[
            'id' => self::STEP, 'title' => StepTypes::TYPES['edited-outside']['title'], 'category' => Category::GUIDED, 'status' => Planner::PENDING, 'scope' => '.',
            'check' => 'larapack:realign check '.self::STEP,
        ]], $result['missing']['steps']);
        $this->assertSame([], $result['missing']['verify']);
        $this->assertSame(['1 pasos del plan sin hacer (larapack:realign next)'], $result['reasons']);

        // Alguien lo devuelve sin correr check ni las pruebas.
        $this->write(self::REQUEST, $generated);
        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertSame([self::STEP], array_column($result['missing']['untested'], 'id'));
        $this->assertSame(['steps' => 0, 'decisions' => 0, 'untested' => 1, 'exceptions' => 0, 'validate' => 0, 'verify' => 0], $result['counts']);
    }

    public function test_validate_estricto_cuenta_los_avisos_como_los_errores(): void
    {
        $this->bare();
        copy(dirname(__DIR__).'/Fixtures/laraimport.json', $this->project->path.'/laraimport.json');

        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertNotSame([], $result['missing']['validate']);
        $this->assertSame([SemanticValidator::WARNING], array_values(array_unique(array_column($result['missing']['validate'], 'level'))));
        $this->assertSame(count($result['missing']['validate']), $result['counts']['validate']);
        $this->assertContains($result['counts']['validate'].' errores o avisos de larapack:validate --strict', $result['reasons']);
    }

    public function test_un_contrato_que_aparece_en_lo_declarado_fuera_si_se_valida(): void
    {
        $this->bare();
        $this->completion();
        $target = 'realign:no-contract:laraimport.json';
        $this->changeStep('no-contract@.', ['decision' => ['option' => 'keep', 'note' => null, 'at' => 'antes']]);
        $this->request($target, 'no-contract@.', 'Fuera.');
        $this->approve([$target => 'Fuera.']);
        $this->assertTrue($this->completion()['done']);

        // Alguien escribe un laraimport que no vale: ya no es «no tiene contrato».
        $this->write('laraimport.json', '{"models": "no es una lista"}');
        $result = $this->completion();

        $this->assertFalse($result['done']);
        $this->assertNotSame([], $result['missing']['validate']);
        $this->assertSame([], array_values(array_filter($result['declared'], fn (array $row): bool => $row['source'] === 'validate')));
    }
}
