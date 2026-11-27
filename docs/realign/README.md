# Realinear un proyecto con LaraPack: `larapack:realign`

Llevar de vuelta al contrato un proyecto que empezó con LaraPack (o no) y se
desvió, **sin reconstruirlo**. Lo que es determinista se aplica solo; lo
mecánico lo hace una persona o un agente siguiendo un playbook del paquete, con
una comprobación concreta como criterio de terminado; lo que es decisión se
presenta como decisión, con sus opciones; y lo que no es de LaraPack se declara
fuera y no se toca.

Esta guía es para cualquiera que lo ejecute, persona o agente, **sin contexto
previo del proyecto**. No hace falta escribir un prompt por proyecto: el
paquete trae el diagnóstico, el plan, las instrucciones de cada tipo de paso y
sus comprobaciones.

> Estado en 9.4.0: el núcleo (`diagnose`, `plan`, `status`, `next`, `check`,
> `decide`) está completo. Las operaciones que escriben (`adopt`, `apply`,
> `infer`, `except`) tienen su contrato y su registro; mientras una no tenga
> implementación, `larapack:realign help` lo dice y `next` muestra el paso para
> hacerlo a mano. Las secciones marcadas *(por completar)* las amplía la guía
> de cada operación.

## Índice

- [El modelo mental](#el-modelo-mental)
- [El ciclo](#el-ciclo)
- [Diagnosticar: qué es cada desviación](#diagnosticar-qué-es-cada-desviación)
- [El plan y sus pasos](#el-plan-y-sus-pasos)
- [Comprobar que un paso está hecho](#comprobar-que-un-paso-está-hecho)
- [Las decisiones y las excepciones](#las-decisiones-y-las-excepciones)
- [Las operaciones que escriben](#las-operaciones-que-escriben)
- [Seguridad: qué nunca hace](#seguridad-qué-nunca-hace)
- [El estado en disco y los formatos JSON](#el-estado-en-disco-y-los-formatos-json)
- [Los tres perfiles de proyecto](#los-tres-perfiles-de-proyecto)
- [Limitaciones y riesgos](#limitaciones-y-riesgos)
- [Para un agente: la receta](#para-un-agente-la-receta)

## El modelo mental

LaraPack genera el código de un proyecto desde su contrato (`laraimport.json`)
y anota cada archivo que escribe en `.larapack/manifest.json`, con su plantilla
y el hash de su contenido. Un proyecto se desvía de tres formas, y las tres se
ven comparando el proyecto con **lo que generaría hoy su contrato con la
versión actual del generador**:

1. **El generador avanzó** y el proyecto no: plantillas de una versión
   anterior, deuda que `larapack:fix` sabe pagar.
2. **Alguien editó lo generado**: a veces dentro de los huecos (es lo
   correcto), a veces fuera.
3. **Hay código escrito a mano** que hace lo que haría el contrato:
   controladores, rutas, modelos.

Todo lo que encuentra el diagnóstico cae en una de cuatro categorías, las
mismas en el informe, en el plan, en la salida y aquí:

| Categoría | Qué significa | Quién lo hace |
|---|---|---|
| `deterministic` | Se aplica con una orden y su resultado se comprueba sin opinar. Nunca toca lo que no reconoce con certeza. | La herramienta. |
| `guided` | Trabajo mecánico: mover lógica a su sitio, completar pruebas. Las instrucciones vienen del paquete (playbook) y el criterio de terminado es una comprobación concreta. | Una persona o un agente. |
| `decision` | Hay más de una respuesta legítima y cambia el comportamiento o el alcance. Se presenta con sus opciones y consecuencias. | Quien tenga autoridad sobre el proyecto. |
| `out_of_scope` | No es de LaraPack: dominio, servicios, front propio, historia de migraciones. | Nadie: se declara y no se toca. |

## El ciclo

```
diagnose → plan → next → (hacer el paso) → check → next → … → diagnose
```

```bash
vendor/bin/builder larapack:realign diagnose     # o php artisan larapack:realign diagnose
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign next         # el siguiente paso, con todo lo necesario
# … hacer lo que dice el paso …
vendor/bin/builder larapack:realign check <id>   # ¿está hecho? lo marca, o dice qué falta
vendor/bin/builder larapack:realign status       # dónde se va
```

Todas aceptan `--root=<proyecto>` y `--format=json`. Las que leen el proyecto
aceptan además `--package` (repetible: el directorio en `packages/`, la ruta,
el nombre de Composer o `app` para el núcleo), igual que `larapack:verify`.

El proceso es **reanudable**: el estado vive en `.larapack/realign/`, así que
otra persona u otro agente que llegue mañana corre `status` y `next` y sigue.
Volver a diagnosticar y planificar no pierde lo hecho ni lo decidido.

## Diagnosticar: qué es cada desviación

```bash
larapack:realign diagnose [--package=…] [--jobs=4] [--no-cache]
```

1. Por cada **ámbito** —la raíz y cada paquete local con `laraimport.json` o
   manifiesto propio— regenera el contrato en `.larapack/realign/cache/`, con
   la versión actual del generador, la Pint del proyecto y su `pint.json`. Los
   ámbitos se regeneran en paralelo (`--jobs`) y se guardan por el hash de todo
   lo que decide el resultado: si nada cambió, la siguiente vez no se regenera
   (`--no-cache` lo fuerza).
2. Compara cada archivo con lo regenerado y lo clasifica.
3. Lista el código HTTP y de modelos que no gestiona nadie y cuenta el resto.
4. Corre `verify` sobre todo el proyecto y clasifica cada hallazgo.

No escribe código: sólo `.larapack/realign/report.json` y la caché.

### Las clases de archivo

| Clase | Qué es | Categoría | Paso |
|---|---|---|---|
| `identical` | Byte a byte lo que generaría el contrato. | cumple; si el manifiesto tiene otro hash o no lo conoce, `deterministic` | `adopt` |
| `format_only` | Igual salvo formato: espacios, comentarios con otros espacios, coma final, orden de imports, o igual tras pasar el archivo por la misma Pint. | igual que `identical` | `adopt` |
| `version_delta` | Nadie lo editó (su hash es el del manifiesto) y difiere: la plantilla cambió (`cause: generator`, escrito por una versión anterior o sin versión) o el contrato cambió (`cause: contract`). | `deterministic` | `regenerate` |
| `hole_edited` | Editado sólo dentro de sus huecos. | cumple | — |
| `edited_outside` | Editado fuera de sus huecos. La entrada dice qué piezas (`members`). | `guided` | `edited-outside` |
| `missing` | No está en disco. Si el contrato lo sigue pidiendo y se borró, `decision`; si es de un modelo retirado del contrato o el contrato no lo volvería a generar, `deterministic` (`prune`); si el contrato lo pide y nunca se generó, `deterministic` (`regenerate`). | según el caso | `missing`, `prune`, `regenerate` |
| `renamed` | El manifiesto lo conoce con otra ruta: una migración renombrada a mano, o el mismo contenido en el mismo directorio. | `deterministic` | `relink` |
| `not_regenerated` | Lo registró el generador, pero `import` no lo regenera (proveedores, configuración, alteraciones, lo de un modelo que ya no está en el contrato). | `out_of_scope` | — |
| `migration_history` | Una migración generada que ya no es la que produciría hoy el contrato. **Las migraciones no se reescriben nunca.** | `out_of_scope` | — |
| `untracked` | En la ruta de un archivo generado hay uno que el manifiesto no conoce. Igual a lo regenerado: `adopt`; distinto: `decision`. | según el caso | `adopt`, `untracked-edited` |
| `absent` | Ni en disco, ni en el manifiesto, ni lo produce el contrato. Sólo aparece al comprobar un paso (`prune`). | — | — |

**Los huecos.** Un archivo entero es hueco si su plantilla es la de un trait
del modelo, un Resource, un listener, un observer, una factoría, una política o
una prueba. En una request, el hueco es `rules()`; en un ManagedFilter,
`canView()`. Lo que cambie en cualquier otro método, una propiedad o un `use`
está fuera. Es la tabla «Dónde va tu código» del README, escrita como datos en
`src/Support/Realign/Holes.php`.

**Lo que no se lista**, sino que se cuenta por directorio
(`out_of_scope.<ámbito>.other_code`): el código de las fuentes que no es de
LaraPack —servicios, soportes, jobs— y los traits del modelo (`Models/Traits`),
que son huecos que el generador crea una vez y no registra.

### El código sin gestionar

Controladores de `Http/Controllers`, archivos de `routes/` y modelos de
`Models/` que ni el manifiesto ni el contrato conocen. Son siempre
`decision`: declararlos en el contrato (`unmanaged-http`,
`unmanaged-models`) o dejarlos como código propio.

### Los hallazgos de verify

Cada comprobación de `verify` pide un tipo de paso (`StepTypes::CHECKS`):

| Comprobación | Paso | Categoría |
|---|---|---|
| `index-managed`, `resource-appends`, `relation-missing` y el `route-closure` del fallback de la SPA | `fix` | `deterministic` |
| `controller-shape`, `request-handle` | `controller-shape` | `guided` |
| `controller-concern` | `controller-concern` | `guided` |
| `endpoint-tests-outdated` | `endpoint-tests` | `guided` |
| `route-not-declared`, `endpoint-missing`, `route-prefix` | `declared-routes` | `guided` |
| `immutable-write`, `secret-exposed` | `security` | `guided` |
| `model-lists-outdated` | `model-lists` | `guided` |
| `hand-rolled` (sin aprobar) | `hand-rolled` | `decision` |
| `route-closure` (otro closure) | `route-closure` | `decision` |
| `inconsistent-entity` | `inconsistent-entity` | `decision` |
| `customised`, `missing-file`, `empty-manifest` | — (la clasificación de archivos lo dice mejor) | — |
| una comprobación que el plan no conoce | `verify-other` | `guided` |

## El plan y sus pasos

```bash
larapack:realign plan
```

Convierte el informe en pasos en `.larapack/realign/plan.json`. Cada paso
agrupa lo que se resuelve junto y lleva:

| Campo | Qué es |
|---|---|
| `id` | Estable entre diagnósticos: `<tipo>[:<sujeto>]@<ámbito>` (`adopt@.`, `fix:index-managed@packages/billing`, `edited-outside:Requests@.`) o, en un controlador, `controller-shape@<archivo>`. |
| `category`, `type`, `title`, `description` | Qué es. |
| `scope` | `{package, path}`: el paquete (null es el núcleo) y su ruta. |
| `files`, `details` | Los archivos, y detrás de cada uno su clase y razón, los hallazgos de verify con su mensaje y el código sin gestionar. |
| `risk` | `{level: low|medium|high, why}`. |
| `reversible` | Si se puede deshacer (con git). |
| `command` | Pasos `deterministic`: la orden. |
| `playbook` | Pasos `guided`: el nombre del playbook. |
| `options` | Pasos `decision`: `{key, label, consequences, run?}`. |
| `done_when` | La comprobación de terminado (ver abajo). |
| `depends_on` | Lo que tiene que estar hecho antes. |
| `status`, `decision`, `history`, `last_check`, `order` | El estado. |

**El orden.** Primero lo que desbloquea —que el manifiesto diga la verdad
(`relink`, `prune`, `adopt`) y los saltos de versión (`regenerate`, `fix`)—,
luego lo guiado **por ámbito, del que más cumple al que menos** (así los
primeros pasos terminan paquetes enteros), y al final las decisiones agrupadas
por tipo. Lo guiado y las decisiones de un ámbito esperan a su `relink`,
`prune` y `adopt`; `edited-outside` y `model-lists` esperan además a
`regenerate` y `fix`.

**Los tipos de paso** (`src/Support/Realign/StepTypes.php`):

| Tipo | Categoría | Qué hace |
|---|---|---|
| `relink` | `deterministic` | Vuelve a enlazar en el manifiesto lo renombrado. |
| `prune` | `deterministic` | Olvida en el manifiesto lo que ya no existe y el contrato no volvería a generar. |
| `adopt` | `deterministic` | Anota en el manifiesto lo que ya es lo que generaría el contrato. |
| `regenerate` | `deterministic` | Regenera lo que nadie editó y quedó atrás, o lo que falta. |
| `fix` | `deterministic` | `larapack:fix <comprobación> --package=<paquete>`. |
| `controller-shape` | `guided` | Lleva la lógica del controlador a su FormRequest. Playbook propio. |
| `controller-concern` | `guided` | Saca los traits de `Http/Controllers/Concerns`. |
| `endpoint-tests` | `guided` | Completa las pruebas de endpoints de versiones anteriores. |
| `edited-outside` | `guided` | Devuelve a sus huecos (o al contrato) lo editado fuera. Un paso por familia de plantilla. Playbook propio. |
| `declared-routes` | `guided` | Cuadra rutas y requests con lo declarado. |
| `security` | `guided` | Cierra una escritura o una exposición que el contrato prohíbe. |
| `model-lists` | `guided` | Pone las listas del modelo al día con el contrato. |
| `verify-other` | `guided` | Cualquier otro hallazgo de verify. |
| `hand-rolled` | `decision` | Aprobar o deshacer las excepciones `#[HandRolled]`. |
| `route-closure` | `decision` | Closures de rutas que no son el fallback de la SPA. |
| `inconsistent-entity` | `decision` | Un modelo con una forma distinta a la de los demás. |
| `missing` | `decision` | Archivos generados que se borraron: restaurarlos o dejarlos borrados. |
| `untracked-edited` | `decision` | Archivos escritos a mano en la ruta de uno generado. |
| `unmanaged-http` | `decision` | Controladores y rutas escritos a mano. |
| `unmanaged-models` | `decision` | Modelos escritos a mano. |
| `no-contract` | `decision` | Un ámbito sin `laraimport.json`: inferirlo o dejarlo fuera. |
| `contract-invalid` | `decision` | El contrato no valida con esta versión: corregirlo o retirarlo. |

**Volver a planificar** después de diagnosticar otra vez conserva todo: un
paso con el mismo `id` conserva su estado, su decisión y su historia; uno hecho
cuyo problema vuelve a salir se reabre (`reopened`); uno pendiente que ya no
sale se cierra (`resolved`); una decisión tomada sigue tomada.

### `next`

Da el primer paso que no esté hecho ni bloqueado, con todo lo necesario para
hacerlo **sin contexto**: qué es, su riesgo, sus archivos y hallazgos, y

- si es `deterministic`, la orden (y si esa operación está disponible en esta
  versión);
- si es `guided`, su **playbook rellenado** con los archivos, los hallazgos, la
  orden de `verify` acotada y la de `check`;
- si es `decision`, las opciones con sus consecuencias y cómo decidir; si ya se
  decidió, qué falta para cumplirlo.

Los playbooks están en `resources/realign/playbooks/` del paquete. Un tipo sin
playbook propio usa `generic.md`, y `next` lo dice en `how.playbook`.

## Comprobar que un paso está hecho

```bash
larapack:realign check <id>
```

Corre el `done_when` del paso. Si pasa, lo marca `done`; si no, dice qué falta,
elemento por elemento, y termina con código distinto de cero. Hacer un paso
(o ejecutar su orden) **no** lo marca hecho: sólo `check`.

| `kind` | Pasa cuando |
|---|---|
| `verify` | `verify`, acotado a sus comprobaciones (`checks`), a su paquete y a sus archivos, no encuentra ni errores ni avisos (sin contar las excepciones aprobadas). |
| `classification` | Cada archivo, comparado otra vez con lo regenerado (con la misma clasificación del diagnóstico), queda en una de las clases de `accept`, en ninguna de `reject`, y, si se pide, registrado (`tracked`) con el hash actual (`hash_current`) o sin paso pendiente (`resolved`). |
| `unmanaged` | Ninguno de los archivos sigue siendo código sin gestionar. |
| `contract` | El `laraimport.json` del ámbito valida. |
| `decision` | Hay una decisión registrada. |

Una decisión, además de su comprobación, necesita estar decidida.

## Las decisiones y las excepciones

```bash
larapack:realign decide <id> <opción> --note="por qué"
```

Registra la opción en el plan (con la nota y la hora) y dice qué hacer para
cumplirla: la orden de la opción, si la tiene. Decidir no es hacer: el paso
sigue pendiente hasta que su `done_when` pasa.

**Las excepciones aprobadas** viven en `.larapack/realign/exceptions.json` y
las escribe la operación `except`. El diagnóstico no vuelve a proponer lo que
cubren. El formato:

```json
{
  "format_version": 1,
  "exceptions": [
    {"type": "unmanaged-http", "scope": ".", "file": "app/Http/Controllers/HookController.php",
     "check": null, "reason": "Firma sobre el cuerpo en bruto.", "approved_by": "…", "at": "…"}
  ]
}
```

Una excepción casa si coinciden todas las claves que trae (`type`, `scope`,
`file`, `check`); una sin `file` cubre todo lo de ese tipo en ese ámbito. **Sin
`reason` no cuenta**, ni una que no acote por tipo, archivo o comprobación.
Las de `#[HandRolled]` no van aquí: tienen su candado
(`.larapack/hand-rolled.lock`).

## Las operaciones que escriben

| Acción | Qué hace | Pasos |
|---|---|---|
| `adopt` | Pone el manifiesto al día con lo que hay: adopta, vuelve a enlazar, olvida. | `adopt`, `relink`, `prune`, `untracked-edited` |
| `apply` | Ejecuta un paso determinista que reescribe código. | `regenerate`, `missing` (restaurar) |
| `infer` | Infiere el contrato desde el código. | `no-contract`, `unmanaged-models`, `unmanaged-http` |
| `except` | Registra una excepción aprobada con su razón. | las decisiones con opción «dejarlo así» |

```bash
larapack:realign <acción> [<id>…] [--package=…] [--dry-run] [--allow-dirty] [--option clave=valor]
```

Lo común a todas lo garantiza el núcleo (`OperationRunner`):

- **`--dry-run`** no escribe nada y dice qué haría.
- **Sin `--dry-run`, el árbol git tiene que estar limpio** (fuera de
  `.larapack/realign/`, que es estado y no código), para que todo se pueda
  deshacer con `git checkout`/`git restore`. Fuera de un repositorio git
  también se rechaza. `--allow-dirty` lo salta, y es tu responsabilidad.
- Sin ids, toma todos los pasos pendientes que la operación sabe hacer
  (acotados con `--package`). Un paso de otro tipo, uno hecho o uno bloqueado
  se rechaza con su razón.
- Informa `fixed`, `skipped` (con la razón; hace que el comando termine con
  error) y `unchanged`, como `larapack:fix`.
- Anota en la historia del paso lo que hizo, sin darlo por hecho.

*(por completar: la guía de cada operación, con sus opciones, límites y
riesgos.)*

## Seguridad: qué nunca hace

- `diagnose`, `plan`, `status`, `next`, `check` y `decide` **no escriben
  código**: sólo su estado en `.larapack/realign/`.
- Ninguna operación reescribe una **migración** que ya existe. Lo que difiere
  en una migración se resuelve con otra migración o con el contrato.
- Ninguna cambia un **nombre de ruta ni una URI** que el contrato no declare: el
  front y los clientes dependen de ellos.
- Lo que no se reconoce con certeza no se toca: se salta, con su razón.
- Lo `out_of_scope` no se toca nunca.

## El estado en disco y los formatos JSON

```
.larapack/realign/
  report.json        el último diagnóstico (versionable)
  plan.json          los pasos, su estado y su historia (versionable)
  exceptions.json    las excepciones aprobadas (versionable)
  .gitignore         deja fuera cache/
  cache/             lo regenerado para comparar (no se versiona)
```

Todos los JSON —los archivos y toda salida `--format=json`— llevan
`format_version` (hoy `1`). Se sube cuando cambia algo que un consumidor lee;
añadir una clave no la sube. Un archivo de una versión posterior no se lee: se
pide actualizar el paquete.

- `diagnose --format=json`: `{format_version, ok, action, generator,
  generated_at, totals: {classes, categories, conforming, files}, verify,
  scopes: [{package, path, contract, manifest, manifest_version, regeneration,
  counts, total, conforming}], report}`.
- `report.json`: lo mismo por ámbito (`scopes`, con su `fingerprint` del
  contrato y del manifiesto), más `files` (sólo lo que no cumple:
  `{scope, package, file, path, model, stub, class, category, type, reason,
  tracked, hash_current, generator, to?, members?, cause?}`), `unmanaged`,
  `findings` (los de verify, con `scope`, `category` y `type`) y
  `out_of_scope`.
- `status --format=json`: `{format_version, ok, action, diagnosed_at,
  planned_at, stale, totals, verify, steps: {total, by_status, by_category},
  next, last_check, plan}`. `stale` lista los ámbitos cuyo contrato o
  manifiesto cambió después del diagnóstico.
- `next --format=json`: `{format_version, ok, action, done, step, how, check,
  done_when}`.
- `check --format=json`: `{format_version, ok, action, id, done, remaining,
  kind}`.
- `decide --format=json`: `{format_version, ok, action, id, decision, then,
  check}`.
- Las operaciones: `{format_version, ok, action, dry_run, steps, fixed,
  skipped, unchanged, notes}`.
- Un error, en cualquiera: `{ok: false, error, format_version, action}`.

**El manifiesto v2.** Desde 9.4.0 cada archivo del manifiesto dice qué versión
del generador lo escribió (`generator`) y, si se adoptó en vez de generarse,
`state: adopted`. Un manifiesto v1 se sigue leyendo y la siguiente escritura lo
pasa a la 2. El diagnóstico usa `generator` para separar un salto de plantilla
de una edición.

## Los tres perfiles de proyecto

**Con contrato y manifiesto al día** (school-management). El caso fácil: casi
todo es `identical` o `format_only` con el hash viejo (`adopt`), deuda de
`fix` y huecos editados. Lo guiado es poco y concreto.

**Con contrato, de una versión vieja y con mucha deuda de controladores a mano**
(expediente, 7.x). Muchos `adopt` (el proyecto pasaba Pint después de cada
import y el manifiesto guardaba el hash de antes), `prune` de lo retirado,
`fix`, una familia de `edited-outside` por plantilla, cientos de
`controller-shape` (uno por controlador) y las decisiones de los modelos y
controladores a mano.

**Sin contrato** (brandai). No hay con qué comparar: el diagnóstico lo dice
(`no-contract`) y lista el código HTTP y de modelos. El camino es inferir el
contrato (`infer`) o dejar el proyecto fuera de LaraPack y quedarse con
`verify` como guardia de la forma de los controladores.

## Limitaciones y riesgos

- **Un archivo editado y escrito por una versión anterior** no se puede separar
  en «lo que editaste» y «lo que cambió la plantilla» sin las plantillas
  viejas. Se clasifica `edited_outside` con `cause: edited_or_generator`; el
  playbook lo dice. Correr primero los `fix` reduce mucho esa lista.
- **Un manifiesto cuyo hash es de antes de Pint** hace que lo formateado parezca
  editado: el diagnóstico lo reconoce como `identical`/`format_only` y lo
  propone para `adopt`, pero si además se editó, no se distingue la edición del
  formato hasta adoptar.
- **«Igual salvo formato»** considera los comentarios: un comentario añadido es
  una edición. Sin Pint disponible (`LARAPACK_PINT=off`), sólo se reconocen los
  cambios de formato por tokens.
- **Las migraciones** se comparan sólo por nombre y contenido. Si una tabla se
  creó con otra migración, la regenerada no tiene con qué compararse y no se
  propone nada.
- **La regeneración** sale de un directorio vacío con el `composer.json`, el
  `laraimport.json` y el `pint.json` del ámbito: lo que el import leería de
  otros archivos del proyecto no cuenta. Tarda: en expediente, unos dos
  minutos; en school-management, unos minutos con `--jobs=4` (ver CHANGELOG).
  La caché ocupa el tamaño de lo generado.
- **El código sin gestionar** se busca en `Http/Controllers`, `Models` y
  `routes/`. Lo que esté en otro sitio se cuenta en `other_code`, no se lista.
- **Cada paso guiado mueve código** y puede cambiar comportamiento (por ejemplo,
  un 403 que llega antes que un 422). Las pruebas del paquete tienen que pasar
  antes y después; el playbook lo pide.

## Para un agente: la receta

1. `larapack:realign status --format=json`. Si `plan` es null o `stale` no está
   vacío: `diagnose` y `plan`.
2. `larapack:realign next --format=json`. Si `done` es true, `diagnose` y
   `plan` otra vez para confirmar; si sigue sin nada, se terminó.
3. Según `step.category`:
   - `deterministic`: si `how.available`, ejecuta `how.command` (primero con
     `--dry-run`). Si no, hazlo a mano como dice `step.description`.
   - `guided`: sigue `how.instructions` al pie de la letra.
   - `decision`: **no decidas tú**. Presenta `how.options` a quien tenga
     autoridad y, cuando decida, `larapack:realign decide <id> <opción>
     --note="…"`.
4. `larapack:realign check <id> --format=json`. Si `done` es false, resuelve
   `remaining` y vuelve a comprobar.
5. Comitea el paso (un commit por paso) y vuelve al 2.
