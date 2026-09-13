---
name: larapack
description: Genera y mantiene la arquitectura de una API Laravel con UI en Vue o React a partir de un laraimport.json declarativo. Úsalo siempre que haya que crear, ampliar o revisar un modelo, un endpoint, un formulario o una vista en un paquete o aplicación que dependa de innoboxrr/larapack-generator, y antes de escribir a mano cualquier controlador, request, policy, migración o módulo de interfaz.
---

# LaraPack

LaraPack no es un andamiador que arranca un proyecto y desaparece. Es la
**autoridad arquitectónica**: define cómo se estructura una aplicación Laravel
del ecosistema innoboxrr y comprueba que siga siendo así.

El problema que resuelve no es escribir código rápido — eso ya lo haces tú. Es
la **deriva**: cada modelo escrito a mano sale un poco distinto del anterior, y
a los treinta modelos la aplicación ya no tiene una arquitectura, tiene treinta.
Un generador determinista invierte esa dinámica: tú escribes un JSON pequeño y
verificable, y las ~58 piezas de cada modelo salen idénticas siempre.

## La regla

**La arquitectura se declara, no se escribe.**

Si vas a crear un modelo, un endpoint, una request, una policy, una migración,
un recurso, un formulario o una vista: **no lo escribas**. Decláralo en
`laraimport.json` y genera. Escribir a mano lo que el generador produce es
exactamente la deriva que este paquete existe para impedir.

Lo que sí escribes a mano es la **lógica de negocio**, y solo dentro de los
huecos que el generador deja para eso. Están listados más abajo.

## Flujo

Siempre en este orden. Cada paso tiene un comando y un código de salida, así
que puedes comprobar tu propio trabajo sin preguntar.

```
0. larapack:new vendor/paquete        # sólo si el paquete aún no existe
1. larapack:schema                    # descubre el contrato, no lo adivines
2. escribe/edita laraimport.json
3. larapack:validate                  # falla → corrige el JSON, no el código
4. larapack:import --dry-run          # mira qué va a tocar
5. larapack:import [--vue] [--react]  # genera
6. rellena los huecos                 # aquí sí escribes código
7. larapack:verify                    # falla → algo se salió del contrato
```

Los comandos existen de dos formas:

```
php artisan larapack:<comando>                    # dentro de una app Laravel
php vendor/bin/builder larapack:<comando>         # también fuera de Laravel
```

Todos aceptan `--format=json` (parséalo, no raspes el texto), `--root=<ruta>`
para decir sobre qué proyecto trabajar, y los generadores aceptan además
`--force` y `--dry-run`.

## Paso 1: el contrato

`larapack:schema` imprime el JSON Schema completo. **Léelo antes de escribir el
archivo.** No deduzcas el formato de un ejemplo: los ejemplos envejecen, el
esquema no.

Lo único obligatorio es esto:

```json
{
    "models": [
        {
            "name": "Category",
            "props": [
                { "name": "name", "type": "string" }
            ]
        }
    ]
}
```

Todo lo demás tiene valor por defecto. **Declara solo lo que decidas de
verdad**; rellenar las veinte claves de cada propiedad con su valor por defecto
hace el archivo ilegible y no cambia nada.

Lo que conviene saber sin tener que leer el esquema entero:

