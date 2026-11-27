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
> `decide`) está completo, y también `adopt` (poner el manifiesto al día) y
> `apply` (los pasos deterministas que reescriben). Las operaciones que
> escriben comparten contrato y registro; mientras una no tenga
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
| `format_only` | Igual salvo formato: espacios, coma final, orden de imports, o igual tras pasar el archivo por la misma Pint. También el mismo código con **otros comentarios**, marcado `comments: true` (salvo en lo que nadie editó, que es `version_delta`, y en un hueco entero, que es `hole_edited`). | igual que `identical` | `adopt` |
| `version_delta` | Nadie lo editó (su hash es el del manifiesto) y difiere: la plantilla cambió (`cause: generator`, escrito por una versión anterior o sin versión) o el contrato cambió (`cause: contract`). | `deterministic` | `regenerate` |
| `hole_edited` | Editado sólo dentro de sus huecos. | cumple | — |
| `edited_outside` | Editado fuera de sus huecos. La entrada dice qué piezas (`members`). | `guided` | `edited-outside` |
| `missing` | No está en disco. Si el contrato lo sigue pidiendo y se borró, `decision`; si es de un modelo retirado del contrato (`cause: retired`), el contrato no lo volvería a generar (`not_regenerated`) o es una migración registrada dos veces cuya otra copia sigue ahí (`superseded`), `deterministic` (`prune`); si el contrato lo pide y nunca se generó, `deterministic` (`regenerate`). | según el caso | `missing`, `prune`, `regenerate` |
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
| `controller-concern` (aviso: sólo lo usan requests) | `controller-concern` | `deterministic` |
| `controller-concern` (error: lo usa un controlador) | `controller-shape` | `guided` |
| `endpoint-tests-outdated` | `endpoint-tests` | `deterministic` |
| `route-not-declared`, `endpoint-missing`, `route-prefix` | `declared-routes` | `guided` |
| `immutable-write`, `secret-exposed` | `security` | `guided` |
| `model-lists-outdated` | `model-lists` | `deterministic` |
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
`prune` y `adopt`; `edited-outside` espera además a `regenerate`, `fix` y
`model-lists`. Dentro de lo determinista, `pin-foreign-keys` va el último:
cambia el contrato, y lo que va detrás se compararía con otra regeneración.

**Los tipos de paso** (`src/Support/Realign/StepTypes.php`):

| Tipo | Categoría | Qué hace |
|---|---|---|
| `relink` | `deterministic` | Vuelve a enlazar en el manifiesto lo renombrado. |
| `prune` | `deterministic` | Olvida en el manifiesto lo que ya no existe y el contrato no volvería a generar. |
| `adopt` | `deterministic` | Anota en el manifiesto lo que ya es lo que generaría el contrato. |
| `regenerate` | `deterministic` | Regenera lo que nadie editó y quedó atrás, o lo que falta. |
| `fix` | `deterministic` | `larapack:fix <comprobación> --package=<paquete>`. |
| `controller-shape` | `guided` | Lleva la lógica del controlador a su FormRequest. Playbook propio. |
| `controller-concern` | `deterministic` | Mueve a `Http/Requests/Concerns` los traits de `Http/Controllers/Concerns` que sólo usan requests, con sus `use`. Playbook propio para lo que salte. |
| `endpoint-tests` | `deterministic` | Añade a cada `<Modelo>EndpointsTest` las pruebas que trae hoy la plantilla, conservando lo escrito a mano. Playbook propio para lo que salte. |
| `edited-outside` | `guided` | Devuelve a sus huecos (o al contrato) lo editado fuera. Un paso por familia de plantilla. Playbook propio. |
| `declared-routes` | `guided` | Cuadra rutas y requests con lo declarado. |
| `security` | `guided` | Cierra una escritura o una exposición que el contrato prohíbe. |
| `model-lists` | `deterministic` | Completa las listas del modelo con lo que declara el contrato (sólo añade). Playbook propio para lo que salte. |
| `pin-foreign-keys` | `deterministic` | Escribe en el contrato el `on_delete`/`on_update` que hacen hoy las migraciones donde el contrato calla. |
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
  versión) y, si el tipo tiene playbook, el playbook rellenado en
  `how.fallback`: es lo que se hace a mano con lo que la orden salte;
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
| `foreign-keys` | El `laraimport.json` del ámbito declara `on_delete`/`on_update` en cada una de sus columnas (`columns`). El valor que declare es suyo: lo que había que resolver era que callaba. |
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
| `apply` | Ejecuta los pasos deterministas que reescriben código o el contrato (uno a uno, o todos con `--deterministic`). | `regenerate`, `missing` (restaurar), `fix`, `endpoint-tests`, `controller-concern`, `model-lists`, `pin-foreign-keys`, y los de `adopt` |
| `infer` | Infiere el contrato desde el código. | `no-contract`, `unmanaged-models`, `unmanaged-http` |
| `except` | Registra una excepción aprobada con su razón. | las decisiones con opción «dejarlo así» |

