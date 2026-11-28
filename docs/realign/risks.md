# Limitaciones y riesgos

Parte de [`larapack:realign`](README.md). Lo que cada operación puede romper,
lo que no sabe hacer y lo que no se ha probado. Está escrito para leerlo
**antes** de empezar: un realineamiento sale bien cuando quien lo ejecuta sabe
dónde mirar después de cada paso.

## Índice

- [La regla de oro](#la-regla-de-oro)
- [Por operación](#por-operación)
- [Migraciones y datos](#migraciones-y-datos)
- [Los contratos con el front y los clientes](#los-contratos-con-el-front-y-los-clientes)
- [Comportamiento: 403 antes que 422, y otros](#comportamiento-403-antes-que-422-y-otros)
- [Dependencias](#dependencias)
- [Monorepos y trabajo en paralelo](#monorepos-y-trabajo-en-paralelo)
- [Windows](#windows)
- [Rendimiento y disco](#rendimiento-y-disco)
- [Lo que el diagnóstico no puede saber](#lo-que-el-diagnóstico-no-puede-saber)

## La regla de oro

Un realineamiento **no garantiza que el comportamiento siga igual**: garantiza
que lo que escribe se reconoce con certeza, se puede simular y se deshace con
git. Que el proyecto siga haciendo lo mismo lo dicen **sus pruebas**. Por eso:

1. Una rama para el realineamiento, y el árbol limpio antes de cada operación
   que escribe.
2. `--dry-run` antes de cada `adopt`, `apply`, `infer` y `except`.
3. Las pruebas del proyecto **antes** de empezar (para saber qué fallaba ya) y
   **después de cada paso** que reescribe código.
4. Un commit por paso (o por pasada de `apply --deterministic`), con el
   `plan.json` que lo marca hecho. Lo que falle se revierte sólo.

## Por operación

| Operación | Escribe | Riesgo | Qué puede salir mal | Cómo se deshace |
|---|---|---|---|---|
| `diagnose`, `plan`, `status`, `next`, `show`, `check`, `decide`, `defer`, `undefer`, `escalate`, `accept-untested`, `done`, `brief`, `playbook`, `help` | Su estado (o nada) | Ninguno sobre el código | Un diagnóstico viejo da un plan viejo (`status` lo dice en `stale`). La caché ocupa disco. `check` con la orden de pruebas configurada la **ejecuta** en la raíz del proyecto. | Borrar `.larapack/realign/`: sólo se pierde el estado (lo hecho y lo decidido incluidos, si no se versionó). |
| `adopt` | El manifiesto | Bajo | Lo adoptado lo vuelve a escribir el siguiente `import --force`: por eso un archivo con otros comentarios no se adopta ([adopt.md](adopt.md#riesgos)). Adoptar como editado algo escrito a mano hace que `--force` no lo regenere nunca. `prune` deja de vigilar lo que el contrato ya no genera. | `git restore .larapack/manifest.json` |
| `apply` → `regenerate`, `missing` | Código generado | Medio | La plantilla nueva cambia comportamiento (un listado que respeta `managed`, un Resource sin `appends`) o usa una clase de una versión nueva de una dependencia. | `git restore`, `git clean` para lo creado |
| `apply` → `fix` | Requests, Resources, traits, `routes/web.php` | Medio | `index-managed` cambia qué ve un listado; `resource-appends`, qué sirve un Resource; el fallback de la SPA, cómo se resuelve una ruta del front. | `git restore` |
| `apply` → `endpoint-tests` | Pruebas | Bajo | Una prueba nueva falla porque la acción responde sin sesión o no pregunta a la política: **es un hallazgo**, no un fallo de la herramienta. | `git restore` |
| `apply` → `controller-concern` | Traits y sus referencias | Bajo | Un nombre en una cadena, un `.json`, un `.yaml` o un comentario no se reescribe (lo que se ve, se salta; lo que no es PHP, no se ve). Lo que queda nombrando el trait viejo en un PHP del proyecto después de escribir revierte el cambio entero. | `git restore`, `git clean` |
| `apply` → `model-lists` | Modelos | Medio | Cambia cómo se convierte una columna (`casts()`) y qué se exporta. Sólo añade, y nunca a `$fillable`, `$creatable` ni `$updatable`: eso lo salta y lo decide una persona. | `git restore` |
| `apply` → `pin-foreign-keys` | `laraimport.json` | Bajo | El contrato pasa a decir lo que hacen las migraciones; si la base de producción hace otra cosa sin migración, el contrato no lo sabe. | `git restore laraimport.json` |
| `infer` | Un borrador | Ninguno sobre el código | El riesgo está en **adoptar** el borrador: lo que no infirió (la interfaz, la autorización) y lo que se pierde (`lost`). Ver [infer.md](infer.md#limitaciones-y-riesgos). | Borrar `laraimport.inferred.json` |
| `except` | Solicitudes | Ninguno hasta que se aprueba | Una excepción aprobada deja de vigilar ese código mientras no cambie; la primera aprobación crea `.larapack/hand-rolled.lock`, y desde ahí un `#[HandRolled]` sin aprobar es error. Ver [exceptions.md](exceptions.md#limitaciones-y-riesgos). | `larapack:hand-rolled revoke` (el dueño) |
| Pasos guiados | Lo que diga el playbook | Medio a alto | Mover código cambia comportamiento (abajo). | `git revert` del commit del paso |

## Migraciones y datos

- **Ninguna operación del realineamiento escribe ni reescribe una migración, ni
  toca la base.** Lo que difiere en una migración generada se clasifica
  `migration_history` y queda fuera de alcance.
- **Pero el contrato sí puede cambiar lo que generará `larapack:import`.**
  Después de adoptar un borrador de `infer`, de declarar un endpoint o de
  completar un modelo, el siguiente `larapack:import` puede escribir
  alteraciones nuevas: columnas e índices que faltan y, si lo declarado difiere
  de lo que dicen las migraciones (un tipo, una medida, un `nullable`, un
  default), un `->change()`. **Corre `larapack:import --dry-run` y revisa cada
  alteración antes de migrar.** Desde 9.3.1 el lector de migraciones lee todas
  antes de escribir ninguna y no deshace lo que escribió una persona, pero lo
  que no se lee (un bucle, SQL crudo, una tabla en una variable) no lo sabe.
- **Las llaves foráneas**: un proyecto de 7.x borra en cascada y el contrato de
  8.x en adelante restringe por omisión: el contrato no dice lo que hace
  producción. `import` (desde 9.3.1) no cambia una llave que el contrato no
  declara, pero declararla sin mirar sí la cambia. `pin-foreign-keys` fija en
  el contrato lo que ya hay; cambiarlo es una decisión con su migración
  (`decide-foreign-keys`).
- **Las alteraciones vacías de 9.x** (sólo un comentario «escribe el cambio a
  mano») no se borran solas: saber si ya se migraron en producción pide la
  base. Se dice, no se hace.
- **El contrato se compara con el repositorio, nunca con la base.** Un cambio
  hecho en producción sin migración (un índice a mano, una columna añadida por
  consola) no lo ve nadie desde el código. Si hay sospecha, compara el esquema
  real (`php artisan schema:dump` o el cliente de la base) con lo que dicen las
  migraciones antes de declarar.
- **Datos**: ningún paso migra datos. Un cambio de `$fillable` o de `casts()`
  (`model-lists`) cambia cómo se escriben y leen los datos desde ese momento,
  no los que ya están.

## Los contratos con el front y los clientes

- **Ningún nombre de ruta ni URI cambia sin que el contrato lo declare.** Para
  adoptar un endpoint escrito a mano se declara con su `route_name` y su `uri`
  ([contract.md](contract.md#rutas-conservar-el-nombre-y-la-uri)); una ruta que
  no se puede conservar se queda como código propio. En un proyecto ensayado,
  el front propio llamaba a 339 de las 401 rutas escritas a mano: renombrarlas
  rompía esas llamadas y ninguna prueba de PHP lo veía.
- **La forma de una respuesta sí puede cambiar**: un Resource regenerado o
  arreglado (`resource-appends`) deja de servir lo que pedía el cliente sin
  lista blanca; un listado que pasa a respetar `managed` devuelve menos filas.
  Comprueba el front (sus pruebas, o abriéndolo) después de `regenerate` y
  `fix`.
- **El front generado** (Vue o React) se regenera con el contrato: si estaba
  atrasado respecto del contrato, `regenerate` lo pone al día, y eso es un
  cambio visible.
- **Los archivos que el front lee del back** (`routes.json`, el contrato JS de
  los endpoints) cambian sólo si el contrato cambia. Si el proyecto los
  comprueba en CI, córrelo.

## Comportamiento: 403 antes que 422, y otros

- **El caso conocido**: con la validación en `rules()` y la autorización en
  `authorize()`, Laravel autoriza **antes** de validar. Una petición sin
  permiso que antes recibía un 422 (validación) recibe ahora un 403. Pasó en
  un proyecto real al llevar 1 523 acciones a sus requests. Cada playbook de
  `controller-shape` pide fijar con una prueba lo que responde hoy antes de
  tocar nada, y anotar el cambio.
- **El cuerpo del 422** puede cambiar de forma si el controlador lo armaba a
  mano; el anexo `validate-to-rules` dice cómo conservarlo si el cliente lo lee.
- **Los parámetros escalares de la ruta** pasan a leerse con
  `$this->route('nombre')` en la request: el nombre del segmento, no el de la
  variable del controlador (anexo `raw-request-to-formrequest`).
- **Una política nueva nace denegando**: un endpoint de modelo declarado al
  adoptar pregunta a una habilidad que niega hasta que se escribe. Adoptar sin
  escribirla cierra el endpoint (403).
- **Un listado que respeta `managed`** (`index-managed`) deja de enseñar lo que
  el `ManagedFilter::canView()` no permite: si el front contaba con verlo, es
  un cambio.

## Dependencias

- `regenerate` copia lo que genera **hoy** la plantilla, que puede usar clases
  de una versión nueva de una dependencia del ecosistema
  (`innoboxrr/search-surge`, `innoboxrr/laravel-setup`…). `apply` comprueba que
  el PHP parsea, no que esas clases existan: corre las pruebas y actualiza la
  dependencia si hace falta (el CHANGELOG de cada versión de LaraPack dice qué
  pide).
- La Pint del proyecto (o, si no tiene, la del generador) formatea lo escrito.
  Una Pint distinta entre quien diagnostica y quien aplica puede hacer que lo
  aplicado salga `format_only` en el siguiente diagnóstico: se adopta en la
  segunda pasada.

## Monorepos y trabajo en paralelo

- **Cada paquete local es un ámbito** con su contrato y su manifiesto. Las
  migraciones de todos los ámbitos comparten base: `infer` y
  `pin-foreign-keys` las leen todas juntas.
- **`--package`** acota `diagnose`, `status`, `next`, `brief`, `done` y las
  operaciones a un ámbito: así se trabaja (y se comitea) paquete por paquete.
- **Un solo proceso de realineamiento a la vez por copia de trabajo.**
  `plan.json` se escribe de forma atómica pero sin candado: dos procesos a la
  vez (dos `check`, un `apply` y un `diagnose`) se pisan, y gana el último. Para
  trabajar en paralelo, cada consola en su propia rama o worktree, acotada con
  `--package`, y al juntar las ramas: `diagnose`, `plan` y `check` de lo hecho
  (el estado se recalcula del código, no del archivo que ganó la fusión).
- **El diagnóstico de un monorepo grande tarda minutos** (abajo). La caché lo
  reduce a la mitad mientras los contratos no cambian.

## Windows

**Lo que está cubierto**: los hashes y las comparaciones normalizan `CRLF` a
`LF` (un archivo que pasó por git en Windows no parece editado); el manifiesto
se escribe una vez por corrida, de forma atómica y con reintento ante un
bloqueo pasajero (el antivirus o el indexador); los procesos hijos se lanzan
con el mismo PHP (`PHP_BINARY`) y sin shell.

**Lo que no se ha ensayado**: el realineamiento se ensayó en Linux. En Windows,
además:

- **`git` tiene que estar en el `PATH`** del PHP que corre el comando: las
  operaciones que escriben lo usan para comprobar que el árbol está limpio, y
  sin él se niegan (salvo `--allow-dirty`).
- **Las rutas largas**: la caché vive en
  `.larapack/realign/cache/<ámbito>/<clave>/…`, y
  en un monorepo con paquetes anidados una ruta puede pasar de 260 caracteres.
  Activa las rutas largas de Windows (`LongPathsEnabled` y
  `git config core.longpaths true`) o pon el proyecto cerca de la raíz del
  disco.
- **El antivirus** puede hacer mucho más lento `diagnose` (miles de archivos
  escritos y leídos en la caché): excluye `.larapack/realign/cache/`.
- **Los hooks de agente** usan `$CLAUDE_PROJECT_DIR`: hace falta un shell que
  expanda variables (Git Bash).
- **El PHP del `PATH`** puede no ser el del proyecto (Laragon trae varios): usa
  el mismo con el que corren las pruebas.

## Rendimiento y disco

| Proyecto ensayado | `diagnose` (4 procesos) | `apply --deterministic` |
|---|---|---|
| Monorepo de 82 ámbitos, 22 846 archivos | 283 s; 116 s con la caché | 578 s la primera pasada (5 843 cambios) |
| Proyecto 7.x, 3 550 archivos | 128 s | 47 s (2 272 cambios) |
| Proyecto sin contrato | 3 s | — |

- La caché ocupa lo que ocupa lo generado de cada ámbito, y se puede borrar.
- `--jobs` ayuda hasta el número de núcleos; cada proceso es un
  `larapack:import` completo con su memoria.
- `check` de un paso `verify` corre `verify` acotado (segundos); el de un paso
  de clasificación vuelve a comparar con la caché (no regenera si no cambió).

## Lo que el diagnóstico no puede saber

- **Un archivo editado y escrito por una versión anterior** no se separa en
  «lo que editaste» y «lo que cambió la plantilla» sin las plantillas viejas:
  `edited_outside` con `cause: edited_or_generator`. Correr primero los `fix`
  y `regenerate` reduce mucho esa lista.
- **Un manifiesto cuyo hash es de antes de Pint** hace que lo formateado
  parezca editado: se reconoce como igual salvo formato y se propone para
  `adopt`, pero si además se editó, la edición no se distingue hasta adoptar.
- **Las migraciones se comparan sólo por nombre y contenido**: si una tabla se
  creó con otra migración, la regenerada no tiene con qué compararse.
- **El código sin gestionar** se busca en `Http/Controllers`, `Models` y
  `routes/`; lo que está en otro sitio se cuenta, no se lista.
- **La regeneración no ve el resto del proyecto**: sale de un directorio vacío
  con el `composer.json`, el `laraimport.json` y el `pint.json` del ámbito.
- **Lo que no se lee sin ejecutar** (columnas en bucles, SQL armado, listas de
  modelo con `array_merge`) no se infiere ni se compara: se dice dónde.
