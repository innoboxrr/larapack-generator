<?php

namespace Innoboxrr\LarapackGenerator\Tests\Support;

use Innoboxrr\LarapackGenerator\Support\HandRolledLock;
use Innoboxrr\LarapackGenerator\Support\Realign\Exceptions;
use Innoboxrr\LarapackGenerator\Support\Realign\ExceptionTargets;
use Innoboxrr\LarapackGenerator\Support\Realign\RealignState;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Command\Command;

/**
 * Un proyecto mínimo para probar las clases de `Support/Realign` llamándolas
 * directamente, sin `larapack:realign`: un paquete sin nada de LaraPack, o
 * uno generado desde un contrato de un modelo (décimas de segundo, frente a
 * los segundos del laraimport de las pruebas con React de RealignProject).
 *
 * Lo que una acción dejaría escrito —una decisión en el plan, una solicitud
 * de excepción, su aprobación en el candado— se escribe aquí con las mismas
 * clases que lo escriben de verdad, para que la prueba llegue al estado que
 * quiere mirar sin pasar por el comando ni por la terminal del dueño.
 */
trait SmallRealignProject
{
    /**
     * Un paquete sin contrato ni manifiesto.
     */
    protected function bare(): FakeProject
    {
        return $this->useProject(FakeProject::library('Acme\\Blog\\'));
    }

    /**
     * Un paquete generado desde un contrato con un modelo, Post.
     */
    protected function generated(): FakeProject
    {
        $project = $this->bare();
        $this->write('laraimport.json', (string) json_encode(self::smallContract(), JSON_PRETTY_PRINT));

        Assert::assertSame(Command::SUCCESS, $this->runCommand('larapack:import', ['--format' => 'json']), $this->lastOutput);

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function smallContract(): array
    {
        return ['models' => [['name' => 'Post', 'props' => [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'fillable' => true, 'creatable' => true, 'updatable' => true],
        ]]]];
    }

    protected function write(string $relative, string $content): void
    {
        $path = $this->project->path.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Pide una excepción, como `larapack:realign except`.
     */
    protected function request(string $target, ?string $step, string $reason = 'Se queda así.', string $scope = '.'): void
    {
        $parts = (array) ExceptionTargets::parse($target);

        Exceptions::forRoot($this->project->path)->saveRequests([[
            'target' => $target, 'type' => (string) $parts['type'], 'check' => null, 'scope' => $scope, 'file' => (string) $parts['file'], 'subject' => null,
            'line' => null, 'step' => $step, 'message' => null, 'reason' => $reason, 'fingerprint' => null, 'requested_at' => '2026-01-01T00:00:00+00:00',
        ]]);
    }

    /**
     * Aprueba sobre el código de hoy, como el dueño con
     * `larapack:hand-rolled approve`. Lo que ya no existe se aprueba con una
     * huella que no es la de nada.
     *
     * @param  array<string, string>  $targets  objetivo => razón
     */
    protected function approve(array $targets): void
    {
        $approved = [];

        foreach ($targets as $target => $reason) {
            $approved[$target] = ['reason' => $reason, 'fingerprint' => ExceptionTargets::fingerprint($this->project->path, $target) ?? 'sha256:'.str_repeat('0', 64)];
        }

        HandRolledLock::write($this->project->path, $approved);
    }

    /**
     * Cambia un paso del plan guardado, como lo dejan `decide` o
     * `accept-untested`.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function changeStep(string $id, array $changes): void
    {
        $state = new RealignState($this->project->path);
        $plan = (array) $state->plan();

        foreach ($plan['steps'] as $index => $step) {
            if ($step['id'] === $id) {
                $plan['steps'][$index] = $changes + $step;
                $state->savePlan($plan);

                return;
            }
        }

        Assert::fail("No hay un paso {$id}. Hay: ".implode(', ', array_column($plan['steps'], 'id')));
    }
}
