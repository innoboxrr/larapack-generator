<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * Lo que una comprobación (`check`) o una aceptación del dueño
 * (`accept-untested`) hacen con el estado de un paso. Es la única pieza que
 * da un paso por hecho; no escribe nada: recibe el paso y lo devuelve como
 * queda, y quien llama guarda el plan.
 *
 * Las reglas, de check():
 *
 *  - El `done_when` no pasa: el paso sigue (o vuelve a estar) pendiente. Uno
 *    que estaba hecho se reabre y pierde su prueba y su aceptación.
 *  - Pasa y el paso no cambia comportamiento: hecho.
 *  - Pasa, cambia comportamiento (StepTypes::changesBehaviour()) y hay
 *    prueba (`$tested`): hecho, con la prueba anotada; una aceptación sin
 *    probar anterior sobra y se quita.
 *  - Pasa, cambia comportamiento y no hay prueba: `untested`, si estaba
 *    pendiente. Uno ya hecho y probado (o aceptado) no pierde eso por
 *    comprobarlo otra vez sin `--tested`.
 *
 * @phpstan-type Step array<string, mixed>
 * @phpstan-type Tested array{via: string, command: string, at: string}
 */
final class StepCheck
{
    /**
     * @param  Step  $step
     * @param  bool  $passes  si el `done_when` pasa (y, si se corrieron, las pruebas del proyecto)
     * @param  int  $remaining  cuántas cosas faltan
     * @param  Tested|null  $tested  la prueba registrada en esta comprobación
     * @param  string  $now  la hora, en ISO 8601
     * @return Step el paso como queda
     */
    public static function check(array $step, bool $passes, int $remaining, ?array $tested, string $now): array
    {
        // Una prueba sólo cuenta sobre un paso cuyo `done_when` pasa.
        $tested = $passes ? $tested : null;
        $untested = $passes && StepTypes::changesBehaviour($step) && $tested === null;
        $was = $step['status'] ?? Planner::PENDING;
        $condition = Playbooks::describeCondition((array) $step['done_when']);

        $step['last_check'] = ['id' => $step['id'], 'at' => $now, 'done' => $passes && ! $untested, 'remaining' => $remaining]
            + ($untested ? ['untested' => true] : []);

        if ($tested !== null) {
            $step['tested'] = $tested;
            unset($step['untested_accepted']);
        }

        if ($passes && ! $untested && ($was !== Planner::DONE || $tested !== null)) {
            $step['status'] = Planner::DONE;
            $step['history'][] = ['at' => $now, 'event' => 'done', 'detail' => 'Comprobado: '.$condition
                .($tested === null ? '' : ' Probado ('.($tested['via'] === 'config' ? 'la orden del proyecto' : 'lo declara quien lo hizo').'): '.$tested['command'].'.')];
        } elseif ($untested && $was === Planner::PENDING) {
            $step['status'] = Planner::UNTESTED;
            $step['history'][] = ['at' => $now, 'event' => 'untested', 'detail' => 'Comprobado sin probar: '.$condition];
        } elseif (! $passes && in_array($was, [Planner::DONE, Planner::UNTESTED], true)) {
            $step['status'] = Planner::PENDING;
            unset($step['tested'], $step['untested_accepted']);
            $step['history'][] = ['at' => $now, 'event' => 'reopened', 'detail' => 'La comprobación ya no pasa.'];
        }

        return $step;
    }

    /**
     * El dueño acepta sin probar un paso `untested`: queda hecho, con su
     * aceptación a la vista en el plan (y en lo que lista `done`).
     *
     * @param  Step  $step
     * @param  array{note: string, at: string, via: string, by: string|null}  $accepted
     * @return Step
     */
    public static function acceptUntested(array $step, array $accepted): array
    {
        $step['status'] = Planner::DONE;
        $step['untested_accepted'] = $accepted;
        $step['history'][] = ['at' => $accepted['at'], 'event' => 'accepted-untested', 'detail' => 'Aceptado sin probar'
            .($accepted['by'] === null ? ' (en la terminal del dueño)' : ' por '.$accepted['by']).': '.$accepted['note']];

        return $step;
    }
}
