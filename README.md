# LaraPack

`innoboxrr/larapack-generator` genera y mantiene la arquitectura de APIs Laravel
—y su interfaz en Vue o React— a partir de un archivo declarativo,
`laraimport.json`.

No es un andamiador que arranca un proyecto y desaparece. Es la **autoridad
arquitectónica** del ecosistema innoboxrr: define cómo se estructura un paquete
o una aplicación, lo genera siempre igual y comprueba que siga siendo así.

## Índice

1. [Por qué existe](#por-qué-existe)
2. [Requisitos](#requisitos)
3. [Instalación](#instalación)
4. [Cómo se desarrolla con LaraPack](#cómo-se-desarrolla-con-larapack)
5. [Desarrollo con IA](#desarrollo-con-ia)
6. [El contrato: laraimport.json](#el-contrato-laraimportjson)
7. [Qué se genera y dónde va tu código](#qué-se-genera-y-dónde-va-tu-código)
8. [Regenerar sin destruir](#regenerar-sin-destruir)
9. [Verificar la arquitectura](#verificar-la-arquitectura)
10. [La interfaz: Vue y React](#la-interfaz-vue-y-react)
11. [Montar un paquete en una aplicación](#montar-un-paquete-en-una-aplicación)
12. [La línea base y la publicación](#la-línea-base-y-la-publicación)
13. [Guía de actualización](#guía-de-actualización)
14. [Referencia de comandos](#referencia-de-comandos)
15. [Código generado (Laravel 13)](#código-generado-laravel-13)
16. [Apoya nuestro trabajo](#apoya-nuestro-trabajo)

---

## Por qué existe

El problema de construir muchos modelos —a mano o con una IA— no es escribir
código rápido. Es la **deriva**: cada modelo sale un poco distinto del anterior,
y a los treinta modelos la aplicación ya no tiene una arquitectura, tiene
treinta.

LaraPack invierte esa dinámica. Se escribe un JSON pequeño y verificable, y las
~58 piezas de cada modelo —migración, modelo, filtros, controlador, requests,
política, recurso, eventos, exportación, rutas, tests, y la interfaz en Vue o
React— salen idénticas siempre. Encima de eso hay tres comprobaciones con código
de salida:

| Comando | Qué comprueba |
|---|---|
| `larapack:validate` | Que el `laraimport.json` es válido y coherente, antes de generar nada. |
| `larapack:verify` | Que lo generado sigue diciendo lo mismo que el contrato. |
| `larapack:audit` | Que el paquete cumple la línea base del ecosistema: versiones, tests y publicación. |

La regla que se desprende de todo esto guía el resto del documento:

> **La arquitectura se declara, no se escribe.** Lo que sí se escribe a mano es
> la lógica de negocio, y sólo en los huecos que el generador deja para eso.

---

## Requisitos

| Requisito | Versión |
| --------- | ------- |
| PHP | ^8.3 |
| Laravel | ^13.0 |
| Symfony Console | ^7.4 \|\| ^8.0 |
| PHPUnit (dev) | ^12.0 \|\| ^13.0 |
| Orchestra Testbench (dev) | ^11.0 |

- La lógica principal vive en `src/` en un paquete y en `app/` en una aplicación
  Laravel.
- Lo generado no depende de `App\Models\User`: las políticas reciben el usuario
  de la aplicación sea cual sea su clase. Lo que sí espera de la aplicación está
  en [Montar un paquete en una aplicación](#montar-un-paquete-en-una-aplicación).
- La exportación usa el disco de `export_disk` en la configuración del paquete;
  por defecto S3.
- Si el usuario de la aplicación define `isAdmin()`, las políticas dejan pasar al
  administrador; si no lo define, deciden sus métodos. Sin sistema de roles, un
  mínimo:

```php
public function isAdmin(): bool
{
    return $this->id === 1;
}
```

---

## Instalación

```
composer require innoboxrr/larapack-generator
```

Los comandos viven en el espacio `larapack:` y se ejecutan de dos formas:

```
php artisan larapack:full-model Post            # dentro de una aplicación Laravel
php vendor/bin/builder larapack:full-model Post # binario, también fuera de Laravel
```

El binario mantiene los nombres antiguos (`make:*`, `json:importer`) como alias.
En Artisan no se registran porque `make:model`, `make:policy`, `make:factory` y
`make:observer` son comandos del propio Laravel.

Todos aceptan `--root=<ruta>` para decir sobre qué proyecto se trabaja. Sin esa
opción la raíz se descubre subiendo directorios desde el paquete hasta dar con
un `vendor/autoload.php`: dentro de una aplicación o de un paquete que lo tenga
instalado eso da la raíz correcta; con el binario sobre un clon del propio
generador, da el generador. Los generadores aceptan además `--force` y
`--dry-run`, y todos `--format=json`.

---

## Cómo se desarrolla con LaraPack

### El reparto del trabajo

| Qué | Quién lo decide o lo escribe | Dónde |
|---|---|---|
| La forma del dominio: modelos, columnas, relaciones, qué acciones tiene cada tabla | Una persona, o una IA con revisión de una persona | `laraimport.json` |
| La estructura: las ~58 piezas de cada modelo | El generador | Todo lo generado |
| La lógica de negocio, la autorización, la visibilidad | Una persona o una IA | Los [huecos](#dónde-va-tu-código) |
| Que todo siga cuadrando | Las herramientas y la CI | `validate`, `verify`, `audit`, tests |
| Qué se publica y cuándo | Una persona | `VERSION` y `CHANGELOG.md` |

El `laraimport.json` es la decisión de arquitectura. Por eso es lo primero que se
revisa en un cambio: si el JSON está bien, lo generado está bien por
construcción.

### Un paquete desde cero

```
# 1. Crear el paquete. Deja composer.json con la línea base, proveedores,
#    configuración, phpunit.xml.dist, los workflows, VERSION y la guía para agentes.
php vendor/bin/builder larapack:new acme/catalogo packages/catalogo
cd packages/catalogo
composer install          # LaraPack queda en require-dev del paquete nuevo

# 2. Declarar el dominio. Primero se lee el esquema; no se adivina.
php vendor/bin/builder larapack:schema
#    ... escribir laraimport.json ...

# 3. Validar. Si falla, se corrige el JSON, nunca el código.
php vendor/bin/builder larapack:validate --vue

# 4. Mirar qué se va a tocar y generar.
php vendor/bin/builder larapack:import --vue --dry-run
php vendor/bin/builder larapack:import --vue

# 5. Escribir la lógica de negocio en los huecos (ver «Dónde va tu código»).

# 6. Comprobar.
php vendor/bin/builder larapack:verify
vendor/bin/phpunit
php vendor/bin/builder larapack:audit
```

`larapack:new` no sobrescribe nada y no trabaja sobre un directorio que ya tenga
`composer.json`. `--dry-run` dice exactamente lo que crearía.

### Ampliar un paquete que ya usa LaraPack

Añadir un modelo, una columna, una relación o una regla es el flujo normal, no
una excepción:

1. Edita `laraimport.json`.
2. `larapack:validate`.
3. `larapack:import --dry-run` para ver qué cambia.
4. `larapack:import --force`. Regenera lo que no has editado y **conserva lo que
   sí**, avisándote.
5. Revisa lo que el paso anterior dijo que conservó: esos archivos no recibieron
   el cambio y hay que llevarlo a mano.
6. `larapack:verify` y los tests.

Dos avisos:

- **Una tabla que ya existe en producción no se altera regenerando su migración
  de creación.** El JSON actualiza el modelo, las requests, los formularios y la
  tabla de la interfaz; el cambio de esquema de una base ya migrada va en una
  migración nueva (`php artisan make:migration`).
- **Si falta un endpoint o una pieza, falta en el JSON o en el generador**, no en
  el archivo generado. Escribirlo a mano es exactamente la deriva que
  `larapack:verify` señala.

### Tablas que no se administran desde un formulario

Una bitácora sólo se agrega, un catálogo sólo se lee, una concesión se otorga y
se revoca pero no se edita. No se generan las diez acciones para borrar después
lo que sobra: se declara con `routes`, `immutable` y `secret` (ver
[el contrato](#tablas-que-no-se-administran-desde-un-formulario-1)). Lo borrado
a mano queda marcado como editado para siempre, y el contrato deja de describir
el código.

### Publicar una versión

Los paquetes del ecosistema se publican desde el archivo `VERSION`:

1. Sube `VERSION` (semver) y describe el cambio en `CHANGELOG.md`, explicando el
   porqué y lo que tiene que hacer quien actualice.
2. Empuja a `main` o `master`.
3. `tests.yml` corre la suite y `larapack:audit`. Si pasa, `release.yml` crea el
   tag a partir de `VERSION`. Un push con un test roto no publica nada, y un tag
   que ya existe no se vuelve a crear.

Detalles en [La línea base y la publicación](#la-línea-base-y-la-publicación).

### Convenciones

- **`.larapack/manifest.json` se versiona.** Sin él el generador no distingue tu
  código del suyo y se vuelve conservador con todo.
- **Commits pequeños, uno por cambio conceptual**, con un mensaje que explique el
  porqué. Un cambio del JSON y su regeneración van juntos; la lógica de negocio
  que se escribe después, en otro.
- **Los módulos Vue y React comparten `models/<entidad>/index.js`.** Editarlo en
  uno solo los separa.
- **Ningún archivo generado lleva clases de un framework CSS** (`uk-*`, `fa-*`,
  Tailwind). El aspecto sale del tema.

---

## Desarrollo con IA

LaraPack está pensado para trabajar con un agente —Claude Code, Codex u otro—
sin que la arquitectura se degrade. El reparto es simple: **el agente escribe el
contrato y la lógica de negocio; el generador escribe la estructura; las
herramientas comprueban el trabajo de los dos; una persona decide el dominio y
revisa.**

### Por qué funciona

Un agente que escribe Laravel a mano produce código plausible y distinto cada
vez. Con LaraPack el agente no escribe controladores, requests ni migraciones:
escribe un JSON que `larapack:validate` acepta o rechaza con un mensaje concreto,
genera, y comprueba su propio trabajo con `larapack:verify`. Los tres comandos
devuelven `--format=json` y un código de salida distinto de cero cuando algo
falla, así que el agente puede iterar solo, sin preguntar y sin raspar texto.

### Preparar el proyecto

Las instrucciones para el agente viven en `skill/SKILL.md`, dentro de LaraPack, y
se instalan en el proyecto:

```
php vendor/bin/builder larapack:skill                  # -> .claude/skills/larapack/SKILL.md
php vendor/bin/builder larapack:skill --path=AGENTS.md # para agentes que leen AGENTS.md
php vendor/bin/builder larapack:skill --print          # por salida estándar
```

- **Claude Code** carga la skill de `.claude/skills/larapack/` sola cuando la
  tarea toca modelos, endpoints, formularios o vistas.
- **Codex y otros agentes** leen `AGENTS.md`. Un paquete creado con
  `larapack:new` ya trae uno que apunta a la skill.
- **Tras actualizar LaraPack**, reinstala la skill con `--force`: las
  instrucciones viajan con la versión del generador. Sin `--force` no se pisa un
  destino que se haya ampliado con reglas del proyecto.

Un paquete dentro de `vendor/` no lo lee nadie; de ahí la copia.

### El ciclo que sigue el agente

```
0. larapack:new vendor/paquete         sólo si el paquete aún no existe
1. larapack:schema                     descubre el contrato, no lo adivina
2. escribe o edita laraimport.json
3. larapack:validate --format=json     falla → corrige el JSON, no el código
4. larapack:import --dry-run           mira qué va a tocar
5. larapack:import [--vue] [--react]   genera
6. rellena los huecos                  aquí sí escribe código
7. larapack:verify --format=json       falla → algo se salió del contrato
8. vendor/bin/phpunit                  el comportamiento
```

La skill le explica además el mapa de lo que se genera, **dónde va la lógica de
negocio**, qué no debe tocar y los errores frecuentes.

### Cómo pedirle las cosas

Pide **dominio y comportamiento**, no archivos:

| En lugar de | Pide |
|---|---|
| «Crea el controlador y la migración de proveedores» | «Añade proveedores: nombre, email único y teléfono opcional. Un producto pertenece a un proveedor. Genera la API y el módulo Vue.» |
| «Quita las rutas de editar y borrar de la bitácora» | «La bitácora de cambios de precio sólo se agrega y se consulta; nadie la modifica.» (espera `immutable` y `routes`) |
| «Oculta el token en el Resource» | «El token de las credenciales se guarda pero nunca sale por la API.» (espera `secret`) |
| «Arregla el formulario de productos» | «Al crear un producto, el stock inicial no puede ser negativo.» (espera una regla en el JSON) |

Y di qué cuenta como terminado. Una definición razonable:

- `laraimport.json` actualizado y `larapack:validate` en verde.
- Generado con `larapack:import`, sin tocar archivos generados fuera de los
  huecos.
- La lógica en `Operations`, la política, `ManagedFilter::canView`, las reglas o
  los listeners, según corresponda.
- `larapack:verify` y la suite en verde; `larapack:audit` si es un paquete.
- Si se va a publicar, `VERSION` y `CHANGELOG.md` actualizados.

### Qué revisa una persona

En este orden, porque cada paso invalida los siguientes si falla:

1. **El diff de `laraimport.json`.** Es la decisión de arquitectura: tipos,
   claves foráneas, qué acciones tiene cada tabla, qué es `secret` o `immutable`.
2. **La autorización y la visibilidad.** La política nace cerrada (sólo pasa el
   administrador) y `ManagedFilter::canView` decide qué ve cada usuario en el
   índice. Es donde un error se convierte en una fuga de datos.
3. **La lógica de negocio en los huecos.** El modelo es una fachada: un método
   en `Operations` que orquesta, y el trabajo real en la clase que le toque.
4. **Que `larapack:verify` sólo marque como editados los huecos.** Un
   controlador, un archivo de rutas o un `$fillable` editados son deriva.
5. **Los tests del comportamiento**, no sólo los generados.

Señales de que el agente se salió del flujo: editó el controlador o el archivo
de rutas, cambió `$fillable` o `casts()` a mano, borró acciones en vez de
declararlas, escribió clases de Tailwind o de UIkit en una vista, hizo que un
formulario navegue al guardar, o expuso un campo sensible «sólo para depurar».

### Lo que no se delega

Decidir el dominio, dar permisos en las políticas, manejar credenciales y
secretos, publicar una versión y migrar una base de producción. El agente puede
proponerlo; lo confirma una persona.

---

## El contrato: laraimport.json

El `laraimport.json` no es un archivo de configuración: es el contrato del que
sale toda la arquitectura. Está descrito en un JSON Schema (draft 2020-12) en
`schema/laraimport.schema.json`, declarado también en `extra.larapack.schema`
del `composer.json` para que una herramienta externa lo encuentre sin conocer la
estructura del paquete.

```
php vendor/bin/builder larapack:validate                 # valida ./laraimport.json
php vendor/bin/builder larapack:validate ruta.json       # valida el que le digas
php vendor/bin/builder larapack:validate --format=json   # para la CI o un agente
php vendor/bin/builder larapack:validate --vue --react   # también lo que necesitan las vistas
php vendor/bin/builder larapack:schema                   # imprime el esquema
```

`larapack:validate` sale con código distinto de cero si el archivo no es válido,
así que sirve de puerta antes de generar. Comprueba dos cosas:

**Forma**, contra el esquema: tipos de columna y componentes de formulario son
enums cerrados; `form: true` obliga a declarar `form_component`; `foreignId`
obliga a declarar `constraint`.

**Coherencia**, mirando el documento entero: modelos o columnas repetidos, ciclos
de claves foráneas (que no tienen orden de migración posible), claves foráneas a
sí mismo no anulables, reglas sobre campos que no son columnas, relaciones que no
resuelven contra ningún modelo, `UpdateRequest` sin la regla del identificador
del que dependen `authorize()` y `handle()`, `only` junto a `except`, un modelo
`immutable` que pide una escritura, y un `secret` que pide salir en la tabla o en
la exportación.

Los avisos no bloquean. Los errores sí: generar con ellos produce código que no
arranca. El importador ejecuta esta misma validación antes de escribir nada, así
que un archivo incompleto no deja el proyecto a medio generar.

### Lo mínimo

Sólo `models[].name`, `props[].name` y `props[].type` son obligatorios. Todo lo
demás tiene un valor por defecto que el esquema declara, así que el archivo sólo
dice lo que se decide de verdad:

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

### Las claves que más se usan

| Clave | Para qué |
|---|---|
| `props[].type` | Tipo de columna de la migración. Enum cerrado. |
| `props[].constraint` | Tabla a la que apunta un `foreignId`. Obligatoria si el tipo lo es. |
| `props[].nullable` | La columna admite `null`. |
| `props[].cast` | Va al `casts()` del modelo. |
| `props[].fillable` / `creatable` / `updatable` | Arrays del modelo. Por defecto `true`. |
| `props[].exports_cols` | Columna de la exportación a Excel. |
| `props[].form` + `form_component` | Pinta un input en `CreateForm` y `EditForm`. `form: true` obliga a declarar el componente. |
| `props[].form_submit` | El campo viaja en el envío. Un `user_id` con `form: false` y `form_submit: true` se convierte en prop del componente. |
| `props[].datatable` | Columna de la tabla y candidata al orden por defecto. |
| `props[].enum` | Opciones de un `SelectInputComponent`. |
| `props[].secret` | Nunca sale por la API: va a `$hidden`, fuera de la exportación y de la tabla. |
| `metas` | Genera el modelo `<Model>Meta` y sus operaciones. |
| `load_relations` | Métodos del trait `Relations` y la lista `$loadable_relations`. |
| `load_counts` | La lista `$loadable_counts`. |
| `editable_metas` | Metas que acepta la actualización. |
| `requests[]` | Reglas de `CreateRequest` y `UpdateRequest`. |
| `pivots[]` | Migraciones de tablas pivote, sin modelo. |
| `routes` | Qué acciones genera el modelo: `only` o `except`. Sin la clave, las diez. |
| `immutable` | La fila no cambia una vez creada. |

### Un modelo completo

Con formulario, tabla, relaciones y validación:

```json
{
    "models": [
        {
            "name": "Post",
            "metas": true,
            "props": [
                {
                    "name": "title",
                    "type": "string",
                    "form": true,
                    "form_component": "TextInputComponent",
                    "form_submit": true,
                    "datatable": true
                },
                {
                    "name": "status",
                    "type": "string",
                    "cast": "string",
                    "form": true,
                    "form_component": "SelectInputComponent",
                    "form_submit": true,
                    "datatable": true,
                    "enum": {
                        "draft": "Borrador",
                        "published": "Publicado"
                    }
                },
                {
                    "name": "payload",
                    "type": "longText",
                    "nullable": true,
                    "cast": "json"
                },
                {
                    "name": "category_id",
                    "type": "foreignId",
                    "constraint": "categories",
                    "form_submit": true
                },
                {
                    "name": "user_id",
                    "type": "foreignId",
                    "constraint": "users",
                    "exports_cols": false,
                    "form_submit": true
                }
            ],
            "load_relations": [
                { "type": "belongsTo", "related": "Category", "name": "category" },
                { "type": "belongsTo", "related": "User", "name": "user", "namespace": "App\\Models" }
            ],
            "load_counts": [],
            "editable_metas": ["seo_title"],
            "requests": [
                {
                    "name": "Create",
                    "rules": {
                        "title": "required|string|max:255",
                        "status": ["required", "in:draft,published"],
                        "category_id": "required|exists:categories,id",
                        "user_id": "required|exists:users,id"
                    }
                },
                {
                    "name": "Update",
                    "rules": {
                        "post_id": "required|numeric",
                        "title": "nullable|string|max:255",
                        "status": ["nullable", "in:draft,published"],
                        "category_id": "nullable|exists:categories,id"
                    }
                }
            ]
        },
        {
            "name": "Category",
            "props": [
                { "name": "name", "type": "string", "form": true, "form_component": "TextInputComponent", "form_submit": true, "datatable": true }
            ]
        },
        {
            "name": "Tag",
            "props": [
                { "name": "name", "type": "string", "form": true, "form_component": "TextInputComponent", "form_submit": true, "datatable": true }
            ]
        }
    ],
    "pivots": [
        {
            "name": "post_tag",
            "props": [
                { "name": "post_id", "type": "foreignId", "constraint": "posts" },
                { "name": "tag_id", "type": "foreignId", "constraint": "tags" }
            ]
        }
    ]
}
```

Tres cosas que se resuelven solas y no hay que arreglar a mano:

- **El orden de los modelos no importa.** `Post` depende de `Category` por su
  clave foránea, así que la migración de `categories` se genera primero aunque el
  modelo esté declarado después.
- **El namespace de las relaciones.** `Category` se declara en este mismo
  archivo, así que vive en el paquete y el `use` generado apunta ahí. `User` no
  está, así que resuelve contra `App\Models`. Sólo se escribe `namespace` si no es
  ninguna de las dos cosas.
- **Las reglas admiten array**, que es la forma recomendada de Laravel para todo
  lo que lleve `|` dentro, como un `regex:`.

`assignments` y `filters` siguen aceptándose por compatibilidad, pero están
marcadas como obsoletas en el esquema: el generador no las lee.

### Tablas que no se administran desde un formulario

LaraPack genera diez acciones por modelo: `policies`, `policy`, `index`, `show`,
`create`, `update`, `delete`, `restore`, `forceDelete` y `export`. Es la forma
correcta para algo que una persona administra desde una pantalla, y la
equivocada para buena parte de un sistema real. Tres claves lo declaran; si no
están, el modelo tiene las diez de siempre:

```json
{
    "models": [
        {
            "name": "AuditEvent",
            "immutable": true,
            "routes": { "only": ["policies", "index", "show", "export"] },
            "props": [
                { "name": "action", "type": "string", "datatable": true },
                { "name": "payload", "type": "longText", "cast": "json" }
            ]
        },
        {
            "name": "ApiKey",
            "routes": { "except": ["update", "restore", "forceDelete"] },
            "props": [
                { "name": "label", "type": "string", "form": true, "form_component": "TextInputComponent", "form_submit": true, "datatable": true },
                { "name": "token_hash", "type": "string", "secret": true }
            ]
        }
    ]
}
```

- **`routes`** — `only` o `except`, nunca las dos. Quitar una acción quita todo lo
  que cuelga de ella: su ruta, su request, su método del controlador, su evento,
  su habilidad en la política, su test y, en la interfaz, su formulario, su vista,
  su drawer y su función del contrato. Sin `delete`, `restore` ni `forceDelete`,
  el modelo tampoco usa `SoftDeletes`.
- **`immutable`** — una vez creada, la fila no se modifica ni se borra. Quita
  `update`, `delete`, `restore` y `forceDelete`, y el modelo lanza una excepción
  si algo lo intenta por otro camino que no sea HTTP. **Crear sigue permitido**:
  una fila inmutable nace. Si tampoco debe crearse por la API, se quita con
  `routes`.
- **`secret`** — la columna va a `$hidden` y nunca sale en la exportación ni en la
  tabla. Se puede escribir, pero no leer.

Las vistas del módulo de interfaz cuelgan del índice, y el índice necesita
`policies` porque la tabla las consulta para decidir qué acciones ofrece. Sin
alguna de las dos se generan igualmente el contrato y el store, y
`larapack:import --vue` lo avisa.

`larapack:full-model` acepta las mismas primitivas para un modelo suelto:

```
php artisan larapack:full-model AuditEvent --only=policies,index,show --immutable
```

---

## Qué se genera y dónde va tu código

Un modelo produce unos 58 archivos. Los marcados como hueco son los sitios donde
se escribe a mano:

```
src/Models/<Model>.php                             fillable, casts, whitelists
src/Models/<Model>Meta.php                         sólo si metas: true
src/Models/Traits/Relations/<Model>Relations.php   ← hueco
src/Models/Traits/Operations/<Model>Operations.php ← hueco
src/Models/Traits/Storage/<Model>Storage.php       ← hueco
src/Models/Traits/Mutators/<Model>Mutators.php     ← hueco
src/Models/Traits/Assignments/<Model>Assignment.php
src/Models/Filters/<Model>/ManagedFilter.php       ← hueco (visibilidad)
src/Models/Filters/<Model>/{Id,Creation,Updated,EagerLoading}Filter.php

src/Http/Controllers/<Model>Controller.php         delega en las requests
src/Http/Requests/<Model>/*.php                    rules() ← hueco
src/Http/Resources/Models/<Model>Resource.php      ← hueco (forma de la respuesta)
src/Http/Events/<Model>/Events/*.php
src/Http/Events/<Model>/Listeners/*/*.php          ← hueco (efectos secundarios)

src/Policies/<Model>Policy.php                     ← hueco (autorización)
src/Observers/<Model>Observer.php                  ← hueco
src/Exports/<Plural>Exports.php
src/Notifications/<Model>/ExportNotification.php

routes/api/models/<kebab>.php
database/migrations/*_create_<plural>_table.php
database/factories/<Model>Factory.php              ← hueco (datos de prueba)
tests/Feature/Models/<Model>EndpointsTest.php      ← hueco

resources/<ui>/src/models/<kebab>/index.js         contrato del modelo, igual en Vue y React
resources/<ui>/src/models/<kebab>/store/index.js   Pinia (Vue) / Zustand (React)
resources/<ui>/src/models/<kebab>/routes/index.js
resources/<ui>/src/models/<kebab>/forms/*          Create, Edit, Filter
resources/<ui>/src/models/<kebab>/views/*          Admin, Create, Edit, Show
resources/<ui>/src/models/<kebab>/widgets/*        DataTable, ModelCard, ModelProfile
```

Los proveedores no los genera el importador. En un paquete creado con
`larapack:new` ya están; en uno que no, una vez: `larapack:providers` y
`larapack:config`. `larapack:providers` los declara en `extra.laravel.providers`
del `composer.json`, así que la aplicación que instala el paquete lo arranca
sola: migraciones, vistas, configuración, rutas y eventos.

### Dónde va tu código

Si una lógica no cabe en ninguno de estos sitios, falta algo en el
`laraimport.json`; no hay que salirse.

| Hueco | Qué va ahí |
|---|---|
| `Traits/Operations/` | La lógica de negocio del modelo. Es el sitio por defecto. |
| `Traits/Relations/` | Relaciones que el JSON no declara (through, morph, condicionales). |
| `Traits/Storage/` | Subida y borrado de archivos del modelo. |
| `Traits/Mutators/` | Accessors y mutators. |
| `Filters/<Model>/ManagedFilter::canView` | **Quién puede ver qué.** Sin esto el índice lo devuelve todo. |
| `Policies/<Model>Policy` | Autorización por acción. Nace cerrada: sólo pasa el administrador, y ni él borra para siempre hasta que se saque `forceDelete` de `$exceptAbilities`. |
| `Requests/*/rules()` | Reglas que no vengan del JSON. Nada fuera del array. |
| `Resources/<Model>Resource` | La forma exacta de la respuesta y su array `actions`. |
| `Events/*/Listeners/` | Efectos secundarios: notificaciones, colas, integraciones. |
| `Observers/` | Ciclo de vida del modelo. |
| `Factories/` | Datos de prueba realistas. Las generadas ya insertan. |
| `tests/Feature/` | El comportamiento. Los generados pasan recién generados; si uno falla, algo se rompió. |

**El modelo es una fachada, no un almacén de lógica.** Un método público en
`Operations` que orquesta, y el trabajo real en la clase que le corresponda.

### Qué no se toca

- El controlador: delega en las requests y no tiene lógica.
- El archivo de rutas: si falta un endpoint, falta en el generador.
- `$fillable`, `$creatable`, `$updatable`, `$export_cols`, `$loadable_relations`,
  `$loadable_counts` y `casts()`: salen del JSON.
- Los archivos con marcadores `//RULES//`, `//IMPORTS//` o `//EDIT//`, fuera de
  su marcador.
- `models/<kebab>/index.js` de un solo framework.

---

## Regenerar sin destruir

El generador anota lo que produce en `.larapack/manifest.json`: cada archivo, la
plantilla de la que salió y el hash de su contenido en ese momento. Con eso sabe
distinguir lo que escribió él de lo que escribiste tú.

```
php artisan larapack:model Post --dry-run    # dice qué haría, sin escribir
php artisan larapack:model Post --force      # regenera
php artisan larapack:model Post --format=json
```

`--force` **nunca sobrescribe un archivo cuyo hash haya cambiado**. Si lo
editaste, se conserva y se avisa. Un archivo que el manifiesto no conoce se
respeta igual: el generador no toca lo que no escribió él.

El manifiesto debe versionarse con el proyecto.

---

## Verificar la arquitectura

```
php artisan larapack:verify
php artisan larapack:verify --strict          # los avisos cuentan como fallos
php artisan larapack:verify --format=json
```

| Comprobación | Nivel | Qué detecta |
| --- | --- | --- |
| `missing-file` | error | Algo que se generó y ya no está. |
| `customised` | info | Editado a mano; no se podrá regenerar sin perderlo. |
| `inconsistent-entity` | aviso | Una entidad sin un componente que tienen todas las que declararon su misma forma. |
| `route-prefix` | error | El `API_ROUTE_PREFIX` del módulo JS dejó de cuadrar con el `->as()` del RouteServiceProvider. |
| `route-not-declared` | error | Una ruta, un método del controlador, un request o una vista de una acción que el modelo no declara. |
| `immutable-write` | error | Un camino de escritura en un modelo `immutable`, o su modelo sin la guarda que rechaza modificaciones. |
| `secret-exposed` | error | Una columna `secret` fuera de `$hidden`, en la exportación, en el Resource o en la tabla. |

Devuelve un código distinto de cero si hay errores, así que sirve como puerta en
la CI. La salida en JSON está pensada para que un agente lea qué corregir:

```json
{
  "ok": false,
  "errors": 1,
  "warnings": 0,
  "findings": [
    {
      "level": "error",
      "check": "missing-file",
      "model": "Post",
      "file": "src/Policies/PostPolicy.php",
      "message": "Se generó pero ya no existe. Regenéralo con --force o retíralo del manifiesto."
    }
  ]
}
```

No intenta adivinar si una clase «tiene demasiada lógica»: esa clase de
comprobación produce falsos positivos y acaba desactivándose. Sólo verifica lo
que es determinista.

---

## La interfaz: Vue y React

La UI vive **dentro del paquete**, junto al backend que consume, y se publica
como su propio paquete npm:

```
packages/<feature>/
  composer.json                 -> vendor/<feature>          (Laravel)
  resources/vue/
    package.json                -> vendor-<feature>          (npm)
    src/models/<entidad>/...
  resources/react/
    package.json                -> vendor-<feature>-react    (npm)
    src/models/<entidad>/...
```

```
php vendor/bin/builder larapack:import --vue
php vendor/bin/builder larapack:import --react
php vendor/bin/builder larapack:import --vue --react
```

Los dos módulos salen del mismo `laraimport.json` y tienen exactamente la misma
estructura. **`src/models/<entidad>/index.js` es el mismo archivo en los dos**:
funciones puras y llamadas HTTP —`API_ROUTE_PREFIX`, `crudActions()`,
`dataTableHead()`, `dataTableSort()` y las funciones CRUD—, sin nada de un
framework de UI.

| | Vue | React |
|---|---|---|
| Componentes | `<script setup>`, `.vue` | funciones, `.jsx` |
| Enlace de datos | `v-model` | `value` + `onChange(valor)` |
| Store | Pinia | Zustand, con la misma superficie |
| Router | vue-router 4 | React Router 7 |
| Formularios y piezas | `innoboxrr-form-elements` | `innoboxrr-react-form-elements` |
| Tabla | `innoboxrr-vue-datatable` | `innoboxrr-react-datatable` |

Los dos paquetes de formularios exportan los mismos 37 nombres, así que el
`form_component` del `laraimport` vale igual para los dos.

### Cómo se usa

Las vistas se usan como una aplicación de escritorio:

- **El índice deja la tabla montada.** El alta se abre en un drawer encima; al
  guardar, la tabla se recarga en su sitio con `refresh()`, sin perder página,
  orden ni filtros.
- **El detalle** enseña la forma del registro mientras llega y abre la edición en
  otro drawer sobre la ficha.
- **Las rutas conservan sus nombres**: un enlace a `AdminCreatePost` o
  `AdminEditPost` abre el drawer que toca.
- **Crear, guardar y borrar lo confirman con un aviso**; borrar y exportar
  preguntan con la confirmación del tema.
- **Las acciones de un registro son un menú desplegable**, y **Ctrl+K** abre la
  paleta de comandos del índice.
- **La tabla** resuelve los permisos de cada fila antes de abrir su menú, pinta
  esqueletos mientras carga, explica un 403 en lugar de enseñar una tabla vacía y
  admite selección de filas con acciones masivas.

Un formulario no navega al guardar: avisa (`updateData` en Vue, `onUpdateData` en
React), y la vista que abrió el drawer decide qué pasa.

### El aspecto

El módulo generado **no necesita ningún framework de CSS**. Todo sale de
`innoboxrr-form-core`, y `resources/<ui>/src/theme.js` lo importa una vez:

```js
import 'innoboxrr-form-core/styles'
```

Colores, formas y densidad son variables CSS:

```css
:root {
    --fe-primary: #7c3aed;
    --fe-radius: 10px;
    --fe-density: 0.875;          /* interfaz más compacta */
    --fe-table-max-height: 70vh;  /* cabecera de la tabla fija al bajar */
}
```

El modo oscuro responde a la preferencia del sistema y a un `data-theme="dark"`
en la raíz. `setTheme` queda para apuntar un token a las clases de otro sistema
visual, si la aplicación ya tiene el suyo.

Los iconos se piden **por nombre semántico** —`plus`, `edit`, `delete`, `show`,
`actions`— y el mapa decide de qué colección salen:

```js
setIcons({ plus: 'lucide:plus', delete: 'lucide:trash-2' })
```

Un `Resource` de Laravel emite también el nombre semántico: `'icon' => 'show'`,
no `'fa-eye'`.

---

## Montar un paquete en una aplicación

### Backend

```
composer require acme/catalogo
php artisan migrate
```

Los proveedores se descubren solos. Lo que el paquete da por hecho de la
aplicación:

| Qué | Por qué |
|---|---|
| `laravel/sanctum` | Las rutas usan `auth:sanctum`. |
| Usuario `Notifiable` | La exportación avisa al usuario por notificación. |
| `isAdmin()` en el usuario, opcional | `before()` de cada política deja pasar al administrador. |
| `maatwebsite/excel` | Sólo si se usa la exportación. |
| `JsonResource::withoutWrapping()` en el `AppServiceProvider` | La tabla espera `data`, `meta` y `links` en la raíz; sin esto sale vacía. |
| `innoboxrr/routes-to-json` | El front resuelve cada URL por el nombre de la ruta. |

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Http\Resources\Json\JsonResource;

public function boot(): void
{
    JsonResource::withoutWrapping();
}
```

El front no escribe URLs: las pide por nombre con `innoboxrr-route-resolver`,
que no es Ziggy. La cadena es:

```
php artisan route:json        (innoboxrr/routes-to-json)
  -> routes.json
  -> setRoutes(routes) al arrancar el front
  -> route('api.acme.catalogo.product.index')
```

Si se añade un endpoint, hay que volver a exportar `routes.json`.

### Frontend con Vue

El módulo se instala como cualquier paquete npm (publicado, o con
`"file:vendor/acme/catalogo/resources/vue"` mientras se desarrolla). Necesita
`vue`, `vue-router` y `pinia` en la aplicación.

```js
// main.js
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import { setRoutes } from 'innoboxrr-route-resolver'

import 'acme-catalogo/src/theme.js'
import catalogo, { routes as catalogoRoutes } from 'acme-catalogo'

import routes from './routes.json'
import App from './App.vue'
import AdminLayout from './AdminLayout.vue'

setRoutes(routes)

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/admin', component: AdminLayout, children: catalogoRoutes },
    ],
})

// Qué ruta pide sesión lo declara la ruta (`meta.auth`); decide la aplicación.
router.beforeEach((to) => (to.meta.auth && ! isLoggedIn() ? '/login' : true))

createApp(App).use(createPinia()).use(router).use(catalogo).mount('#app')
```

```vue
<!-- App.vue: los avisos y la confirmación se montan una sola vez -->
<template>
    <RouterView />
    <ToastRegionComponent />
    <ConfirmHostComponent />
</template>

<script setup>
    import { RouterView } from 'vue-router'
    import { ConfirmHostComponent, ToastRegionComponent } from 'innoboxrr-form-elements'
</script>
```

### Frontend con React

Necesita `react`, `react-dom`, `react-router-dom` 7 y `zustand`. React Router no
tiene rutas con nombre, así que el módulo registra las suyas con el mismo prefijo
con el que se montan:

```jsx
// main.jsx
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, Outlet, RouterProvider } from 'react-router-dom'
import { setRoutes } from 'innoboxrr-route-resolver'
import { ConfirmHostComponent, ToastRegionComponent } from 'innoboxrr-react-form-elements'

import 'acme-catalogo-react/src/theme.js'
import { registerModuleRoutes, routes as catalogoRoutes } from 'acme-catalogo-react'

import routes from './routes.json'

setRoutes(routes)
registerModuleRoutes('/admin')

function AdminLayout() {
    // Qué ruta pide sesión lo declara la ruta (`handle.auth`); decide la aplicación.
    return (
        <>
            <Outlet />
            <ToastRegionComponent />
            <ConfirmHostComponent />
        </>
    )
}

const router = createBrowserRouter([
    { path: '/admin', element: <AdminLayout />, children: catalogoRoutes },
])

createRoot(document.getElementById('app')).render(<RouterProvider router={router} />)
```

Desde una vista React se navega con `buildPath('AdminShowProduct', { id })` de
`innoboxrr-react-datatable`, nunca con una ruta escrita a mano.

---

## La línea base y la publicación

### La línea base

`larapack:verify` mira un paquete contra su propio manifiesto. `larapack:audit`
lo mira contra las versiones que rigen a todos:

```
php vendor/bin/builder larapack:audit                    # el paquete actual
php vendor/bin/builder larapack:audit packages --all     # todos los de un directorio
php vendor/bin/builder larapack:audit --format=json --strict
```

Esas versiones viven en un solo archivo, `ecosystem.json`: PHP, `illuminate/*`,
`orchestra/testbench`, las dependencias internas de Composer y las de npm que el
módulo generado pide. El audit comprueba además que el paquete tenga tests de
verdad y los workflows de publicación, y que `composer.json` no fije su propia
`version`: Composer descarta cada tag que no coincide con ella, así que una
publicación nueva quedaría invisible.

Un paquete que no pasa el audit no debería publicarse; por eso lo ejecuta el
`tests.yml` del ecosistema.

### La CI y la publicación

Un paquete del ecosistema tiene dos workflows que llaman a los reutilizables de
`innoboxrr/.github`:

```yaml
# .github/workflows/tests.yml
on:
    push:
        branches: [main, master]
    pull_request:

jobs:
    tests:
        uses: innoboxrr/.github/.github/workflows/php-tests.yml@main
```

```yaml
# .github/workflows/release.yml
on:
    workflow_run:
        workflows: ['Tests']
        types: [completed]
        branches: [main, master]
    workflow_dispatch:

jobs:
    release:
        permissions:
            contents: write
        uses: innoboxrr/.github/.github/workflows/php-release.yml@main
```

`tests.yml` instala, comprueba la sintaxis, ejecuta `larapack:audit` y corre la
suite. `release.yml` sólo actúa si los tests pasaron: lee `VERSION` y crea ese
tag si no existe. `permissions: contents: write` va en el propio `release.yml`:
un repositorio cuyo permiso por defecto sea de sólo lectura, sin esa línea, no
arranca el workflow y no deja log.

`larapack:new` genera los dos, junto con `VERSION` en 0.1.0.

---

## Guía de actualización

### Dónde estás

Mira la versión instalada con `composer show innoboxrr/larapack-generator` y
busca tu fila. Las secciones se aplican **en orden**, desde la siguiente a tu
versión hasta la última.

| Estás en | Esfuerzo | Qué rompe | Secciones a seguir |
|---|---|---|---|
| 5.x | Alto | PHP y Laravel, nombres de comandos, sitio del módulo de interfaz, aspecto | [5.x → 6.0](#de-5x-a-60), [6.x → 7.0](#de-6x-a-70) y todas las siguientes |
| 6.x | Medio | El aspecto del módulo, los iconos, la publicación | [6.x → 7.0](#de-6x-a-70) y todas las siguientes |
| 7.0 | Bajo | Nada; `verify` gana comprobaciones | [7.0 → 7.1](#de-70-a-71) y siguientes |
| 7.1 | Bajo | `audit` rechaza `version` en `composer.json` | [7.1 → 7.2](#de-71-a-72) y siguientes |
| 7.2 | Medio | Rutas Vue con `meta.auth`; archivos a regenerar | [7.2 → 7.3](#de-72-a-73) y siguientes |
| 7.3 | Bajo | Nada | [7.3 → 7.4](#de-73-a-74) y siguiente |
| 7.4 | Medio | Datatables 3.0, avisos y confirmación en la aplicación | [7.4 → 7.5](#de-74-a-75) y siguiente |
| 7.5.0 | Bajo | Nada; tres dependencias npm | [7.5.0 → 7.5.1](#de-750-a-751) |
| 7.5.1 | — | Estás al día | — |

Las notas completas de cada versión están en `CHANGELOG.md`.

### El procedimiento, sea cual sea la versión

1. **Trabaja en una rama, con el árbol limpio** y `.larapack/manifest.json`
   versionado.
2. **Toma la foto de partida** con la versión actual:
   `php vendor/bin/builder larapack:verify --format=json`. Los archivos marcados
   `customised` son los que la regeneración no va a tocar.
3. **Actualiza el generador** con la misma restricción que ya uses (añade `--dev`
   si lo tenías en `require-dev`):

   ```
   composer require innoboxrr/larapack-generator:"^7.5"
   ```

4. **Actualiza las instrucciones del agente**:
   `php vendor/bin/builder larapack:skill --force`.
5. **Valida el contrato**: `php vendor/bin/builder larapack:validate --vue --react`.
   Un esquema nuevo puede señalar algo que antes pasaba.
6. **Regenera**, primero en simulación:

   ```
   php vendor/bin/builder larapack:import --vue --react --dry-run
   php vendor/bin/builder larapack:import --vue --react --force
   ```

   Omite `--vue` o `--react` si el paquete no tiene ese módulo.
7. **Lleva a mano los cambios a lo editado.** Cada archivo que el paso anterior
   conservó por estar editado no recibió los arreglos de la versión; la sección de
   tu versión dice qué cambió en cada uno.
8. **Alinea las dependencias.** `larapack:audit` dice qué versiones de Composer no
   cumplen la línea base. En `resources/<ui>/package.json`, las dependencias
   `innoboxrr-*` tienen que coincidir con `internalNpm` de `ecosystem.json`; si ese
   archivo está marcado como editado, se cambian a mano.
9. **Comprueba**: `larapack:verify`, `vendor/bin/phpunit`, `larapack:audit`,
   `php artisan route:json` si cambió alguna ruta, la compilación del front y una
   pasada por la interfaz en el navegador.

### De 5.x a 6.0

La 6.0 pasa a Laravel 13 y PHP 8.3, cambia los nombres de los comandos y mueve el
módulo de interfaz al paquete. Nada de esto es compatible con la 5.x.

**Requisitos.** PHP `^8.3`, `illuminate/support ^13.0` y
`symfony/console ^7.4 || ^8.0`. Las dependencias internas tienen que ser las de la
línea base actual (`innoboxrr/traits` e `innoboxrr/search-surge`, ver
`ecosystem.json`).

**Comandos.** Cambia scripts, CI y documentación:

```
make:full-model   ->  larapack:full-model
json:importer     ->  larapack:import
remove:full-model ->  larapack:remove-full-model
```

El binario `builder` mantiene los nombres antiguos como alias; Artisan no.

**El contrato.** `laraimport.json` se valida contra un esquema antes de generar.
Ejecuta `larapack:validate` y corrige el JSON hasta que pase. `assignments` y
`filters` quedan obsoletas.

**La interfaz.** El módulo vive en `resources/<vue|react>/` del paquete y se
publica como su propio npm. Actualiza los imports de la aplicación al paquete
nuevo.

**El manifiesto.** Un proyecto generado con la 5.x no tiene
`.larapack/manifest.json`, así que el generador no sabe qué escribió él y no
sobrescribe nada que ya exista, ni con `--force`. Para adoptarlo:

1. Genera sobre una copia limpia: `larapack:import --root=../copia-limpia`.
2. Compara con `git diff --no-index` los archivos que no tocaste.
3. Borra en el proyecto los que no tengan cambios tuyos y vuelve a importar: así
   entran en el manifiesto.
4. Los que sí tengan cambios tuyos, pórtalos a mano sobre la versión nueva o
   déjalos como están, sabiendo que no recibirán arreglos.

**Arreglos que llegan al regenerar.** Formularios que había que enviar dos veces,
migraciones en un orden que hacía fallar `migrate`, reglas con cuantificadores
que borraban `messages()` y `handle()` de la request, y relaciones que apuntaban a
`App\Models` dentro de un paquete.

### De 6.x a 7.0

El módulo de interfaz deja de depender de UIkit, Tailwind y Font Awesome, que
nadie declaraba. **Un módulo de la 6.x no se actualiza cambiando el número**: su
aspecto venía de la aplicación.

**El aspecto.** Regenera el módulo. La aplicación importa una vez el tema de cada
módulo (`import 'acme-catalogo/src/theme.js'`) y deja de necesitar UIkit y Font
Awesome para él. Si la aplicación tenía su propio sistema visual, se conecta con
`setTheme`. Los mixins globales `inputClass` y `buttonClass` ya no se usan.

**Los iconos.** Se piden por nombre semántico. **Traduce los Resources escritos a
mano**: `'icon' => 'fa-eye'` pasa a `'icon' => 'show'`, `fa-edit` a `edit`,
`fa-trash` a `delete`, `fa-plus` a `plus`.

**Componentes globales.** `BreadcrumbsComponent` y `DropdownButtonComponent` ya
no hacen falta: el módulo genera su propio `Breadcrumbs` y su `ActionMenu`. Quita
su registro global si sólo existía para los módulos generados.

**La línea base.** Ejecuta `larapack:audit` y corrige lo que diga: versiones de
PHP, `illuminate/*`, testbench y dependencias internas.

**La publicación.** `bump-patch.yml` publicaba en cada push sin correr tests.
Sustitúyelo por `tests.yml` y `release.yml` (ver
[La CI y la publicación](#la-ci-y-la-publicación)) y crea un archivo `VERSION`
con la versión actual. Para ver los archivos exactos,
`larapack:new vendor/x --dry-run` en un directorio vacío.

### De 7.0 a 7.1

No hay que cambiar nada: un `laraimport.json` que no declara las claves nuevas
genera exactamente lo mismo.

`routes`, `immutable` y `secret` son nuevas. Si en algún modelo **borraste a mano
acciones que sobraban**, `larapack:verify` ahora lo señala como
`route-not-declared` y falla la CI. Declara la forma real en el JSON y regenera
con `--force`:

```json
{ "name": "AuditEvent", "immutable": true, "routes": { "only": ["policies", "index", "show"] } }
```

`immutable-write` y `secret-exposed` también son errores nuevos.

### De 7.1 a 7.2

`larapack:audit` trata como error un `composer.json` que fija su propia
`version`, porque Composer descarta cada tag que no coincide con ella. **Borra la
línea `"version"` del `composer.json`** de tus paquetes y publica desde `VERSION`.

### De 7.2 a 7.3

La 7.3 corrige lo que impedía que un paquete generado arrancara y funcionara
instalado en una aplicación.

**Regenera con `--force`** y, si los editaste, aplica a mano:

| Archivo | Qué cambió |
|---|---|
| `AppServiceProvider` | Carga migraciones, vistas y configuración (venían comentadas). |
| Políticas | Tipan `Authenticatable` en lugar de `App\Models\User`, consultan `isAdmin()` sólo si existe y apagan `forceDelete` en `$exceptAbilities`. |
| `Traits/Storage` | `forceDeleteModel()` ya no hace `abort(403)`. |
| Factories | Dan valor a todos los tipos de columna y usan la factory del modelo relacionado. |
| `tests/TestCase.php` | Registra el paquete, migra, autentica con Sanctum y abre la autorización. En un paquete se genera además `tests/User.php`. |
| Tests de endpoints | Llaman a las rutas por nombre. |

**Rutas del módulo Vue.** Declaran `meta: { auth: true }` y ya no importan
`@router/middleware`. **Si la aplicación protegía las rutas leyendo
`meta.middleware`, pasa a leer `meta.auth`**; en React, `handle.auth`.

**En la aplicación.** Asegura `laravel/sanctum` y
`JsonResource::withoutWrapping()`.

### De 7.3 a 7.4

Nada se rompe.

- Para empezar un paquete, `larapack:new` en lugar de copiar otro.
- Si ejecutaste `larapack:providers` más de una vez, revisa
  `extra.laravel.providers` del `composer.json` y quita los proveedores
  duplicados.
- Si el proyecto tiene `phpunit.xml.dist` y también un `phpunit.xml` generado,
  borra el segundo: tapaba al primero.

### De 7.4 a 7.5

Los módulos generados pasan a usarse como una aplicación de escritorio. Es la
actualización con más cambios en el front desde la 7.0.

**Dependencias npm** de `resources/<ui>/package.json`:

| Paquete | Versión |
|---|---|
| `innoboxrr-form-core` | `^2.6.0` |
| `innoboxrr-form-elements` (Vue) | `^6.4.0` |
| `innoboxrr-react-form-elements` (React) | `^3.4.0` |
| `innoboxrr-vue-datatable` (Vue) | `^3.0.0` |
| `innoboxrr-react-datatable` (React) | `^3.0.0` |

**En la aplicación, una vez:** monta `ToastRegionComponent` y
`ConfirmHostComponent` (ver [Montar un paquete](#frontend-con-vue)). Sin ellos los
avisos no se ven y la confirmación cae en `window.confirm`.

**Regenera con `--force`** y, si los editaste, aplica a mano:

| Archivo | Qué cambió |
|---|---|
| `views/AdminView` | Deja la tabla montada, abre el alta en un drawer y recarga con `refresh()` en lugar de remontar la tabla con una `key`. Añade la paleta de comandos. |
| `views/CreateView`, `views/EditView` | Ya no navegan ni pintan migas: avisan con `updateData` (Vue) u `onUpdateData` (React). **Un formulario editado que navegue al guardar deja el drawer abierto sobre otra página.** |
| `views/ShowView` | Esqueleto mientras llega el registro y edición en drawer. |
| `widgets/DataTable` | Expone `refresh()` (Vue, `defineExpose`) o acepta `ref` (React). |
| `widgets/ModelCard`, `widgets/ModelProfile` | Clases del tema en lugar de Tailwind; borrar avisa y no trata una cancelación como error. |
| `src/components/ActionMenu` | Menú desplegable sobre `MenuComponent`; los elementos admiten `icon`. |
| `models/<kebab>/index.js` | Borrar, borrar para siempre y exportar preguntan con `confirmAction` en lugar de SweetAlert. Cancelar sigue rechazando con `RequestCancelledError`. |

**Si tu código usa los datatables directamente**, la 3.0 no es compatible con la
2.x:

- Ya no existen `NavDropdownComponent`, `IconRouteComponent`,
  `IconLinkComponent`, `DisabledLinkComponent` ni `PaginationComponent` (Vue), ni
  `ActionListComponent` y `DatatableIcon` (React).
- `DataTableComponent` recibe la instancia de TanStack Table.
- Los textos por defecto están en español y se cambian con el prop `labels`.
- Un fallo ya no se reintenta en silencio: se ve y se reintenta a mano.
- Las pruebas en jsdom que abran un menú tienen que simular `@floating-ui/dom`.

Los README de `innoboxrr-vue-datatable` y `innoboxrr-react-datatable` tienen su
propia sección «De 2.x a 3.0».

**Comprueba en el navegador**: crear desde la tabla, editar desde el detalle,
borrar con confirmación, un borrado que falla y la paleta con Ctrl+K.

### De 7.5.0 a 7.5.1

No hay que regenerar. Sube tres dependencias en `resources/<ui>/package.json`:

| Paquete | Versión |
|---|---|
| `innoboxrr-form-core` | `^2.7.0` |
| `innoboxrr-form-elements` (Vue) | `^6.5.0` |
| `innoboxrr-react-form-elements` (React) | `^3.5.0` |

Con ellas los formularios dejan de necesitar Tailwind en la aplicación: las
etiquetas, el grupo repetible, la zona de archivos, las casillas del código y el
avatar salen del tema. Si tu aplicación sobrescribía esos campos con clases de
Tailwind, revisa que sigan viéndose como esperas en claro y en oscuro.

---

## Referencia de comandos

```
larapack:new                     Crea un paquete nuevo listo para generar, probar y publicar.
larapack:import                  Genera varios modelos desde un laraimport.
larapack:validate                Valida un laraimport sin generar nada.
larapack:schema                  Emite el esquema del laraimport.
larapack:verify                  Busca desviaciones respecto al manifiesto.
larapack:audit                   Comprueba la línea base del ecosistema.
larapack:skill                   Instala las instrucciones para un agente.

larapack:full-model              Crea un entorno completo de modelo.
larapack:remove-full-model       Elimina todas las entidades de un modelo.
larapack:model-view              Crea el módulo Vue del modelo.
larapack:react-view              Crea el módulo React del modelo.

larapack:app-service-provider    Crea un proveedor de servicio de aplicación.
larapack:auth-service-provider   Crea un proveedor de servicio de autenticación.
larapack:config                  Crea un archivo de configuración.
larapack:controller              Crea un nuevo controlador.
larapack:event-service-provider  Crea un proveedor de servicio de eventos.
larapack:events                  Crea eventos y listeners para el modelo.
larapack:excel                   Crea una clase de Excel.
larapack:export                  Crea una clase de exportación.
larapack:export-notification     Crea una clase de notificación de exportación.
larapack:factory                 Crea una nueva fábrica.
larapack:filters                 Crea una clase de filtros.
larapack:migration               Crea una nueva migración.
larapack:model                   Crea un nuevo modelo.
larapack:model-traits            Crea traits para el modelo.
larapack:observer                Crea un observer.
larapack:policy                  Crea una nueva política.
larapack:providers               Crea todos los proveedores de servicio.
larapack:requests                Crea una clase de requests.
larapack:resource                Crea una nueva clase de recurso.
larapack:route                   Crea una nueva ruta.
larapack:route-service-provider  Crea un proveedor de servicio de rutas.
larapack:test                    Crea una nueva clase de test.
```

Opciones comunes: `--root=<ruta>` en todos; `--force` y `--dry-run` en los
generadores; `--format=json` en todos.

---

## Código generado (Laravel 13)

- **Modelos**: los casts se declaran con el método `casts(): array`, y el
  observer, la política y la factoría se enlazan con los atributos
  `#[ObservedBy]`, `#[UsePolicy]` y `#[UseFactory]` en lugar de descubrirse por
  reflexión.
- **Controladores**: implementan `Illuminate\Routing\Controllers\HasMiddleware`
  con un método estático `middleware()`, en vez de `$this->middleware()` en el
  constructor. El controlador base ya no extiende `Illuminate\Routing\Controller`.
- **Rutas**: usan callables `[FooController::class, 'accion']`, así que el
  `RouteServiceProvider` ya no declara un namespace de controladores.
- **Proveedores**: extienden `Illuminate\Support\ServiceProvider` directamente.
  El `EventServiceProvider` sólo descubre eventos y listeners.
- **Migraciones, requests, políticas y recursos**: firmas con tipos de retorno
  (`up(): void`, `rules(): array`, `toArray(Request $request): array`…).
- **`phpunit.xml`**: esquema de PHPUnit 12/13 con `cacheDirectory` y las variables
  de entorno de Laravel 13 (`CACHE_STORE`, `APP_MAINTENANCE_DRIVER`).

---

## Apoya nuestro trabajo

Desarrollamos estos paquetes para la comunidad de Laravel con el objetivo de
hacer la vida de los desarrolladores más fácil. Si te sientes agradecido por
nuestro trabajo y te gustaría apoyarnos, considera inscribirte en uno de nuestros
cursos de pago.

Te recomendamos especialmente el curso
[Desarrollo de Paquetes en Laravel](https://laravelers.com/course/275).

Gracias por tu apoyo.