```bash
larapack:realign <acción> [<id>…] [--package=…] [--dry-run] [--allow-dirty] [--option clave=valor]
larapack:realign apply --deterministic [--package=…] [--dry-run] [--allow-dirty]
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

### `adopt`: que el manifiesto diga la verdad

```bash
larapack:realign adopt adopt@. --dry-run      # qué anotaría
larapack:realign adopt adopt@.                # lo anota
larapack:realign adopt                        # todos sus pasos pendientes (y las decisiones `adopt` tomadas)
```

**Nunca cambia el contenido de un archivo**: sólo `.larapack/manifest.json`
(se escribe una vez, al final). Antes de anotar cada archivo lo vuelve a
comparar con lo regenerado, con la misma clasificación del diagnóstico: si
alguien lo editó después de diagnosticar, lo salta diciéndolo.

| Paso | Qué hace con cada archivo | Cuándo lo salta |
|---|---|---|
| `adopt` | Igual a lo regenerado (`identical`, `format_only`). Si el manifiesto lo conoce, vuelve a anotar su hash y la versión del generador, **conservando su estado** (lo generado sigue siendo `generated`). Si no lo conoce, lo registra con `state: adopted`, con el modelo, el namespace y el stub de lo regenerado. | Ya no es igual a lo regenerado. Lo regenerado no lo registra. |
| `relink` | Renombrado: mueve el registro a la ruta nueva con su stub y su hash. | Ya no se reconoce como renombrado (dos candidatos, o el original volvió). |
| `prune` | Borrado y el contrato no lo volvería a generar (`retired`, `not_regenerated`, `superseded`): lo olvida. Si es de un modelo que ya no está en el contrato, lo que le queda en disco pasa a quien lo genera hoy (el módulo compartido de la interfaz) o, si no lo genera nadie (una vista que se quedó), a lo que no es de ninguna entidad —sigue registrado y vigilado—, y el modelo se olvida: si no, `verify` lo vería como una entidad a la que le falta todo. | Vuelve a estar en disco. El contrato lo sigue pidiendo: eso es la decisión `missing`. |
| `untracked-edited` (si se decidió `adopt`) | Escrito a mano en la ruta de uno generado: lo registra con `state: adopted` y **el hash de lo regenerado**. Queda personalizado: `verify` lo sigue (deriva, forma de controlador) y `--force` no lo sobrescribe jamás. | Falta decidir, o se decidió otra cosa. Ya no es de esa clase. |

**La versión en lo heredado.** En cada ámbito que toca, `adopt` anota además
la versión actual del generador en los archivos de un manifiesto v1 que siguen
siendo, **sin editar** (su hash es el del manifiesto), idénticos o iguales
salvo espacios a lo regenerado. Su hash no cambia. Desde ahí, si una versión
posterior cambia la plantilla, el diagnóstico lo distingue de un cambio del
contrato. Lo que sólo es igual pasándolo por Pint, o está editado en sus
huecos, se queda sin versión: no se afirma lo que no se comprobó.

**Riesgos.** Bajos: se deshace con el control de versiones. Adoptar un
`format_only` marcado `comments: true` deja que un `import --force` posterior
reemplace esos comentarios por los de la plantilla. Adoptar como editado algo
escrito a mano hace que `--force` no lo regenere nunca: si después se quiere lo
generado, hay que borrarlo y regenerarlo.

### `apply`: lo determinista que reescribe

```bash
larapack:realign apply <id>… --dry-run        # con el diff de cada archivo
larapack:realign apply <id>…
larapack:realign apply --deterministic --dry-run
larapack:realign apply --deterministic        # todos los deterministas pendientes, en el orden del plan
```

**Sin ids hay que pedirlo** (`--deterministic`): `apply` reescribe código y no
lo hace por omisión. Con `--deterministic` hace también los pasos de `adopt`
(`relink`, `prune`, `adopt`), en su orden. Las decisiones sólo entran si se
decidió la opción que ejecuta `apply` (`restore` en `missing`).

Cada cambio se escribe entero o no se escribe: si después de escribirlo (y de
pasarle la Pint del proyecto, donde se dice) un PHP no parsea, todo lo de ese
cambio vuelve a como estaba —un archivo creado se borra, uno borrado se
restaura— y se salta con la razón. Lo saltado deja el paso pendiente; lo que
falta lo dice su playbook (`next`, en `how.fallback`).

| Paso | Qué escribe | Qué nunca hace / cuándo salta | Riesgo |
|---|---|---|---|
| `regenerate` | Lo que nadie editó y quedó atrás (`version_delta`: su hash es el del manifiesto) y lo que el contrato pide y nunca se generó, **copiado de lo regenerado**, que ya pasó por la Pint del proyecto. Lo registra como generado, con la versión actual. Es un `import --force` acotado a lo seguro. | Nunca un archivo editado (si alguien lo editó después de diagnosticar, salta), **nunca una migración**, nunca nada de un modelo que no se ha importado (su migración la escribe `larapack:import`). | Medio: cambia comportamiento si la plantilla nueva lo cambia (un IndexRequest que fuerza `managed`, un Resource que deja de servir `appends`). Puede pedir dependencias nuevas que la plantilla usa. |
| `missing` (si se decidió `restore`) | Lo generado que se borró, igual que `regenerate`. | Lo que no está como `missing` borrado. | Medio: vuelve algo que alguien quitó. |
| `fix` | Lo mismo que `larapack:fix <comprobación> --package=<paquete>` (LegacyFix): `index-managed`, `resource-appends`, `relation-missing`, el fallback de la SPA. | Lo que no tiene la forma generada: lo salta con la razón de `larapack:fix`. | Medio: ver `fix` en el plan. |
| `endpoint-tests` | Añade a cada `<Modelo>EndpointsTest` las pruebas que trae hoy la plantilla (sin sesión no se entra, cada acción pregunta a la política, el listado pasa por su ManagedFilter), copiadas de lo regenerado, con los auxiliares privados que llaman y los `use` que necesitan. Pasa la Pint del proyecto por el test. | Nunca quita ni cambia un método que ya está. Salta si el test no se reconoce con certeza (dos clases, una importación agrupada), si su clase o su namespace no son los de lo regenerado, o si una importación choca con otra del mismo nombre. | Bajo: no toca código de producción. Una prueba nueva **puede fallar con razón** (una acción que responde sin sesión): no se quita, se arregla la acción. |
| `controller-concern` | Mueve el trait de `Http/Controllers/Concerns` a `Http/Requests/Concerns` (el aviso: sólo lo usan requests), cambia su namespace y reescribe **por tokens** cada nombre que lo apunta en el proyecto: `use`, `\…::class`, nombres relativos. Si el manifiesto lo registraba, el registro lo sigue. | El que usa un controlador (es `controller-shape`). Salta si alguien lo nombra en una cadena (configuración), en un `use` agrupado o por su nombre corto desde su mismo namespace, si ya hay un archivo en el destino o si su namespace no es el de su ruta. Un comentario que lo nombra se queda. | Bajo: el comportamiento no cambia. |
| `model-lists` | Completa `$fillable`, `$creatable`, `$updatable`, `$export_cols` y `casts()` de un modelo editado con lo que declara el contrato (ModelRegions, lo mismo que `import --force`). | Sólo añade: nunca quita lo que el contrato ya no declara ni cambia un cast distinto (son avisos `info`). Una región que no se localiza con certeza se salta con su orden manual. | Medio: cambia qué se asigna en masa y cómo se convierte una columna. |
| `pin-foreign-keys` | Escribe en `laraimport.json` el `on_delete`/`on_update` que hacen hoy las migraciones (leídas todas, por tokens) donde el contrato calla y difiere de `restrict` —la cascada de 7.x—. Edita el JSON **en su sitio**: sólo cambian las líneas de esas columnas. Valida el contrato antes de escribirlo. | Nunca toca una migración ni la base. Salta si el contrato ya declara otra acción (es una decisión), o si el JSON no se puede editar sin reescribirlo. Una llave que alguna migración toca de forma que no se lee no se propone. | Bajo: la base no cambia; el contrato pasa a decir lo que hace producción. Cambiar la acción de verdad es otra decisión (declarar otra y migrar). |

Después de `apply --deterministic`, **vuelve a diagnosticar y planificar**: lo
que una corrección deja igual a lo regenerado (un IndexRequest que arregló
`fix`) aparece como `adopt` en la siguiente pasada. Dos pasadas bastan en los
proyectos ensayados; cuando `plan` no tiene deterministas pendientes, se sigue
con lo guiado.

### Saltar de versión mayor (7.x → 8 → 9)

Las guías «Al actualizar» del CHANGELOG, convertidas en pasos del plan. Lo que
es determinista lo hace `apply --deterministic`; lo demás queda como paso
guiado o decisión, con su razón.

| Guía | Qué pide | Paso |
|---|---|---|
| 8.0 — `restrict` por omisión en las llaves | Declarar `on_delete`/`on_update` si el proyecto dependía de la cascada | `pin-foreign-keys` (`deterministic`) |
| 8.0 / 9.3.1 D4 — alteraciones vacías de 9.x | Borrar las que no se han migrado | Ninguno: saber si se migró pide la base de producción. Se dice, no se hace. |
| 9.0 — forma de los controladores | Llevar la lógica a su FormRequest | `controller-shape` (`guided`) |
| 9.0 — traits en `Http/Controllers/Concerns` | Moverlos a `Http/Requests/Concerns` | `controller-concern` (`deterministic`) si sólo los usan requests; `controller-shape` si los usa un controlador |
| 9.0 — IndexRequest, Resource y exportación nuevos | `import --force` en lo no editado; `fix index-managed resource-appends` en lo editado | `regenerate` y `fix` (`deterministic`) |
| 9.1 — pruebas de autorización en `<Modelo>EndpointsTest` | Borrar, importar y volver a pegar lo propio | `endpoint-tests` (`deterministic`, sin borrar nada) |
| 9.2 — `relation-missing` | `fix relation-missing` | `fix` |
| 9.2 — `model-lists-outdated` | `import --force` | `model-lists` (`deterministic`) |
| 9.3.0 — lo formateado después de generar figura `customised` | No se arreglaba solo | `adopt` (`deterministic`) |
| 9.4.0 — manifiesto v2 | Nada | `adopt` anota la versión en lo heredado |

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
  `findings` (los de verify, con `scope`, `category` y `type`), `contract`
  (lo que el contrato tiene que decir y calla: `{scope, package, file, model,
  column, table, on_delete?, on_update?, migration, category, type}`, hoy sólo
  `pin-foreign-keys`) y `out_of_scope`.
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
  skipped, unchanged, notes}`. Cada elemento de `fixed`/`skipped`/`unchanged`
  es `{file, reason, step}` más lo suyo: `diff` en una simulación de `apply`,
  `to` y `references` en `controller-concern`, `model` y `stub` en `adopt`,
  `stamped` en la línea de la versión anotada en lo heredado (`file: null`).
