---
name: larapack-realign
description: Realinea con su contrato (laraimport.json) un proyecto Laravel que usa —o quiere usar— innoboxrr/larapack-generator y se desvió, sin reconstruirlo. Úsalo cuando haya un realineamiento en curso (.larapack/realign/plan.json), cuando pidan realinear, adoptar código existente, saltar de versión de LaraPack, bajar los hallazgos de larapack:verify o inferir un laraimport desde el código.
---

# Realinear con LaraPack

Llevar un proyecto de vuelta a su contrato **sin reconstruirlo**. Lo
determinista lo hace la herramienta; lo mecánico lo haces tú siguiendo un
playbook del paquete, con una comprobación concreta como criterio de terminado;
lo que es decisión se lo presentas a quien tenga autoridad; lo que no es de
LaraPack no se toca.

No necesitas un prompt del proyecto ni contexto previo: el estado vive en
`.larapack/realign/` y el paquete trae las instrucciones de cada paso. La guía
completa está en el paquete: `<paquete>/docs/realign/README.md`, donde
`<paquete>` es `vendor/innoboxrr/larapack-generator` o, si el paquete no está
en `vendor/`, el clon del generador.

```
php artisan larapack:realign <acción>                                 # en una aplicación Laravel
php vendor/bin/builder larapack:realign <acción>                      # con el paquete en vendor/
php <clon>/builder larapack:realign <acción> --root=<proyecto>        # desde un clon (composer install en el clon)
```

Abajo se escribe `larapack:realign …`: antepón la forma que te toque y, con
un clon, `--root=<proyecto>` en **cada** orden.

## Empieza siempre aquí

```bash
larapack:realign status --format=json
```

- Sin plan, o con `stale` no vacío: `larapack:realign diagnose` y
  `larapack:realign plan`.
- Con plan: sigue la receta.
- Si te atascas, la salida está en `<paquete>/docs/realign/faq.md`.

## El orden de punta a punta

1. Rama propia y árbol limpio. ¿Puedes correr las pruebas del proyecto? Si
   no, lo dirás en cada paso guiado (ver «Sin pruebas»).
2. `status`. Sin plan o con `stale`: `diagnose` y `plan`.
3. `apply --deterministic --dry-run`, después sin `--dry-run`; pruebas y
   commit; `diagnose`, `plan` y otra vez `apply --deterministic`, hasta que no
   aplique nada.
4. Lo guiado y las decisiones, con la receta de abajo.
5. **Otra pasada** `diagnose` → `plan` → `apply --deterministic` al terminar
   los guiados de un ámbito, y **siempre** después de copiar lo regenerado
   desde la caché o de cambiar el contrato. Vuelve al 4.
6. Cuando `next` diga `done` tras esa pasada: `larapack:realign done
   --format=json`, y entrega la lista de lo que quedó sin probar
   (`missing.untested`).

## La receta

1. `larapack:realign next --format=json`. Si `done` es false y `step` es
   null, no hay nada que hacer ahora: `waiting` dice qué está aplazado, en
   manos del dueño o bloqueado. Si `done` es true, corre
   `larapack:realign done --format=json`: es **el** criterio de terminado.
   Sale con 0 sólo si no queda nada en ningún ámbito; si no, `missing` dice
   qué falta (pasos, decisiones, pasos sin probar, excepciones, `validate`,
   `verify`) y lo resuelves. `verify --strict` en verde **no** basta: no ve lo editado
   fuera de los huecos.
2. Según `step.category`:
   - **`deterministic`**: ejecuta `how.command`, primero con `--dry-run` y
     después sin él. Lo que salte (`skipped`, con su razón) lo haces a mano
     siguiendo `how.fallback.instructions`. `larapack:realign apply
     --deterministic` hace todos los deterministas de una vez; después,
     `diagnose` y `plan` otra vez.
   - **`guided`**: sigue `how.instructions` al pie de la letra. Corre las
     pruebas del proyecto **antes** de tocar nada y **después**. Si el
     playbook dice dejarlo o no se puede ahora: `larapack:realign defer <id>
     --note="…"`; si lo tiene que decidir el dueño (lo que no cabe en un
     hueco, por ejemplo): `larapack:realign escalate <id> --note="…"`. No
     pidas `except` de algo que el dueño no ha decidido dejar así.
     En `edited-outside`, cada archivo trae en `regenerado:` la ruta exacta de lo
     que generaría el contrato (en `.larapack/realign/cache/`): compáralo con
     `git diff --no-index <regenerado> <archivo>` y, cuando lo tuyo esté en
     sus huecos, `cp <regenerado> <archivo>`. Nunca `larapack:import
     --force` para regenerar un archivo.
   - **`decision`**: **no decidas tú.** Presenta `how.options`, con sus
     consecuencias y, si lo trae, el playbook de la decisión
     (`how.instructions`), a quien tenga autoridad, y mientras decide sigue
     con `meanwhile` (`larapack:realign next <meanwhile.id>`). Cuando decida:
     `larapack:realign decide <id> <opción> --note="…"` (por elemento, con
     `--target=<archivo>`). Después, `next` dice
     qué pide la opción. Si es dejarlo como está, pide la excepción
     (`larapack:realign except <id> --option reason="…"`) y avisa al dueño:
     **no la apruebes tú**.
   - **Si lo que queda es del dueño** (decisiones, excepciones sin aprobar) y
     no está, no preguntes de una en una: `larapack:realign brief` lo junta
     todo en `.larapack/realign/brief.md`, con la orden exacta de cada cosa.
     Avísale con su resumen, sigue con lo de `meanwhile` y retoma con
     `larapack:realign status` cuando decida. Es el camino normal.
