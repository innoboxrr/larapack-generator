# SPEC 02 — Acciones declaradas: `endpoints` y `controllers`

**Estado:** implementado, con las decisiones que recoge la [sección 8](#8-resolución).
**Origen:** la deriva medida en `teoremagroup/school-management`. Los agentes respetaron `return $request->handle();` en los controladores que LaraPack genera, y lo perdieron en cuanto tuvieron que escribir uno que LaraPack no sabía generar.
**Depende de:** SPEC 01 (la forma declarada vive en el manifiesto; `verify` la lee de ahí).
**Nota de numeración:** SPEC 01 reservaba «SPEC 02» para el manifiesto de contrato. Ese spec no llegó a escribirse; éste toma el número. El manifiesto de contrato, si se escribe, será el siguiente.

---

## 1. El problema

LaraPack genera doce acciones por modelo y nada más. Todo endpoint que no sea una de ellas —publicar un curso, reenviar una invitación, el escritorio de un módulo, un asistente de varios pasos, el canje de un token, un webhook— hoy **se escribe a mano**. Y lo que se escribe a mano no tiene plantilla que seguir.

Lo que pasó en un sistema real de 65 paquetes:

- Los controladores **generados** quedaron todos en `return $request->handle();`. La regla funciona cuando la plantilla la trae.
- Los controladores **escritos a mano** tienen validación (`$request->validate(...)`), autorización (`abort_unless(...)`), consultas Eloquent y armado de respuesta dentro del método. `InstalledModulesController::__invoke`, `TokenController::__invoke` de OneRoster, y una veintena de paquetes con un trait `Http/Controllers/Concerns/AuthorizesInScope.php` —autorización viviendo en la capa de controladores—.
- El `CLAUDE.md` del proyecto lo prohíbe en negrita («el controlador no lleva lógica, tampoco el que se escribe a mano»). No bastó: **una regla que sólo está escrita se cumple mientras alguien la recuerda**.
- `larapack:verify` no lo vio nunca, porque sólo mira los archivos del manifiesto y lo escrito a mano no está en él.

La causa raíz no es el agente: es que **para un endpoint que no es CRUD no había nada que declarar**, así que no había nada que generar ni nada contra lo que verificar. Cada vez que el contrato no tiene forma para algo, el código se sale del contrato.

---

## 2. Qué se añade al esquema

### 2.1 `endpoints` — a nivel de modelo

```json
{
    "name": "Course",
    "props": [ ... ],
    "endpoints": [
        { "name": "publish", "method": "post" },
        { "name": "archive", "method": "post", "ability": "update" },
        { "name": "importRoster", "method": "post", "target": "collection" },
        { "name": "certificate", "method": "get", "uri": "{course_id}/certificate", "public": true,
          "middleware": ["throttle:30,1"] }
    ]
}
```

| Clave | Tipo | Por omisión | Qué decide |
|---|---|---|---|
| `name` | camelCase | — | El método del controlador, `<Name>Request`, el nombre de la ruta y la operación `<name>Model` del modelo. |
| `method` | `get` `post` `put` `patch` `delete` | — | El verbo HTTP. |
| `uri` | segmentos kebab o `{parametro}` | `name` en kebab (`importRoster` → `import-roster`) | La URI, relativa al prefijo del modelo, como las CRUD. |
| `target` | `record` `collection` | `record` | `record` actúa sobre un registro: la request lo busca por `<modelo>_id` y la política lo recibe. `collection` actúa sobre el modelo, como `create` o `index`. |
| `ability` | camelCase | `name` | La habilidad que se pregunta a la política del modelo. |
| `public` | boolean | `false` | Sin sesión: el controlador quita `auth:sanctum` sólo para este método. La política se sigue preguntando, con `?User`. |
| `middleware` | lista | `[]` | Middleware de la ruta, además del grupo `api`. |

### 2.2 `controllers` — a nivel raíz

Para lo que no tiene modelo propio: un escritorio, un asistente, el canje de un token, un webhook.

```json
"controllers": [
    { "name": "Desk", "endpoints": [ { "name": "overview", "method": "get" } ] },
    { "name": "Oauth", "endpoints": [
        { "name": "token", "method": "post", "public": true, "middleware": ["throttle:6,1"] }
    ] }
]
```

Las mismas claves que un endpoint de modelo salvo `target` (no hay registro) y con otro significado para `ability`: sin modelo no hay política que Laravel resuelva sola, así que `ability` es **una habilidad de Gate** (`Gate::define`) que define la aplicación o el paquete. Sin `ability`, `authorize()` nace devolviendo `false` y se rellena a mano —es un hueco—.

Fragmentos para `laraimport.schema.json`: ver `$defs/endpoint`, `$defs/controller` y `$defs/controllerEndpoint` en el propio esquema.

Ausencia de ambas claves = exactamente lo que se generaba antes. **Las claves son retrocompatibles**, y lo comprueba la foto por hash de `GeneratedOutputSnapshotTest`.

---

## 3. Qué se genera

Todo determinista, a partir de la declaración. Lo que se escribe a mano va **sólo en huecos**:

| Pieza | Qué sale | ¿Hueco? |
|---|---|---|
| Controlador del modelo | `public function publish(PublishRequest $request) { return $request->handle(); }` y su `use`. `except:` en `auth:sanctum` si es público. | No. Se regenera con `--force`. |
| Rutas `routes/api/models/<modelo>.php` | `Route::post('publish', [...])->name('publish')`, detrás de las CRUD, con `->whereNumber('<modelo>_id')` si el identificador va en la URI y `->middleware([...])` si se declaró. Nombre de ruta: `importRoster` → `import.roster`, como `forceDelete` → `force.delete`. | No. |
| `Http/Requests/<Modelo>/PublishRequest.php` | `authorize()` pregunta a la política (`can('publish', $course)`; `Gate::forUser()` si es público); `rules()` con `<modelo>_id` si es `record` y el identificador no va en la URI, vacío si no; `handle()` busca el registro y delega en `$course->publishModel($this)`. | Sí: `rules()` y lo que necesite `authorize()`/`handle()`. Un archivo editado no se pisa. |
| Política del modelo | `public function publish(User $user, Course $course): Response\|bool { return false; }`. `collection` no recibe el modelo; `public` recibe `?User`. Una `ability` CRUD (`update`, `view`, …) no genera método: reutiliza el que hay. | Sí. **Nace denegando.** |
| Trait `Operations` del modelo | `public function publishModel($request)` que lanza `LogicException` hasta que se escriba. Sólo al crear el trait: un trait existente no se toca (ver `verify`). | Sí. Aquí va la lógica. |
| `tests/Feature/Endpoints/<Modelo>/PublishTest.php` | Un invitado recibe 401; si es público, la política lo cierra y recibe 403. | Sí. |

Un `controllers[]` genera lo mismo salvo política y operación: su controlador, su archivo de rutas en `routes/api/models/<nombre>.php` (el mismo directorio que ya carga el `RouteServiceProvider`), sus requests y sus tests. `handle()` nace como `abort(501)` con el comentario de que la lógica va en una clase de soporte, no en la request ni en el controlador.

### 3.1 Por qué `controllers` y no «un modelo sin tabla»

Se consideró declarar el escritorio como un modelo con `"table": false`. Se descartó:

- Un modelo arrastra veinticinco generadores (migración, factory, resource, filtros, export, observer, eventos, vistas). Sin tabla habría que apagar cada uno, y cada apagado es un sitio más donde la cascada de SPEC 01 se puede romper.
- Un modelo sin tabla en `models[]` es mentira en el sitio que todos leen como «las tablas del paquete».
- `controllers` reutiliza exactamente tres generadores (controlador, rutas, endpoint) y ninguna condición nueva en sus stubs.

### 3.2 Por qué `endpoints` y no `actions`

En el documento normalizado y en el manifiesto, `actions` ya es la lista de acciones CRUD que quedan tras `routes` e `immutable` (`declaration.actions` en `.larapack/manifest.json`). Llamar igual a la clave nueva habría puesto dos significados en la misma palabra, en el mismo archivo que leen los agentes. `routes` dice **qué CRUD** hay; `endpoints` dice **qué más** hay.

---

## 4. Qué valida `larapack:validate`

Esquema:

- `name` en camelCase; `method` en el enum; `uri` sólo segmentos kebab o `{parametro}`, sin `/` inicial ni `..`; `middleware` sin espacios.

Semántica (errores salvo que se diga):

- `name` repetido en el mismo modelo o controlador.
- `name` igual a una de las doce acciones CRUD (`update`, `forceDelete`, `bulkDelete`, …): usa `routes`.
- `name` que choca con PHP o con Laravel: palabras reservadas (no pueden ser clase `<Name>Request`), `form` (`FormRequest`), y los métodos del controlador base (`middleware`, `authorize`, `validate`, `callAction`, …).
- `method` + `uri` repetidos, o `uri` igual a la de una acción CRUD (`update`, `force-delete`, …).
- `target: record` con parámetros en la `uri` que no incluyen `{<modelo>_id}`: la request lee el registro de ahí.
- `ability` CRUD cuya acción el modelo no tiene (`ability: update` con `routes.only: [index]`), o que no casa con el `target` (`update` recibe un registro; `create`, no), o en un endpoint `public` (las habilidades CRUD no admiten invitados).
- Dos endpoints que comparten una `ability` propia con distinto `target` o distinto `public`: la firma del método de la política sería distinta.
- `controllers[].name` igual a un modelo (compartirían archivo de rutas y carpeta de requests), repetido, o terminado en `Controller`.
- **Aviso:** endpoint `public` sin `throttle:` en su `middleware`.

---

## 5. Qué comprueba `larapack:verify`

`verify` sigue sin leer el laraimport: la forma declarada se guarda en el manifiesto (`models.<Modelo>.declaration.endpoints`, y `controller: true` para los de `controllers[]`).

| Código | Detecta |
|---|---|
| `endpoint-missing` | Un endpoint declarado al que le falta su método del controlador, su ruta, su request, su método de la política o su operación en el trait `Operations`. El mensaje dice cuál y qué escribir o regenerar. |
| `route-not-declared` | (ampliado) Un método público del controlador (un `__invoke`, un constructor que inyecta un servicio), una ruta con nombre, una ruta sin nombre o un `*Request.php` de la carpeta del modelo que no es ni una acción CRUD declarada ni un endpoint declarado. |
| `controller-logic` | Un método del controlador generado —de un endpoint o de una acción CRUD— cuyo cuerpo no es `return $request->handle();`. Los cuerpos se leen con el tokenizador, no con una expresión regular. |

`controller-logic` es la comprobación que faltaba. Antes de esto, editar el controlador generado sólo daba un `customised` informativo. **Es un cambio de comportamiento:** un proyecto que ya metió lógica en un controlador generado, o le añadió un método, pasa de `verify` en verde a `verify` en rojo. Es lo que se quiere —es exactamente la deriva—, pero conviene publicarlo como tal.

Lo que sigue sin verse es un controlador escrito a mano que **no** está en el manifiesto. Con `controllers[]` ya no hay motivo para escribirlo; detectarlo es otra comprobación (sección 7).

---

## 6. Criterios de aceptación

1. `larapack:schema` incluye `endpoints` y `controllers` con su documentación.
2. Un laraimport sin las dos claves genera exactamente lo mismo que antes (foto por hash).
3. Un endpoint `publish` genera el método del controlador (sólo `return $request->handle();`), `PublishRequest`, la ruta `publish`, `publish()` en la política negando, `publishModel()` en `Operations` y su test.
4. `larapack:import --force` dos veces seguidas no cambia nada; con `PublishRequest` editado, lo conserva.
5. Un `controllers[]` genera controlador, rutas, requests y tests, sin modelo, política ni migración.
6. `validate` rechaza: nombre CRUD, nombre reservado, `uri` con `..`, método fuera del enum, `ability` CRUD que el modelo no tiene.
7. `verify` falla con `endpoint-missing` si se borra la ruta o la operación de un endpoint, con `route-not-declared` si se añade un método al controlador, y con `controller-logic` si se mete lógica en uno.
8. La prueba de punta a punta instala un paquete con endpoints y controladores, pasa sus tests generados, Pint y Larastan.

---

## 7. Lo que este spec NO pide

- **Interfaz Vue/React** para los endpoints: ni botón, ni acción por fila del datatable, ni llamada en el `store`. Queda pendiente; el contrato del módulo JS (`API_ROUTE_PREFIX + '<ruta>'`) ya permite llamarlos a mano con el nombre de ruta.
- **Reglas declaradas** (`rules` dentro del endpoint, como `requests[]` para Create/Update). `rules()` es un hueco.
- **Eventos** por endpoint (las CRUD disparan `CreateEvent`, …). Se emiten desde la operación si hacen falta.
- **Respuesta tipada**: `handle()` devuelve lo que devuelva la operación.
- **Detectar controladores escritos a mano fuera del manifiesto.** Es otra comprobación; ésta hace que ya no haga falta escribirlos.

---

## 8. Resolución

- **La clave es `endpoints`**, no `actions` (sección 3.2).
- **Los endpoints sin modelo van en `controllers[]`** (sección 3.1), y su `ability` es de Gate.
- **Todo nace cerrado.** La política devuelve `false`; un controlador sin `ability` devuelve `false` en `authorize()`; un endpoint `public` sigue preguntando a la política, ahora con `?User`. El `before()` del administrador no aplica a invitados (Laravel no lo llama si su primer parámetro no admite `null`), así que un endpoint público no queda abierto por accidente.
- **La operación se llama `<name>Model`**, como `createModel` y `updateModel`. Con el nombre pelado, un endpoint `refresh`, `touch` o `replicate` habría sobrescrito un método de Eloquent.
- **El trait `Operations` sólo recibe la operación al crearse.** Es código del proyecto: añadir métodos a uno existente sería escribir en un archivo que no es del generador. Si falta, `verify` lo dice con `endpoint-missing` y el nombre exacto del método.
- **Tampoco se añade a una política ya editada**, por la misma regla de `--force`. `verify` lo dice igual.
- **Las rutas de `controllers[]` van en `routes/api/models/`**: es el directorio que el `RouteServiceProvider` ya recorre, así que no hay que regenerar el proveedor de ningún proyecto existente. Por eso un controlador no puede llamarse como un modelo.
- **El identificador en la URI lo exige la ruta, no `rules()`.** Las reglas validan el cuerpo y la query, no los parámetros de ruta: `'course_id' => 'required'` con `{course_id}/publish` rechazaría todas las peticiones. Se genera `->whereNumber('course_id')` y la request lo lee igual (`$this->course_id` cae en el parámetro de ruta).
- **El código de los endpoints se inserta después de sustituir los tokens.** Un endpoint `renameModelName` contiene el token `ModelName`; si pasara por `strtr()` saldría roto.
- **`larapack:full-model` suelto no conoce los endpoints**, igual que no conoce las columnas: regenerar un modelo con él deja el controlador sin los métodos de sus endpoints y `verify` lo marca con `endpoint-missing`. Los endpoints se generan con `larapack:import`.
- **Las piezas de un endpoint no cuentan para `inconsistent-entity`**, como las metas: cada modelo declara los suyos. Un `controllers[]` sólo se compara con otros controladores.
