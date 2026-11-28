# Diagnosticar: `larapack:realign diagnose`

Parte de [`larapack:realign`](README.md). Dónde está el proyecto respecto de su
contrato, archivo por archivo, sin escribir una línea de código.

## Índice

- [Correrlo](#correrlo)
- [Qué hace](#qué-hace)
- [Leer la salida](#leer-la-salida)
- [Las clases de archivo](#las-clases-de-archivo)
- [Los huecos](#los-huecos)
- [El código sin gestionar](#el-código-sin-gestionar)
- [Lo que el contrato calla](#lo-que-el-contrato-calla)
- [Los hallazgos de verify](#los-hallazgos-de-verify)
- [Lo que está fuera de alcance](#lo-que-está-fuera-de-alcance)
- [El informe: `report.json`](#el-informe-reportjson)
- [Rendimiento y caché](#rendimiento-y-caché)
- [Limitaciones](#limitaciones)

## Correrlo

```bash
larapack:realign diagnose                       # todo el proyecto: la raíz y cada paquete local
larapack:realign diagnose --package=billing     # sólo un ámbito (repetible; `app` es el núcleo)
larapack:realign diagnose --jobs=8              # más regeneraciones a la vez
larapack:realign diagnose --no-cache            # regenera aunque nada haya cambiado
larapack:realign diagnose --format=json         # para un agente
```

No escribe código. Sólo escribe `.larapack/realign/report.json` y la caché
`.larapack/realign/cache/` (con su `.gitignore`). No acepta `--dry-run`: no hay
nada que simular, y pasarlo es un error.

## Qué hace

1. **Encuentra los ámbitos**: la raíz y cada paquete local (`packages/*`, y lo
   que declare el `composer.json` como repositorio `path`) con
   `laraimport.json` o `.larapack/manifest.json` propios.
2. **Regenera cada contrato aparte**, en `.larapack/realign/cache/`, con la
   versión **actual** del generador, la Pint del proyecto y su `pint.json`: un
   `larapack:import` en un directorio vacío con el `composer.json`, el
   `laraimport.json` y el `pint.json` del ámbito. Nunca regenera encima del
   proyecto. Los ámbitos se regeneran en procesos hijos, `--jobs` a la vez.
3. **Compara cada archivo** con lo regenerado y lo clasifica (abajo), mirando
   también el manifiesto: si su hash es el registrado (nadie lo editó), qué
   versión lo escribió, si está registrado con otra ruta.
4. **Lista el código sin gestionar**: controladores, rutas y modelos que ni el
   manifiesto ni el contrato conocen.
5. **Lee las migraciones** para encontrar lo que el contrato calla y la base
   hace (las llaves foráneas en cascada de 7.x).
6. **Corre `larapack:verify`** sobre todo el proyecto y clasifica cada
   hallazgo.
7. **Lo junta en el informe**, con cada cosa en una de las [cuatro
   categorías](README.md#las-cuatro-categorías).

Después, `larapack:realign plan` lo convierte en pasos ([plan.md](plan.md)).

## Leer la salida

La de texto, para una persona:

```
  app (núcleo): 412 archivos, 330 cumplen, 50 por adoptar, 20 con otro paso, 12 fuera de alcance
  billing: 96 archivos, 41 cumplen, 30 por adoptar, 15 con otro paso, 10 fuera de alcance
  legacy-reports: 0 archivos, 0 cumplen (sin contrato: no hay con qué comparar)
  «cumplen»: sin paso pendiente —idénticos o iguales salvo formato que el manifiesto ya registra con su hash, …

  Archivos por clase:
    identical            380  Byte a byte lo que generaría el contrato.
    format_only           55  …
    edited_outside        12  …

  Por categoría (archivos, código sin gestionar y hallazgos de verify):
    determinista          71
    guiado                14
    decisión               6
    fuera de alcance     130

  Informe: .larapack/realign/report.json. Siguiente: larapack:realign plan
```

- **«cumplen»** es lo que no tiene paso pendiente: `identical` y
  `format_only` que el manifiesto ya registra con su hash actual,
  `hole_edited` y lo que tiene una excepción aprobada. Un `identical` o
  `format_only` que el manifiesto no registra con su hash (formateado después
  de generar, o de otra versión) es ya lo que el contrato pide pero **todavía
  no cumple**: cuenta en **«por adoptar»** —el paso `adopt`, que sólo anota el
  manifiesto—. Por eso «cumplen» puede ser mucho menos que `identical` en la
  lista de clases: en una copia de un proyecto 7.x, 1 185 cumplían con 2 844
  `identical`, casi todos por adoptar.
- Los cuatro números suman los archivos del ámbito: **cumplen**, **por
  adoptar**, **con otro paso** (`regenerate`, `edited-outside`, `missing`…) y
  **fuera de alcance** (`migration_history`, `not_regenerated`).
- Un ámbito **«no se pudo regenerar»** dice por qué: casi siempre, un contrato
  que no valida con esta versión. Ese ámbito no se compara y el plan pide la
  decisión `contract-invalid`.
- **«sin contrato»** es un ámbito con manifiesto pero sin `laraimport.json`, o
  la raíz de un proyecto que nunca usó LaraPack: el plan pide `no-contract`.

La de `--format=json` lleva lo mismo con números: `{format_version, ok,
action, generator, generated_at, totals: {classes, categories, conforming,
files}, verify, scopes: [{package, path, contract, manifest, manifest_version,
regeneration, counts, total, conforming, breakdown}], report}`. `breakdown` es
`{conforming, to_adopt, other_steps, out_of_scope}`, el reparto de `total` de
la salida de texto. El detalle de cada archivo
está en el informe (`report`, la ruta de `report.json`).

## Las clases de archivo

| Clase | Qué es | Categoría | Paso |
|---|---|---|---|
| `identical` | Byte a byte lo que generaría el contrato. | cumple; si el manifiesto tiene otro hash o no lo conoce, `deterministic` | `adopt` |
| `format_only` | Igual salvo formato: en PHP, espacios, coma final, orden de imports, o igual tras pasar el archivo por la misma Pint; en lo demás (jsx, tsx, vue, js, ts, md), sólo finales de línea, indentación y espacio al final de línea. **Otros comentarios no son formato** (ver abajo). | igual que `identical` | `adopt` |
| `version_delta` | Nadie lo editó (su hash es el del manifiesto) y difiere: la plantilla cambió (`cause: generator`, escrito por una versión anterior o sin versión) o el contrato cambió (`cause: contract`). | `deterministic` | `regenerate` |
| `hole_edited` | Editado sólo dentro de sus [huecos](#los-huecos). Es lo correcto. También un PHP registrado y editado con el mismo código y **otros comentarios** (`comments: true`): son del usuario y se conservan. | cumple | — |
| `edited_outside` | Editado fuera de sus huecos. La entrada dice qué piezas (`members`: métodos, propiedades, `use`) y dónde está lo regenerado con qué compararlo (`regenerated`, relativo a la raíz del proyecto). | `guided` | `edited-outside` |
| `missing` | No está en disco. Si el contrato lo sigue pidiendo y se borró, `decision`; si es de un modelo retirado del contrato (`cause: retired`), el contrato no lo volvería a generar (`not_regenerated`) o es una migración registrada dos veces cuya otra copia sigue ahí (`superseded`), `deterministic` (`prune`); si el contrato lo pide y nunca se generó, `deterministic` (`regenerate`). | según el caso | `missing`, `prune`, `regenerate` |
| `renamed` | El manifiesto lo conoce con otra ruta: una migración renombrada a mano, o el mismo contenido en el mismo directorio. | `deterministic` | `relink` |
| `not_regenerated` | Lo registró el generador, pero `import` no lo regenera (proveedores, configuración, alteraciones, lo de un modelo que ya no está en el contrato). | `out_of_scope` | — |
| `migration_history` | Una migración generada que ya no es la que produciría hoy el contrato. **Las migraciones no se reescriben nunca.** | `out_of_scope` | — |
| `untracked` | En la ruta de un archivo generado hay uno que el manifiesto no conoce. Igual a lo regenerado: `adopt`; distinto: `decision`. | según el caso | `adopt`, `untracked-edited` |
| `absent` | Ni en disco, ni en el manifiesto, ni lo produce el contrato. Sólo aparece al comprobar un paso (`prune`). | — | — |

**Cómo se decide «igual salvo formato».** Primero byte a byte; después por
tokens (lo que no cambia el código: espacios, comas finales, el orden de los
`use`); después pasando el archivo del proyecto por la misma Pint con el mismo
`pint.json`. En lo que no es PHP no hay tokens: sólo cuentan como formato los
finales de línea, la indentación y el espacio al final de cada línea (y las
líneas en blanco del final). Un espacio dentro de una línea —en una cadena,
en el texto de JSX, entre dos operadores (`a - -b` frente a `a--b`)— o una
línea partida en dos es una edición: ante la duda, distinto, porque dar por
formato una edición la adopta y el siguiente `import --force` la borra. Lo que
reformatea Prettier más allá de la indentación sale, por eso, como editado.

**El mismo código con otros comentarios** (PHP) no es formato. Adoptarlo
dejaría que un `import --force` reemplace los comentarios y docblocks del
usuario por los de la plantilla, así que no se adopta: en un archivo registrado
y editado es `hole_edited` con `comments: true` —cumple, no da paso, y su hash
sigue siendo el de antes, así que `--force` no lo toca—; en lo que nadie editó
es `version_delta` (la plantilla cambió sus comentarios), en lo que el
manifiesto no conoce, `untracked` (lo decide alguien), y en una migración,
`migration_history` (no se reescribe nunca). Si se quieren los
comentarios de la plantilla, se borra el archivo y se vuelve a importar.

**La clasificación no depende de lo que escribe `adopt`.** `diagnose`,
`check` y la comprobación que hace `adopt` antes de anotar cada archivo usan la
misma clasificación (ScopeClassifier), y lo que `adopt` cambia —el hash del
manifiesto y la versión, que pasa a ser la actual— no cambia la clase: lo que
diagnose da por formato y adopt anota, `check` lo sigue viendo formato.

**Un archivo editado y de una versión anterior** no se puede separar en «lo
que editaste» y «lo que cambió la plantilla» sin las plantillas viejas: se
clasifica `edited_outside` con `cause: edited_or_generator`. Correr primero los
`fix` y `regenerate` reduce mucho esa lista ([apply.md](apply.md)).

## Los huecos

Donde va el código del proyecto, según la tabla «Dónde va tu código» del
README del paquete, escrita como datos en `src/Support/Realign/Holes.php`:

- **Archivo entero**: los traits del modelo, un Resource, un listener, un
  observer, una factoría, una política y una prueba.
- **Un método**: en una request, los ganchos de validación que la plantilla
  deja vacíos —`rules()`, `messages()`, `attributes()`,
  `prepareForValidation()` y `passedValidation()`; en `IndexRequest`, todos
  menos `prepareForValidation()`, que fuerza `managed`—; en la request de un
  endpoint, `prepareForValidation()` y `rules()`, y además `authorize()` y
  `handle()` si es de un endpoint sin modelo (nacen cerrado y con
  `abort(501)`: lo regenerado lo dice); en un ManagedFilter, `canView()`; en
  el AuthServiceProvider, `defineGates()`. Un hueco de método admite además
  `use` nuevos en la cabecera (el código del hueco trae los suyos).

La lista es la de las plantillas: `tests/Unit/HolesTest` la cruza con lo que
cada plantilla deja vacío para el usuario y con la tabla del README, y una
plantilla nueva con un hueco sin declarar falla ahí.

Lo que cambie en cualquier otro método, una propiedad o un `use` de otro
archivo está fuera: `edited_outside`, con las piezas en `members`.

## El código sin gestionar

Controladores de `Http/Controllers`, archivos de `routes/` y modelos de
`Models/` que ni el manifiesto ni el contrato conocen. Son siempre `decision`:

| Paso | Qué es | Opciones |
|---|---|---|
| `unmanaged-http` | Controladores y archivos de rutas escritos a mano. | Declararlos en el contrato (`endpoints`, `controllers[]`, conservando su `route_name` y su `uri`), dejarlos como código propio (y pedir la excepción) o inferir primero. |
| `unmanaged-models` | Modelos escritos a mano. | Adoptarlos en el contrato, dejarlos fuera o inferir primero. |
| `no-contract` | Un ámbito sin `laraimport.json`. | Inferirlo ([infer.md](infer.md)) o dejarlo fuera de LaraPack. |

Lo que esté en otro sitio (servicios, soportes, jobs) no se lista: se cuenta en
`out_of_scope.<ámbito>.other_code`, por directorio.

## Lo que el contrato calla

La sección `contract` del informe: lo que el contrato tendría que decir y no
dice. Hoy, una sola cosa: **las llaves foráneas** cuyo `on_delete`/`on_update`
no declara el contrato y cuyas migraciones hacen algo distinto de `restrict`
(la cascada que generaba la 7.x). El contrato de 8.x en adelante restringe por
omisión, así que el contrato dice otra cosa que la base: quien lo lea, o lo
declare sin mirar, cambia lo que se borra en producción.
Lo propone el paso `pin-foreign-keys` ([apply.md](apply.md#pin-foreign-keys)).

## Los hallazgos de verify

Cada comprobación de `verify` pide un tipo de paso
(`StepTypes::CHECKS`):

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

Lo que ya cubre una [excepción aprobada](exceptions.md) no se vuelve a
proponer.

## Lo que está fuera de alcance

`out_of_scope` en el informe, por ámbito: `not_regenerated`,
`migration_history` y `other_code` (lo que no es de LaraPack, contado por
directorio). Se dice para que nadie piense que se olvidó, y ningún comando lo
toca. Si algo de ahí tiene que cambiar, lo cambia el proyecto con sus propias
herramientas (una migración nueva, un cambio en un servicio).

## El informe: `report.json`

```
{format_version, generator, generated_at, totals, verify,
 scopes:    [{package, path, contract, manifest, manifest_version, fingerprint, regeneration, counts, total, conforming}],
 files:     [{scope, package, file, path, model, stub, class, category, type, reason, tracked, hash_current, generator, to?, members?, cause?, comments?, regenerated?}],
 unmanaged: [...],
 findings:  [...],   los de verify, con scope, category y type
 contract:  [{scope, package, file, model, column, table, on_delete?, on_update?, migration, category, type}],
 out_of_scope: {<ámbito>: {...}}}
```

- `files` sólo lleva lo que **no** cumple: lo que cumple se cuenta en `counts`.
- `fingerprint` es el hash del contrato y del manifiesto del ámbito: si cambian
  después del diagnóstico, `status` lo dice en `stale` y hay que volver a
  diagnosticar.
- `regeneration` dice si se regeneró, si vino de la caché, cuánto tardó y, si
  falló, por qué.
- `format_version` es la del formato (hoy `1`): se sube cuando cambia algo que
  un consumidor lee; añadir una clave no la sube. Un informe de una versión
  posterior no se lee: se pide actualizar el paquete.

## Rendimiento y caché

- Cada ámbito se regenera una vez y se guarda en la caché por el hash de todo
  lo que decide el resultado (el contrato, el `composer.json`, el `pint.json`
  propio o el de la raíz, la versión y las plantillas del generador, la Pint
  que se usa y si el ámbito tiene interfaz Vue o React). Si nada cambió, la siguiente vez no se regenera.
  `--no-cache` lo fuerza.
- **Tiempo medido** (4 procesos, en una máquina Linux de 4 núcleos): un
  monorepo de 82 ámbitos y 22 846 archivos, 283 s la primera vez y 116 s con la
  caché; un proyecto de 3 550 archivos, 128 s; un proyecto sin contrato, 3 s.
- **La caché ocupa lo que lo generado** (el tamaño de los archivos de LaraPack
  de cada ámbito). Se puede borrar sin perder nada: sólo cuesta tiempo.
- `--jobs` más alto ayuda en un monorepo hasta el número de núcleos; cada
  proceso hijo es un `larapack:import` completo.

## Limitaciones

Las de todo el realineamiento están en [risks.md](risks.md). Las del
diagnóstico:

- La regeneración sale de un directorio vacío con el `composer.json`, el
  `laraimport.json` y el `pint.json` del ámbito: lo que el import leería de
  otros archivos del proyecto no cuenta.
- Las migraciones se comparan sólo por nombre y contenido. Si una tabla se
  creó con otra migración, la regenerada no tiene con qué compararse y no se
  propone nada.
- Un manifiesto cuyo hash es de antes de pasar Pint hace que lo formateado
  parezca editado: se reconoce como `identical`/`format_only` y se propone para
  `adopt`, pero si además se editó, la edición no se distingue del formato
  hasta adoptar.
- Sin Pint disponible (`LARAPACK_PINT=off`), sólo se reconocen los cambios de
  formato por tokens.
- El código sin gestionar se busca en `Http/Controllers`, `Models` y `routes/`.
