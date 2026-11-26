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
| `uri` | segmentos kebab o `{parametro}` | `name` en kebab (`importRoster` → `import-roster`; una sigla es una palabra: `importCSV` → `import-csv`) | La URI, relativa al prefijo del modelo, como las CRUD. |
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
| Rutas `routes/api/models/<modelo>.php` | `Route::post('publish', [...])->name('publish')`, detrás de las CRUD, con `->whereNumber('<modelo>_id')` si el identificador va en la URI y `->middleware([...])` si se declaró. Nombre de ruta: `importRoster` → `import.roster`, como `forceDelete` → `force.delete`; `importCSV` → `import.csv`. | No. |
| `Http/Requests/<Modelo>/PublishRequest.php` | `authorize()` pregunta a la política (`can('publish', $course)`; si es público, `Gate::forUser($this->user('sanctum'))`: sin `auth:sanctum` delante, el guard por omisión no ve un token Bearer); `rules()` con `<modelo>_id` si es `record` y el identificador no va en la URI, vacío si no; `handle()` busca el registro y delega en `$course->publishModel($this)`. | Sí: `rules()` y lo que necesite `authorize()`/`handle()`. Un archivo editado no se pisa. |
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
- Dos `name` que dan la misma ruta (`importCsv` e `importCSV` → `import.csv`). En un disco que no distingue mayúsculas darían además la misma request.
- `controllers[].name` igual a un modelo (compartirían archivo de rutas y carpeta de requests), repetido, o terminado en `Controller`.
- `controllers[].name` igual a un modelo **que ya está en el manifiesto**, aunque venga de otro laraimport: escribiría sobre su controlador y sus rutas —con `--force`, sus CRUD desaparecerían— y su anotación diría que no es un modelo. Lo comprueban `validate` e `import` antes de escribir nada. Al revés no: declarar como modelo lo que era un controlador es la forma de convertirlo.
- Una `uri` que empieza por un parámetro sin restricción (`{slug}`, o cualquiera en un `controllers[]`) y se registra antes que otro endpoint del mismo verbo que casa con ella: esas peticiones llegarían al primero. El `{<modelo>_id}` de un endpoint `record` no cuenta, porque la ruta lo restringe con `whereNumber`.
- **Aviso:** esa misma `uri` aunque no tape a nadie todavía: taparía al siguiente endpoint del mismo verbo que se declare.
- **Aviso:** endpoint `public` sin `throttle:` en su `middleware`.

---

## 5. Qué comprueba `larapack:verify`

`verify` sigue sin leer el laraimport: la forma declarada se guarda en el manifiesto (`models.<Modelo>.declaration.endpoints`, y `controller: true` para los de `controllers[]`).

| Código | Detecta |
|---|---|
| `endpoint-missing` | Un endpoint declarado al que le falta su método del controlador, su ruta, su request, su método de la política o su operación en el trait `Operations`. El mensaje dice cuál y qué escribir o regenerar; si el controlador o las rutas están editados a mano, dice que `--force` los conserva y que regenerar no basta. |
| `route-not-declared` | (ampliado) Un método público del controlador (un `__invoke`, un constructor que inyecta un servicio), una ruta con nombre, una ruta sin nombre o un `*Request.php` de la carpeta del modelo que no es ni una acción CRUD declarada ni un endpoint declarado. |

Los dos entran en `Verifier::CHECKS`, así que `--check endpoint-missing` y `--check route-not-declared` funcionan.

**Que el controlador haga algo más que `return $request->handle();` no es de este spec.** Lo comprueba `controller-shape` (`ControllerShape`), en todos los controladores del proyecto, generados o no, estén o no en el manifiesto. Una primera versión de este spec añadía `controller-logic` sólo para los generados; con las dos, un controlador generado con lógica daba dos errores por el mismo problema y con reglas distintas (una sólo aceptaba `handle()` y `handle($this)`; la otra, `handle(...)` con cualquier argumento). Se quitó `controller-logic`: un solo dueño, una sola regla.

`route-not-declared` distingue dos orígenes del mismo rastro, porque el manifiesto lo sabe:

- **Escrito a mano.** El archivo no lo escribió el generador, o lo editó alguien después: «Alguien lo escribió a mano: declara el endpoint en el laraimport…».
- **Declarado antes y retirado del laraimport.** El generador lo escribió y nadie lo ha tocado (o es una request, que se rellena a mano por diseño): «Lo generó LaraPack para un endpoint que ya no está declarado: si lo retiraste del laraimport, regenera con `--force` para quitarlo del controlador y de las rutas, y borra su request y su test». Lo mismo con una acción CRUD que se quita de `routes`.

Culpar de lo segundo a quien no tocó nada sólo enseña a desconfiar de `verify`.

**Es un cambio de comportamiento:** un proyecto que añadió un método o una ruta a un controlador generado pasa de `verify` en verde a `verify` en rojo, y lo mismo con la lógica dentro de uno (por `controller-shape`). Es lo que se quiere —es exactamente la deriva—, y se publica como versión mayor.

---

## 6. Criterios de aceptación

