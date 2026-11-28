<?php

namespace Innoboxrr\LarapackGenerator\Support\Realign;

use Innoboxrr\LarapackGenerator\Support\Realign\Operations\Operations;

/**
 * Lo que hace cada acción de `larapack:realign`, escrito una vez.
 *
 * Lo leen tres sitios que antes podían decir cosas distintas: `--help` del
 * comando, `larapack:realign help [<acción>]` (texto y JSON, que es lo que lee
 * un agente) y la prueba de la documentación (RealignDocTest), que comprueba
 * que cada guía citada existe y que docs/realign no cita una acción, una
 * opción o una clave de `--option` que no esté aquí.
 *
 * Las opciones de cada acción (`options`) son las que acepta: el comando
 * rechaza las demás (RealignCommand::unexpectedOptions las lee de aquí).
 * `--root` y `--format` valen en todas y no se listan.
 */
final class RealignActions
{
    /**
     * Las guías del paquete, relativas a su raíz.
     */
    public const GUIDE = 'docs/realign/README.md';

    /**
     * @var array<string, array{summary: string, usage: list<string>, writes: string, options: list<string>, keys: array<string, string>, guide: string}>
     */
    public const ALL = [
        'diagnose' => [
            'summary' => 'Regenera el contrato aparte con la versión actual y clasifica cada archivo y cada hallazgo de verify. No escribe código.',
            'usage' => ['larapack:realign diagnose [--package=…] [--jobs=4] [--no-cache]'],
            'writes' => 'Sólo .larapack/realign/report.json y la caché .larapack/realign/cache/ (lo regenerado para comparar).',
            'options' => ['package', 'jobs', 'no-cache'],
            'keys' => [],
            'guide' => 'docs/realign/diagnose.md',
        ],
        'plan' => [
            'summary' => 'Convierte el diagnóstico en pasos ordenados y reanudables (.larapack/realign/plan.json), conservando lo hecho y lo decidido.',
            'usage' => ['larapack:realign plan'],
            'writes' => 'Sólo .larapack/realign/plan.json.',
            'options' => [],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'status' => [
            'summary' => 'Dónde se va: pasos hechos, pendientes y bloqueados, el siguiente, y si el diagnóstico está viejo. El punto de entrada de quien llega sin contexto.',
            'usage' => ['larapack:realign status [--package=…]'],
            'writes' => 'Nada.',
            'options' => ['package'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'next' => [
            'summary' => 'El siguiente paso, con todo lo necesario para hacerlo sin contexto: la orden, el playbook rellenado o las opciones de la decisión, y cómo comprobarlo. Con un id, ese paso. Salta lo aplazado; si lo que toca es una decisión del dueño, dice con qué seguir mientras.',
            'usage' => ['larapack:realign next [<id>] [--package=…]'],
            'writes' => 'Nada.',
            'options' => ['package'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'show' => [
            'summary' => 'Un paso cualquiera, como lo da next: la orden, el playbook rellenado o las opciones, lo que lo bloquea y cómo comprobarlo. Esté hecho, bloqueado o aplazado.',
            'usage' => ['larapack:realign show <id>'],
            'writes' => 'Nada.',
            'options' => [],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'check' => [
            'summary' => 'Comprueba el done_when de un paso y lo marca hecho, o dice qué falta, elemento por elemento (sale con error). Un paso que cambia comportamiento sólo queda hecho con su prueba (--tested, o la orden de pruebas de .larapack/realign/config.json, que corre él); sin ella queda untested, con su aviso.',
            'usage' => ['larapack:realign check <id>', 'larapack:realign check <id> --tested="<la orden de pruebas que se corrió y pasó>"'],
            'writes' => 'Sólo el estado del paso en .larapack/realign/plan.json. Con la orden de pruebas configurada, la corre en la raíz del proyecto.',
            'options' => ['tested'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'decide' => [
            'summary' => 'Registra la opción elegida en un paso de decisión, con su nota; con --target, sólo para esos elementos (las decisiones pueden ser mixtas). Decidir no es hacer: el paso sigue pendiente hasta que check pasa.',
            'usage' => ['larapack:realign decide <id> <opción> --note="por qué"', 'larapack:realign decide <id> <opción> --target=<archivo> [--target=<archivo>…] --note="por qué"'],
            'writes' => 'Sólo la decisión del paso en .larapack/realign/plan.json.',
            'options' => ['note', 'target'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'defer' => [
            'summary' => 'Aplaza un paso con su razón: sigue pendiente, next pasa al siguiente y status lo lista como aplazado. undefer lo devuelve.',
            'usage' => ['larapack:realign defer <id> --note="por qué"'],
            'writes' => 'Sólo el estado del paso en .larapack/realign/plan.json.',
            'options' => ['note'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'undefer' => [
            'summary' => 'Devuelve al orden un paso aplazado con defer.',
            'usage' => ['larapack:realign undefer <id>'],
            'writes' => 'Sólo el estado del paso en .larapack/realign/plan.json.',
            'options' => [],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'escalate' => [
            'summary' => 'Convierte un paso guiado en una decisión del dueño, con su nota: hacerlo como dice el playbook o dejarlo como está. Lo decide el dueño con decide.',
            'usage' => ['larapack:realign escalate <id> --note="qué hay que decidir"'],
            'writes' => 'Sólo el paso en .larapack/realign/plan.json.',
            'options' => ['note'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'accept-untested' => [
            'summary' => 'El dueño acepta sin probar un paso que quedó untested (cambia comportamiento y nadie registró su prueba), con su nota. Lo confirma él en su terminal, escribiendo «acepto sin probar»; sin terminal, sólo dentro de una corrida del workflow de aprobación lanzada a mano por una persona (--approver). Un agente no lo corre. Queda en el plan y lo lista done.',
            'usage' => ['larapack:realign accept-untested <id> --note="por qué y cuándo se probará"'],
            'writes' => 'Sólo el paso en .larapack/realign/plan.json (untested_accepted y su historia).',
            'options' => ['note', 'approver'],
            'keys' => [],
            'guide' => 'docs/realign/guided.md',
        ],
        'done' => [
            'summary' => 'El criterio de terminado, uno para todo el proyecto: diagnostica y planifica otra vez, y falla (con lo que falta, elemento por elemento) mientras quede un paso sin hacer, una decisión sin tomar, un paso sin probar (untested) que el dueño no aceptó, una excepción sin aprobar, o un error o aviso de validate --strict o verify --strict en cualquier ámbito.',
            'usage' => ['larapack:realign done [--package=…] [--jobs=4] [--no-cache]'],
            'writes' => 'Sólo .larapack/realign/report.json, plan.json y la caché, como diagnose y plan.',
            'options' => ['package', 'jobs', 'no-cache'],
            'keys' => [],
            'guide' => 'docs/realign/verify.md',
        ],
        'brief' => [
            'summary' => 'Junta en un documento todo lo que espera al dueño del proyecto: cada decisión pendiente con su evidencia agrupada, sus opciones, la recomendación del paquete y la orden exacta, y cada excepción pedida sin aprobar con su orden de aprobación. No decide ni aprueba nada.',
            'usage' => ['larapack:realign brief [--package=…]'],
            'writes' => 'Sólo .larapack/realign/brief.md (para una persona) y .larapack/realign/brief.json (para un agente); se rehacen cada vez.',
            'options' => ['package'],
            'keys' => [],
            'guide' => 'docs/realign/owner.md',
        ],
        'playbook' => [
            'summary' => 'Lista los playbooks del paquete, o imprime uno sin rellenar con sus marcadores explicados.',
            'usage' => ['larapack:realign playbook', 'larapack:realign playbook <nombre>'],
            'writes' => 'Nada. No necesita un proyecto.',
            'options' => [],
            'keys' => [],
            'guide' => 'docs/realign/guided.md',
        ],
        'adopt' => [
            'summary' => 'Pone el manifiesto al día con lo que hay: adopta lo que ya es lo que generaría el contrato, vuelve a enlazar lo renombrado y olvida lo que ya no existe. Nunca cambia el contenido de un archivo.',
            'usage' => ['larapack:realign adopt [<id>…] [--package=…] [--dry-run] [--allow-dirty]'],
            'writes' => 'Sólo .larapack/manifest.json (el del ámbito de cada paso).',
            'options' => ['package', 'dry-run', 'allow-dirty', 'option'],
            'keys' => [],
            'guide' => 'docs/realign/adopt.md',
        ],
        'apply' => [
            'summary' => 'Ejecuta los pasos deterministas que reescriben código o el contrato: regenerar lo que nadie editó, fix, pruebas de endpoints, traits de Concerns, listas del modelo y llaves foráneas. Uno a uno, o todos con --deterministic.',
            'usage' => [
                'larapack:realign apply <id>… [--dry-run] [--allow-dirty]',
                'larapack:realign apply --deterministic [--package=…] [--dry-run] [--allow-dirty]',
            ],
            'writes' => 'Código generado (nunca una migración), laraimport.json (sólo pin-foreign-keys) y el manifiesto. Un cambio que no parsea se deshace entero.',
            'options' => ['package', 'dry-run', 'allow-dirty', 'option', 'deterministic'],
            'keys' => [],
            'guide' => 'docs/realign/apply.md',
        ],
        'infer' => [
            'summary' => 'Infiere un laraimport borrador desde el código (esquema de las migraciones, modelos, rutas), con su procedencia, lo que no infirió y las decisiones. No toca el código ni el contrato que exista.',
            'usage' => ['larapack:realign infer <id>… [--dry-run] [--allow-dirty] [--option write=contract] [--option http=0]'],
            'writes' => 'laraimport.inferred.json y .larapack/realign/infer/<ámbito>.json. laraimport.json sólo si no existe y se pide --option write=contract.',
            'options' => ['package', 'dry-run', 'allow-dirty', 'option'],
            'keys' => [
                'write' => '`draft` (por omisión) o `contract`: escribe laraimport.json en vez de laraimport.inferred.json, sólo si no existe ninguno.',
                'http' => '`1` (por omisión) o `0`: inferir también los endpoints y controladores, no sólo modelos y columnas.',
            ],
            'guide' => 'docs/realign/infer.md',
        ],
        'except' => [
            'summary' => 'Pide una excepción por cada elemento de un paso que se decide dejar como está, con su razón y su huella. No cubre nada hasta que el dueño la aprueba con larapack:hand-rolled approve.',
            'usage' => ['larapack:realign except <id>… --option reason="…" [--target=<objetivo|archivo>…] [--dry-run] [--allow-dirty]'],
            'writes' => 'Sólo .larapack/realign/exceptions.json (las solicitudes). La aprobación la escribe larapack:hand-rolled en .larapack/hand-rolled.lock.',
            'options' => ['package', 'dry-run', 'allow-dirty', 'option', 'target'],
            'keys' => [
                'reason' => 'Obligatoria: por qué se deja así. La lee el dueño antes de aprobar y queda en el candado.',
            ],
            'guide' => 'docs/realign/exceptions.md',
        ],
        'help' => [
            'summary' => 'Lista las acciones, o explica una con su uso, lo que escribe, sus opciones y su guía.',
            'usage' => ['larapack:realign help', 'larapack:realign help <acción>'],
            'writes' => 'Nada. No necesita un proyecto.',
            'options' => [],
            'keys' => [],
            'guide' => self::GUIDE,
        ],
    ];

    /**
     * Cuántos argumentos (ids, opciones, nombres) recibe cada acción: [mínimo,
     * máximo], null sin máximo. Uno de más es un error, como una opción que
     * la acción no usa: `next <id>` ignoraba el id y daba el siguiente. El
     * mínimo lo dice cada acción con su mensaje.
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    public const ARGUMENTS = [
        'diagnose' => [0, 0], 'plan' => [0, 0], 'status' => [0, 0], 'next' => [0, 1], 'show' => [1, 1],
        'check' => [1, 1], 'decide' => [2, 2], 'defer' => [1, 1], 'undefer' => [1, 1], 'escalate' => [1, 1], 'accept-untested' => [1, 1],
        'done' => [0, 0], 'brief' => [0, 0], 'playbook' => [0, 1], 'help' => [0, 1], 'adopt' => [0, null], 'apply' => [0, null], 'infer' => [0, null], 'except' => [0, null],
    ];

    /**
     * Las que no necesitan un proyecto para responder.
     */
    public const WITHOUT_PROJECT = ['help', 'playbook'];

    /**
     * Las opciones que acepta una acción además de --root y --format.
     *
     * @return list<string>
     */
    public static function options(string $action): array
    {
        return self::ALL[$action]['options'] ?? [];
    }

    /**
     * La línea de `help`: la de la acción y, en una operación sin
     * implementación, que no está disponible.
     */
    public static function summary(string $action): string
    {
        $summary = self::ALL[$action]['summary'] ?? '';

        return in_array($action, Operations::ACTIONS, true) && ! Operations::available($action)
            ? $summary.' (no disponible en esta versión)'
            : $summary;
    }

    /**
     * El texto de `--help`: el ciclo, cada acción con su uso y su guía.
     */
    public static function helpText(): string
    {
        $lines = [
            'Lleva de vuelta al contrato (laraimport.json) un proyecto que se desvió, sin reconstruirlo.',
            'Lo determinista se aplica solo; lo mecánico lo hace un agente con el playbook del paquete y',
            'una comprobación como criterio de terminado; lo que es decisión se presenta con sus opciones;',
            'lo que no es de LaraPack se declara fuera y no se toca.',
            '',
            'Si llegas sin contexto: <info>larapack:realign status</info> dice dónde se va y qué sigue.',
            '',
            'El ciclo: diagnose → plan → apply --deterministic → next → (hacer el paso y sus pruebas) → check [--tested] → next … → done',
            'Se termina cuando <info>larapack:realign done</info> sale con 0. Inicio rápido, glosario y «qué hago si…»: la guía de abajo.',
            '',
        ];

        foreach (self::ALL as $action => $definition) {
            $lines[] = '<info>'.$action.'</info>  '.self::summary($action);

            foreach ($definition['usage'] as $usage) {
                $lines[] = '    '.$usage;
            }

            foreach ($definition['keys'] as $key => $meaning) {
                $lines[] = '    --option '.$key.'=…  '.$meaning;
            }

            $lines[] = '    Escribe: '.$definition['writes'];
            $lines[] = '    Guía: vendor/innoboxrr/larapack-generator/'.$definition['guide'];
            $lines[] = '';
        }

        $lines[] = 'Las que escriben (adopt, apply, infer, except) aceptan --dry-run, exigen el árbol git limpio salvo';
        $lines[] = '--allow-dirty e informan fixed/skipped/unchanged. Todas aceptan --root y --format=json.';
        $lines[] = 'Guía completa, limitaciones y riesgos: vendor/innoboxrr/larapack-generator/'.self::GUIDE;

        return implode("\n", $lines);
    }
}