| Clave | Para qué |
|---|---|
| `props[].type` | Tipo de columna de la migración. Enum cerrado. |
| `props[].constraint` | Tabla a la que apunta un `foreignId`. Obligatoria si el tipo lo es. |
| `props[].cast` | Va al `casts()` del modelo. |
| `props[].fillable` / `creatable` / `updatable` | Arrays del modelo. Por defecto `true`. |
| `props[].exports_cols` | Columna de la exportación a Excel. |
| `props[].form` + `form_component` | Pinta un input en `CreateForm`/`EditForm`. `form: true` obliga a declarar el componente. |
| `props[].form_submit` | El campo viaja en el submit. Un `user_id` con `form: false` y `form_submit: true` se convierte en prop del componente. |
| `props[].datatable` | Columna de la tabla y candidata al orden por defecto. |
| `props[].enum` | Opciones de un `SelectInputComponent`. En otro componente se ignora y se avisa. |
| `metas` | Campos flexibles en `<modelo>_metas`, con relación `metas()`, copia en `payload` y guardado al crear y actualizar. Ver «Metas y payload». |
| `load_relations` | Métodos del trait `Relations` y whitelist `$loadable_relations`. |
| `load_counts` | Whitelist `$loadable_counts`. |
| `editable_metas` | Metas que escribe el formulario, con el nombre ya aplanado (`seo_title`). |
| `protected_metas` | Metas que sólo escribe tu código; el formulario no las toca. |
| `display` | La columna que nombra a un registro en la ficha, las migas y la pestaña. Decláralo sólo si no es `name`, `title` ni la primera columna de texto; nunca una `secret`. |
| `requests[]` | Reglas de `CreateRequest` y `UpdateRequest`. Solo esos dos. |
| `pivots[]` | Migraciones de tablas pivote, sin modelo. |
| `routes` | Qué acciones genera el modelo: `only` o `except`. Sin la clave, las diez. |
| `immutable` | La fila no cambia una vez creada: sin `update`, `delete`, `restore` ni `forceDelete`, y el modelo lanza si algo lo intenta. Crear sigue permitido. |
| `props[].secret` | Nunca sale por la API: va a `$hidden`, fuera de la exportación y de la tabla. Se escribe, no se lee. |

Tres cosas se resuelven solas — **no intentes arreglarlas a mano**:

- **El orden de los modelos da igual.** Se ordenan topológicamente por sus
  claves foráneas antes de generar las migraciones.
- **El namespace de las relaciones.** Un modelo declarado en el mismo archivo
  vive en este paquete y el `use` apunta ahí; uno que no aparece resuelve
  contra `App\Models`. Solo escribe `namespace` si es ninguna de las dos cosas.
- **Las reglas admiten array**, que es la forma de Laravel para todo lo que
  lleve `|` dentro, como un `regex:`.

**No todas las tablas se administran desde un formulario.** Una bitácora sólo se
agrega, un catálogo sólo se lee, una concesión se otorga y se revoca pero no se
edita. Decláralo con `routes` e `immutable` en lugar de generar las diez
acciones y borrar lo que sobra: lo borrado a mano queda marcado como editado
para siempre, `larapack:verify` lo detecta como deriva, y el contrato deja de
describir el código.

```json
{ "name": "AuditEvent", "immutable": true, "routes": { "only": ["policies", "index", "show"] }, "props": [ ... ] }
```

Quitar una acción quita todo lo que cuelga de ella, también en la interfaz. Las
vistas necesitan `index` y `policies`: sin ellas sólo se generan el contrato y
el store.

**Metas y payload: campos flexibles sin columna.** Con `metas: true` el modelo
guarda filas `key`/`value` en `<modelo>_metas` y una copia de todas en la
columna JSON `payload`. Lo que se genera ya funciona: relación `metas()`,
guardado al crear y actualizar, y `buildPayload()`/`updatePayload()`. Reglas:

- **Columna o meta.** Lo que se filtra, se ordena, es clave foránea o necesita
  índice es una columna. Lo opcional, lo que cambia de forma o lo que son muchos
  campos, una meta. **No crees una columna JSON a mano** para datos flexibles:
  para eso están las metas y `payload`.
- **`editable_metas` es lo que escribe el formulario**, y los grupos anidados
  llegan aplanados con guion bajo: si el formulario manda `seo.og.image`, declara
  `seo_og_image`.
- **`protected_metas` es lo que sólo escribe tu código** —contadores, fechas de
  un proceso—. Se escriben con `setMeta()`/`setMetas()`, y después
  `updatePayload()`: esos métodos no refrescan la copia.
- **Un valor vacío borra la meta.** Para no cambiarla, no mandes la clave.
- **Lee con `getPayload('clave')`**, no con `meta()` en un bucle: `meta()` hace
  una consulta por llamada y devuelve las listas como texto JSON.