1. `larapack:schema` incluye `endpoints` y `controllers` con su documentación.
2. Un laraimport sin las dos claves genera exactamente lo mismo que antes (foto por hash).
3. Un endpoint `publish` genera el método del controlador (sólo `return $request->handle();`), `PublishRequest`, la ruta `publish`, `publish()` en la política negando, `publishModel()` en `Operations` y su test.
4. `larapack:import --force` dos veces seguidas no cambia nada; con `PublishRequest` editado, lo conserva.
5. Un `controllers[]` genera controlador, rutas, requests y tests, sin modelo, política ni migración.
6. `validate` rechaza: nombre CRUD, nombre reservado, `uri` con `..`, método fuera del enum, `ability` CRUD que el modelo no tiene.
7. `verify` falla con `endpoint-missing` si se borra la ruta o la operación de un endpoint, con `route-not-declared` si se añade un método al controlador, y con `controller-shape` —una sola vez— si se mete lógica en uno.
8. La prueba de punta a punta instala un paquete con endpoints y controladores, pasa sus tests generados, Pint y Larastan.

---

## 7. Lo que este spec NO pide

- **Interfaz Vue/React** para los endpoints: ni botón, ni acción por fila del datatable, ni llamada en el `store`. Queda pendiente; el contrato del módulo JS (`API_ROUTE_PREFIX + '<ruta>'`) ya permite llamarlos a mano con el nombre de ruta.
- **Reglas declaradas** (`rules` dentro del endpoint, como `requests[]` para Create/Update). `rules()` es un hueco.
- **Eventos** por endpoint (las CRUD disparan `CreateEvent`, …). Se emiten desde la operación si hacen falta.
- **Respuesta tipada**: `handle()` devuelve lo que devuelva la operación.
- **Detectar controladores escritos a mano fuera del manifiesto.** Lo hace `controller-shape`; este spec hace que ya no haga falta escribirlos.
- **Retirar un `controllers[]` entero.** Quitarlo del laraimport no borra sus archivos ni su entrada del manifiesto, y `verify` no lo ve: la anotación vieja sigue cuadrando con los archivos. Se borran a mano, y con ellos la entrada de `.larapack/manifest.json`: `larapack:remove-full-model` no la olvida, tampoco la de un modelo.

---

## 8. Resolución

- **La clave es `endpoints`**, no `actions` (sección 3.2).
- **Los endpoints sin modelo van en `controllers[]`** (sección 3.1), y su `ability` es de Gate.
- **Todo nace cerrado.** La política devuelve `false`; un controlador sin `ability` devuelve `false` en `authorize()`; un endpoint `public` sigue preguntando a la política, ahora con `?User`. Con un **invitado**, Laravel no llama a `before()` (su primer parámetro no admite `null`), así que `before()` no abre nada a quien no tiene sesión. Con **sesión** sí pasa por `before()`, como en las CRUD: un administrador autenticado entra también en los endpoints públicos, salvo lo que `before()` excluya. Una versión anterior de este spec decía que `?User` impedía que el administrador pasara por `before()`; sólo es así con invitados.
- **Un endpoint público autoriza con el usuario de `sanctum`.** No pasa por `auth:sanctum`, así que `$this->user()` usaría el guard por omisión (normalmente `web`), que no ve un token Bearer: un cliente con token llegaría a la política como invitado. La request generada pregunta a `$this->user('sanctum')`, que mira la sesión y el token. La prueba de punta a punta lo llama con un token de verdad y sin `actingAs`, que fijaría el guard y escondería justo esto.
- **La operación se llama `<name>Model`**, como `createModel` y `updateModel`. Con el nombre pelado, un endpoint `refresh`, `touch` o `replicate` habría sobrescrito un método de Eloquent.
- **El trait `Operations` sólo recibe la operación al crearse.** Es código del proyecto: añadir métodos a uno existente sería escribir en un archivo que no es del generador. Si falta, `verify` lo dice con `endpoint-missing` y el nombre exacto del método.
- **Tampoco se añade a una política ya editada**, por la misma regla de `--force`. `verify` lo dice igual.
- **Las rutas de `controllers[]` van en `routes/api/models/`**: es el directorio que el `RouteServiceProvider` ya recorre, así que no hay que regenerar el proveedor de ningún proyecto existente. Por eso un controlador no puede llamarse como un modelo.
- **El identificador en la URI lo exige la ruta, no `rules()`.** Las reglas validan el cuerpo y la query, no los parámetros de ruta: `'course_id' => 'required'` con `{course_id}/publish` rechazaría todas las peticiones. Se genera `->whereNumber('course_id')` y la request lo lee igual (`$this->course_id` cae en el parámetro de ruta).
- **El código de los endpoints se inserta después de sustituir los tokens.** Un endpoint `renameModelName` contiene el token `ModelName`; si pasara por `strtr()` saldría roto.
- **`larapack:full-model` suelto conserva los endpoints del manifiesto.** No tiene opción para declararlos, y `Manifest::declare` reemplaza la declaración entera: en la primera versión, suelto y con `--force`, anotaba el modelo sin endpoints y regeneraba el controlador y las rutas sin sus métodos, que pasaban a dar 404 sin aviso. Ahora los toma del manifiesto, los genera y los vuelve a anotar, también con `--only`, `--except` o `--immutable`; si una opción quita la acción CRUD por cuya habilidad pregunta un endpoint, falla antes de escribir nada. Sobre un `controllers[]` se niega: le crearía modelo, tabla y política. Declarar o cambiar endpoints sigue siendo cosa de `larapack:import`.
- **Las piezas de un endpoint no cuentan para `inconsistent-entity`**, como las metas: cada modelo declara los suyos. Un `controllers[]` sólo se compara con otros controladores.
