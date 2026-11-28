<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

/**
 * Las cuatro categorías de todo lo que encuentra el diagnóstico de
 * `larapack:realign`. Son las mismas en el informe, en el plan, en la salida
 * y en la documentación (docs/realign/README.md): un agente que lee
 * `deterministic` sabe qué hacer sin mirar nada más.
 *
 *  - `deterministic`: se aplica sola, con una orden, y su resultado se puede
 *    comprobar sin opinar. Nunca toca lo que no reconoce con certeza.
 *  - `guided`: trabajo mecánico que hace una persona o un agente siguiendo
 *    un playbook del paquete, con una comprobación concreta como criterio de
 *    terminado (`done_when`). El código no se adivina; se mueve.
 *  - `decision`: hace falta que alguien con autoridad sobre el proyecto
 *    elija. Se presenta con sus opciones y sus consecuencias; nunca se toma
 *    sola.
 *  - `out_of_scope`: no es de LaraPack (dominio, servicios, front propio,
 *    historia de migraciones). Se declara fuera y no se toca.
 */
final class Category
{
    public const DETERMINISTIC = 'deterministic';

    public const GUIDED = 'guided';

    public const DECISION = 'decision';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const ALL = [self::DETERMINISTIC, self::GUIDED, self::DECISION, self::OUT_OF_SCOPE];

    /**
     * Cómo se nombra cada una de cara a una persona.
     */
    public const LABELS = [
        self::DETERMINISTIC => 'determinista',
        self::GUIDED => 'guiado',
        self::DECISION => 'decisión',
        self::OUT_OF_SCOPE => 'fuera de alcance',
    ];
}
