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
            'summary' => 'El siguiente paso, con todo lo necesario para hacerlo sin contexto: la orden, el playbook rellenado o las opciones de la decisión, y cómo comprobarlo.',
            'usage' => ['larapack:realign next [--package=…]'],
            'writes' => 'Nada.',
            'options' => ['package'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'check' => [
            'summary' => 'Comprueba el done_when de un paso y lo marca hecho, o dice qué falta, elemento por elemento (sale con error).',
            'usage' => ['larapack:realign check <id>'],
            'writes' => 'Sólo el estado del paso en .larapack/realign/plan.json.',
            'options' => [],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'decide' => [
            'summary' => 'Registra la opción elegida en un paso de decisión, con su nota. Decidir no es hacer: el paso sigue pendiente hasta que check pasa.',
            'usage' => ['larapack:realign decide <id> <opción> --note="por qué"'],
            'writes' => 'Sólo la decisión del paso en .larapack/realign/plan.json.',
            'options' => ['note'],
            'keys' => [],
            'guide' => 'docs/realign/plan.md',
        ],
        'done' => [
            'summary' => 'El criterio de terminado, uno para todo el proyecto: diagnostica y planifica otra vez, y falla (con lo que falta, elemento por elemento) mientras quede un paso sin hacer, una decisión sin tomar, una excepción sin aprobar, o un error o aviso de validate --strict o verify --strict en cualquier ámbito.',
            'usage' => ['larapack:realign done [--package=…] [--jobs=4] [--no-cache]'],
            'writes' => 'Sólo .larapack/realign/report.json, plan.json y la caché, como diagnose y plan.',
            'options' => ['package', 'jobs', 'no-cache'],
            'keys' => [],
            'guide' => 'docs/realign/verify.md',
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
            'usage' => ['larapack:realign infer <id>… [--dry-run] [--allow-dirty] [--option write=contract] [--option packages=0] [--option http=0]'],
            'writes' => 'laraimport.inferred.json y .larapack/realign/infer/<ámbito>.json. laraimport.json sólo si no existe y se pide --option write=contract.',
            'options' => ['package', 'dry-run', 'allow-dirty', 'option'],
            'keys' => [
                'write' => '`draft` (por omisión) o `contract`: escribe laraimport.json en vez de laraimport.inferred.json, sólo si no existe ninguno.',
                'packages' => '`1` (por omisión) o `0`: en `no-contract@.`, inferir también los paquetes locales sin contrato.',
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
            'El ciclo: diagnose → plan → next → (hacer el paso) → check → next … → diagnose',
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