- Un error, en cualquiera: `{ok: false, error, format_version, action}`.

**El manifiesto v2.** Desde 9.4.0 cada archivo del manifiesto dice qué versión
del generador lo escribió (`generator`) y, si se adoptó en vez de generarse,
`state: adopted`. Un manifiesto v1 se sigue leyendo y la siguiente escritura lo
pasa a la 2. El diagnóstico usa `generator` para separar un salto de plantilla
de una edición.

## Los tres perfiles de proyecto

**Con contrato y manifiesto al día** (school-management). El caso fácil: casi
todo es `identical` o `format_only` con el hash viejo (`adopt`), deuda de
`fix` y huecos editados. Lo guiado es poco y concreto. En la copia ensayada,
dos pasadas de `apply --deterministic` (unos 10 minutos) dejaron 20 avisos de
`verify` de 1 975 y 207 archivos editados fuera de sus huecos de 404.

**Con contrato, de una versión vieja y con mucha deuda de controladores a mano**
(expediente, 7.x). Muchos `adopt` (el proyecto pasaba Pint después de cada
import y el manifiesto guardaba el hash de antes), `prune` de lo retirado,
`fix`, una familia de `edited-outside` por plantilla, cientos de
`controller-shape` (uno por controlador) y las decisiones de los modelos y
controladores a mano. `apply --deterministic` paga todo lo mecánico —también
fija en el contrato la cascada de 56 llaves de 7.x— y deja lo que de verdad
es trabajo: los 377 hallazgos `controller-shape` de 77 controladores.

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
- **«Igual salvo formato» incluye los comentarios** cuando el código es el
  mismo (`comments: true`). Adoptar un archivo así deja que un `import --force`
  posterior reemplace esos comentarios por los de la plantilla: quien adopta
  puede conservarlos adoptándolo como editado. Sin Pint disponible
  (`LARAPACK_PINT=off`), sólo se reconocen los cambios de formato por tokens.
