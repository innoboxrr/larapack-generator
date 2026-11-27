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
- [Riesgos de los pasos guiados](#riesgos-de-los-pasos-guiados)
- [Para quien añade un playbook](#para-quien-añade-un-playbook)

## Cómo se usa

```bash
vendor/bin/builder larapack:realign next --format=json   # how.instructions es el playbook rellenado
# … seguirlo al pie de la letra …
vendor/bin/builder larapack:realign check <id>
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
| `controller-to-request` | `controller-shape` | Llevar la lógica de cada acción a su FormRequest: `authorize()`, `rules()`, `handle()`, con las pruebas antes y después y el **403 antes que 422** advertido. |
| `edited-outside` | `edited-outside` | Devolver a sus huecos (o al contrato) lo editado fuera de ellos, por familia de plantilla. |
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
  pruebas del paquete, antes y después, sí.
- **Los nombres de ruta y las URIs** no se cambian en ningún paso guiado: el
  front y los clientes dependen de ellos. Lo que no se puede conservar se queda
  como está o es una decisión.
- **Lo que no cabe** —una acción que de verdad no puede delegar— no se fuerza:
  se dice en el informe del paso y lo decide el dueño (`#[HandRolled]` o una
  excepción, ver [exceptions.md](exceptions.md)).

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
