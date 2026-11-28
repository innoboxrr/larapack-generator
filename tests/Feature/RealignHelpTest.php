<?php

namespace Innoboxrr\LarapackGenerator\Tests\Feature;

use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignActions;
use Innoboxrr\LarapackGenerator\Tests\Support\RealignProject;
use Innoboxrr\LarapackGenerator\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Lo que dice el propio comando de sí mismo: es lo primero que corre quien
 * llega sin contexto, a menudo desde fuera del proyecto, y tiene que bastar
 * para saber qué hacer sin abrir la documentación.
 */
final class RealignHelpTest extends TestCase
{
    use RealignProject;

    public function test_help_y_playbook_responden_sin_un_proyecto(): void
    {
        // Sin --root y desde el propio generador: no hay proyecto.
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'help']), $this->lastOutput);
        $this->assertStringContainsString('larapack:realign status', $this->lastOutput);

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'playbook']), $this->lastOutput);
        $this->assertStringContainsString('controller-to-request', $this->lastOutput);
        $this->assertStringNotContainsString('<el título', $this->lastOutput, 'Un playbook titulado con el del paso se lista por su tipo.');

        // Lo que sí lee el proyecto lo sigue pidiendo.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no un proyecto');
        $this->runCommand('larapack:realign', ['action' => 'status']);
    }

    public function test_help_de_una_accion_dice_su_uso_lo_que_escribe_sus_opciones_y_su_guia(): void
    {
        foreach (RealignActions::ALL as $action => $definition) {
            $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'help', 'targets' => [$action], '--format' => 'json']), $this->lastOutput);
            $document = json_decode($this->lastOutput, true);

            $this->assertSame($action, $document['topic']);
            $this->assertSame($definition['usage'], $document['usage']);
            $this->assertSame($definition['guide'], $document['guide']);
            $this->assertSame($definition['options'], $document['options']);
            $this->assertNotSame('', $document['writes']);
            $this->assertTrue($document['available'], "{$action} tiene que estar disponible en esta versión.");
        }

        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'help', 'targets' => ['infer']]));
        $this->assertStringContainsString('--option write=', $this->lastOutput);
        $this->assertStringContainsString('docs/realign/infer.md', $this->lastOutput);

        $this->assertSame(Command::FAILURE, $this->runCommand('larapack:realign', ['action' => 'help', 'targets' => ['arreglar'], '--format' => 'json']));
        $this->assertStringContainsString('help recibe a lo sumo una acción conocida', json_decode($this->lastOutput, true)['error']);
    }

    public function test_help_lista_cada_accion_con_su_guia_y_el_punto_de_entrada(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'help', '--format' => 'json']));
        $document = json_decode($this->lastOutput, true);

        $this->assertSame('larapack:realign status', $document['entry_point']);

        foreach (RealignActions::ALL as $action => $definition) {
            $this->assertSame($definition['guide'], $document['guides'][$action]);
        }
    }

    /**
     * `--help` explica cada acción: un agente que sólo sabe que existe el
     * comando aprende de ahí qué hace cada una y dónde está su guía.
     */
    public function test_la_ayuda_del_comando_explica_cada_accion(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['--help' => true]));

        foreach (RealignActions::ALL as $action => $definition) {
            $this->assertStringContainsString($definition['usage'][0], $this->lastOutput);
            $this->assertStringContainsString($definition['guide'], $this->lastOutput);
        }

        $this->assertStringContainsString('larapack:realign status', $this->lastOutput);
    }

    /**
     * Las descripciones de las operaciones salen del mismo sitio que la
     * ayuda: no pueden decir otra cosa (la de apply decía que sólo
     * regeneraba).
     */
    public function test_las_operaciones_se_describen_desde_actions(): void
    {
        foreach (Operations::ACTIONS as $operation) {
            $this->assertSame(RealignActions::ALL[$operation]['summary'], Operations::for($operation)->description());
        }
    }

    public function test_una_clave_de_option_que_la_operacion_no_lee_es_un_error(): void
    {
        $this->generatedPackage();
        $this->realign('diagnose');
        $this->realign('plan');

        $document = $this->realign('infer', ['--option' => ['escribir=contract'], '--dry-run' => true], Command::FAILURE);
        $this->assertStringContainsString('no usa --option escribir', $document['error']);
        $this->assertStringContainsString('write, packages, http', $document['error']);

        $document = $this->realign('adopt', ['--option' => ['reason=x'], '--dry-run' => true], Command::FAILURE);
        $this->assertStringContainsString('adopt no lee ninguna clave', $document['error']);
    }

    /**
     * Una prueba de autonomía vio la ayuda dos veces (el texto completo y
     * luego la lista). `help` da la lista y `--help` el texto completo: cada
     * acción sale una sola vez en cada uno.
     */
    public function test_help_y_la_ayuda_del_comando_no_repiten_nada(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['action' => 'help']));
        $list = $this->lastOutput;
        $this->assertSame(Command::SUCCESS, $this->runCommand('larapack:realign', ['--help' => true]));
        $full = $this->lastOutput;

        foreach (array_keys(RealignActions::ALL) as $action) {
            $summary = RealignActions::summary($action);

            $this->assertSame($action === 'help' ? 0 : 1, substr_count($list, $summary), "`help` da {$action} más de una vez.");
            $this->assertSame(1, substr_count($full, $summary), "`--help` da {$action} más de una vez.");
        }

        // La lista no trae el texto de --help.
        $this->assertStringNotContainsString('Escribe:', $list);
    }
}