- **Un hueco con métodos** (`rules()`, `canView()`) admite además `use` nuevos
  en la cabecera (`imports`): el código del hueco trae los suyos. Una propiedad
  o un método nuevos están fuera.
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
- **`regenerate` copia lo que generaría hoy la plantilla**, y la plantilla
  nueva puede usar clases de una versión nueva de una dependencia
  (`innoboxrr/search-surge`, por ejemplo). `apply` comprueba que el PHP
  parsea, no que esas clases existan: corre las pruebas del proyecto después,
  y actualiza la dependencia si hace falta.
- **`adopt` sólo anota la versión** en lo heredado que se sabe igual sin Pint;
  el resto sigue sin versión, y el diagnóstico lo trata como de una versión
  anterior (que es lo prudente).
- **`endpoint-tests`** sólo reconoce las pruebas por el final de su nombre (el
  mismo criterio que `verify`): una prueba propia que se llame igual cuenta
  como la de la plantilla. Y copia las pruebas de lo regenerado, que se
  formatean con la Pint del proyecto; si el proyecto no tiene Pint ni la tiene
  el generador, quedan con el formato de la plantilla.
- **`controller-concern`** reescribe nombres de clase por tokens, no
  comentarios ni docblocks: un `@see` al namespace viejo se queda. Y busca
  referencias en los PHP del proyecto (fuera de `vendor`, `node_modules`,
  `storage` y `cache`): un nombre en un archivo que no es PHP (un `.json`, un
  `.yaml`) no se ve.