- **La forma de `payload` se decide en `buildPayload()`** (Operations). Nunca
  declares `payload` como `creatable` ni `updatable`: es una copia derivada y el
  generador lo impide igualmente.

`assignments` y `filters` están marcadas obsoletas en el esquema. No las uses.

## Paso 3: validar

```
larapack:validate --format=json
```

Sale con código distinto de cero si el archivo no vale. Comprueba dos cosas
distintas y las dos importan:

- **Forma**, contra el esquema.
- **Coherencia**, mirando el documento entero: modelos o columnas repetidos,
  ciclos de claves foráneas (que no tienen orden de migración posible), claves
  foráneas a sí mismo no anulables, reglas sobre campos que no son columnas,
  relaciones que no resuelven, `UpdateRequest` sin la regla del identificador
  del que dependen su `authorize()` y su `handle()`, `only` junto a `except`,
  un modelo `immutable` que pide una escritura, un campo de formulario sin
  `create` ni `update` donde vivir, un `secret` que pide salir en la tabla o
  en la exportación, metas declaradas sin `metas: true`, una meta editable y
  protegida a la vez, y un `payload` que pide escribirse desde la petición.

Si vas a generar la interfaz, añade `--vue` o `--react`: también avisa de lo que
las vistas necesitan y el modelo no declara.

**Un error de validación se corrige en el JSON.** Nunca generando igualmente y
parcheando el resultado.

## Paso 5: qué se genera

Un modelo produce ~58 archivos. Este es el mapa; los que te interesan van
marcados.

```
src/Models/<Model>.php                             fillable, casts, whitelists
src/Models/<Model>Meta.php                         solo si metas: true
src/Models/Traits/Relations/<Model>Relations.php   ← HUECO
src/Models/Traits/Operations/<Model>Operations.php ← HUECO
src/Models/Traits/Storage/<Model>Storage.php       ← HUECO
src/Models/Traits/Mutators/<Model>Mutators.php     ← HUECO
src/Models/Traits/Assignments/<Model>Assignment.php
src/Models/Filters/<Model>/ManagedFilter.php       ← HUECO (visibilidad)
src/Models/Filters/<Model>/{Id,Creation,Updated,EagerLoading}Filter.php

src/Http/Controllers/<Model>Controller.php         delega en las requests
src/Http/Requests/<Model>/*.php                    10 requests; rules() ← HUECO
src/Http/Resources/Models/<Model>Resource.php      ← HUECO (forma de la respuesta)
src/Http/Events/<Model>/Events/*.php               6 eventos
src/Http/Events/<Model>/Listeners/*/*.php          ← HUECO (efectos secundarios)

src/Policies/<Model>Policy.php                     ← HUECO (autorización)
src/Observers/<Model>Observer.php                  ← HUECO
src/Exports/<Plural>Exports.php
src/Notifications/<Model>/ExportNotification.php

routes/api/models/<kebab>.php                      10 rutas
database/migrations/*_create_<plural>_table.php
database/migrations/*_create_<model>_metas_table.php solo si metas: true
database/factories/<Model>Factory.php              ← HUECO (datos de prueba)
tests/Feature/Models/<Model>EndpointsTest.php      ← HUECO

resources/<ui>/src/models/<kebab>/index.js         contrato del modelo
resources/<ui>/src/models/<kebab>/store/index.js   Pinia (Vue) / Zustand (React)
resources/<ui>/src/models/<kebab>/routes/index.js
resources/<ui>/src/models/<kebab>/forms/*          Create, Edit, Filter
resources/<ui>/src/models/<kebab>/views/*          Admin, Create, Edit, Show
resources/<ui>/src/models/<kebab>/widgets/*        DataTable, ModelCard, ModelProfile
```

La UI solo se genera si la pides: `--vue`, `--react`, o las dos. No son
excluyentes.

