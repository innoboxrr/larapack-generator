# Los pasos guiados y los playbooks

Esta guía es la parte de [`larapack:realign`](README.md) que explica **cómo se
hace a mano —persona o agente— lo que no es determinista**, sin escribir un
prompt por proyecto. Las instrucciones de cada tipo de trabajo están escritas
una vez en el paquete (`resources/realign/playbooks/`), y `larapack:realign
next` las imprime **ya rellenadas** con los archivos, los hallazgos y las
órdenes exactas del paso que toca.

## Índice

- [Cómo se usa](#cómo-se-usa)
- [Qué trae un playbook](#qué-trae-un-playbook)
- [Los playbooks del paquete](#los-playbooks-del-paquete)
- [Anexos, decisiones y opciones](#anexos-decisiones-y-opciones)
- [Comprobar un paso guiado](#comprobar-un-paso-guiado)
- [Sin pruebas: cuando no se pueden correr las del proyecto](#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto)
- [Después de los pasos guiados: otra pasada](#después-de-los-pasos-guiados-otra-pasada)
- [Riesgos de los pasos guiados](#riesgos-de-los-pasos-guiados)
- [Lo que no cabe: `escalate` o `except`](#lo-que-no-cabe-escalate-o-except)
- [Para quien añade un playbook](#para-quien-añade-un-playbook)

## Cómo se usa

```bash
vendor/bin/builder larapack:realign next --format=json   # how.instructions es el playbook rellenado
# … seguirlo al pie de la letra, con las pruebas del proyecto antes y después …
vendor/bin/builder larapack:realign check <id> --tested="php artisan test --filter=…"
```

`--tested` sólo en un paso que cambia comportamiento (`tests.required` en
`next`), y sólo si las pruebas pasaron; sin ellas, `check <id>` a secas lo deja
`untested` ([Sin pruebas](#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto)).

El playbook rellenado de **cualquier** paso, no sólo del siguiente —uno que
está bloqueado, una decisión, uno ya hecho—:

```bash
vendor/bin/builder larapack:realign show <id> --format=json    # o: larapack:realign next <id>
```

Un paso guiado que el playbook dice dejar (el controlador del callback de
OAuth2, por ejemplo) o que no se puede hacer ahora se aplaza con su razón, y
`next` sigue con el siguiente; si hacerlo o no lo tiene que decidir el dueño,
se le pasa como decisión ([plan.md](plan.md#escalate)):

```bash
vendor/bin/builder larapack:realign defer <id> --note="es el callback de OAuth2: el playbook dice dejarlo"
vendor/bin/builder larapack:realign escalate <id> --note="¿se queda escrito a mano?"
```

Sin un paso delante, para leer un playbook antes de empezar:

```bash
vendor/bin/builder larapack:realign playbook                        # la lista
vendor/bin/builder larapack:realign playbook decide-foreign-keys    # uno, con cada marcador explicado
```

## Qué trae un playbook

Todos tienen la misma forma, para que se lean sin pensar:

1. **Objetivo**: qué queda hecho, en una frase, y qué **no** cambia (la URI, el
   nombre de la ruta, el código de estado…).
2. **Lo que marca verify** y **los archivos**, rellenados desde el plan.
3. **Precondiciones**: lo que tiene que estar antes (el árbol limpio, la
   decisión registrada, el paso no bloqueado).
4. **Pasos exactos**, numerados.
5. **Qué NO hacer**: los atajos que verify ve, los que no ve, y lo que rompe a
   otros (el front, la base de producción).
6. **Riesgo**, del paso y por qué.
7. **Comprobar**: la orden de `verify` acotada al paso —sus comprobaciones y
   sus archivos (`--check=… --path=…`)— para mientras se trabaja, y la de
   `larapack:realign check <id>` para cuando se crea terminado.

## Los playbooks del paquete

**Los de un tipo guiado** (el plan los asigna; `next` los muestra):

| Playbook | Paso | Qué se hace |
|---|---|---|
| `controller-to-request` | `controller-shape` | Llevar la lógica de cada acción a su FormRequest: `authorize()`, `rules()`, `handle()`, con las pruebas antes y después y el **403 antes que 422** advertido; la autorización que se queda en `handle()` para conservar un 404, y el criterio de los protocolos (mover si se conservan el código y el formato; si no, `#[HandRolled]` con su razón para el dueño). |
| `edited-outside` | `edited-outside` | Devolver a sus huecos (o al contrato) lo editado fuera de ellos, por familia de plantilla: con la ruta exacta de lo regenerado en la caché (`regenerado:`) para el diff, qué hacer con los `use` y los métodos auxiliares, y cuándo copiar lo regenerado es la salida correcta. |
| `declared-routes` | `declared-routes` | Cuadrar rutas, acciones y requests con el contrato: declararlas o retirarlas y regenerar. |
| `security` | `security` | Cerrar una escritura a un modelo inmutable o una columna secreta expuesta. |
| `controller-concern`, `endpoint-tests`, `model-lists` | los suyos (deterministas) | Lo que la orden de `apply` salte, con su razón, se hace a mano con éste: `next` lo da en `how.fallback` ([apply.md](apply.md)). |
| `generic` | cualquier otro | Leer el hallazgo, cambiar el contrato antes que el código, no adivinar. |

**Los anexos** (se añaden al de arriba cuando los hallazgos lo piden):

| Anexo | Cuándo | Qué añade |
|---|---|---|
| `validate-to-rules` | Una acción valida en el controlador (`validate()`). | Las reglas tal cual a `rules()`, los mensajes a `messages()`, lo condicional a `withValidator()`; la forma del 422 si el cliente la lee. |
| `raw-request-to-formrequest` | Una acción recibe `Illuminate\Http\Request` (o `FormRequest` a secas). | Tipar con la request propia; los **parámetros escalares de la ruta** pasan a `$this->route('nombre')` (el nombre del segmento, no el de la variable). |

**Los de una opción** (se muestran cuando se decidió esa opción):

| Playbook | Decisión → opción | Qué se hace |
|---|---|---|
| `closure-to-controller` | `route-closure` → `refactor` | Cada closure a un controlador con su request, con la misma URI, nombre, verbo y middleware; `Route::view()` si sólo pinta una vista. |
| `declare-handwritten-controller` | `unmanaged-http` → `declare` | Declarar en `endpoints`/`controllers[]` lo que conserva su ruta (`route_name`, `uri`), mover los `handle()` a sus huecos, importar y quitar lo duplicado. |
| `controller-to-request` | `hand-rolled` → `refactor` | Quitar el atributo y llevar la acción a su request. |

**Los de una decisión** (cómo pensarla antes de tomarla; las toma el dueño):

| Playbook | Decisión | Qué ayuda a decidir |
|---|---|---|
| `decide-hand-rolled` | `hand-rolled` | Cuándo una acción a mano es legítima (firma sobre el cuerpo en bruto, formato de un protocolo, streaming) y cuándo no. |
| `decide-route-closure` | `route-closure` | Qué closures son legítimos (la SPA según el Host, CORS, salud, CSP) y cómo se exceptúan uno por uno. |
| `decide-unmanaged-http` | `unmanaged-http` | Declarar un controlador a mano en el contrato o dejarlo como código propio. |
| `decide-adopt-model` | `unmanaged-models` | Adoptar o no un modelo: lo que genera, los inmutables, los de Laravel, las llaves. |
| `decide-package-without-models` | `contract-invalid` | El paquete de integración sin modelos (`models: []`): corregir el contrato o retirarlo. |
| `decide-foreign-keys` | (se lee aparte) | `cascade` frente a `restrict` al saltar de 7.x: fijar lo que hay primero (`pin-foreign-keys`, [apply.md](apply.md)) y decidir después, llave por llave. |

## Anexos, decisiones y opciones

- **Los anexos** los decide el plan a partir de los mensajes de verify
  (`Playbooks::annexesFor()`); salen en `step.annexes` y van detrás del
  playbook del tipo, separados por `---`.
- **El de una decisión** sale en `next` junto con las opciones mientras no se
  decide (`how.playbook`, `how.instructions`).
- **El de una opción** sale en `next` una vez decidida esa opción, junto con
  su orden si la tiene (`how.then`). Así, decidir «llevar los closures a un
  controlador» no deja a nadie sin instrucciones.

## Comprobar un paso guiado

Un paso guiado se da por hecho **sólo** con `larapack:realign check <id>`, que
corre su `done_when`. Para un controlador, es que verify no encuentre
`controller-shape` ni `request-handle` en él; y `request-handle` cuenta aunque
el hallazgo esté en la request y no en el controlador (una acción que delega
en una request sin `handle()` tiene la forma correcta y falla al llamarla).
Si el paso cambia comportamiento, además, con su prueba: `--tested` o la orden
configurada; si no, queda `untested`
([Sin pruebas](#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto)).

Mientras se trabaja, la orden de verify que trae el playbook mira sólo lo del
paso:

```bash
vendor/bin/builder larapack:verify --format=json --package=app --check=controller-shape --check=request-handle --path=src/Http/Controllers/Admin/ReportController.php
```

`--path` (repetible) acota a esos archivos o a lo que hay bajo esos
directorios; lo que no tiene archivo queda fuera, y el `request-handle` de una
request en la que delega un controlador de esas rutas se cuenta con él.

Si `check` dice que falta, dice **qué** y **dónde**: el hallazgo con su archivo
y su mensaje («valida en el controlador con validate()», «no define
handle()»). Se resuelve y se vuelve a comprobar.

## Sin pruebas: cuando no se pueden correr las del proyecto

La regla: **un paso que cambia comportamiento no se da por hecho sin su
prueba**, y la herramienta lo lleva en el plan, no en la memoria de nadie.
Cambian comportamiento (`StepTypes::BEHAVIOUR`):

- `controller-shape` (mover a una FormRequest cambia el orden
  autorizar/validar y puede cambiar un 404 o un 422), `declared-routes` y
  `security`;
- `edited-outside` de las familias `Requests`, `Controller` y `Policy`
  (`edited-outside:Requests@.`): mueven autorización o validación;
- una decisión decidida con una opción que mueve código
  (`StepTypes::BEHAVIOUR_OPTIONS`): `route-closure` → `refactor`
  (`closure-to-controller`), `hand-rolled` → `refactor`, `unmanaged-http` →
  `declare`, y un guiado pasado al dueño (`escalate`) → `refactor`.

`next` y `show` lo dicen en `tests.required`, con la orden configurada si la
hay (`tests.command`).

### El estado `untested`

Un paso así sólo queda `done` con evidencia de prueba. Sin ella queda
**`untested`**: hecho a falta de su prueba. `next` no lo vuelve a dar (lo que
falta no es trabajo del plan), pero `status`, `brief` y `done` lo listan, y
**`larapack:realign done` falla mientras quede uno**.

| Qué pasa | Cómo queda |
|---|---|
| `check <id>` pasa y nadie registró la prueba | `untested`, con un aviso (`warning` en el JSON) que dice qué hacer. |
| `check <id> --tested="<orden>"` pasa | `done`; la orden queda en el paso (`tested: {via: declared, command, at}`) y en su historia. |
| `check <id>` pasa con la orden de pruebas configurada | `check` la corre: si sale con 0, `done` (`tested.via: config`); si no, el paso no está hecho y `check` da el final de la salida (`tests.output`). |
| `plan` ya no encuentra lo que el paso pedía (`resolved`) | `untested`, no `done`: alguien lo cambió y nadie vio pasar la prueba. |
| Vuelve a salir (`check` o `plan`) | `pending` otra vez (`reopened`); la prueba registrada se pierde. |
| El dueño lo acepta así | `done`, con `untested_accepted: {note, at, via, by}` y la línea en `done` (`accepted_untested`). |

`--tested` lleva **la orden que se corrió y pasó** (`--tested="php artisan
test --filter=ReportTest"`): es la evidencia que lee el dueño. Úsala sólo si
de verdad la corriste y pasó.

### La orden de pruebas del proyecto, en la configuración

Si el proyecto puede correr sus pruebas donde se realinea, regístralas una vez
en `.larapack/realign/config.json` (se versiona con el plan) y `check` las
corre él mismo en cada paso que cambia comportamiento, en la raíz del
proyecto:

```json
{"format_version": 1, "tests": "php artisan test"}
```

Corre la orden entera: el paso no sabe qué pruebas cubren sus archivos.
Acótala tú (`--filter`, una carpeta) si la suite es larga. La orden es del
proyecto, como un script de Composer: la escribe una persona.

### Si no puedes correrlas

1. **Escribe la prueba igualmente** (el paso 1 de cada playbook: lo que
   responde hoy), aunque no puedas correrla, y comítala aparte diciendo que
   no se corrió: `pruebas antes de mover X (SIN CORRER: no hay base de datos)`.
2. Haz el cambio y comítalo solo, con la marca en el mensaje:
   `realign: <id> (SIN PROBAR: <motivo>)`.
3. Corre `larapack:realign check <id>` **sin** `--tested`: comprueba la forma y
   deja el paso `untested`, que es la verdad. No inventes una orden para
   `--tested`, y no marques el paso hecho editando `plan.json` a mano.
4. Si prefieres no hacerlo todavía, aplázalo con su razón
   (`larapack:realign defer <id> --note="sin base de datos para probarlo"`):
   sigue `pending` y `next` pasa al siguiente.
5. Dilo en tu informe: `larapack:realign status` (`untested`) y
   `larapack:realign brief` (la sección «Sin probar») dan la lista con la
   orden de cada uno. Quien tenga el entorno corre las pruebas y, si pasan,
   `check <id> --tested="…"`; si no, `git revert` del commit del paso.

`next` vuelve a dar el mismo paso mientras siga pendiente. Para seguir con
otros, aplaza el paso sin probar con su motivo
(`larapack:realign defer <id> --note="SIN PROBAR: no hay base de datos"`):
sigue `pending`, `next` lo salta, `status` lo lista en «Aplazados» y
`undefer <id>` lo devuelve cuando haya entorno para probarlo. También puedes
trabajar otro ámbito con `--package`, o parar y entregar el informe. No
marques el paso hecho editando `plan.json` a mano.

### Aceptarlo sin probar: lo decide el dueño

```bash
larapack:realign accept-untested <id> --note="se prueba en staging antes de desplegar"
```

Es como aprobar una excepción ([exceptions.md](exceptions.md)): lo confirma
**el dueño**, en su terminal, viendo el paso, por qué cambia comportamiento,
sus archivos y la nota, y escribiendo «acepto sin probar». Sin terminal vale
la misma vía que para aprobar una excepción, y sólo ésa: la corrida de
aprobación lanzada a mano por una persona en GitHub
([exceptions.md](exceptions.md#por-qué-un-agente-no-puede-aprobar)); nombrarse
fuera de ella no basta, y la guardia de los agentes bloquea cualquier orden
con `--approver` o que dé valor a `LARAPACK_APPROVER`. Un agente no lo corre: lo deja en su informe. Lo aceptado
queda en `plan.json` (el diff que se revisa) y `done` lo sigue enseñando.

## Después de los pasos guiados: otra pasada

Copiar lo regenerado encima de un archivo, mover lógica a un hueco o llevar un
controlador a su request cambia lo que el diagnóstico vio. Al terminar los
pasos guiados de un ámbito —y siempre que copies lo regenerado o cambies el
contrato—:

```bash
vendor/bin/builder larapack:realign diagnose
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign apply --deterministic
```

El archivo copiado sale como `adopt`, una request nueva puede pedir
`endpoint-tests`, y un paso que ya no hace falta se cierra solo (`resolved`).
Repítelo hasta que `apply --deterministic` no tenga nada que aplicar, y
vuelve a `next`.

## Riesgos de los pasos guiados

- **Mover código cambia comportamiento.** El caso conocido: con la
  validación en `rules()` y la autorización en `authorize()`, Laravel autoriza
  **antes** de validar, y una petición sin permiso que recibía un 422 recibe
  un 403. Pasó en school-management. Por eso cada playbook pide fijar con una
  prueba lo que responde hoy, antes de tocar nada, y anotar el cambio.
- **Un controlador por paso.** Es la unidad que se lleva de principio a fin
  con sus pruebas, y la que se revierte con `git revert` si sale mal: un commit
  por paso.
- **Lo que el playbook no ve.** La orden de verify acotada no corre las
  pruebas: que verify pase no dice que la acción responda lo mismo. Las
  pruebas del paquete, antes y después, sí. En particular, verify **no**
  comprueba el orden en que la request autoriza y carga el registro: una
  autorización llevada a `authorize()` que antes iba después de un
  `findOrFail` cambia un 404 por un 403 y verify no lo ve.
- **Los nombres de ruta y las URIs** no se cambian en ningún paso guiado: el
  front y los clientes dependen de ellos. Lo que no se puede conservar se queda
  como está o es una decisión.
- **Lo que no cabe** —una pieza que no tiene hueco ni se puede declarar en el
  contrato, una acción que de verdad no puede conservar su código de estado o
  el formato de su respuesta con una request— no se fuerza. Ver
  [Lo que no cabe: escalate o except](#lo-que-no-cabe-escalate-o-except). Un
  protocolo (OAuth2, RFC 7591, un webhook) no es por sí solo una razón: casi
  siempre se mueve conservando la respuesta.

## Lo que no cabe: `escalate` o `except`

Dejar algo fuera de su hueco (o fuera del contrato) lo decide el dueño, nunca
quien sigue el plan. Las dos órdenes no son alternativas, son dos momentos:

| Cuándo | Qué | Orden |
|---|---|---|
| El dueño **no ha dicho** si se queda así (lo normal en un paso guiado). | Se le pasa: el paso guiado se convierte en una decisión con sus opciones (`refactor` y dejarlo como está). | `larapack:realign escalate <id> --note="qué no cabe y por qué"` |
| El dueño **ya lo decidió** (`decide <id> except`, o la opción «dejarlo como está» de cualquier decisión). | Se pide la excepción de cada elemento, con su razón y su huella; cuenta cuando el dueño la aprueba. | `larapack:realign except <id> --option reason="…"` |
| Es un **controlador** que no puede delegar. | No es una excepción del realineamiento: `#[HandRolled('…')]` en la clase y la aprobación del dueño (la opción `hand-rolled` de `escalate`). | `larapack:hand-rolled approve` (el dueño) |

En una decisión, `except` se niega mientras el dueño no la haya tomado con la
opción que la pide ([exceptions.md](exceptions.md#el-ciclo)). Y aplazar
(`defer`) no es ninguna de las dos: deja el paso pendiente para más tarde,
con su razón, sin que nadie decida nada.

## Para quien añade un playbook

1. El archivo `resources/realign/playbooks/<nombre>.md`, con los marcadores
   que necesite (`{{id}}`, `{{scope}}`, `{{package}}`, `{{files}}`,
   `{{findings}}`, `{{risk}}`, `{{check}}`, `{{verify}}`, `{{done_when}}`,
   `{{title}}`, `{{description}}`). Un marcador que no existe es un error al
   rellenarlo.
2. Quién lo usa, en `src/Support/Realign/StepTypes.php`: `playbook` de un tipo
   guiado o de una decisión, o `playbook` de una opción. Un anexo, en
   `Playbooks::annexesFor()`.
3. Su nombre en esta guía: `RealignCatalogTest` lo exige, y exige que todo
   playbook que se nombra exista.
