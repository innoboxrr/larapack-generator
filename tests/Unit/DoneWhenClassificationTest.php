<?php

namespace Innoboxrr\LarapackGenerator\Tests\Unit;

use Innoboxrr\LarapackGenerator\Support\Manifest;
use Innoboxrr\LarapackGenerator\Support\Realign\DoneWhen;
use Innoboxrr\LarapackGenerator\Support\Realign\FileClass;
use Innoboxrr\LarapackGenerator\Tests\Support\SmallRealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;

/**
 * El `kind: classification` de DoneWhen: cada archivo del paso, comparado
 * otra vez con lo regenerado, tiene que quedar donde el paso dice. Es lo que
 * da por hecho un `adopt`, un `regenerate` o lo devuelto a sus huecos.
 *
 * Va aparte de DoneWhenTest porque necesita la referencia: regenera el
 * contrato en un proceso hijo (Regeneration). Por eso estas pruebas no están
 * en el `--filter` de infection.json5: bajo Infection, `proc_open` con un
 * descriptor `file` falla siempre (el `file://` es de su interceptor), la
 * prueba da error con cualquier mutante y todos saldrían «muertos»
 * (docs/mutacion.md).
 */
final class DoneWhenClassificationTest extends TestCase
{
    use SmallRealignProject;

    private const CONTROLLER = 'src/Http/Controllers/PostController.php';

    private const POLICY = 'src/Policies/PostPolicy.php';

    private const OBSERVER = 'src/Observers/PostObserver.php';

    /**
     * @param  array<string, mixed>  $step
     * @return array{done: bool, remaining: list<string>, kind: string}
     */
    private function check(array $step): array
    {
        return (new DoneWhen($this->project->path))->check($step);
    }

