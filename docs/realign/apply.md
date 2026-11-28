# Correcciones automáticas: `larapack:realign apply`

Parte de [`larapack:realign`](README.md). Los pasos deterministas que
**reescriben código o el contrato**: lo que se puede hacer con una orden y cuyo
resultado se comprueba sin opinar. Lo que una corrección no reconoce con
certeza lo salta, con su razón, y lo termina a mano su playbook.

## Índice

- [Correrla](#correrla)
- [Cómo escribe](#cómo-escribe)
- [Los pasos](#los-pasos)
  - [regenerate](#regenerate)
  - [missing (restaurar)](#missing-restaurar)
  - [fix](#fix)
  - [endpoint-tests](#endpoint-tests)
  - [controller-concern](#controller-concern)
  - [model-lists](#model-lists)
  - [pin-foreign-keys](#pin-foreign-keys)
- [Lo saltado](#lo-saltado)
- [Dos pasadas](#dos-pasadas)
- [Saltar de versión mayor (7.x → 8 → 9)](#saltar-de-versión-mayor-7x--8--9)
- [Riesgos, en resumen](#riesgos-en-resumen)

## Correrla

```bash
larapack:realign apply <id>… --dry-run        # con el diff de cada archivo
larapack:realign apply <id>…
larapack:realign apply --deterministic --dry-run
larapack:realign apply --deterministic        # todos los deterministas pendientes, en el orden del plan
larapack:realign apply --deterministic --package=billing
```

**Sin ids hay que pedirlo** (`--deterministic`): `apply` reescribe código y no
lo hace por omisión; `--deterministic` con ids es un error. Con
`--deterministic` hace también los pasos de [adopt](adopt.md) (`relink`,
`prune`, `adopt`), en su orden. Las decisiones sólo entran si se decidió la
opción que ejecuta `apply` (`restore` en `missing`). Sin nada determinista
pendiente (un proyecto sin contrato, o ya realineado), termina con error y lo
dice: «No hay pasos pendientes que `apply` sepa hacer». No es un fallo del
proyecto; `larapack:realign status` dice qué queda.

Como toda operación que escribe: sin `--dry-run` exige el árbol git limpio
fuera de `.larapack/realign/` (o `--allow-dirty`), informa
`fixed`/`skipped`/`unchanged`, termina con error si saltó algo y anota en la
historia de cada paso lo que hizo, sin darlo por hecho (eso es `check`).

## Cómo escribe

- **Cada cambio se escribe entero o no se escribe.** Si después de escribirlo
  (y de pasarle la Pint del proyecto, donde se dice) un PHP no parsea, todo lo
  de ese cambio vuelve a como estaba —un archivo creado se borra, uno borrado
  se restaura— y se salta con la razón.
- **Un paso que revienta se salta y los demás siguen**: una corrida de
  `--deterministic` no se queda a medias por un archivo raro.
- **Vuelve a clasificar antes de tocar**: cada archivo se compara otra vez con
  lo regenerado justo antes de escribirlo. Si alguien lo editó después del
  diagnóstico, se salta.
- **El manifiesto sigue a lo que escribe**: lo regenerado se registra como
  generado con la versión actual; lo movido se registra en su ruta nueva.
- **Nunca escribe una migración** ni toca la base.

## Los pasos

| Paso | Qué escribe | Qué nunca hace / cuándo salta | Riesgo |
|---|---|---|---|
| `regenerate` | Lo que nadie editó y quedó atrás, y lo que el contrato pide y nunca se generó, copiado de lo regenerado. | Nunca un archivo editado, nunca una migración, nunca lo de un modelo sin importar. | Medio |
| `missing` (si se decidió `restore`) | Lo generado que se borró, igual que `regenerate`. | Lo que no está como `missing` borrado. | Medio |
| `fix` | Lo mismo que `larapack:fix <comprobación> --package=<paquete>`. | Lo que no tiene la forma generada. | Medio |
| `endpoint-tests` | Las pruebas que faltan en cada `<Modelo>EndpointsTest`. | Nunca quita ni cambia un método. | Bajo |
| `controller-concern` | Mueve un trait de `Http/Controllers/Concerns` a `Http/Requests/Concerns` y reescribe sus nombres. | El que usa un controlador. | Bajo |
| `model-lists` | Lo que falta en las listas del modelo. | Nunca quita ni cambia. | Medio |
| `pin-foreign-keys` | `on_delete`/`on_update` en `laraimport.json`. | Nunca una migración ni la base. | Bajo |

### regenerate

Copia **de lo regenerado** (que ya pasó por la Pint del proyecto) lo que nadie
editó y quedó atrás (`version_delta`: su hash es el del manifiesto) y lo que el
contrato pide y nunca se generó. Lo registra como generado, con la versión
actual. Es un `larapack:import --force` acotado a lo seguro.

- **Nunca** un archivo editado (si alguien lo editó después de diagnosticar,
  salta), **nunca** una migración, nunca nada de un modelo que no se ha
  importado (su migración la escribe `larapack:import`).
- **Riesgo medio**: cambia comportamiento si la plantilla nueva lo cambia (un
  IndexRequest que fuerza `managed`, un Resource que deja de servir `appends`).
  Y puede pedir dependencias nuevas que la plantilla usa
  (`innoboxrr/search-surge`, por ejemplo): `apply` comprueba que el PHP
  parsea, no que esas clases existan. Corre las pruebas del proyecto después.

### missing (restaurar)

Una decisión: un archivo generado que se borró y el contrato sigue pidiendo.
Con `larapack:realign decide missing@. restore`, `apply` lo restaura igual que
`regenerate`. Con `forget`, se deja borrado con una [excepción](exceptions.md)
(el contrato lo seguiría pidiendo: lo honesto es retirarlo del contrato si no
se quiere).

### fix

Lo mismo que `larapack:fix <comprobación> --package=<paquete>` (la misma
implementación, `LegacyFix`, sin dos copias), una corrida por comprobación para
todos sus paquetes: `index-managed`, `resource-appends`, `relation-missing` y
el fallback de la SPA (`route-closure` del `Route::fallback()` de la SPA). Lo
que no tiene la forma generada lo salta con la razón de `larapack:fix`.

Lo que `larapack:fix` salta y es de **otro paso** del plan no cuenta como
saltado: en `route-closure`, los closures que no son el fallback de la SPA son
de la decisión `route-closure@<ámbito>`. Salen en `unchanged` con
`deferred_to` (el id de ese paso) y la razón «No es de este paso: lo resuelve
…», y el paso `fix` puede quedar hecho sin ellos. Si el plan no tiene ese
paso, quedan `skipped` y el paso, a medias: `check` no lo da por hecho
mientras quede un hallazgo suyo.

El SpaController que crea `route-closure` es código del paquete: mientras sea
el de laravel-setup 7.2.1 (salvo formato) el diagnóstico no lo lista como
código sin gestionar (`unmanaged-http`). Si se edita, sí.

Riesgo medio: `index-managed` cambia qué ve un listado (pasa a respetar
`managed`); `resource-appends` cambia qué campos sirve un Resource. Las
pruebas del proyecto lo dicen.

### endpoint-tests

Añade a cada `<Modelo>EndpointsTest` las pruebas que trae hoy la plantilla
(sin sesión no se entra, cada acción pregunta a la política, el listado pasa
por su ManagedFilter), copiadas de lo regenerado, con los auxiliares privados
que llaman y los `use` que necesitan, y pasa la Pint del proyecto por el test.
En vez de «borrar el test, importar y volver a pegar lo propio», que es lo que
pedía la guía de 9.1.

- **Nunca** quita ni cambia un método que ya está. Salta si el test no se
  reconoce con certeza (dos clases, una importación agrupada), si su clase o
  su namespace no son los de lo regenerado, o si una importación choca con otra
  del mismo nombre.
- Reconoce las pruebas por el final de su nombre (el mismo criterio que
  `verify`): una prueba propia que se llame igual cuenta como la de la
  plantilla.
- **Riesgo bajo**: no toca código de producción. Pero **una prueba nueva puede
  fallar con razón** (una acción que responde sin sesión o no pregunta a la
  política): no se quita la prueba, se arregla la acción.

### controller-concern

El aviso de `controller-concern`: un trait de `Http/Controllers/Concerns` que
sólo usan requests. Lo mueve a `Http/Requests/Concerns`, cambia su namespace y
reescribe **por tokens** cada nombre que lo apunta en el proyecto: `use`,
`\…::class`, nombres relativos. Si el manifiesto lo registraba, el registro lo
sigue.

- El que usa un controlador no se mueve: es `controller-shape` (guiado).
- Salta si alguien lo nombra en una cadena (configuración), en un `use`
  agrupado o por su nombre corto desde su mismo namespace, si ya hay un archivo
  en el destino o si su namespace no es el de su ruta.
- Un comentario o un docblock que lo nombra (`@see`) se queda como estaba. Y
  sólo busca en los PHP del proyecto (fuera de `vendor`, `node_modules`,
  `storage` y `cache`): un nombre en un `.json` o un `.yaml` no se ve.
- Riesgo bajo: el comportamiento no cambia.

### model-lists

Completa `$fillable`, `$creatable`, `$updatable`, `$export_cols` y `casts()` de
un modelo editado con lo que declara el contrato (`ModelRegions`, lo mismo que
`import --force` con un modelo editado).

- **Sólo añade**: nunca quita lo que el contrato ya no declara ni cambia un
  cast distinto (son avisos `info` de verify, y decisiones del dueño). Una
  región que no se localiza con certeza se salta con su orden manual.
- Riesgo medio: cambia qué se asigna en masa y cómo se convierte una columna.

### pin-foreign-keys

Escribe en `laraimport.json` el `on_delete`/`on_update` que hacen hoy las
migraciones (leídas todas, por tokens) donde el contrato calla y difiere de
`restrict`: la cascada que generaba la 7.x. Desde la 8.0 el contrato restringe
por omisión, así que sin esto el contrato **no dice lo que hace producción**: la
base borra en cascada y quien lee el contrato —o lo usa para generar o probar—
cree que restringe. Desde 9.3.1 `import` no cambia una llave que el contrato no
declara (sólo lo sugiere; las 9.x anteriores escribían alteraciones vacías
«escribe el cambio a mano»), pero el día que alguien la declare sin mirar,
cambia lo que se borra.

- Edita el JSON **en su sitio**: sólo cambian las líneas de esas columnas; el
  resto del archivo (orden, espacios, comentarios de formato) queda igual.
  Valida el contrato antes de escribirlo.
- **Nunca** toca una migración ni la base. Salta si el contrato ya declara otra
  acción (eso es una decisión), o si el JSON no se puede editar sin
  reescribirlo. Una llave que alguna migración toca de forma que no se lee no
  se propone.
- Riesgo bajo: la base no cambia; el contrato pasa a decir lo que hace
  producción. **Cambiar** la acción de verdad (pasar de `cascade` a `restrict`)
  es otra decisión, con su migración: el playbook `decide-foreign-keys` ayuda a
  pensarla, llave por llave.

## Lo saltado

Lo que `apply` salta deja el paso pendiente, con la razón en `skipped`. Lo que
falta lo dice el playbook del tipo, que `next` da rellenado en
`how.fallback.instructions`:

```bash
larapack:realign next --format=json       # how.fallback: el playbook del paso, con sus archivos
larapack:realign playbook endpoint-tests  # leerlo sin un paso delante
```

Se hace a mano lo saltado y `check` lo da por hecho como a cualquier otro.

## Dos pasadas

Después de `apply --deterministic`, **vuelve a diagnosticar y planificar**:
lo que una corrección deja igual a lo regenerado (un IndexRequest que arregló
`fix`) aparece como `adopt` en la siguiente pasada. Dos pasadas bastaron en los
proyectos ensayados; cuando `plan` no tiene deterministas pendientes, se sigue
con lo guiado.

```bash
larapack:realign apply --deterministic
larapack:realign diagnose
larapack:realign plan
larapack:realign apply --deterministic
```

## Saltar de versión mayor (7.x → 8 → 9)

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

## Riesgos, en resumen

Todo `apply` se deshace con git (`git restore`, `git clean` para lo creado). Lo
que puede cambiar comportamiento —`regenerate`, `fix`, `model-lists`— lo dice
su riesgo en el plan y en `next`. **Corre las pruebas del proyecto después de
cada `apply`**, y comitea por paso o por pasada para poder revertir sólo lo que
falle. El detalle por tema: [risks.md](risks.md).
