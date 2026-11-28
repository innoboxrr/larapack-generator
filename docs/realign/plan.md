# El plan y el estado: `plan`, `status`, `next`, `show`, `check`, `decide`, `defer`, `escalate`

Parte de [`larapack:realign`](README.md). Del diagnóstico a pasos que cualquiera
puede retomar, con un criterio de terminado que no depende de la opinión de
nadie. Ninguna de estas acciones escribe código: sólo el estado en
`.larapack/realign/`.

## Índice

- [`plan`](#plan)
- [Los pasos](#los-pasos)
- [Los tipos de paso](#los-tipos-de-paso)
- [El orden](#el-orden)
- [Volver a planificar](#volver-a-planificar)
- [`status`](#status)
- [`next`](#next)
- [`show`](#show)
- [`check`](#check)
- [`decide`](#decide)
- [`defer` y `undefer`](#defer-y-undefer)
- [`escalate`](#escalate)
- [El estado en disco y los formatos JSON](#el-estado-en-disco-y-los-formatos-json)

## `plan`

```bash
larapack:realign plan
larapack:realign plan --format=json
```

Lee `.larapack/realign/report.json` (sin diagnóstico, falla y pide
`diagnose`), convierte cada cosa que no cumple en pasos y los escribe en
`.larapack/realign/plan.json`. Imprime lo mismo que `status`.

## Los pasos

Cada paso agrupa lo que se resuelve junto y lleva:

| Campo | Qué es |
|---|---|
| `id` | Estable entre diagnósticos: `<tipo>[:<sujeto>]@<ámbito>` (`adopt@.`, `fix:index-managed@packages/billing`, `edited-outside:Requests@.`) o, en un controlador, `controller-shape@<archivo>`. Es lo que se pasa a `check`, `decide` y las operaciones. |
| `category`, `type`, `title`, `description` | Qué es y qué hay que hacer. |
| `scope` | `{package, path}`: el paquete (null es el núcleo) y su ruta. |
| `files`, `details` | Los archivos, y detrás de cada uno su clase y razón, los hallazgos de verify con su mensaje y el código sin gestionar. |
| `risk` | `{level: low\|medium\|high, why}`: qué puede romper y por qué. |
| `reversible` | Si se puede deshacer (con git). |
| `command` | Pasos `deterministic`: la orden que lo hace. |
| `playbook`, `annexes` | Pasos `guided`: el playbook y sus anexos ([guided.md](guided.md)). |
| `options` | Pasos `decision`: `{key, label, consequences, run?, playbook?}`. |
| `done_when` | La comprobación de terminado ([`check`](#check)). |
| `depends_on` | Lo que tiene que estar hecho antes; mientras no, el paso está `blocked`. Una decisión que condiciona lo guiado ([El orden](#el-orden)) deja de bloquearlo antes: en cuanto cada archivo compartido está decidido con una opción con la que lo guiado sigue valiendo. |
| `status`, `decision`, `history`, `last_check`, `order` | El estado. |
| `decisions` | Lo decidido elemento por elemento (`decide --target`): `{<archivo>: {option, note, at}}`. Manda sobre `decision`. |
| `deferred` | `{note, at}` si se aplazó ([`defer`](#defer-y-undefer)). |
| `escalated` | `{from, note, at}` si un paso guiado se le pasó al dueño ([`escalate`](#escalate)). |

Los estados: `pending`, `done`, `awaiting_decision` (una decisión sin decidir,
o decidida sólo para algunos de sus elementos), `decided` (decidida entera, o
cada elemento, y todavía sin cumplir) y `blocked` (calculado al leer: espera a
un paso de `depends_on`). Aplazar no es un estado: el paso sigue en el suyo.

## Los tipos de paso

Los define `src/Support/Realign/StepTypes.php`:

| Tipo | Categoría | Qué hace | Cómo |
|---|---|---|---|
| `relink` | `deterministic` | Vuelve a enlazar en el manifiesto lo renombrado. | [adopt](adopt.md) |
| `prune` | `deterministic` | Olvida en el manifiesto lo que ya no existe y el contrato no volvería a generar. | [adopt](adopt.md) |
| `adopt` | `deterministic` | Anota en el manifiesto lo que ya es lo que generaría el contrato. | [adopt](adopt.md) |
| `regenerate` | `deterministic` | Regenera lo que nadie editó y quedó atrás, o lo que falta. | [apply](apply.md) |
| `fix` | `deterministic` | `larapack:fix <comprobación> --package=<paquete>`. | [apply](apply.md) |
| `controller-concern` | `deterministic` | Mueve a `Http/Requests/Concerns` los traits de `Http/Controllers/Concerns` que sólo usan requests. Playbook para lo que salte. | [apply](apply.md) |
| `endpoint-tests` | `deterministic` | Añade a cada `<Modelo>EndpointsTest` las pruebas que trae hoy la plantilla. Playbook para lo que salte. | [apply](apply.md) |
| `model-lists` | `deterministic` | Completa las listas del modelo con lo que declara el contrato (sólo añade). Playbook para lo que salte. | [apply](apply.md) |
| `pin-foreign-keys` | `deterministic` | Escribe en el contrato el `on_delete`/`on_update` que hacen las migraciones donde el contrato calla. | [apply](apply.md) |
| `controller-shape` | `guided` | Lleva la lógica del controlador a su FormRequest. | [guided](guided.md) |
| `edited-outside` | `guided` | Devuelve a sus huecos (o al contrato) lo editado fuera. Un paso por familia de plantilla. | [guided](guided.md) |
| `declared-routes` | `guided` | Cuadra rutas y requests con lo declarado. | [guided](guided.md) |
| `security` | `guided` | Cierra una escritura o una exposición que el contrato prohíbe. | [guided](guided.md) |
| `verify-other` | `guided` | Cualquier otro hallazgo de verify. | [guided](guided.md) |
| `hand-rolled` | `decision` | Aprobar o deshacer las excepciones `#[HandRolled]`. | [exceptions](exceptions.md) |
| `route-closure` | `decision` | Closures de rutas que no son el fallback de la SPA. | [exceptions](exceptions.md) |
| `inconsistent-entity` | `decision` | Un modelo con una forma distinta a la de los demás. | — |
| `missing` | `decision` | Archivos generados que se borraron: restaurarlos (`apply`) o dejarlos borrados (`except`). | [apply](apply.md) |
| `untracked-edited` | `decision` | Archivos escritos a mano en la ruta de uno generado. | [adopt](adopt.md) |
| `unmanaged-http` | `decision` | Controladores y rutas escritos a mano. | [infer](infer.md) |
| `unmanaged-models` | `decision` | Modelos escritos a mano. | [infer](infer.md) |
| `no-contract` | `decision` | Un ámbito sin `laraimport.json`: inferirlo o dejarlo fuera. | [infer](infer.md) |
| `contract-invalid` | `decision` | El contrato no valida con esta versión: corregirlo o retirarlo. | [contract](contract.md) |

`larapack:realign help` dice qué operación hace cada tipo, y
`larapack:realign playbook` los playbooks.

## El orden

1. **Lo que desbloquea**: que el manifiesto diga la verdad (`relink`, `prune`,
   `adopt`) y los saltos de versión (`regenerate`, `fix`, `controller-concern`,
   `endpoint-tests`, `model-lists`). `pin-foreign-keys` va el último de los
   deterministas: cambia el contrato, y lo que va detrás se compararía con otra
   regeneración.
2. **Las decisiones que condicionan lo guiado**: `unmanaged-http` (declarar en
   el contrato los controladores escritos a mano o dejarlos como código
   propio), `unmanaged-models` (adoptar el modelo o dejarlo), `hand-rolled` y
   `route-closure`, cuando tocan los archivos de un paso guiado. Si el dueño
   elige declararlos, el contrato los regenera: llevarlos antes a mano a su
   request era trabajo tirado.
3. **Lo guiado, por ámbito, del que más cumple al que menos**: así los primeros
   pasos terminan paquetes enteros.
4. **Las demás decisiones**, agrupadas por tipo.

Lo guiado y las decisiones de un ámbito esperan a su `relink`, `prune` y
`adopt` (`depends_on`); `edited-outside` espera además a `regenerate`, `fix` y
`model-lists`: no se devuelve a mano lo que una corrección automática iba a
cambiar. Un paso guiado espera además a cada decisión del punto 2 que toca sus
archivos, hasta que se decide con una opción con la que lo guiado sigue
valiendo (`keep` en `unmanaged-http` y `unmanaged-models`, `refactor` en
`hand-rolled` y `route-closure`) o hasta que la decisión está hecha. Lo demás
no espera: el agente sigue con lo que no depende de ella.

## Volver a planificar

Después de hacer pasos, diagnosticar y planificar otra vez **no pierde nada**:

- un paso con el mismo `id` conserva su estado, su decisión y su historia;
- uno hecho cuyo problema vuelve a salir se reabre (`reopened` en su
  historia): alguien lo deshizo o una corrección lo cambió;
- uno pendiente que ya no sale se cierra (`resolved`);
- una decisión tomada sigue tomada, también la tomada por elemento;
- lo aplazado sigue aplazado y lo pasado al dueño sigue siendo una decisión;
- la última comprobación (`last_check`) **no** pasa: era de otro diagnóstico,
  y `status` la daría como si fuera de éste.

Hay que volver a diagnosticar cuando `status` lo dice (`stale`: el contrato o
el manifiesto de un ámbito cambió después del diagnóstico) y siempre después de
`apply --deterministic`.

## `status`

```bash
larapack:realign status [--package=…] [--format=json]
```

El punto de entrada de quien llega sin contexto. Dice cuándo se diagnosticó y
planificó, si el diagnóstico está viejo (`stale`, con los ámbitos), cuántos
pasos hay por estado y por categoría, el siguiente paso y la última
comprobación hecha después del diagnóstico. Sin plan, dice que hay que
diagnosticar y planificar. No escribe nada.

Además:

- **`awaiting_owner`**: por ámbito, las decisiones del dueño sin tomar que
  detienen lo guiado y lo guiado que detienen. En texto: «Falta 1 decisión del
  dueño antes de seguir con lo guiado de app (2 pasos esperan): …».
- **`deferred`**: lo aplazado, con su nota. Sigue pendiente.

## `next`

```bash
larapack:realign next [--package=…] [--format=json]
```

```bash
larapack:realign next <id> [--format=json]
```

Da el primer paso que no esté hecho ni bloqueado (con `--package`, de ese
ámbito) —o, con un id, **ese paso**, esté como esté—, con todo lo necesario
para hacerlo **sin contexto**: qué es, su riesgo, sus archivos y hallazgos,
cómo comprobarlo, lo que lo bloquea (`blocked_by`) y `how`:

- **`deterministic`**: `how.command`, la orden (y `how.available`, si esa
  operación existe en esta versión). Si el tipo tiene playbook,
  `how.fallback` lo trae rellenado: es lo que se hace a mano con lo que la
  orden salte.
- **`guided`**: `how.playbook` y `how.instructions`, el playbook del paquete
  **rellenado** con los archivos, los hallazgos, la orden de `verify` acotada
  y la de `check`, más sus anexos.
- **`decision`** sin decidir: `how.options` con sus consecuencias, cómo
  decidir (`how.decide`) y, si la decisión tiene uno, el playbook para
  pensarla.
- **`decision`** ya decidida: `how.decided`, lo que pide la opción
  (`how.then`, su orden) y, si la opción lo trae, su playbook.
- **`decision`** decidida por elemento: `how.decided_by_target` (opción →
  archivos), `how.then_by_option` (la orden de cada opción, que sólo toca los
  suyos) y `how.undecided`, lo que falta; mientras falte algo, también
  `how.options`.

Sin id, `next` salta:

- lo **aplazado** ([`defer`](#defer-y-undefer));
- una decisión que condiciona lo guiado y ya está decidida, mientras quede
  algo guiado que dependa de ella: lo que pide (la excepción de «dejarlos como
  código propio») se hace sobre el código ya trabajado, porque pedirla antes la
  caducaría al cambiar el archivo;
- una decisión cuya orden ya se ejecutó después de decidirla: lo que falta
  (aprobar las excepciones) es del dueño.

Si lo que toca es una decisión sin tomar, `next` la da —es del dueño:
preséntasela— y en `meanwhile` (`{id, title, category, show}`) **con qué
seguir mientras**: el primer paso que no espera a nadie. `larapack:realign next
<meanwhile.id>` lo da entero.

Cuando no queda nada, `done` es true y `step` es null. Si queda algo pero nada
que hacer ahora, `done` es false, `step` es null y `waiting` dice qué espera:
`{deferred, owner, blocked}`.

## `show`

```bash
larapack:realign show <id> [--format=json]
```

Lo mismo que `next <id>`: cualquier paso, con el playbook rellenado, las
opciones o la orden, lo que lo bloquea y cómo comprobarlo. Hecho, bloqueado o
aplazado, se ve igual. No escribe nada.

## `check`

```bash
larapack:realign check <id> [--format=json]
```

Corre el `done_when` del paso. Si pasa, lo marca `done`; si no, dice qué
falta, elemento por elemento (`remaining`), y termina con código distinto de
cero. **Hacer un paso, o ejecutar su orden, no lo marca hecho: sólo `check`.**

| `kind` | Pasa cuando |
|---|---|
| `verify` | `verify`, acotado a sus comprobaciones (`checks`), a su paquete y a sus archivos, no encuentra ni errores ni avisos (sin contar las excepciones aprobadas). |
| `classification` | Cada archivo, comparado otra vez con lo regenerado (con la misma clasificación del diagnóstico), queda en una de las clases de `accept`, en ninguna de `reject`, y, si se pide, registrado (`tracked`) con el hash actual (`hash_current`) o sin paso pendiente (`resolved`). |
| `unmanaged` | Ninguno de los archivos sigue siendo código sin gestionar. |
| `contract` | El `laraimport.json` del ámbito valida. |
| `foreign-keys` | El `laraimport.json` del ámbito declara `on_delete`/`on_update` en cada una de sus columnas (`columns`). El valor que declare es suyo: lo que había que resolver era que callaba. |
| `decision` | Hay una decisión registrada. |

Una decisión, además de su comprobación, necesita estar decidida. Y si la
opción elegida deja algo como está (su orden es `larapack:realign except`),
cada excepción que pidió tiene que estar **aprobada por el dueño**
([exceptions.md](exceptions.md)): mientras no, `check` dice cuál falta y a
quién le toca.

## `decide`

```bash
larapack:realign decide <id> <opción> --note="por qué"
```

```bash
larapack:realign decide <id> <opción> --target=<archivo> [--target=<archivo>…] --note="por qué"
```

Registra la opción en el plan (con la nota y la hora) y dice qué hacer para
cumplirla: la orden de la opción, si la tiene, y su playbook. **Decidir no es
hacer**: el paso sigue pendiente hasta que su `done_when` pasa. Decidir otra
vez sustituye la decisión y queda en la historia.

**Por elemento**: con `--target` (un archivo del paso, repetible) la opción vale
sólo para esos; el resto sigue por decidir. Así 84 controladores escritos a
mano no se deciden de golpe: unos se declaran y otros se quedan. Una decisión
sin `--target` vale para todo el paso y sustituye lo decidido por elemento. La
orden de cada opción sólo ve sus elementos: `except` pide excepciones sólo de
los que se dejan, y `adopt` y `apply` sólo tocan los suyos. `infer` escribe el
borrador del ámbito entero (no toca el código ni el contrato): de él se declara
sólo lo que se decidió declarar. `check` dice qué elementos faltan por
decidir.

Quien decide es quien tiene autoridad sobre el proyecto. Un agente presenta
las opciones (`next`) y espera; no elige por su cuenta, aunque una opción
parezca obvia.

## `defer` y `undefer`

```bash
larapack:realign defer <id> --note="por qué"
larapack:realign undefer <id>
```

Aplaza un paso que no se puede hacer ahora —un controlador de un protocolo
(el callback de OAuth2) que el playbook dice dejar, algo que espera a otro
equipo—: **sigue pendiente**, `next` pasa al siguiente y `status` lo lista en
`deferred` con su nota. La nota es obligatoria: es lo que lee quien retome el
plan. `undefer` lo devuelve al orden. Las dos quedan en la historia del paso.
Una operación sin ids (`apply --deterministic`) tampoco toca lo aplazado.

## `escalate`

```bash
larapack:realign escalate <id> --note="qué hay que decidir"
```

Convierte un paso **guiado** en una decisión del dueño, con su nota, cuando
hacerlo no le toca a quien sigue el plan. Las opciones: `refactor` (hacerlo
como dice su playbook) y dejarlo como está —`hand-rolled` en un controlador
(`#[HandRolled]` y la aprobación del dueño), `except` en lo demás—. Su
`done_when` no cambia. Lo decide el dueño con `decide`; volver a planificar no
lo pierde.

## Argumentos

Un argumento que la acción no usa es un error, igual que una opción: `next
<id> <otro>`, `status <id>` o `check <id> <otro>` fallan diciendo qué sobra.
Antes, `next <id>` ignoraba el id y daba el siguiente.

## El estado en disco y los formatos JSON

```
.larapack/realign/
  report.json        el último diagnóstico
  plan.json          los pasos, su estado y su historia
  exceptions.json    las excepciones pedidas, sin aprobar (exceptions.md)
  infer/             los informes de infer (infer.md)
  .gitignore         deja fuera cache/
  cache/             lo regenerado para comparar
```

Todos los JSON —los archivos y toda salida `--format=json`— llevan
`format_version` (hoy `1`). Se sube cuando cambia algo que un consumidor lee;
añadir una clave no la sube. Un archivo de una versión posterior no se lee: se
pide actualizar el paquete.

- `diagnose --format=json` y `report.json`: [diagnose.md](diagnose.md#el-informe-reportjson).
- `plan --format=json` y `status --format=json`: `{format_version, ok, action,
  diagnosed_at, planned_at, stale, totals, verify, steps: {total, by_status,
  by_category}, next, last_check, awaiting_owner, deferred, plan}`. `stale`
  lista los ámbitos cuyo contrato o manifiesto cambió después del diagnóstico;
  `awaiting_owner` es `[{scope, package, decisions, guided}]` y `deferred`,
  `[{id, title, note, at}]`.
- `next --format=json` y `show --format=json`: `{format_version, ok, action,
  done, step, how, check, done_when, blocked_by, meanwhile}`; sin nada que
  hacer ahora, `{…, done: false, step: null, waiting: {deferred, owner,
  blocked}}`.
- `check --format=json`: `{format_version, ok, action, id, done, remaining,
  kind}`.
- `decide --format=json`: `{format_version, ok, action, id, decision, targets,
  undecided, then, check}`.
- `defer`/`undefer --format=json`: `{format_version, ok, action, id, deferred,
  next}`; `escalate --format=json`: `{format_version, ok, action, id,
  escalated, options, decide}`.
- `help --format=json`: `{format_version, ok, action, actions, categories,
  classes, guide, guides, entry_point}`; con una acción, `{…, topic, summary,
  usage, writes, options, keys, guide, available}`.
- Las operaciones (`adopt`, `apply`, `infer`, `except`): `{format_version, ok,
  action, dry_run, steps, fixed, skipped, unchanged, notes}`. Cada elemento de
  `fixed`/`skipped`/`unchanged` es `{file, reason, step}` más lo suyo: `diff`
  en una simulación de `apply`, `to` y `references` en `controller-concern`,
  `model` y `stub` en `adopt`, `stamped` en la línea de la versión anotada en
  lo heredado (`file: null`).
- Un error, en cualquiera: `{ok: false, error, format_version, action}`.

**El manifiesto v2.** Desde 9.4.0 cada archivo del manifiesto dice qué versión
del generador lo escribió (`generator`) y, si se adoptó en vez de generarse,
`state: adopted`. Un manifiesto v1 se sigue leyendo y la siguiente escritura lo
pasa a la 2. El diagnóstico usa `generator` para separar un salto de plantilla
de una edición.
