# Cuando el dueño no está: `larapack:realign brief`

Esta guía es la parte de [`larapack:realign`](README.md) que resuelve el caso
normal de un realineamiento hecho por un agente: **el agente llega hasta donde
puede llegar solo y lo que queda es del dueño del proyecto**. Decisiones que
cambian el comportamiento o el alcance (controladores a mano, closures de
rutas, modelos que adoptar, llaves foráneas) y excepciones que sólo el dueño
aprueba. En un proyecto real fueron 77 decisiones. Preguntarlas de una en una
deja al agente parado y al dueño contestando sin contexto. Por eso el agente
las junta **en un solo documento**, avisa al dueño y sigue con lo demás.

## Índice

- [El flujo normal](#el-flujo-normal)
- [Qué trae el documento](#qué-trae-el-documento)
- [La recomendación del paquete](#la-recomendación-del-paquete)
- [Para el dueño: cómo leerlo](#para-el-dueño-cómo-leerlo)
- [Para un agente: la receta](#para-un-agente-la-receta)
- [El formato JSON](#el-formato-json)
- [Limitaciones y riesgos](#limitaciones-y-riesgos)

## El flujo normal

```
diagnose → plan → (lo determinista y lo guiado) → brief → avisar al dueño
                                      ↑                         │
                                      └──── status ← el dueño decide y aprueba
```

1. **El agente hace todo lo que no depende del dueño**: los pasos
   deterministas (`apply --deterministic`), los guiados con su playbook y,
   para las decisiones, lo que se puede preparar sin decidir
   (`larapack:realign infer <id> --dry-run`, que dice si las rutas de un
   controlador se conservan al declararlo). Si el dueño ya dijo qué hacer con
   algo, lo registra y pide la excepción (`except`), pero **no la aprueba**.
2. **Genera el documento** y se lo da al dueño:

   ```bash
   vendor/bin/builder larapack:realign brief                 # o: php artisan larapack:realign brief
   vendor/bin/builder larapack:realign brief --format=json   # lo mismo, para otro agente
   ```

   Escribe `.larapack/realign/brief.md` (para una persona) y
   `.larapack/realign/brief.json` (para un agente) y lo imprime. No decide, no
   aprueba y no toca el código, el plan ni el candado.
3. **Avisa al dueño** con el resumen de arriba del documento (cuántas, cuánto
   tiempo de revisión) y la ruta del archivo. Mientras espera, sigue con lo
   que el documento lista en «lo que el agente puede seguir haciendo».
4. **El dueño decide y aprueba** corriendo las órdenes del documento tal
   cual: `larapack:realign decide …` y `larapack:hand-rolled approve …`.
5. **El agente retoma** con `larapack:realign status` y `next`: lo decidido
   ya trae su orden y su playbook ([plan.md](plan.md#next)). Si lo decidido es
   dejarlo como está, pide las excepciones y, si quedan por aprobar, vuelve a
   generar el documento: `brief` siempre dice lo que **todavía** espera al
   dueño, y nada más.

## Qué trae el documento

Arriba, **el resumen**: cuántas decisiones y cuántas excepciones esperan,
cuánto tiempo estimado lleva revisarlas, qué pasa si no se decide nada y
cuántos pasos puede seguir haciendo el agente mientras.

**Cada decisión pendiente** (los pasos de la categoría `decision` sin
decidir; los ya decididos no son del dueño):

| Parte | Qué dice |
|---|---|
| Qué es | El título y la descripción del paso, y su ámbito. |
| Por qué importa | El riesgo del tipo y por qué, y si se puede deshacer. |
| Evidencia | Los archivos, líneas y hallazgos, **agrupados por tipo y directorio**: setenta controladores de `Admin/` son un grupo con su cuenta, no setenta bloques. Un mensaje que se repite en todo el grupo (cambiando sólo la línea) se dice una vez. Un closure se nombra por su llamada y su URI (`post('/csp-report')`), como su excepción. El markdown enseña diez por grupo; el JSON, todos. |
| Opciones | Cada una con sus consecuencias, lo que hará el agente después (su orden o su playbook), si vuelve a necesitar al dueño (las que piden excepciones) y **la orden exacta para elegirla**: `larapack:realign decide <id> <opción> --note="…"`. |
| Recomendación | La del paquete, sólo si una regla la sostiene ([abajo](#la-recomendación-del-paquete)). |
| Bloquea | Lo que no puede terminar mientras no se decida: su `check`, los pasos que dependan de ella y `larapack:verify --strict`. Si la decisión espera además a un paso determinista, lo dice: se puede decidir ya, y lo que siga espera a ese paso. |
| Cómo pensarla | El playbook de la decisión (`larapack:realign playbook decide-…`), si lo tiene ([guided.md](guided.md#los-playbooks-del-paquete)). |

**Cada excepción pedida sin aprobar** (las de `.larapack/realign/exceptions.json`
cuyo estado no es `approved`, ver [exceptions.md](exceptions.md#el-ciclo)):
su objetivo, el paso que la pidió, archivo y línea, el motivo con que se
pidió, el mensaje del hallazgo que cubre, su estado (`unapproved`,
`reason-changed`, `code-changed`) y **la orden exacta**:
`larapack:hand-rolled approve "<objetivo>"`. Una cuyo código ya no existe
(`stale`) trae la de retirarla (`revoke`).

Abajo, **lo que el agente puede seguir haciendo** (los pasos pendientes que no
son decisiones y las decisiones ya tomadas, cuyo trabajo hace el agente) y
**cómo se retoma**.

## La recomendación del paquete

El paquete recomienda **sólo cuando una regla de su playbook la sostiene**, y
dice cuál y si es firme o tentativa. Sin regla, lo dice y dice qué falta saber.
Una recomendación nunca decide: el agente no la sigue por su cuenta.

| Decisión | Recomienda | Por qué |
|---|---|---|
| `no-contract` | `infer` (firme) | Inferir sólo escribe un borrador y su informe; adoptarlo se decide después. |
| `untracked-edited` | `adopt` (firme) | Conserva lo escrito y lo protege de `--force`; la excepción caduca con cada cambio. |
| `route-closure` | `except` si todas son de las legítimas conocidas (`OPTIONS`, `fallback`, salud, CSP); si no, `refactor` nombrando las que no lo son (tentativa) | Las conocidas no tienen datos de nadie; las demás probablemente leen o escriben sin request. Se pueden mezclar. |
| `unmanaged-models` | `keep` (firme si es el `User` de Laravel; tentativa si no) | Adoptar genera CRUD, política y rutas: ante la duda, no se adopta, y adoptarlo después sigue siendo posible. |
| `missing` | `restore` (tentativa) | El contrato los sigue pidiendo; si se borraron adrede, lo limpio es retirarlos del contrato. |
| `contract-invalid` | `fix` (tentativa) | Corregir el contrato no toca el código. |
| `inconsistent-entity` | `except` si es el `User` de Laravel; si no, ninguna | Al `User` le falta lo que no necesita; a otro modelo, depende. |
| `unmanaged-http` | Ninguna | Depende de si sus rutas se conservan al declararlas: lo dice `larapack:realign infer <id> --dry-run`. |
| `hand-rolled` | Ninguna | Cada atributo trae su razón; sólo quien conoce el dominio juzga si la acción no puede delegar. |

## Para el dueño: cómo leerlo

- Empieza por el resumen: el tiempo estimado es para planear la revisión, no
  una promesa (unos minutos por decisión más uno por elemento de evidencia, y
  uno por excepción).
- Corre las órdenes en la raíz del proyecto, tal cual
  (`vendor/bin/builder …` o `php artisan …`). Cambia la nota de `decide` por
  tu razón: queda en el plan.
- Una excepción no cubre nada hasta que la apruebas. Lee el motivo y el
  hallazgo antes; `approve --all` existe, pero es aprobar sin leer. Después,
  `git diff .larapack/hand-rolled.lock` y comitéalo: la aprobación es ese
  commit ([exceptions.md](exceptions.md#por-qué-un-agente-no-puede-aprobar)).
- No hace falta decidirlo todo de una vez. Lo que no decidas sigue esperando y
  no rompe nada; el siguiente `brief` lo vuelve a traer.

## Para un agente: la receta

1. Haz todo lo que no depende del dueño ([la receta](README.md#para-un-agente-la-receta)).
   Cuando `next` sólo te dé decisiones o pasos bloqueados por ellas, para.
2. `larapack:realign brief --format=json`. Si `summary.decisions` y
   `summary.exceptions` son 0, no hay nada del dueño: sigue con `next`.
3. Avisa al dueño con `summary` y la ruta de `files.markdown`. No decidas, no
   apruebes, no edites `plan.json` ni el candado, aunque la recomendación
   parezca obvia.
4. Mientras esperas, sigue con lo de `meanwhile`.
5. Cuando el dueño avise, `larapack:realign status` y `next`, y vuelve a la
   receta. Si lo decidido pidió excepciones, pídelas (`except`) y genera el
   documento otra vez para que las apruebe.

## El formato JSON

`brief --format=json` y `.larapack/realign/brief.json` son el mismo documento:

```
{format_version, ok, action: "brief", generated_at, diagnosed_at, planned_at, stale,
 summary: {decisions, exceptions, review_minutes, meanwhile, if_nothing_is_decided},
 decisions: [{id, type, title, description, why, risk, reversible, scope, status,
              evidence: {count, groups: [{kind, directory, count, message, items: [{file, line, subject, message}]}]},
              options: [{key, label, consequences, decide, then, playbook, needs_owner_again}],
              recommendation: {option, confidence, why}, playbook, blocks, waits_for, review_minutes}],
 exceptions: [{target, type, step, file, line, subject, reason, status, finding, approve, command, blocks}],
 meanwhile: [{id, title, category, status}],
 resume: {command, then, how},
 files: {markdown, json}}
```

`recommendation.option` es null cuando no hay regla; `confidence` es `firm` o
`tentative`. `exceptions[].approve` es null en una `stale` (`command` trae su
`revoke`). Como el resto del realineamiento, `format_version` es `1`
([plan.md](plan.md#el-estado-en-disco-y-los-formatos-json)).

## Limitaciones y riesgos

- **Es una foto.** Se rehace cada vez y no es el estado: el estado es
  `plan.json`, `exceptions.json` y el candado. Si el contrato o el manifiesto
  cambiaron después del diagnóstico, el documento lo dice arriba: pide
  `diagnose`, `plan` y `brief` otra vez antes de decidir. No hace falta
  versionarlo.
- **La recomendación es una regla, no un juicio.** La de los closures mira la
  llamada y la URI, no el cuerpo: un `/health` que devuelve datos de alguien
  sigue pareciendo legítimo. Por eso es tentativa y pide revisar el cuerpo.
- **El tiempo es una estimación** por tipo de decisión y número de elementos.
- **Las excepciones de `#[HandRolled]`** no salen como excepciones: salen en
  su decisión (`hand-rolled`), con su evidencia.
- **Acotado con `--package`**, sólo trae las decisiones y las excepciones de
  esos ámbitos.
