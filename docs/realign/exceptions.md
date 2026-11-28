# Gestionar excepciones: `larapack:realign except` y `larapack:hand-rolled`

Esta guía es la parte de [`larapack:realign`](README.md) que **deja como
está, a propósito, algo que LaraPack señala**: un closure de una ruta que es
legítimo, un controlador o un modelo que se queda fuera del contrato, un
archivo generado que se borró adrede, un hallazgo de verify que se acepta. Es
para una persona o un agente sin contexto: dice cómo se pide, quién aprueba,
qué cubre exactamente, cuándo caduca, cómo lo ve verify y qué riesgos tiene.

## Índice

- [El principio: quien pide no aprueba](#el-principio-quien-pide-no-aprueba)
- [Qué se puede exceptuar y qué no](#qué-se-puede-exceptuar-y-qué-no)
- [El ciclo](#el-ciclo)
- [El objetivo: qué cubre exactamente](#el-objetivo-qué-cubre-exactamente)
- [La huella: cuándo caduca](#la-huella-cuándo-caduca)
- [Cómo lo ve verify](#cómo-lo-ve-verify)
- [Por qué un agente no puede aprobar](#por-qué-un-agente-no-puede-aprobar)
- [Aprobar sin terminal: el workflow de aprobación](#aprobar-sin-terminal-el-workflow-de-aprobación)
- [Los formatos](#los-formatos)
- [Limitaciones y riesgos](#limitaciones-y-riesgos)

## El principio: quien pide no aprueba

Es el mismo de `#[HandRolled]` desde 9.1: el código —o quien trabaja en él—
**declara** la excepción con su razón, y el dueño del proyecto la **aprueba**.
Separarlos es lo que impide que un agente que quiere ver verify en verde lo
consiga con una línea.

| | `#[HandRolled]` | Excepción del realineamiento |
|---|---|---|
| Para qué | Una acción o un controlador que no puede delegar en su request. | Lo que no puede llevar atributo: closures de rutas, código que se deja fuera del contrato, archivos borrados, hallazgos aceptados. |
| Quién la declara | El atributo, en el código, con su razón. | `larapack:realign except <paso> --option reason="…"` (la puede correr un agente). Queda en `.larapack/realign/exceptions.json`. |
| Quién la aprueba | El dueño: `larapack:hand-rolled approve`. | El dueño: **la misma orden**, `larapack:hand-rolled approve`. |
| Dónde queda aprobada | `.larapack/hand-rolled.lock` | **El mismo candado**, `.larapack/hand-rolled.lock`, con un objetivo que empieza por `realign:`. |
| Qué la invalida | Cambiar el código o la razón. | Cambiar el código que cubre (su huella) o la razón pedida. |
| Qué dice verify | Aprobada: `info`. Sin aprobar (con candado): error. | Aprobada: el hallazgo baja a `info` diciendo que es una excepción aprobada. Sin aprobar: el hallazgo sigue como estaba. |

Que sea el mismo candado no es una comodidad: todo lo que ya protege la
aprobación de `#[HandRolled]` —la guardia de los agentes, el hook de git que
pregunta en la terminal, CODEOWNERS en CI— protege ésta sin nada más (ver
[abajo](#por-qué-un-agente-no-puede-aprobar)).

## Qué se puede exceptuar y qué no

`except` acepta pasos de estos tipos, cada elemento con su objetivo exacto:

| Paso | Qué se exceptúa | Ejemplos reales |
|---|---|---|
| `route-closure` | Cada closure de ruta, por su llamada y su URI. | La raíz de la SPA según el Host y `/ci/test-health` (school-management); `/csp-report`, `/app-shell` (expediente); el `OPTIONS` del CORS de xAPI. |
| `unmanaged-http` | Cada controlador o archivo de rutas a mano que se deja fuera del contrato. | Un webhook que firma el cuerpo en bruto. |
| `unmanaged-models` | Cada modelo a mano que no se adopta. | Un registro inmutable que no debe tener API. |
| `untracked-edited` | Cada archivo a mano en la ruta de uno generado. | |
| `missing` | Cada archivo generado borrado adrede. | |
| `no-contract` | El ámbito entero, mientras no tenga `laraimport.json`. | Un paquete que nunca fue de LaraPack. |
| `inconsistent-entity` | La frase exacta de verify sobre ese modelo. | |
| `fix`, `declared-routes`, `security`, `edited-outside`, `model-lists`, `endpoint-tests`, `verify-other` | Cada hallazgo, por su comprobación y su archivo. | Una ruta interna que se declara en la próxima versión. |

No se exceptúa con `except`:

- **Un controlador que no delega** (`controller-shape`, `controller-concern`):
  lleva `#[HandRolled('razón')]` en el código; la razón vive junto al código.
- **`hand-rolled`**: es la propia aprobación de los atributos.
- **Lo que pone el manifiesto al día** (`adopt`, `relink`, `prune`,
  `regenerate`): no hay nada que aceptar; se aplica.
- **Un contrato que no valida** (`contract-invalid`): se corrige o se retira.

## El ciclo

```bash
# 1. Alguien con autoridad decide dejarlo así (en una decisión, la opción
#    cuya orden es `except`):
vendor/bin/builder larapack:realign decide route-closure@. except --note="CSP, CORS y salud son legítimas."

# 2. Cualquiera —también un agente— pide la excepción, con la razón:
vendor/bin/builder larapack:realign except route-closure@. --option reason="Sin datos de nadie y sin sesión." --dry-run
vendor/bin/builder larapack:realign except route-closure@. --option reason="Sin datos de nadie y sin sesión."

# 3. El dueño del proyecto las lee y las aprueba (una a una, o --all), en su
#    terminal: ve cada una y escribe «apruebo <n>».
vendor/bin/builder larapack:hand-rolled list
vendor/bin/builder larapack:hand-rolled approve "routes/web.php#post('/csp-report')"
git diff .larapack/hand-rolled.lock && git commit .larapack/hand-rolled.lock   # la aprobación es este commit

# 4. El paso se da por hecho cuando su comprobación pasa:
vendor/bin/builder larapack:realign check route-closure@.
```

- **En una decisión, `except` exige la decisión tomada** con la opción que la
  pide (el paso 1). Sobre una decisión que nadie tomó, o que se decidió con
  otra opción (`refactor`, `declare`), se niega y dice la orden `decide` que
  falta: la solicitud no puede llegar al dueño como si él ya hubiera elegido.
  Decidida por elemento (`decide --target`), sólo pide por los elementos cuya
  opción es la de `except`. Lo que no es una decisión (un hallazgo de `fix`,
  `declared-routes`, un paso guiado que se acepta) se pide sin `decide`.
- `except` **no escribe código ni el candado**: sólo la solicitud. Como toda
  operación del realineamiento, sin `--dry-run` exige el árbol git limpio (o
  `--allow-dirty`). Informa `fixed` (solicitada), `unchanged` (ya estaba
  pedida o aprobada igual, o ya no hace falta) y `skipped` (lo que no sabe
  nombrar con exactitud, con la razón).
- **Pide por lo de hoy, no por lo del plan.** Antes de pedir, vuelve a mirar
  cada elemento del paso: un hallazgo, con la misma comprobación de verify
  sobre el código de ahora; un archivo (`missing`, `untracked-edited`…), con
  la misma clasificación que el diagnóstico; uno a mano (`unmanaged-http`), si
  sigue existiendo; un closure, por su línea. Lo que ya no está —un archivo que
  `apply` o una persona arreglaron después de planificar— **no se pide**: sale
  en `unchanged` con «verify ya no lo señala» o «ya no es …», y que el plan es
  de antes. No hace falta volver a diagnosticar para pedir lo que queda, pero
  `diagnose && plan` ponen el paso al día.
- **`--target`, repetible, acota a esos elementos**, por su objetivo
  (`realign:fix/index-managed:src/…`) o por su archivo:

  ```bash
  vendor/bin/builder larapack:realign except fix:index-managed@. --option reason="…" \
      --target=src/Http/Requests/Post/ReportRequest.php
  ```

  Un `--target` que no es ningún elemento de esos pasos es un error que dice
  cuáles hay, no una corrida vacía. Sólo lo acepta `except`.
- **Aprobada, cierra el elemento en su paso**, también en los de
  clasificación (`edited-outside`, `missing`, `untracked-edited`): `check` y
  `realign done` lo dan por hecho aunque el archivo siga editado fuera de los
  huecos o borrado, y `diagnose`/`plan` no lo vuelven a abrir. Hasta 9.4.0 un
  `edited-outside` aprobado seguía fallando con «(excepción aprobada); se
  espera identical o format_only o hole_edited» y el paso no terminaba nunca.
  Si el código cambia, la huella caduca y el paso se reabre.
- **Antes de pedirla para un archivo editado fuera de los huecos, mira si la
  plantilla de hoy ya trae lo editado** (el playbook `edited-outside`): el
  `bindValue` contra la inyección de fórmulas de los Exports de 7.x/8.x ya
  viene en la plantilla desde 9.0.0, y lo correcto es copiar lo regenerado,
  no exceptuarlo.
- Se pueden mezclar decisiones: llevar unos closures a un controlador
  (`closure-to-controller`) y pedir la excepción sólo para los legítimos. El
  paso queda hecho cuando verify ya no encuentra ninguno sin resolver.
- `check` de una decisión que eligió dejarlo así **no pasa hasta que el dueño
  aprueba** cada excepción pedida: si falta pedirla lo dice («falta pedir la
  excepción»), y si falta aprobarla, también, con el objetivo.
- `larapack:hand-rolled list` muestra, además de los `#[HandRolled]`, cada
  excepción del realineamiento con su estado: `unapproved` (pedida),
  `approved`, `reason-changed` (se pidió con otra razón que la aprobada),
  `code-changed` (lo aprobado ya no es el código de hoy) o `stale` (lo
  aprobado ya no existe).
- **Si el dueño no está**, el agente no le pregunta una por una:
  `larapack:realign brief` le prepara un documento con cada excepción sin
  aprobar —objetivo, motivo, el mensaje del hallazgo y la orden exacta
  `larapack:hand-rolled approve "<objetivo>"`— junto con las decisiones
  pendientes, y sigue con lo demás ([owner.md](owner.md)).
- `larapack:hand-rolled approve` acepta el objetivo entero o su final, si es
  único: `"routes/web.php#post('/csp-report')"`, `"post('/csp-report')"`,
  `src/Http/Controllers/HookController.php`. `--all` aprueba también todas las
  excepciones del realineamiento pedidas: léelas antes. `revoke` las retira.
- `approve` y `revoke` **los confirma una persona** (ver
  [abajo](#por-qué-un-agente-no-puede-aprobar)): enseñan en la terminal cada
  objetivo, su razón, lo que cubre y el mensaje del hallazgo, y hay que escribir
  `apruebo <n>` (`retiro <n>`). Sin terminal no cambian nada (salvo dentro
  del [workflow de aprobación](#aprobar-sin-terminal-el-workflow-de-aprobación)),
  y todo rechazo sale con un código distinto de cero.
- La excepción de **un hallazgo sin archivo** (`model:`) se aprueba con el
  hallazgo **de hoy**: `approve` corre verify con su comprobación, y si verify
  ya no dice esa frase no hay nada que aprobar. El mensaje que trae la
  solicitud lo escribe quien pide; el que se enseña y se aprueba es el de
  verify.

## El objetivo: qué cubre exactamente

Una excepción nunca cubre «todo lo de este tipo»: cubre **un** elemento, que
nombra su objetivo:

```
realign:<tipo>[/<comprobación>]:<archivo>[#<sujeto>]

realign:route-closure:routes/web.php#post('/csp-report')
realign:route-closure:routes/web.php#fallback()
realign:unmanaged-http:app/Http/Controllers/HookController.php
realign:declared-routes/route-not-declared:src/Http/Controllers/PostController.php
realign:no-contract:packages/school-pay-stripe/laraimport.json
realign:inconsistent-entity:model:User#3f9c0a1b2d4e
```

- El archivo, relativo a la raíz del proyecto.
- El closure de una ruta, por su llamada y su URI (el primer argumento que sea
  una cadena literal). Dos con la misma llamada y la misma URI en el mismo
  archivo: `~2`, `~3` por orden.
- `/<comprobación>` cuando el hallazgo de verify no se llama como su tipo de
  paso.
- Un hallazgo sin archivo (el de una entidad): `model:<Modelo>` y el principio
  de la huella de su mensaje.

## La huella: cuándo caduca

Cada aprobación guarda la huella (`sha256:`) de lo que cubre **cuando se
aprobó**, y sólo lo cubre mientras la huella de hoy sea la misma:

| Cubre | La huella es | Caduca cuando |
|---|---|---|
| un closure | su llamada, su URI, sus parámetros y su cuerpo, sin espacios ni comentarios | cambia el cuerpo, los parámetros o la URI. Moverlo de línea, reformatearlo o comentarlo, no. |
| un archivo PHP | sus tokens, sin espacios ni comentarios | cambia una sentencia, un `use`, un método. |
| otro archivo | su contenido, con los saltos de línea normalizados | cambia cualquier cosa. |
| algo que no está (`missing`, `no-contract`) | una huella fija de su ausencia | el archivo vuelve a existir (el ámbito tiene `laraimport.json`). |
| un hallazgo sin archivo | la de su mensaje, tal como lo dice verify hoy | verify dice otra cosa sobre ese modelo, o nada (`list` la da por `stale`). |

Cuando caduca, el hallazgo vuelve a salir **con su nivel de siempre** y su
mensaje añade «la excepción aprobada `<objetivo>` era de otro código». Si sigue
valiendo, el dueño la vuelve a aprobar (`approve` recalcula la huella); si no,
se corrige.

## Cómo lo ve verify

`larapack:verify` (y `larapack:guard`, y el `check` de los pasos):

- Lo que cubre una excepción aprobada sobre el mismo código **baja a `info`**,
  con «Excepción aprobada por el dueño del proyecto en
  .larapack/hand-rolled.lock: «razón» (objetivo)». No cuenta para el código de
  salida, tampoco con `--strict`.
- La salida JSON trae `exceptions`: cada excepción aprobada con su `status`.
  El texto lo resume: «N aprobadas sobre el código de hoy, M que ya no cubren
  lo que se aprobó».
- Una aprobada que **ya no cubre ningún hallazgo** —se corrigió, o el closure
  dejó de serlo— sale como aviso `hand-rolled-stale` en el candado, como un
  `#[HandRolled]` que ya no está: `--strict` falla hasta que el dueño la retira
  (`larapack:hand-rolled revoke`). Sólo se sabe de las de un hallazgo de verify
  y sólo si su comprobación se corrió (con `--check` acotado, de las demás no
  se dice nada).
- Un candado ilegible no aprueba nada (error, como siempre).

`larapack:realign diagnose` no vuelve a proponer lo que cubre una excepción
aprobada: un archivo, código sin gestionar, un hallazgo o un ámbito sin
contrato.

## Por qué un agente no puede aprobar

Son las mismas capas que la aprobación de `#[HandRolled]`, porque es el mismo
candado y la misma orden:

0. **El propio comando** (desde 9.4.0, también sin la guardia ni el hook
   instalados). `approve` y `revoke` no cambian el candado sin una persona:
   - **en su terminal**: lo que se aprueba o se retira —objetivo, razón, lo
     que cubre y el mensaje del hallazgo— sale en `/dev/tty`, y la persona
     escribe `apruebo <n>` con el número de excepciones. La pregunta no va a
     la entrada estándar: `yes | …` o `echo apruebo 1 | …` no la contestan, y
     un proceso sin terminal de control (un agente, la CI) no puede abrir
     `/dev/tty`. **Límite:** un proceso puede fabricarse una terminal
     (`pty.spawn` de Python, `script -c`, `expect`, `unbuffer`) y escribir él
     la frase; esta capa frena el error y la tubería, no a un agente
     decidido. La guardia bloquea esas órdenes (capa 1), y la que no se puede
     saltar es CODEOWNERS sobre el candado (capa 3): **un cambio de
     `.larapack/hand-rolled.lock` se revisa siempre en el PR**. En Windows vale la consola (PowerShell, cmd, Windows
     Terminal); desde Git Bash (mintty) la entrada es una tubería y no hay
     forma fiable de saber si hay alguien delante: se corre desde PowerShell,
     con `winpty` o, sin terminal, en el workflow de aprobación.
   - **sin terminal, sólo en el workflow de aprobación** de GitHub Actions,
     lanzado a mano por una persona: ver
     [Aprobar sin terminal](#aprobar-sin-terminal-el-workflow-de-aprobación).
     Fuera de él, nombrarse (`LARAPACK_APPROVER` y `--approver`) no aprueba
     nada.
   - **El rechazo no enseña cómo saltárselo.** Sale con un código distinto de
     cero y, a quien no tiene terminal, le dice que un agente no lo intenta
     por ninguna otra vía y le remite a esta página; no le da una orden lista
     para copiar.
1. **La guardia de los agentes** (`larapack:guard --pre`, instalado con
   `larapack:skill --hooks`) bloquea antes de ejecutarse cualquier forma de
   `larapack:hand-rolled approve` o `revoke` —abreviada, dentro de `bash -c`,
   de `eval`, de un heredoc— y cualquier escritura de `.larapack/hand-rolled.lock`
   (con Write/Edit o desde la shell: `>`, `tee`, `cp`, `sed -i`, `php -r`…),
   y la vía sin terminal: cualquier orden con `--approver` o que dé valor a
   `LARAPACK_APPROVER` (`export`, `env`, delante de otra orden). El workflow
   de aprobación no la necesita: corre en GitHub, no en la sesión del agente. Leer o buscar
   la variable no se bloquea. `larapack:realign except` **no** se bloquea:
   pedir es libre.
2. **El hook de git** (`larapack:guard --git`, que también pone `larapack:skill --hooks`)
   pregunta en la terminal (`/dev/tty`) cuando un commit cambia el candado. Un
   agente no tiene terminal en la que contestar.
3. **CODEOWNERS** (`larapack:ci --owner`) pide la revisión del dueño en el pull
   request que toque el candado, el workflow de la puerta o el de aprobación.
4. **La CI enseña y comprueba cada aprobación sin terminal.** El job que
   genera `larapack:ci` corre `larapack:hand-rolled list --format=github`:
   cada entrada con `approved_by` sale como anotación sobre su línea del
   candado —en el diff del PR, donde mira quien revisa— y se pregunta a
   GitHub si su corrida (`approved_run`) existe, es del workflow de
   aprobación, se lanzó a mano y la lanzó quien dice. Si no, es un error y el
   check no pasa. `larapack:verify` también las lista («Aprobadas sin
   terminal», y `named_approvals` en JSON).

Ninguna es perfecta por separado (ver las limitaciones del README); juntas
hacen que aprobar sea un acto del dueño, visible en el historial.

## Aprobar sin terminal: el workflow de aprobación

Para una **persona** que no tiene terminal —aprueba desde el navegador, o el
proyecto se trabaja sólo con agentes—. Un agente no lo usa: no tiene cuenta
en GitHub con la que lanzarlo, y si la tuviera, la aprobación quedaría a su
nombre y la CI lo diría.

1. El dueño lo crea una vez, con la puerta:

   ```bash
   vendor/bin/builder larapack:ci --owner=@dueño --approval
   ```

   Escribe `.github/workflows/larapack-approve.yml` (`workflow_dispatch`, sin
   más disparadores) y CODEOWNERS lo cubre —aunque no se cree, para que nadie
   añada uno a su gusto—.
2. La persona abre Actions → «LaraPack approve» → *Run workflow*, elige la rama
   con las solicitudes y escribe qué aprobar (uno por línea, como los da
   `larapack:hand-rolled list`) o marca `all` después de leerlas
   (`larapack:realign brief` las prepara).
3. La corrida aprueba **a nombre de quien la lanzó** (`github.triggering_actor`):
   cada entrada lleva `approved_by` (su cuenta) y `approved_run` (la corrida),
   y empuja el candado a una rama nueva, `larapack/approve-<corrida>`. El
   resumen de la corrida da el enlace para abrir el PR.
4. En ese PR, el job de la puerta anota cada aprobación sin terminal sobre su
   línea del candado y comprueba con GitHub la corrida; CODEOWNERS pide la
   revisión del dueño. **La aprobación es ese PR.**

Qué exige `approve` para aceptar la vía sin terminal (si falta algo, no
cambia nada y sale con error):

- `GITHUB_ACTIONS=true` y `GITHUB_EVENT_NAME=workflow_dispatch`: una corrida
  de Actions lanzada a mano. Un `CI=true`, un `push` o un `pull_request` no
  valen: los agentes también corren en CI.
- `GITHUB_WORKFLOW_REF` es `.github/workflows/larapack-approve.yml` de este
  repositorio.
- `LARAPACK_APPROVER`, `--approver` y el actor que la lanzó son el mismo, no
  es un bot (`…[bot]`) y no la relanzó otra persona.

**Por qué así y no con un secreto o una firma.** Un secreto viviría donde
vive el agente (una variable del job, el llavero de quien lo lanza), o habría
que repartir y rotar claves. Quién lanza una corrida lo dice GitHub, y no se
falsifica desde fuera: `workflow_dispatch` sólo lo lanza quien tiene permiso
de escritura, y su `triggering_actor` es esa cuenta. La comprobación de la
corrida en la CI es la que da valor a esa identidad.

**Lo que no se puede garantizar, dicho:**

- **Las variables `GITHUB_*` se pueden escribir a mano** fuera de Actions; el
  comando no distingue un entorno de verdad de uno imitado. Por eso la
  aprobación lleva la corrida y la CI pregunta a GitHub por ella: una
  inventada, de otro workflow o de otra persona falla el check. Sin la puerta
  de `larapack:ci` (o con un job que no corre ese paso) esa comprobación no
  existe, y sólo queda la revisión del diff.
- **Una cuenta con permiso para lanzar workflows lanza la corrida a su
  nombre.** Si un agente tiene un token con `actions: write` (el de la
  persona, por ejemplo), la corrida es «de» esa persona, y la CI la da por
  buena. No le des a un agente un token que pueda lanzar workflows.
- **La corrida usa el workflow de la rama que se elige.** Un cambio en
  `larapack-approve.yml` dentro de esa rama corre en la aprobación. CODEOWNERS
  hace que ese cambio lo revise el dueño antes de fusionarse, no antes de
  correr: mira qué rama eliges.
- **La capa que no se salta es la de fuera del proceso**: CODEOWNERS sobre el
  candado y la revisión de su diff en el PR, que la rama protegida exige. Todo
  lo demás lo hace más difícil o más visible, no imposible.

## Los formatos

**La solicitud**, `.larapack/realign/exceptions.json` (`format_version` 1):

```json
{
  "format_version": 1,
  "requests": [
    {
      "target": "realign:route-closure:routes/web.php#post('/csp-report')",
      "type": "route-closure", "check": "route-closure", "scope": ".",
      "file": "routes/web.php", "subject": "post('/csp-report')", "line": 84,
      "step": "route-closure@.", "message": "Closure en la ruta …",
      "reason": "Sin datos de nadie y sin sesión.",
      "fingerprint": "sha256:…", "requested_at": "2026-11-27T10:00:00+00:00"
    }
  ]
}
```

Una solicitud sin `reason` o sin un `target` de la forma de arriba no cuenta.
`fingerprint` es la huella cuando se pidió; la que se aprueba es la de cuando
el dueño corre `approve`. `message` (desde 9.4.0) es el del hallazgo que se
pidió exceptuar, para enseñárselo al dueño; no aprueba nada —lo escribe quien
pide—, y el de un hallazgo sin archivo se enseña y se aprueba como lo dice
verify hoy.

**La aprobación**, en `.larapack/hand-rolled.lock`, con la misma forma que las
de `#[HandRolled]`:

```json
[
  {"target": "realign:route-closure:routes/web.php#post('/csp-report')", "reason": "Sin datos de nadie y sin sesión.", "fingerprint": "sha256:…"},
  {"target": "realign:no-contract:packages/x/laraimport.json", "reason": "…", "fingerprint": "sha256:…", "approved_by": "ana", "approved_run": "https://github.com/acme/notes/actions/runs/4242"}
]
```

`approved_by` y `approved_run` sólo los llevan las aprobadas sin terminal (en
el [workflow de aprobación](#aprobar-sin-terminal-el-workflow-de-aprobación)):
la cuenta de GitHub de quien la lanzó y la corrida. Lo aprobado en la terminal
lo firma quien comitea. Una versión anterior los ignora.

## Limitaciones y riesgos

- **Crear el candado cambia el modo de verify para `#[HandRolled]`.** Si el
  proyecto no tenía `.larapack/hand-rolled.lock`, la primera aprobación lo
  crea, y desde entonces un `#[HandRolled]` sin aprobar es **error** y no
  aviso. Es lo que pasa con la primera aprobación de un atributo; aquí puede
  sorprender porque se aprobaba otra cosa. Aprueba también los atributos
  legítimos en el mismo commit, o llévalos a su request.
- **Una versión anterior a 9.4.0** lee el candado (la forma es la misma), pero
  no conoce los objetivos `realign:`: su verify los ve como aprobaciones que
  sobran (`hand-rolled-stale`, aviso) y `--strict` falla. Todo el equipo y CI
  tienen que estar en 9.4.0 o más antes de aprobar la primera.
- **Una excepción de un archivo entero caduca con cualquier cambio** de ese
  archivo. Es a propósito —cada cambio es código nuevo sin aprobar—, pero en
  un archivo vivo (un `routes/web.php` al que se añaden rutas) obliga a
  reaprobar a menudo. Prefiere lo estrecho: el closure, no el archivo.
- **La URI de un closure es su nombre.** Cambiar la URI de una ruta aprobada
  la deja sin cubrir y deja la aprobación vieja sobrando: hay que pedirla otra
  vez y retirar la vieja.
- **`approve --all` aprueba todo lo pedido.** Es cómodo y es exactamente lo
  que no se debe hacer sin leer: un agente puede pedir lo que quiera. Por eso
  la confirmación enseña cada una y pide escribir cuántas son.
- **La terminal es de la persona, no prueba quién es.** Un agente que corre
  dentro de la terminal de alguien puede abrir `/dev/tty`; entonces quien lee
  la pregunta y escribe la frase es esa persona, que tiene que leerla. La vía
  sin terminal no se fía de un nombre: es la cuenta que lanzó la corrida, y la
  CI la comprueba; [lo que no garantiza](#aprobar-sin-terminal-el-workflow-de-aprobación),
  dicho allí.
- **Exceptuar un hallazgo de seguridad es aceptar el agujero.** Se puede; la
  razón queda escrita y la aprobación en el historial.
- **Lo que cubre una excepción ya no lo vigila nadie** mientras no cambie.
  Revisa el candado de vez en cuando (`larapack:hand-rolled list`).
- **`larapack:verify --root=<paquete>`** no ve el candado de la raíz: las
  excepciones son del proyecto, y se miden desde su raíz.