- **`pin-foreign-keys`** lee las migraciones por tokens (SchemaHistory). Si
  una llave se cambió en la base sin migración, o con SQL que no se lee, el
  contrato diría otra cosa que la base: no se propone lo incierto, pero lo que
  no está en una migración no lo puede saber nadie desde el código.
- **Una pasada no deja todo**: lo que `fix` deja igual a lo regenerado sale
  como `adopt` en la siguiente. Diagnostica y planifica otra vez después de
  `apply --deterministic`.

## Para un agente: la receta

1. `larapack:realign status --format=json`. Si `plan` es null o `stale` no está
   vacío: `diagnose` y `plan`.
2. `larapack:realign next --format=json`. Si `done` es true, `diagnose` y
   `plan` otra vez para confirmar; si sigue sin nada, se terminó.
3. Según `step.category`:
   - `deterministic`: si `how.available`, ejecuta `how.command` (primero con
     `--dry-run`). Si no, hazlo a mano como dice `step.description`. Lo que la
     orden salte (`skipped`, con su razón) se hace a mano siguiendo
     `how.fallback.instructions`. Para ir más rápido, `apply --deterministic`
     hace todos los deterministas de una vez; después, `diagnose` y `plan`
     otra vez.
   - `guided`: sigue `how.instructions` al pie de la letra.
   - `decision`: **no decidas tú**. Presenta `how.options` a quien tenga
     autoridad y, cuando decida, `larapack:realign decide <id> <opción>
     --note="…"`.
4. `larapack:realign check <id> --format=json`. Si `done` es false, resuelve
   `remaining` y vuelve a comprobar.
5. Comitea el paso (un commit por paso) y vuelve al 2.