**Un paquete nuevo se crea, no se copia de otro.** `larapack:new vendor/paquete`
deja el `composer.json` con las versiones de la línea base, los proveedores,
la configuración, `phpunit.xml.dist` con un primer test, los workflows de tests
y publicación, y esta guía. Pasa `larapack:audit` desde el primer commit. No
trabaja sobre un directorio que ya tenga `composer.json`, y `--dry-run` dice lo
que crearía.

**Los proveedores no los genera el importador.** En un paquete creado con
`larapack:new` ya están; en uno que no, una vez:

```
larapack:providers      # App, Auth, Event y Route service providers
larapack:config         # archivo de configuración
```

`larapack:providers` los declara en `extra.laravel.providers` del
`composer.json`, así que la aplicación que instala el paquete lo arranca sola:
migraciones, vistas, configuración, rutas y eventos.

### Lo que el paquete espera de la aplicación

El código generado no conoce la aplicación que lo instala, pero sí da por hecho
estas cosas de ella. Si falta alguna, no es un defecto del paquete:

| Qué | Por qué |
|---|---|
| `laravel/sanctum` | Las rutas usan `auth:sanctum`. |
| Usuario `Notifiable` | La exportación avisa al usuario por notificación. El de Laravel ya lo es. |
| `isAdmin()` en el usuario, opcional | `before()` de cada Policy deja pasar al administrador. Sin el método nadie lo es y deciden los métodos de la Policy. |
| `$middleware->statefulApi()` en `bootstrap/app.php` | El administrador llama a la API con la sesión. Sin esto, cada petición responde 401. |
| `vue-router` 4 y `pinia` 3 (o `react-router-dom` 7 y `zustand` 5) | Son los que declara el módulo. Sin versión, npm instala hoy majors que no acepta. |
| `JsonResource::withoutWrapping()` | El datatable espera `data`, `meta` y `links` en la raíz. Ver «El contrato front ↔ back». |
| `ToastRegionComponent` y `ConfirmHostComponent`, montados una vez | Ahí se pintan los avisos tras crear, guardar o borrar y la confirmación antes de borrar o exportar. Sin ellos los avisos no se ven y la confirmación cae en `window.confirm`. |
| `addTranslations(translations)` del módulo y `setLocale()`, una vez | Todo texto del módulo es una clave en inglés. Sin cargar sus traducciones la pantalla sale en inglés; la aplicación carga las suyas después para poder corregirlas. |

Esto no es teórico: la suite de LaraPack genera un paquete, lo instala en una
aplicación Laravel y recorre su API y sus tests tal como salen.

## Paso 6: dónde va tu código

Estos son los únicos sitios donde debes escribir. Si tu lógica no cabe en
ninguno, es señal de que falta algo en el `laraimport.json`, no de que haya que
salirse.

| Hueco | Qué va ahí |
|---|---|
| `Traits/Operations/` | La lógica de negocio del modelo. Es el sitio por defecto. Con metas, también la forma de `payload` en `buildPayload()`. |
| `Traits/Relations/` | Relaciones que el JSON no declara (through, morph, condicionales). |
| `Traits/Storage/` | Subida y borrado de archivos del modelo. |
| `Traits/Mutators/` | Accessors y mutators. |
| `Filters/<Model>/ManagedFilter::canView` | **Quién puede ver qué.** Sin esto el índice lo devuelve todo. |
| `Policies/<Model>Policy` | Autorización por acción. Nace cerrada: sólo pasa el administrador, y ni él borra para siempre hasta que saques `forceDelete` de `$exceptAbilities`. No lo encierres en el modelo: la API de políticas no lo vería y el front ofrecería un botón que falla. |
| `Requests/*/rules()` | Reglas que no vengan del JSON. Nunca toques nada fuera del array. |
| `Resources/<Model>Resource` | La forma exacta de la respuesta y su array `actions`. |
| `Events/*/Listeners/` | Efectos secundarios: notificaciones, colas, integraciones. |
| `Observers/` | Ciclo de vida del modelo. |
| `Factories/` | Datos de prueba realistas. Las generadas ya insertan: cada columna tiene un valor que la base acepta y cada `foreignId` hacia un modelo del archivo usa su factory. |
| `tests/Feature/` | El comportamiento, no la estructura. Los generados pasan recién generados y prueban que cada endpoint responde, con la autorización abierta en el `TestCase`. Si uno falla, lo rompiste tú. La autorización pruébala aparte, contra la Policy. |