    public function test_cada_archivo_tiene_que_quedar_en_una_clase_que_se_acepta(): void
    {
        $this->generated();
        $accept = fn (array $files, array $classes = [FileClass::IDENTICAL, FileClass::FORMAT_ONLY]): array => ['id' => 'regenerate@.', 'done_when' => ['kind' => 'classification', 'files' => $files, 'accept' => $classes]];

        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'classification'], $this->check($accept([self::CONTROLLER, self::POLICY])));

        // Sólo cambia el formato: igual salvo formato.
        $this->write(self::POLICY, str_replace('    ', "\t", $this->project->read(self::POLICY)));
        $this->assertTrue($this->check($accept([self::POLICY]))['done']);
        $this->assertSame(
            [self::POLICY.': es format_only (igual salvo espacios u orden de imports); se espera identical.'],
            $this->check($accept([self::POLICY], [FileClass::IDENTICAL]))['remaining']
        );

        // Un método más en un controlador generado: editado fuera de los huecos.
        $this->write(self::CONTROLLER, preg_replace('/}\s*$/', "\n    public function extra()\n    {\n        return 1;\n    }\n}\n", $this->project->read(self::CONTROLLER)));
        $this->assertSame(
            [self::CONTROLLER.': es edited_outside (editado fuera de los huecos: method:extra, outside); se espera identical o format_only.'],
            $this->check($accept([self::CONTROLLER, self::POLICY]))['remaining']
        );
        $this->assertTrue($this->check($accept([self::CONTROLLER], [FileClass::EDITED_OUTSIDE]))['done']);

        // Sin clases que aceptar ni rechazar no se exige ninguna.
        $this->assertTrue($this->check(['id' => 'x', 'done_when' => ['kind' => 'classification', 'files' => [self::CONTROLLER]]])['done']);
    }

    public function test_ningun_archivo_puede_quedar_en_una_clase_que_se_rechaza(): void
    {
        $this->generated();
        $reject = fn (array $classes): array => ['id' => 'relink@.', 'done_when' => ['kind' => 'classification', 'files' => [self::POLICY, self::CONTROLLER], 'reject' => $classes]];

        $this->assertTrue($this->check($reject([FileClass::RENAMED, FileClass::MISSING]))['done']);

        unlink($this->project->path.'/'.self::POLICY);

        $this->assertSame(
            [self::POLICY.': sigue siendo missing (borrado, y el contrato lo sigue generando).'],
            $this->check($reject([FileClass::RENAMED, FileClass::MISSING]))['remaining']
        );
        $this->assertTrue($this->check($reject([FileClass::RENAMED]))['done']);
    }

    public function test_adoptar_no_esta_hecho_hasta_que_el_manifiesto_lo_registra_con_el_hash_de_hoy(): void
    {
        $this->generated();
        $adopt = fn (string $file, array $flags = ['tracked' => true, 'hash_current' => true]): array => ['id' => 'adopt@.', 'done_when' => [
            'kind' => 'classification', 'files' => [$file], 'accept' => [FileClass::IDENTICAL, FileClass::FORMAT_ONLY]] + $flags];

        $this->assertTrue($this->check($adopt(self::OBSERVER))['done']);

        // Idéntico a lo regenerado, pero el manifiesto no lo conoce.
        $this->assertTrue((new Manifest($this->project->path))->drop(self::OBSERVER));
        $this->assertSame([self::OBSERVER.': el manifiesto todavía no lo registra.'], $this->check($adopt(self::OBSERVER))['remaining']);
        $this->assertSame([self::OBSERVER.': el manifiesto todavía no lo registra.'], $this->check($adopt(self::OBSERVER, ['tracked' => true]))['remaining']);
        // Lo exige el paso, no la comprobación: sin pedirlo, la clase basta.
        $this->assertTrue($this->check($adopt(self::OBSERVER, []))['done']);
        $this->assertTrue($this->check($adopt(self::OBSERVER, ['tracked' => 1, 'hash_current' => 1]))['done']);

        // Igual salvo formato, con el hash de antes de reformatearlo.
        $this->write(self::POLICY, str_replace('    ', "\t", $this->project->read(self::POLICY)));
        $this->assertSame([self::POLICY.': el hash del manifiesto todavía no es el de lo que hay en disco.'], $this->check($adopt(self::POLICY))['remaining']);
        $this->assertSame([self::POLICY.': el hash del manifiesto todavía no es el de lo que hay en disco.'], $this->check($adopt(self::POLICY, ['hash_current' => true]))['remaining']);
        $this->assertTrue($this->check($adopt(self::POLICY, ['tracked' => true]))['done']);

        // Adoptado: el manifiesto anota el hash de lo que hay.
        (new Manifest($this->project->path))->refresh($this->project->path.'/'.self::POLICY);
        $this->assertTrue($this->check($adopt(self::POLICY))['done']);
    }

    public function test_resuelto_es_que_el_archivo_ya_no_pide_ningun_paso(): void
    {
        $this->generated();
        $resolved = fn (array $files, array $extra = ['resolved' => true]): array => ['id' => 'missing@.', 'done_when' => ['kind' => 'classification', 'files' => $files] + $extra];

        $this->assertTrue($this->check($resolved([self::POLICY, self::CONTROLLER]))['done']);

        unlink($this->project->path.'/'.self::POLICY);

        $this->assertSame([self::POLICY.': sigue pendiente: borrado, y el contrato lo sigue generando.'], $this->check($resolved([self::POLICY, self::CONTROLLER]))['remaining']);
        $this->assertTrue($this->check($resolved([self::POLICY], []))['done']);
        $this->assertTrue($this->check($resolved([self::POLICY], ['resolved' => 1]))['done']);

        // Se decidió dejarlo borrado y el dueño lo aprobó: mientras no vuelva, resuelto.
        $this->approve(['realign:missing:'.self::POLICY => 'Las políticas van en otro paquete.']);
        $this->assertTrue($this->check($resolved([self::POLICY]))['done']);
    }

    public function test_una_excepcion_aprobada_sobre_el_codigo_de_hoy_cierra_el_archivo_sea_cual_sea_su_clase(): void
    {
        $this->generated();
        $this->write(self::CONTROLLER, preg_replace('/}\s*$/', "\n    public function extra()\n    {\n        return 1;\n    }\n}\n", $this->project->read(self::CONTROLLER)));
        $step = ['id' => 'edited-outside:Controller@.', 'done_when' => ['kind' => 'classification', 'files' => [self::CONTROLLER], 'accept' => FileClass::CONFORMING]];

        $this->assertFalse($this->check($step)['done']);

        // Sigue siendo edited_outside: pedirle otra clase haría que el paso
        // no terminara nunca.
        $this->approve(['realign:edited-outside:'.self::CONTROLLER => 'El método extra se queda.']);
        $this->assertSame(['done' => true, 'remaining' => [], 'kind' => 'classification'], $this->check($step));

        // Si el código cambia, la huella caduca y vuelve a contar su clase.
        $this->write(self::CONTROLLER, str_replace('return 1;', 'return 2;', $this->project->read(self::CONTROLLER)));
        $this->assertSame(
            [self::CONTROLLER.': es edited_outside (editado fuera de los huecos: method:extra, outside); se espera identical o format_only o hole_edited.'],
            $this->check($step)['remaining']
        );
    }
}
