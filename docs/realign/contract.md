# El contrato ampliado (9.4.0)

Parte de [`larapack:realign`](README.md). Para adoptar un proyecto real sin
cambiarlo, el contrato tiene que poder **describir lo que ya hay**: una columna
de 20 caracteres, un decimal de 16,4, un índice compuesto, la tabla que ya
existe con otro nombre, la ruta que ya llama el front. Sin estas claves,
describir brandai con un `laraimport.json` perdía 1 633 longitudes, 104
precisiones, 816 índices y todos sus `morphTo`, y expediente no podía declarar
un endpoint sin cambiar el nombre de ruta que llaman 339 llamadas de su front.

**Todo es opcional.** Lo que no se declara se genera exactamente como en 9.3.1:
las instantáneas de lo generado no cambiaron. El esquema completo está en
`schema/laraimport.schema.json` (`larapack:schema` lo imprime).

## Índice

- [Columnas: medida, precisión y `useCurrent`](#columnas-medida-precisión-y-usecurrent)
- [Índices](#índices)
- [La tabla de un modelo](#la-tabla-de-un-modelo)
- [Relaciones](#relaciones)
- [Rutas: conservar el nombre y la URI](#rutas-conservar-el-nombre-y-la-uri)
- [Llaves foráneas: `on_delete` y `on_update`](#llaves-foráneas-on_delete-y-on_update)
- [Lo que se relajó al validar](#lo-que-se-relajó-al-validar)
- [Un contrato que no valida](#un-contrato-que-no-valida)
- [Lo que el contrato todavía no puede decir](#lo-que-el-contrato-todavía-no-puede-decir)

## Columnas: medida, precisión y `useCurrent`

```json
{ "name": "code", "type": "string", "length": 20 }
{ "name": "amount", "type": "decimal", "precision": 16, "scale": 4 }
{ "name": "measured_at", "type": "timestamp", "use_current": true }
```

| Clave | Dónde | Qué genera | Lo que rechaza la validación |
|---|---|---|---|
| `length` | prop `string`, `char` | `$table->string('code', 20)` | En otro tipo. |
| `precision`, `scale` | prop `decimal` | `$table->decimal('amount', 16, 4)` | En otro tipo; `scale` sin `precision` o mayor que ella. |
| `use_current` | prop `timestamp`, `timestampTz`, `dateTime`, `dateTimeTz` | `->useCurrent()` | En otro tipo; junto a un `default`. |

Van a la migración de creación. En una tabla que ya existe, el siguiente
`larapack:import` compara lo declarado con lo que dicen sus migraciones: si
declaras una medida distinta de la que tiene, escribe una alteración con
`->change()`. Sin medida declarada, la que tenga la columna no cuenta. Igual
con `use_current`: declararlo en una columna que no lo tiene escribe
`->useCurrent()->change()`; que la columna lo tenga y el contrato calle no
cuenta (no se quita). **Al
adoptar, declara la medida que ya tiene la base** (es lo que hace `infer`), o
el import propondrá cambiarla ([risks.md](risks.md#migraciones-y-datos)).

## Índices

```json
"indexes": [
  { "columns": ["clinic_id", "patient_id"] },
  { "columns": ["email"], "unique": true, "name": "users_email_unique" }
]
```

En el modelo. Simples o compuestos, únicos o no, con nombre o sin él. Van en
la migración de creación y, **en una tabla que ya existe, en una alteración con
los que falten**: nunca se quita un índice que no esté declarado. El lector de
migraciones guarda los índices de cada tabla (`$table->index()`, `->unique()`
en una columna, `dropIndex`, `renameIndex`, columnas quitadas o renombradas),
así que un índice escrito a mano en una migración no se vuelve a añadir.

## La tabla de un modelo

```json
{ "name": "StaffMember", "table": "personal" }
```

La tabla tal cual, **sin `table_prefix`**, para un modelo que ya tiene la suya.
Va al `$table` del modelo, a `Schema::create` y al nombre de la migración; una
`foreignId` hacia él lo nombra igual en `constraint`. Dos modelos con la misma
tabla son un error.

## Relaciones

- **`morphTo` sin `related`**: `return $this->morphTo();`, sin `use`. Las demás
  relaciones siguen exigiendo `related`.
- **El nombre puede ir en camelCase** (`practitionerLocations`), como el
  método que ya existe en el modelo, en `load_relations` y `load_counts`. Así
  adoptar no renombra un método que el código ya llama.

## Rutas: conservar el nombre y la URI

```json
"endpoints": [
  { "name": "approve", "method": "post", "uri": "{campaignId}/approve_now", "route_name": "campaigns.approve" }
]
```

- **`route_name`** en un endpoint de un modelo o de `controllers[]`: el nombre
  de la ruta, relativo al grupo de su archivo (`api.<ns>.<archivo>.`). Sin él,
  el nombre es el que genera LaraPack. Un `route_name` repetido, igual al que
  deriva otro endpoint del mismo archivo (vaya antes o después) o al de una
  acción CRUD que exista, es un error.
- **La `uri` admite** segmentos con `_`, mayúsculas o `.` y parámetros en
  camelCase (`{campaignId}`), para conservar la URI que ya usa el front.

Es la regla de todo el realineamiento: **un nombre de ruta o una URI no cambian
sin que el contrato lo declare**. Declarar `route_name` y `uri` es cómo se
adopta un endpoint escrito a mano sin romper a quien lo llama
(`declare-handwritten-controller`, [guided.md](guided.md)).

## Llaves foráneas: `on_delete` y `on_update`

```json
{ "name": "clinic_id", "type": "foreignId", "constraint": "clinics", "on_delete": "cascade", "on_update": "cascade" }
```

Existían antes de 9.4.0. Desde la 8.0, lo que no se declara **restringe**
(`restrict`). Un proyecto generado con 7.x tiene migraciones en cascada y un
contrato que calla: `pin-foreign-keys` ([apply.md](apply.md#pin-foreign-keys))
escribe en el contrato lo que hacen hoy las migraciones, para que el contrato
diga lo que hace producción (de la tabla del modelo: la de `table`, tal cual,
o la deducida con el prefijo). Cambiar esa acción de verdad es una decisión
aparte, con su migración.

## Lo que se relajó al validar

Para poder adoptar, dos reglas dejaron de rechazar lo legítimo:

- **Una URI CRUD sólo choca con la acción que existe**: un `controllers[]` no
  tiene ninguna, y un modelo sólo las de sus `routes`. Antes `…/create` de un
  controlador sin modelo se rechazaba siempre.
- **Un pivote o unas metas cuya tabla ya crea otra migración no se vuelven a
  crear**, como ya pasaba con la de un modelo: `import` las salta con «no la
  generó LaraPack».

Y 9.3.1 ya admitía un paquete sin modelos (`"models": []`) si declara
controladores.

## Un contrato que no valida

El diagnóstico no puede regenerar un ámbito cuyo contrato no valida con esta
versión: el plan pide la decisión `contract-invalid` (corregir el contrato o
retirarlo), con el playbook `decide-package-without-models` para el caso más
común. Su comprobación (`check`) es que `larapack:validate` pase:

```bash
larapack:validate --format=json
larapack:validate --strict           # los avisos también fallan
```

## Lo que el contrato todavía no puede decir

Se dice para que nadie lo busque. Lo que `infer` encuentra y no cabe sale en su
informe como `lost`, con el archivo y la columna ([infer.md](infer.md)):

- Los modificadores de columna `comment`, `charset`, `collation`,
  `virtualAs`, `storedAs`, `generatedAs`, `autoIncrement`,
  `useCurrentOnUpdate`, `invisible`, `always`, `srid`, `from` y
  `startingValue`.
- Un default que es una expresión (`DB::raw(…)`), y una longitud o una
  precisión que no son un entero literal.
- Un tipo de columna fuera de `columnType` (los `unsigned*` de coma flotante y
  los `*Increments` pequeños se declaran con su tipo más cercano, y se dice).
- Una llave foránea que no es `foreignId` hacia `id` (hacia otra columna, o
  con otro método).
- Índices que no son `index` ni `unique` (texto completo, espaciales) o sobre
  columnas que el contrato no declara.
- Una relación cuyo nombre no cabe en el esquema o hacia un modelo que no se
  conoce.
- En las rutas: un closure, `Route::match`, `any`, `resource`, una ruta
  repetida y un middleware que es una clase.

Lo que no cabe en el contrato se queda como código propio (y, si `verify` lo
marca, con una [excepción](exceptions.md)); en la base sigue estando, pero el
contrato no lo vigila.