Y una cosa más sobre el modelo: **el modelo es una fachada, no un almacén de
lógica.** Un método público en `Operations` que orquesta, y el trabajo real en
la clase que le corresponda.

### Qué no tocar

- El `Controller`. Delega en las requests y no tiene lógica. Si necesitas algo
  distinto, va en la request.
- El archivo de rutas. Sale del generador; si falta un endpoint, se añade al
  generador, no al archivo.
- `$fillable`, `$creatable`, `$updatable`, `$export_cols`,
  `$loadable_relations`, `$loadable_counts`, `casts()`. **Salen del JSON.**
  Editarlos a mano los desincroniza del contrato y `larapack:verify` lo dirá.
- Los archivos con el marcador `//RULES//`, `//IMPORTS//`, `//EDIT//` fuera de
  su marcador.

## Paso 7: verificar

```
larapack:verify --format=json
```

Compara el árbol con `.larapack/manifest.json`, que registra cada archivo
generado con su hash. Detecta:

- **`missing-file`** (error): falta algo que se generó. Regenera.
- **`customised`** (info): editaste un archivo generado. Puede estar bien — es
  un hueco — o ser deriva.
- **`inconsistent-entity`** (aviso): un modelo no tiene una pieza que tienen
  todos los que declararon su misma forma. Casi siempre es un olvido.
- **`route-prefix`** (error): el `API_ROUTE_PREFIX` del módulo Vue no coincide
  con el `->as(...)` del `RouteServiceProvider`. El front está llamando a una
  ruta que no existe.
- **`route-not-declared`** (error): existe una ruta, un método, un request o una
  vista de una acción que el modelo no declara. Alguien la escribió a mano:
  declárala en el JSON o retírala.
- **`immutable-write`** (error): un modelo `immutable` tiene un camino de
  escritura, o su modelo perdió la guarda de `booted()`.
- **`secret-exposed`** (error): una columna `secret` volvió a salir por la API,
  la exportación o la tabla.

Código distinto de cero = aún no has terminado.

## Regenerar

El generador es idempotente y **nunca destruye trabajo**:

- Sin `--force`, un archivo que ya existe se omite.
- Con `--force`, se regenera **salvo** que su hash no coincida con el del
  manifiesto, es decir, salvo que lo hayas editado. Entonces se conserva y se
  te dice.

Así que ampliar el `laraimport.json` y volver a importar es seguro. Es el flujo
normal, no una excepción.

## Vue y React

`<ui>` es `vue` o `react`, y lo eliges con `--vue` / `--react`. Los dos
módulos salen del mismo `laraimport.json` y tienen exactamente la misma
estructura.

**`models/<kebab>/index.js` es el mismo archivo en los dos.** No una copia: el
mismo. Son funciones puras y llamadas HTTP, sin nada de un framework de UI —
`API_ROUTE_PREFIX`, `crudActions()`, `dataTableHead()`, `dataTableSort()` y
las funciones CRUD. Ahí está el punto entero: si cada framework tuviera su
contrato, no habría un contrato.

Lo que cambia es sólo la capa de presentación:

| | Vue | React |
|---|---|---|
| Componentes | `<script setup>`, `.vue` | funciones, `.jsx` |
| Enlace de datos | `v-model` | `value` + `onChange(valor)` |
| Store | Pinia | Zustand, con la misma superficie |
| Router | vue-router | React Router 7 |
| Formularios | `innoboxrr-form-elements` | `innoboxrr-react-form-elements` |
| Tabla | `innoboxrr-vue-datatable` | `innoboxrr-react-datatable` |

