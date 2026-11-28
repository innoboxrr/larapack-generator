# Inferir el contrato: `larapack:realign infer`

Esta guía es la parte de [`larapack:realign`](README.md) que **adopta código
existente**: escribe un `laraimport.json` BORRADOR desde las migraciones, los
modelos y las rutas que ya hay, para que un proyecto que nunca tuvo contrato
(brandai) o que tiene código escrito a mano al lado del generado (expediente,
school-management) quede descrito sin reconstruir nada.

Es para una persona o un agente que llega sin contexto: dice cómo correrla,
qué escribe y qué no, cómo leer su informe, qué decisiones deja abiertas y qué
riesgos tiene adoptar el borrador.

## Índice

- [Cuándo se usa](#cuándo-se-usa)
- [Lo que nunca hace](#lo-que-nunca-hace)
- [Correrla](#correrla)
- [Qué se infiere y de dónde](#qué-se-infiere-y-de-dónde)
- [Las rutas: sólo lo que se conserva](#las-rutas-sólo-lo-que-se-conserva)
- [El informe](#el-informe)
- [Revisar el borrador y adoptarlo](#revisar-el-borrador-y-adoptarlo)
- [Lo que el contrato aprendió para poder adoptar (9.4.0)](#lo-que-el-contrato-aprendió-para-poder-adoptar-940)
- [Precisión medida en proyectos reales](#precisión-medida-en-proyectos-reales)
- [Limitaciones y riesgos](#limitaciones-y-riesgos)

## Cuándo se usa

`larapack:realign plan` deja tres tipos de paso que se resuelven con `infer`
(los tres son decisiones: el plan no los da por hechos hasta que se decide):

| Paso | Qué pasa | Qué escribe `infer` |
|---|---|---|
| `no-contract` | El ámbito no tiene `laraimport.json`. | Un borrador entero, sólo de ese ámbito: `no-contract@.` es la raíz y cada paquete tiene el suyo (`no-contract@packages/x`). Para todos a la vez, `infer` sin ids. |
| `unmanaged-models` | Hay modelos que el contrato no declara. | El contrato que ya existe **más** esos modelos. |
| `unmanaged-http` | Hay controladores y rutas escritos a mano. | El contrato que ya existe **más** los endpoints cuyas rutas se pueden conservar. |

Varios pasos del mismo ámbito en una sola orden van a **un** borrador.

## Lo que nunca hace

- **No toca código.** Ni una migración, ni un modelo, ni una ruta, ni un
  controlador. Sólo lee.
- **No sobrescribe `laraimport.json`.** El borrador es
  `laraimport.inferred.json`, junto al contrato. `--option write=contract`
  escribe directamente `laraimport.json` **sólo si no existe**.
- **No inventa.** Lo que no está en el código (formularios, etiquetas,
  `display`, `routes`, reglas, habilidades) no se escribe; se lista en
  `not_inferred`. Un default que es una expresión, una columna que pone un
  bucle o una ruta que cambiaría de nombre tampoco: se dice dónde y por qué.
- **No decide por ti.** Dos modelos con el mismo nombre, un ciclo de llaves,
  una tabla sin modelo, una ruta sin `auth` a la vista: van a `decisions` con
  sus opciones. Nunca hacen fallar la orden.

Como toda operación que escribe, sin `--dry-run` exige el árbol git limpio
(o `--allow-dirty`), para que el borrador se pueda descartar con git.

## Correrla

```bash
vendor/bin/builder larapack:realign diagnose
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign status            # busca no-contract@…, unmanaged-models@…, unmanaged-http@…
vendor/bin/builder larapack:realign infer no-contract@. --dry-run
vendor/bin/builder larapack:realign infer no-contract@.
```

Opciones (`--option clave=valor`, repetible):

| Opción | Valores | Efecto |
|---|---|---|
| `write` | `draft` (por omisión), `contract` | `contract` escribe `laraimport.json` si no existe. |
| `http` | `1` (por omisión), `0` | Inferir también controladores y rutas. |

Con `--format=json` cada ámbito sale en `fixed` con `file` (el borrador) y
`report` (su informe). Un borrador que no pasa `larapack:validate` sale además
en `skipped` y la orden termina con error.

Con `--dry-run` no se escribe nada, tampoco el informe: `report` es `null` y
el informe entero va en el resultado, en `infer` (el mismo documento que
[El informe](#el-informe)). En texto, una nota por ámbito resume las
decisiones por tipo, con cuántas son y hasta tres ejemplos de cada una
(`route-not-preservable 913 (api.v1.posts.index, …)`). Es la forma de saber,
sin tocar nada, si las rutas escritas a mano se conservarían al declararlas:
lo que recomienda el [brief](owner.md) para decidir `unmanaged-http`.

## Qué se infiere y de dónde

**El esquema** sale de TODAS las migraciones que corren contra la base: las del
ámbito (`database/migrations` y lo que sus proveedores cargan con
`loadMigrationsFrom(__DIR__.'/…')`) y las de los demás ámbitos del proyecto,
porque comparten base (en brandai, la tabla de un modelo de un paquete puede
crearla otro). Se leen con el mismo lector con el que el generador decide qué
alterar (`SchemaHistory`): por orden de nombre, aplicando `create`, `table`,
`drop`, `rename`, `change`, llaves e índices.

| Del código | Al laraimport |
|---|---|
| `$table->string('code', 20)` | `"type": "string", "length": 20` |
| `$table->decimal('amount', 16, 4)` | `"precision": 16, "scale": 4` |
| `->nullable()`, `->default(…)` literal | `nullable`, `default` |
| `->useCurrent()` | `"use_current": true` |
| `foreignId()->constrained()->cascadeOnDelete()` o `foreign()->references('id')->on()` | `foreignId`, `constraint`, `on_delete`, `on_update` (siempre escritas: un contrato sin ellas pide `restrict`) |
| `morphs('x')` / `x_type` + `x_id` | `"type": "morphs"` (o `nullableMorphs`) |
| `enum('status', ['a', 'b'])` | `"type": "enum"` y `enum` con los valores como etiquetas |
| `$table->index([...])`, `->unique()`, `unique([...], 'nombre')` | `indexes` del modelo (el nombre sólo si no es el de Laravel) |
| `protected $table` distinto del deducido | `table` (o `table_prefix` si la mitad o más comparten prefijo) |
| `$fillable` / `$guarded` | `fillable: false` en lo no asignable |
| `$creatable`, `$updatable`, `$export_cols` (de LaraPack) | `creatable`, `updatable`, `exports_cols` |
| `$hidden` | `secret: true` (no la contraseña de un usuario `authenticatable`) |
| `casts()` o `$casts` | `cast` |
| `return $this->belongsTo(X::class)` en el modelo **o en sus traits** de `Models/Traits` | `load_relations` (con `namespace` si X no es del ámbito) |
| `return $this->morphTo()` | `{"type": "morphTo", "name": …}` sin `related` |
| Extiende `Authenticatable` | `authenticatable: true` |
| Modelo `XMeta` sobre `x_metas` | `metas: true` en X |
| Tabla sin modelo con dos llaves o más | `pivots[]` |

**Confianza.** Cada prop dice la suya en `provenance`: `high` si se leyó sin
dudas; `medium` si algo de ella no se pudo leer (otra migración la toca dentro
de un `if`, el default es una expresión, el tipo se acercó), o si se juntaron
dos columnas (`morphs`).

## Las rutas: sólo lo que se conserva

El front llama a las rutas por nombre o por URL, y declararlas en el contrato
las mueve al archivo `routes/api/models/<x>.php` de LaraPack, que se carga con
el prefijo `api/<namespace>/<x>` y el nombre `api.<namespace>.<x>.`. Por eso
**una ruta sólo entra al borrador si su URI y su nombre completos ya empiezan
así**: `<x>` es el controlador (o el modelo, si se llama como uno), y lo que
queda es su `uri` y su `route_name`.

Para saber el nombre completo de una ruta hay que saber con qué prefijo se
carga su archivo, y eso se lee de donde se carga: un proveedor
(`Route::prefix('api/app')->as('api.app.')->group(__DIR__.'/../../routes/api/clinic.php')`,
también con la ruta en una variable), `bootstrap/app.php` (`api:` lleva `api`)
o la convención de LaraPack. Un archivo cuyo prefijo no se encuentra no se
adopta (`route-file-prefix`).

| Decisión | Por qué |
|---|---|
| `route-not-preservable` | La ruta no empieza por `api/<ns>/<x>/` y `api.<ns>.<x>.`: declarada cambiaría de URI o de nombre. Se queda como código propio (lo vigila `controller-shape`) o se cambian ruta y front a la vez, a propósito. |
| `route-file-prefix` | No se sabe con qué prefijo se carga el archivo. |
| `route-auth-unknown` | No hay `auth` a la vista (la sesión puede pedirla el controlador). Se declara **con sesión** —cerrar por error es un 401 que se ve; abrir por error es un agujero que no— y se pregunta si es pública. |

En un endpoint de **modelo**, la habilidad de la política es por omisión su
nombre, que nace **denegando**: está en `not_inferred` para que se decida, o la
ruta se cerrará al adoptar. En un `controllers[]` se declara
`"authorize": "authenticated"`, lo más parecido a lo que hacía la ruta.

Lo que no se infiere de las rutas: una ruta con closure, `Route::match`, `any`
y los `resource` (van a `lost`), y un middleware que es una clase (también).

## El informe

`.larapack/realign/infer/<ámbito>.json` (`app` para la raíz, la ruta con
guiones para un paquete). Versión de formato `1`:

```json
{
  "format_version": 1,
  "generated_at": "2026-11-27T22:00:00+00:00",
  "scope": ".",
  "steps": ["no-contract@."],
  "step_types": ["no-contract"],
  "draft": "laraimport.inferred.json",
  "contract_existed": false,
  "added": [],
  "stats": {"models": 62, "props": 714, "relations": 137, "indexes": 176, "pivots": 4, "controllers": 0, "endpoints": 0},
  "validation": {"ok": true, "errors": 0, "warnings": 3, "findings": []},
  "decisions": [{"type": "foreign-key-cycle", "subject": "…", "why": "…", "options": [{"key": "…", "label": "…"}]}],
  "not_inferred": [{"model": "Site", "key": "display", "reason": "…"}],
  "lost": [{"model": "Workspace", "column": "settings", "file": "…_create_workspaces_table.php", "what": "el default DB::raw(…), que es una expresión"}],
  "out_of_scope": [{"table": "jobs", "reason": "tabla de Laravel o de un paquete del framework"}],
  "provenance": [{"model": "Site", "prop": "subdomain", "key": "schema", "source": "2026_02_24_000016_create_sites_table.php", "confidence": "high"}],
  "comparison": null
}
```

- `added`: con contrato, qué entradas se añadieron (`models/X`,
  `controllers/Y`, `models/X/endpoints/z`).
- `comparison`: con contrato, lo inferido frente a lo declarado
  (`ContractComparison`): por modelo, las props que faltan o sobran y cada
  diferencia de `type`, `nullable`, `constraint` (la tabla real, con prefijo)
  y `default`; y los totales en `stats`. **Una diferencia no es un error del
  inferidor**: casi siempre es el contrato el que va por delante del esquema
  (declara `nullable` y ninguna migración lo aplicó) o al revés. Es una lista
  de cosas que revisar.
- Tipos de decisión: `duplicate-model-name`, `foreign-key-cycle`,
  `table-without-model`, `model-without-table`, `model-without-columns`,
  `primary-key`, `route-not-preservable`, `route-file-prefix`,
  `route-auth-unknown`.

## Revisar el borrador y adoptarlo

1. Lee `decisions` y resuelve las que bloquean (un nombre de modelo repetido
   deja el otro fuera; un ciclo de llaves sin columna `nullable` no se puede
   insertar).
2. Completa lo de `not_inferred` que quieras que el generador haga (formularios,
   `display`, `routes.except` si no hay borrado lógico, la `ability` de cada
   endpoint de modelo).
3. Revisa `lost`: lo que la base tiene y el contrato no vigila.
4. `larapack:validate laraimport.inferred.json --react`.
5. Renómbralo a `laraimport.json` (o fusiona a mano lo añadido).
6. **`larapack:import --dry-run`** antes que nada: una tabla que ya crea una
   migración escrita a mano no se vuelve a crear (modelo, pivote o metas: la
   migración sale `skipped` con «no la generó LaraPack»), y el esquema inferido
   coincide con el de las migraciones, así que no debería salir ninguna
   alteración. Lo que sí sale son los archivos que el generador escribiría
   (modelos, requests, controladores…): un modelo escrito a mano que ya existe
   se conserva si no lo generó LaraPack, pero **comprueba la lista**; adoptar
   archivos existentes al manifiesto es `larapack:realign adopt`.
7. `larapack:verify` y `larapack:realign check <id>`.

## Lo que el contrato aprendió para poder adoptar (9.4.0)

Sin esto el borrador perdía, en brandai, 1 633 longitudes, 104 precisiones, 816
índices y todos los `morphTo`. Todo es opcional: **lo que no se declara se
genera exactamente como en 9.3.1**.

| Clave | Dónde | Qué genera |
|---|---|---|
| `length` | prop `string`, `char` | `$table->string('code', 20)` |
| `precision`, `scale` | prop `decimal` | `$table->decimal('amount', 16, 4)`; `scale` exige `precision` y no la pasa |
| `use_current` | prop `timestamp`, `timestampTz`, `dateTime`, `dateTimeTz` | `->useCurrent()`; no junto a `default` |
| `indexes[]` | modelo: `{columns, unique?, name?}` | `$table->index([...])` / `unique(...)` en la creación; en una tabla que existe, una alteración con **los que falten** (nunca quita uno) |
| `table` | modelo | El `$table` del modelo, `Schema::create` y el nombre de la migración, sin `table_prefix`. Una `foreignId` hacia él lo nombra igual en `constraint`. |
| `morphTo` sin `related` | `load_relations` | `return $this->morphTo();` sin `use` |
| `name` de relación en camelCase | `load_relations`, `load_counts` | Se conserva el método que ya existe (`practitionerLocations`). |
| `route_name` | endpoint y endpoint de `controllers[]` | El nombre de la ruta, relativo al grupo del archivo (`api.<ns>.<archivo>.`). |
| `uri` más permisiva | endpoint y endpoint de `controllers[]` | Segmentos con `_`, mayúsculas o `.` y parámetros en camelCase (`{campaignId}`), para conservar la URI que ya existe. |

Y lo que cambió de reglas para poder adoptar:

- Una URI CRUD (`create`, `update`…) sólo choca con la acción CRUD que **existe**:
  un `controllers[]` no tiene ninguna, y un modelo sólo las de sus `routes`.
- Una creación de pivote o de metas sobre una tabla que ya crea otra migración
  se salta, como ya se hacía con la de un modelo.
- El lector de migraciones guarda los índices de cada tabla (antes los
  ignoraba): el generador no añade uno que ya existe.

## Precisión medida en proyectos reales

Medida el 2026-11-27 sobre copias de solo lectura (los originales no se
tocaron), comparando lo inferido con el `laraimport.json` que escribieron sus
autores. «Encontradas» son las props declaradas que el inferidor también
encontró; el resto de columnas son sobre las encontradas.

| Proyecto | Modelos | Props | Encontradas | Tipo | Nullable | FK | Default | Relaciones |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| school-management (raíz + 81 paquetes) | 689/691 | 7 753 | 7 726 (99,7 %) | 7 725 | 7 719 | 7 726 | 7 726 | — (no declara) |
| expediente | 70/70 | 813 | 788 (96,9 %) | 788 | 787 | 788 | 788 | 190/190 |

- Las 25 props que no se encuentran en expediente las añade una migración **en
  un bucle** con `Schema::hasColumn()`: no se puede saber sin ejecutarla, y el
  informe lo dice con su archivo.
- Las diferencias de `nullable` son el contrato por delante del esquema (por
  ejemplo, una llave declarada `nullable` cuya alteración se dejó «escríbela a
  mano» y nunca se escribió).
- Sobre un proyecto generado por LaraPack (las pruebas), lo inferido coincide
  al 100 % con su contrato.
- Rutas escritas a mano: en school-management se conservan 1 743 como
  endpoints (45 no se pueden conservar, 105 sin `auth` a la vista quedan como
  pregunta); en expediente, 252 (112 no se pueden conservar: viven bajo
  `api/app/` sin un segmento propio, como `api.app.panel`).
- Los 83 borradores de school-management, el de expediente y los 20 de
  brandai pasan `larapack:validate`. La inferencia tarda menos de 10 s por
  ámbito con las migraciones de todo el ecosistema.

brandai (sin contrato): **20 de 20 zonas** (la raíz y 19 paquetes) dan un
borrador que pasa `larapack:validate` y `--react`: 426 modelos, 5 201 props,
794 relaciones, 1 330 índices y 30 pivotes. Antes de 9.4.0 fallaban `app` (dos
ciclos de llaves) y `vibe` (modelos con el mismo nombre): los ciclos con una
llave `nullable` son un aviso y los nombres repetidos, una decisión. Ninguna
ruta de brandai se conserva (se sirven bajo `api/v1/...` con nombres que no
siguen el prefijo de LaraPack): 913 rutas quedan como decisión.

## Limitaciones y riesgos

- **Sólo lo que se lee sin ejecutar.** Una columna añadida en un bucle, una
  tabla en una variable, SQL crudo, una lista de modelo armada con
  `array_merge`: no se infiere y se dice dónde.
- **La interfaz no se infiere** (formularios, columnas de la tabla, etiquetas).
  Adoptar el borrador y regenerar el front daría pantallas sin campos hasta
  que se declaren.
- **La autorización no se infiere.** Un endpoint de modelo nace preguntando a
  una habilidad que niega; una ruta sin `auth` a la vista se declara con
  sesión. Las dos cosas se listan, y no decidirlas cambia el comportamiento.
- **`table_prefix` se deduce** si la mitad o más de las tablas lo comparten;
  las demás llevan `table`. Revisa que el prefijo sea el del paquete.
- **Los modelos a mano tienen lógica** (scopes, `boot`, traits propios) que el
  contrato no describe. `import` no los sobrescribe si no los generó LaraPack,
  pero regenerarlos con `--force` sí los reemplazaría: no se hace sin `adopt`.
- **Adoptar cambia lo que se genera, no la base**: las migraciones que existen
  no se reescriben. Lo que se pierde en `lost` (un comentario de columna, un
  índice FULLTEXT, una llave hacia una columna que no es `id`) sigue en la
  base, pero el contrato no lo vigila.
- **El ciclo de llaves** que el generador ordena con una migración aparte sólo
  vale si una de sus llaves admite `null`; si no, es una decisión del dominio.