3. `larapack:realign check <id> --format=json`. Si `done` es false, resuelve
   `remaining` y vuelve a comprobar. Sólo `check` da un paso por hecho.
4. Un commit por paso, con `.larapack/realign/plan.json`. Vuelve al 1.

Para preparar una decisión sobre código sin contrato (`no-contract`,
`unmanaged-models`, `unmanaged-http`) sin decidir nada:
`larapack:realign infer <id>` escribe un borrador
(`laraimport.inferred.json`) y su informe, y no toca el código ni el contrato.

## Sin pruebas

Un paso que cambia comportamiento (`tests.required` en `next`) sólo queda
hecho con su prueba: `larapack:realign check <id> --tested="<la orden que
corriste y pasó>"`, o la orden de `.larapack/realign/config.json`, que `check`
corre él. Si no puedes correr las pruebas del proyecto (sin `vendor/`, sin
base de datos): escribe la prueba igual, comita el cambio solo con
`realign: <id> (SIN PROBAR: <motivo>)` y corre `check <id>` **sin**
`--tested`: lo deja `untested`, que es la verdad, y `done` no termina mientras
quede así. Nunca pongas en `--tested` una orden que no corriste. Aceptarlo sin
probar (`accept-untested`) lo hace el dueño, en su terminal, no tú: entrega
la lista (`status` → `untested`, o `brief`). Detalle en
`<paquete>/docs/realign/guided.md`.

## Lo que nunca haces

- **Decidir o aprobar por el dueño**: ni `larapack:realign decide` sin que te
  lo pidan, ni `larapack:hand-rolled approve`/`revoke`, ni
  `larapack:realign accept-untested`, ni editar
  `.larapack/hand-rolled.lock` o `.larapack/realign/plan.json` a mano, ni
  añadir `#[HandRolled]` para que verify pase.
- **Escribir o reescribir una migración**, ni tocar la base.
- **Cambiar un nombre de ruta o una URI** que el contrato no declare: el front
  y los clientes dependen de ellos.
- **`--allow-dirty`** para saltarte un árbol sucio que no es tuyo: comitea o
  pregunta.
- **Tocar lo `out_of_scope`**: servicios, dominio, front propio, la historia de
  las migraciones.
- **Dar por hecho un paso** porque lo hiciste: lo dice `check`.

## Qué puede romper un paso

- Mover validación y autorización a un FormRequest hace que una petición sin
  permiso reciba **403 en vez de 422**: fíjalo con una prueba antes.
- Llevar a `authorize()` una comprobación que iba **después** de un
  `findOrFail` cambia un **404 por un 403**. verify no lo ve: la prueba con
  un id inexistente es obligatoria, y si no se conserva, la comprobación se
  queda en `handle()`.
- Un protocolo (OAuth2, RFC 7591, un webhook firmado) se mueve igual si se
  conservan exactamente el código de estado y el formato de la respuesta;
  si no se puede, `#[HandRolled('razón concreta')]` y que decida el dueño.
- Regenerar o arreglar cambia comportamiento (un listado que respeta
  `managed`, un Resource que deja de servir `appends`): las pruebas lo dicen.
- Un endpoint de modelo nuevo nace con una política que **niega**.

## Dónde está cada cosa

En `<paquete>/docs/realign/`:

| Guía | Para |
|---|---|
| `README.md` | Los inicios rápidos, el ciclo completo, las categorías, la seguridad y la receta. |
| `faq.md` | «¿Qué hago si…?»: una decisión que no es tuya, sin pruebas, `untested`, `done` que no termina, lo que no cabe, el árbol sucio, un clon, un monorepo, sin contrato. |
| `glossary.md` | Cada término, definido una vez. |
| `diagnose.md` | Leer el informe y cada clasificación. |
| `plan.md` | Los pasos, `next`, `check`, `decide` y los formatos JSON. |
| `adopt.md`, `apply.md` | Lo determinista. |
| `guided.md` | Los playbooks (`larapack:realign playbook` los lista). |
| `exceptions.md` | Dejar algo como está, con la aprobación del dueño. |
| `owner.md` | `brief`: preparar al dueño todo lo suyo de una vez y seguir. |
| `infer.md`, `contract.md` | Un proyecto sin contrato, y lo que el contrato puede declarar. |
| `verify.md` | Cuándo se termina y qué garantiza. |
| `risks.md` | Limitaciones y riesgos de cada operación. |
| `profiles.md` | El recorrido según de dónde parte el proyecto. |

`larapack:realign help <acción>` explica cada acción con su uso, lo que
escribe y su guía.

Este archivo lo reescribe `larapack:skill`. Las reglas propias del proyecto van
en otro archivo.