Los dos paquetes de formularios exportan **los mismos 37 nombres**, así que el
`form_component` del `laraimport` vale igual para ambos. Hay un test en cada
paquete que falla si uno se adelanta al otro.

**Se usa como una aplicación de escritorio.** El índice deja la tabla montada:
el alta se abre en un drawer encima y, al guardar, la tabla se recarga en su
sitio con `refresh()`, sin perder página, orden ni filtros. El detalle enseña la
forma del registro mientras llega y abre la edición en otro drawer sobre la
ficha. Las rutas conservan sus nombres, así que un enlace a `AdminCreatePost` o
`AdminEditPost` abre el drawer que toca. Ctrl+K abre la paleta de comandos del
índice, las acciones de un registro son un menú desplegable, y crear, guardar y
borrar lo confirman con un aviso. **No hagas que un formulario navegue al
guardar**: avisa (`updateData` en Vue, `onUpdateData` en React) y es la vista
que abrió el drawer la que decide. Borrar y exportar preguntan con
`confirmAction` de `innoboxrr-form-core`; cancelar rechaza con
`RequestCancelledError`, y nadie avisa de lo que el usuario decidió no hacer.

**Rutas con nombre.** El contrato apunta a las vistas por nombre
(`params.to.name`). vue-router lo resuelve de fábrica; React Router no, así
que el agregador del módulo recorre el árbol de rutas y registra los nombres en
`innoboxrr-react-datatable`. Para navegar desde una vista React usa
`buildPath('AdminShowPost', { id })`, nunca una ruta escrita a mano.

**Qué rutas piden sesión lo declara la ruta, no el módulo.** Cada una lleva
`auth: true` —en `meta` en Vue, en `handle` en React— y es el router del
anfitrión el que lo lee y redirige. El módulo no importa ningún middleware de la
aplicación: si lo hiciera, sólo compilaría dentro de la aplicación que lo
define.

**El aspecto no depende de ningún framework de CSS.** El módulo generado no
necesita UIkit, ni Tailwind, ni Font Awesome: todo sale de
`innoboxrr-form-core`, y basta con importarlo una vez —el `theme.js` del
módulo ya lo hace:

```js
import 'innoboxrr-form-core/styles'
```

**No añadas clases de ningún framework a un archivo generado.** Si escribes
`uk-input`, `fa-plus` o `bg-blue-600` en un stub o en una vista generada, has
vuelto al problema que este sistema resuelve: esas clases venían de paquetes
que nadie declaraba, así que el módulo sólo se veía bien dentro de la
aplicación que ya los trajera cargados. Hay tests que fallan si reaparecen.

**Para cambiar colores y formas, redefine variables CSS**, no clases:

```css
:root {
    --fe-primary: #7c3aed;
    --fe-radius: 10px;
    --fe-density: 0.875;   /* interfaz más compacta */
}
```

El modo oscuro ya está resuelto: responde a la preferencia del sistema y a un
`data-theme="dark"` en la raíz.

**`setTheme` es para otra cosa**: apuntar un token a las clases de otro sistema
visual, cuando la aplicación ya tiene el suyo.

```js
setTheme({ input: 'form-control', button: 'btn btn-primary' })
```

Cada control lee su token, así que **un formulario generado no lleva ninguna
clase CSS**. **No añadas `customClass` a un input generado**: eso es para el
caso puntual, y si lo usas en todos has vuelto al problema.

**Los iconos se piden por nombre semántico**, nunca por clase. `plus`,
`download`, `edit`, `delete`, `show`, `actions`, `help`… El mapa decide de qué
colección salen, y son más de 200.000 iconos de más de 150 colecciones:

```js
setIcons({ plus: 'lucide:plus', delete: 'lucide:trash-2' })
```

Donde haga falta uno suelto se pasa el nombre de la colección entero
—`<IconComponent name="mdi:home" />`— sin darlo de alta. Si emites un icono
desde un Resource de Laravel, usa también el nombre semántico: `'icon' =>
'show'`, no `'fa-eye'`.

Es el mismo tema y el mismo mapa (`innoboxrr-form-core`) para Vue y para React,
así que los dos módulos se ven igual.

**Los textos son claves en inglés, nunca frases fijas.** Todo lo que se ve pasa
por `t()` de `innoboxrr-i18n` en el front y por `__()` en Laravel, también
títulos de ruta, migas y etiquetas accesibles. **No escribas español ni inglés
suelto en una vista**, y no concatenes el nombre del modelo en la clave: usa un
marcador, `t('Create :name', { name: t('Post') })`, para que la clave se traduzca
una vez para todos los modelos. LaraPack mantiene `src/locales/es.json` y
`en.json` al generar y no pisa lo traducido; lo que añadas a mano lo recoge
`npm run locale`. Los datatables y los componentes traen textos por defecto en
español: pásales los suyos traducidos (`labels`, `closeLabel`, `emptyText`…),
como hacen las vistas generadas.

## El contrato front ↔ back

El módulo de interfaz y la API se hablan por cuatro puntos. Si tocas un lado,
toca el otro. Vale igual para Vue y para React: el archivo es el mismo.

**1. El prefijo de rutas.** `API_ROUTE_PREFIX` en
`resources/<ui>/src/models/<kebab>/index.js` reconstruye el `->as(...)` del
`RouteServiceProvider`:

```
api.<namespace en puntos>.<kebab del modelo>.
api.acme.blog.post.
```

`larapack:verify` comprueba justamente esto.

**2. `window.route`.** El helper viene de `innoboxrr-route-resolver`, **no de
Ziggy**. La cadena completa es:

```
php artisan route:json        (innoboxrr/routes-to-json)
  → resources/.../routes.json
  → setRoutes() en bootstrap.js
  → window.route
  → route(API_ROUTE_PREFIX + 'index')
```

Si añades un endpoint, hay que volver a exportar el `routes.json`.

**3. La forma de la respuesta.** El datatable espera `res.data.data`,
`res.data.meta` y `res.data.links`. La aplicación anfitriona **debe** llamar a
`JsonResource::withoutWrapping()` en su `AppServiceProvider`; sin eso llega un
`data` de más y la tabla sale vacía.

**4. Las acciones.** El array `actions` del `<Model>Resource` llega al datatable
y se dispara con `actionClicked()`. Una acción con `route: false` invoca
`model[callback](params)`, así que `callback` tiene que ser un export real de
`models/<kebab>/index.js`.

## Errores frecuentes

- **Escribir el modelo a mano "porque es solo uno".** Ese es el primero de los
  treinta.
- **Rellenar todas las claves del JSON.** Declara lo que decides; el resto son
  defaults del esquema.
- **Generar las diez acciones y borrar las que sobran.** Declara `routes` o
  `immutable`. Lo borrado a mano queda como deriva para siempre.
- **Exponer un secreto en el Resource "sólo para depurar".** `larapack:verify`
  falla con `secret-exposed`, y tiene razón.
- **Corregir el código generado en vez del JSON.** El siguiente `--force` lo
  conservará, sí, pero el contrato y el código ya no dicen lo mismo.
- **Olvidar `<model>_id` en las reglas de `Update`.** `authorize()` y
  `handle()` hacen `findOrFail($this-><model>_id)`. El validador ya lo exige.
- **Añadir una relación en el trait sin declararla en `load_relations`.** El
  método existirá pero no estará en `$loadable_relations`, así que la API no la
  cargará nunca.
- **Ampliar la UI creando componentes sueltos.** Los formularios y vistas salen
  del JSON; si falta un input, falta una `prop` con `form: true`.
- **Tocar `models/<kebab>/index.js` de un solo framework.** Es el mismo archivo
  en Vue y en React: editarlo en uno los separa, y `larapack:verify` lo dirá.
- **Escribir una ruta a mano en una vista React.** El contrato navega por
  nombre; usa `buildPath()`, que resuelve contra donde el anfitrión montó el
  módulo de verdad.
