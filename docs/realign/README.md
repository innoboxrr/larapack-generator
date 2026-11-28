# Realinear un proyecto con LaraPack: `larapack:realign`

Llevar de vuelta al contrato un proyecto que empezó con LaraPack (o no) y se
desvió, **sin reconstruirlo**. Lo que es determinista se aplica solo; lo
mecánico lo hace una persona o un agente siguiendo un playbook del paquete, con
una comprobación concreta como criterio de terminado; lo que es decisión se
presenta como decisión, con sus opciones; y lo que no es de LaraPack se declara
fuera y no se toca.

Esta guía es para cualquiera que lo ejecute, persona o agente, **sin contexto
previo del proyecto**. No hace falta escribir un prompt por proyecto ni
preguntarle a nadie cómo se hace: el paquete trae el diagnóstico, el plan, las
instrucciones de cada tipo de paso, sus comprobaciones y esta documentación.

> **Si llegas sin contexto**, empieza aquí:
>
> ```bash
> vendor/bin/builder larapack:realign status    # o: php artisan larapack:realign status
> ```
>
> Dice si hay un realineamiento en curso, dónde va y cuál es el siguiente paso.
> Sin plan, dice que hay que diagnosticar. Después, el inicio rápido que te
> toque: [persona](#inicio-rápido-para-una-persona) o
> [agente](#inicio-rápido-para-un-agente). Si el paquete no está en `vendor/`
> del proyecto, mira primero
> [cómo se invoca](#cómo-se-invoca-con-el-paquete-en-vendor-o-desde-un-clon).
> Una palabra que no se entiende está en el [glosario](glossary.md); un
> atasco, en [«¿qué hago si…?»](faq.md).

## Índice

- [Inicio rápido para una persona](#inicio-rápido-para-una-persona)
- [Inicio rápido para un agente](#inicio-rápido-para-un-agente)
- [Cuándo usarlo y cuándo no](#cuándo-usarlo-y-cuándo-no)
- [El modelo mental](#el-modelo-mental)
- [Las cuatro categorías](#las-cuatro-categorías)
- [El ciclo completo](#el-ciclo-completo)
- [Cómo se invoca: con el paquete en vendor o desde un clon](#cómo-se-invoca-con-el-paquete-en-vendor-o-desde-un-clon)
- [El estado: reanudable por cualquiera](#el-estado-reanudable-por-cualquiera)
- [Seguridad: qué garantiza](#seguridad-qué-garantiza)
- [Las acciones](#las-acciones)
- [Para un agente: la receta](#para-un-agente-la-receta)
- [Las guías de cada parte](#las-guías-de-cada-parte)

## Inicio rápido para una persona

Diez líneas, en un proyecto con contrato. Sin contrato se empieza por
[infer.md](infer.md), y el recorrido según de dónde parte el proyecto está en
[profiles.md](profiles.md).

```bash
git switch -c realign                                                 # 1. una rama, con el árbol limpio
vendor/bin/builder larapack:realign diagnose                          # 2. compara el proyecto con su contrato; no escribe código
vendor/bin/builder larapack:realign plan                              # 3. lo convierte en pasos
vendor/bin/builder larapack:realign apply --deterministic --dry-run   # 4. qué haría lo automático, con sus diffs
vendor/bin/builder larapack:realign apply --deterministic             # 5. lo hace; corre tus pruebas y comitea
vendor/bin/builder larapack:realign diagnose                          # 6. otra pasada: repite 3, 5 y 6 hasta que apply no aplique nada
vendor/bin/builder larapack:realign next                              # 7. el siguiente paso, con sus instrucciones
vendor/bin/builder larapack:realign check <id>                        # 8. ¿hecho? un commit por paso, y vuelve a 7
vendor/bin/builder larapack:realign brief                             # 9. lo que sólo decide o aprueba el dueño, en un documento
vendor/bin/builder larapack:realign done                              # 10. sale con 0: terminado; si no, dice qué falta
```

- En la línea 6, cuando ya no queda nada automático, `apply --deterministic`
  dice «No hay pasos pendientes que `apply` sepa hacer» y sale con error: es
  la señal de que se acabó lo automático, no un fallo.
- En la línea 8, un paso que cambia comportamiento pide su prueba:
  `check <id> --tested="<la orden de pruebas que corriste y pasó>"`. Sin ella
  queda `untested` ([sin pruebas](guided.md#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto)).
- Qué pasa entre línea y línea: [el ciclo completo](#el-ciclo-completo).

## Inicio rápido para un agente

Diez líneas; cada orden da JSON con `--format=json`. La versión con todos los
casos es [la receta](#para-un-agente-la-receta).

1. `larapack:realign status --format=json`. Si `plan` es null o `stale` no
   está vacío: `larapack:realign diagnose` y `larapack:realign plan`.
2. `larapack:realign apply --deterministic --dry-run` y después sin
   `--dry-run`; pruebas del proyecto, commit, y `diagnose` y `plan` otra vez.
   Repite hasta que `apply` diga que no hay pasos pendientes.
3. `larapack:realign next --format=json` y mira `step.category`.
4. `deterministic`: ejecuta `how.command`. `guided`: sigue `how.instructions`
   al pie de la letra, con las pruebas del proyecto antes y después.
5. `decision`: **no decidas tú**. Presenta `how.options` al dueño y sigue con
   `meanwhile` (`larapack:realign next <meanwhile.id>`).
6. `larapack:realign check <id> --format=json`. Con `done: true`, comitea y
   vuelve al 3; si no, `remaining` dice qué falta. Si el paso cambia
   comportamiento (`tests.required`), pasa `--tested="<la orden que corriste y
   pasó>"`; sin ella queda `untested`, que es la verdad si no pudiste probar.
7. Lo que no se puede hacer ahora: `larapack:realign defer <id> --note="…"`.
   Lo que no cabe, o es cosa del dueño: `larapack:realign escalate <id>
   --note="…"`.
8. Cuando lo que queda es del dueño: `larapack:realign brief`, avísale y
   sigue con lo que no depende de él. **Nunca** corras por tu cuenta
   `larapack:realign decide`, `larapack:hand-rolled approve` ni
   `larapack:realign accept-untested`.
9. Tras los pasos guiados de un ámbito, o si copiaste lo regenerado o cambió
   el contrato: otra pasada (el 1 y el 2).
10. Cuando `next` dé `done: true`: `larapack:realign done --format=json`. Sale
    con 0, o `missing` dice qué falta y de quién es.

## Cuándo usarlo y cuándo no

**Úsalo** cuando un proyecto tiene (o quiere tener) su arquitectura en un
`laraimport.json` y lo que hay en disco ya no es lo que el contrato generaría:

- El proyecto se generó con una versión anterior de LaraPack (7.x, 8.x, 9.x) y
  quieres llevarlo a la actual sin `import --force` a ciegas.
- Alguien (o un agente) editó lo generado fuera de los huecos, o escribió a
  mano controladores, rutas y modelos que el contrato podría declarar.
- `larapack:verify` da cientos de hallazgos y no sabes por dónde empezar.
- El proyecto nunca usó LaraPack, pero sigue convenciones parecidas, y quieres
  saber cuánto cuesta adoptarlo (`infer`).

**No lo uses** para:

- Generar un proyecto nuevo: eso es `larapack:import` desde un contrato.
- Añadir un modelo o un endpoint: se declara en el contrato y se importa.
- Cambiar el esquema de la base: un realineamiento **nunca** escribe ni
  reescribe una migración (ver [risks.md](risks.md#migraciones-y-datos)).
- Rehacer el front, los servicios o el dominio: eso es `out_of_scope`.

## El modelo mental

LaraPack genera el código de un proyecto desde su contrato (`laraimport.json`)
y anota cada archivo que escribe en `.larapack/manifest.json`, con su plantilla
y el hash de su contenido. Un proyecto se desvía de tres formas, y las tres se
ven comparando el proyecto con **lo que generaría hoy su contrato con la
versión actual del generador**:

1. **El generador avanzó** y el proyecto no: plantillas de una versión
   anterior, deuda que `larapack:fix` sabe pagar.
2. **Alguien editó lo generado**: a veces dentro de los huecos (es lo
   correcto), a veces fuera.
3. **Hay código escrito a mano** que hace lo que haría el contrato:
   controladores, rutas, modelos.

`diagnose` regenera el contrato **aparte** (en `.larapack/realign/cache/`,
nunca encima del proyecto), compara archivo por archivo y clasifica cada
diferencia. `plan` convierte eso en pasos con un criterio de terminado que no
depende de la opinión de nadie. El resto del ciclo es ir haciendo pasos y
comprobándolos. Cada término —ámbito, hueco, paso, decisión, excepción,
adoptar— está definido una sola vez, en el [glosario](glossary.md).

## Las cuatro categorías

Todo lo que encuentra el diagnóstico cae en una de cuatro categorías, las
mismas en el informe, en el plan, en la salida de cada comando y en esta
documentación:

| Categoría | Qué significa | Quién lo hace | Cómo |
|---|---|---|---|
| `deterministic` | Se aplica con una orden y su resultado se comprueba sin opinar. Nunca toca lo que no reconoce con certeza: lo salta, con su razón. | La herramienta. | `adopt` y `apply` ([adopt.md](adopt.md), [apply.md](apply.md)). |
| `guided` | Trabajo mecánico que no se puede automatizar con certeza: mover lógica a su sitio, devolver código a sus huecos. Las instrucciones vienen del paquete (playbook) y el criterio de terminado es una comprobación concreta. | Una persona o un agente. | `next` da el playbook rellenado; `check` lo da por hecho ([guided.md](guided.md)). |
| `decision` | Hay más de una respuesta legítima y cambia el comportamiento o el alcance. Se presenta con sus opciones y consecuencias. | Quien tenga autoridad sobre el proyecto. **Nunca un agente por su cuenta.** | `decide`, y después lo que la opción pida (`apply`, un playbook, `except` con [aprobación del dueño](exceptions.md)). |
| `out_of_scope` | No es de LaraPack: dominio, servicios, front propio, la historia de las migraciones, lo que el generador ya no regenera. | Nadie. | Se cuenta y se declara; ningún comando lo toca. |

## El ciclo completo

De principio a fin, con todo lo que puede pasar por el camino. Cada palabra
suelta es una acción de `larapack:realign`; lo que hace **el dueño** está
dicho.

```
1. ¿Dónde estoy?   status ──┬─ sin plan, o diagnóstico viejo (stale) ──► diagnose ► plan
                            └─ con plan al día ──► sigue en el 2 o en el 3

2. Lo automático   apply --deterministic --dry-run ► apply --deterministic ► pruebas ► commit
                      ├─ aplicó algo ──► diagnose ► plan ► apply --deterministic  (otra pasada)
                      └─ «No hay pasos pendientes que apply sepa hacer» ──► sigue en el 3

3. Paso a paso     next ──┬─ deterministic ► la orden de how.command ──► 4
                          │
                          ├─ guided ► el playbook de how.instructions, con las pruebas
                          │     │      del proyecto antes y después ──► 4
                          │     ├─ no se puede ahora ► defer   (sigue pendiente; undefer lo devuelve)
                          │     └─ no cabe en un hueco, o es cosa del dueño ► escalate
                          │                                    (el paso pasa a ser una decision)
                          │
                          └─ decision ► es del dueño: se le presentan how.options
                                │        mientras decide, se sigue con `meanwhile`
                                │        son muchas, o el dueño no está ► brief (un documento)
                                │
                                └─ EL DUEÑO: decide <id> <opción> ► lo que pida la opción:
                                      ├─ una orden (apply, adopt, infer) o un playbook ───► 4
                                      └─ dejarlo como está ► except <id> --option reason="…"
                                            └─ EL DUEÑO: larapack:hand-rolled approve ──► 4

4. ¿Está hecho?    check <id> ──┬─ falta algo (remaining) ► se resuelve ► check otra vez
                                ├─ done ► commit del paso ► vuelve al 3
                                └─ untested: el paso cambia comportamiento y nadie
                                      │      registró su prueba (cuenta como hecho para
                                      │      seguir, no para terminar)
                                      ├─ pruebas en verde ► check <id> --tested="<orden>" ► done
                                      └─ no se pueden correr ► EL DUEÑO: accept-untested ► done

5. Otra pasada     después de los pasos guiados de un ámbito, de copiar lo regenerado
                   o de cambiar el contrato: diagnose ► plan ► apply --deterministic ► 3

6. ¿Terminado?     done ──┬─ sale con 0 ► terminado
                          └─ sale con 1 ► `missing` dice qué falta (steps, decisions, untested,
                                          exceptions, validate, verify) ► se resuelve ► done
```

Las órdenes del camino principal:

```bash
vendor/bin/builder larapack:realign diagnose     # o php artisan larapack:realign diagnose
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign next         # el siguiente paso, con todo lo necesario
# … hacer lo que dice el paso …
vendor/bin/builder larapack:realign check <id>   # ¿está hecho? lo marca, o dice qué falta
vendor/bin/builder larapack:realign status       # dónde se va
vendor/bin/builder larapack:realign done         # ¿terminado? 0, o lo que falta
```

Todas aceptan `--root=<proyecto>` y `--format=json`. Las que leen el proyecto
aceptan además `--package` (repetible: el directorio en `packages/`, la ruta,
el nombre de Composer o `app` para el núcleo), igual que `larapack:verify`.
`larapack:realign help <acción>` explica cada una con su uso, lo que escribe y
su guía; `larapack:realign --help` las lista todas.

**Otra pasada `diagnose` → `plan` → `apply --deterministic`** (el 2 y el 5 del
diagrama) hace falta en tres momentos, y no es opcional:

1. **Después de cada `apply --deterministic`**: aplicar no marca los pasos
   hechos —los cierra la pasada, que ya no los encuentra, o `check`—, y una
   corrección puede dejar un archivo igual a lo regenerado, que sale como
   `adopt` en la pasada siguiente.
2. **Después de copiar lo regenerado** encima de un archivo (lo que pide el
   playbook `edited-outside`) o de cambiar el contrato: el archivo copiado
   tiene que adoptarse para que el manifiesto diga la verdad, y un contrato
   distinto cambia lo regenerado.
3. **Después de completar pasos guiados**, al menos al terminar los de un
   ámbito: un controlador que ya delega o un archivo devuelto a sus huecos
   pueden dejar pasos deterministas nuevos, o cerrar otros.

`status` lo recuerda cuando el contrato o el manifiesto cambiaron (`stale`),
pero no ve un archivo copiado a mano: la pasada la decides tú. Repítela hasta
que `apply --deterministic` no tenga nada que aplicar (lo dice, y sale con
error: [apply.md](apply.md#correrla)).

**Se termina cuando `larapack:realign done` sale con 0.** Es el único
criterio: diagnostica y planifica otra vez y recorre todos los ámbitos, y
falla —diciendo qué falta, elemento por elemento— mientras quede un paso sin
hacer, una decisión sin tomar, un paso sin probar que el dueño no aceptó, una
excepción sin aprobar, o un error o aviso de `validate --strict` o de
`verify --strict`. Ni «el plan sin pendientes» ni «verify en verde» bastan
solos: `verify` no ve lo editado fuera de los huecos, y el plan no ve lo que
se desvió después del último diagnóstico. Todo en
[verify.md](verify.md#cuándo-se-termina).

## Cómo se invoca: con el paquete en vendor o desde un clon

Hay tres formas de llegar a las mismas órdenes, y **todas las páginas de esta
guía escriben la segunda**:

| Dónde está el paquete | Cómo se invoca |
|---|---|
| Aplicación Laravel con el paquete instalado | `php artisan larapack:realign status` |
| Paquete en `vendor/` del proyecto (cualquier proyecto) | `vendor/bin/builder larapack:realign status` |
| **No está en `vendor/`**: un clon de innoboxrr/larapack-generator en otro directorio | `php <clon>/builder larapack:realign status --root=<proyecto>` |

**La notación.** Donde la guía escribe `vendor/bin/builder larapack:…` (o
`larapack:…` a secas), sustituye la forma de tu fila. Donde escribe una ruta
del paquete —`vendor/innoboxrr/larapack-generator/docs/realign/`,
`resources/realign/playbooks/`— léela relativa a `<paquete>`: el directorio
del paquete, que es `vendor/innoboxrr/larapack-generator` en las dos primeras
filas y `<clon>` en la tercera. Las rutas del **proyecto**
(`.larapack/realign/`, `laraimport.json`, los archivos de un paso) son siempre
relativas a `<proyecto>`, la raíz del proyecto que se realinea.

Con un clon:

- el clon necesita sus dependencias: `composer install` **dentro del clon**,
  una vez;
- **pasa `--root=<proyecto>` en cada orden** (con ruta absoluta, o relativa al
  directorio desde el que corres). Sin `--root`, el `builder` de un clon
  ejecutado fuera de un proyecto falla y lo dice («No se encontró el proyecto
  desde …»): no adivina el proyecto;
- `diagnose` regenera con el generador del clon, así que la versión que cuenta
  es la del clon (`cat <clon>/VERSION`).

Para ensayar sobre una copia del proyecto no hace falta `rsync` (no está en
todos los entornos): `git worktree add ../copia` si es un repositorio, o
`mkdir ../copia && tar -C <proyecto> -cf - . | tar -C ../copia -xf -`.

## El estado: reanudable por cualquiera

El estado vive en el proyecto, en `.larapack/realign/`, no en la cabeza de
quien empezó: el último diagnóstico (`report.json`), el plan con cada paso, su
estado, sus decisiones y su historia (`plan.json`) y las excepciones pedidas
(`exceptions.json`). Otra persona u otro agente que llegue mañana corre
`status` y `next` y sigue. Volver a diagnosticar y planificar **no pierde lo
hecho ni lo decidido**: un paso con el mismo id conserva su estado, su
decisión y su historia.

**Versiona `plan.json` y `exceptions.json`** junto con el código (y
`config.json`, si registras ahí la orden de pruebas): así el plan viaja con la
rama, y un `git revert` de un paso deja también su estado como estaba. La
caché (`cache/`) no se versiona: su `.gitignore` la deja fuera.

Cada archivo del directorio, sus formatos y lo que garantiza cada campo están
en [plan.md](plan.md#el-estado-en-disco-y-los-formatos-json).

## Seguridad: qué garantiza

Los principios, que valen para todas las acciones:

- **Lo que sólo lee es seguro siempre.** `diagnose`, `plan`, `status`, `next`,
  `show`, `check`, `decide`, `defer`, `undefer`, `escalate`,
  `accept-untested`, `done`, `brief`, `playbook` y `help` no escriben código:
  como mucho, su estado (o el documento para el dueño) en
  `.larapack/realign/`. No exigen el árbol limpio.
- **Lo que escribe se puede simular y deshacer.** `adopt`, `apply`, `infer` y
  `except` aceptan `--dry-run` (no escribe nada y dice qué haría; `apply` y
  `adopt` hacen la corrida entera sobre una copia temporal y la tiran, así que
  cuentan lo mismo que la real, con el diff de cada cambio), y sin él
  **exigen un árbol git limpio** —fuera de `.larapack/realign/`— para que todo
  se deshaga con `git restore` o `git checkout`. Fuera de un repositorio git
  también se niegan. `--allow-dirty` lo salta, y es responsabilidad de quien
  lo pasa.
- **Lo que escribe deja algo que comitear, y lo dice.** Si después de una
  corrida quedan cambios, el resultado trae `commit` (`{required, files,
  command, message}`, null si no queda nada) y una nota «Comitea antes de seguir» con
  la orden sugerida (`git add -A && git commit -m 'realign: adopt adopt@.'`).
  Corre antes las pruebas del proyecto: la siguiente operación que escribe se
  niega con el árbol sucio.
- **Lo que no se reconoce con certeza no se toca**: se salta, con su razón
  (`skipped`), y el comando termina con error para que nadie lo pase por alto.
  Lo saltado lo resuelve el playbook del paso.
- **Ninguna migración se escribe ni se reescribe.** Lo que difiere en una
  migración se resuelve con otra migración (del proyecto, no del
  realineamiento) o en el contrato.
- **Ningún nombre de ruta ni URI cambia** sin que el contrato lo declare: el
  front y los clientes dependen de ellos.
- **Lo `out_of_scope` no se toca nunca.**
- **Un agente no decide ni aprueba**: presenta las decisiones y pide las
  excepciones; las aprueba el dueño con `larapack:hand-rolled approve`
  ([exceptions.md](exceptions.md#por-qué-un-agente-no-puede-aprobar)), y lo
  que quedó sin probar lo acepta él con `accept-untested`.
- **Hacer un paso no lo da por hecho**: sólo `check`, que corre su criterio de
  terminado.

Lo que **no** garantiza —que el comportamiento siga igual, que las pruebas
pasen, que el contrato diga lo que hace la base de producción— está en
[risks.md](risks.md).

## Las acciones

| Acción | Escribe | Qué hace | Guía |
|---|---|---|---|
| `diagnose` | estado | Regenera el contrato aparte y clasifica cada archivo y cada hallazgo de verify. | [diagnose.md](diagnose.md) |
| `plan` | estado | Convierte el diagnóstico en pasos ordenados y reanudables. | [plan.md](plan.md#plan) |
| `status` | nada | Dónde se va y qué sigue. | [plan.md](plan.md#status) |
| `next` | nada | El siguiente paso (o, con un id, ése) con todo lo necesario para hacerlo sin contexto. | [plan.md](plan.md#next) |
| `show` | nada | Cualquier paso, como lo da `next`. | [plan.md](plan.md#show) |
| `check` | estado | Corre el criterio de terminado de un paso y lo marca hecho; si cambia comportamiento, sólo con su prueba (`--tested` o la orden configurada), y si no, `untested`. | [plan.md](plan.md#check) |
| `decide` | estado | Registra la opción elegida en una decisión, entera o por elemento (`--target`). | [plan.md](plan.md#decide) |
| `defer` | estado | Aplaza un paso: sigue pendiente y `next` lo salta. | [plan.md](plan.md#defer-y-undefer) |
| `undefer` | estado | Devuelve al orden un paso aplazado. | [plan.md](plan.md#defer-y-undefer) |
| `escalate` | estado | Pasa un paso guiado al dueño como decisión. | [plan.md](plan.md#escalate) |
| `accept-untested` | estado | El dueño acepta sin probar un paso `untested`, con su nota (lo confirma él). | [guided.md](guided.md#aceptarlo-sin-probar-lo-decide-el-dueño) |
| `done` | estado | El criterio de terminado: falla y dice qué falta mientras quede algo en cualquier ámbito. | [verify.md](verify.md#cuándo-se-termina) |
| `brief` | el documento para el dueño | Junta todo lo que espera al dueño, con la orden exacta de cada cosa. | [owner.md](owner.md) |
| `playbook` | nada | Lista los playbooks o imprime uno sin rellenar. | [guided.md](guided.md#los-playbooks-del-paquete) |
| `adopt` | manifiesto | Adopta, vuelve a enlazar y olvida, sin tocar un archivo. | [adopt.md](adopt.md) |
| `apply` | código, contrato y manifiesto | Los pasos deterministas que reescriben. | [apply.md](apply.md) |
| `infer` | un borrador | Infiere un `laraimport.json` desde el código. | [infer.md](infer.md) |
| `except` | solicitudes | Pide las excepciones que aprobará el dueño. | [exceptions.md](exceptions.md) |
| `help` | nada | Lista las acciones o explica una. | esta página |

```bash
larapack:realign <acción> [<id>…] [--package=…] [--dry-run] [--allow-dirty] [--option clave=valor]
larapack:realign apply --deterministic [--package=…] [--dry-run] [--allow-dirty]
```

Una opción o un argumento que la acción no usa es un error, también una clave
de `--option` que la operación no lee: ignorarla haría creer que hizo algo.
Las opciones de cada acción las da `larapack:realign help <acción>`.

## Para un agente: la receta

Esta es toda la lógica que hace falta; no hay que inventar nada por proyecto.

1. `larapack:realign status --format=json`. Si `plan` es null o `stale` no está
   vacío: `larapack:realign diagnose` y `larapack:realign plan`.
2. `larapack:realign next --format=json`. Si `done` es true,
   `larapack:realign done --format=json`: si sale con 0, se terminó; si no,
   `missing` dice qué falta y en qué ámbito (lo resuelves y vuelves al 2).
   Es el criterio final ([verify.md](verify.md#cuándo-se-termina)). Si `done`
   es false y `step` es null, no hay nada que hacer **ahora**: `waiting` dice
   qué está aplazado, en manos del dueño o bloqueado
   ([plan.md](plan.md#next)).
3. Según `step.category`:
   - `deterministic`: si `how.available`, ejecuta `how.command`, primero con
     `--dry-run` y después sin él. Lo que la orden salte (`skipped`, con su
     razón) se hace a mano siguiendo `how.fallback.instructions`. Para ir más
     rápido, `larapack:realign apply --deterministic` hace todos los
     deterministas de una vez; después, `diagnose` y `plan` otra vez.
   - `guided`: sigue `how.instructions` al pie de la letra (el playbook del
     tipo y sus anexos), con las pruebas del proyecto antes y después. Si el
     playbook dice dejarlo (un controlador de un protocolo), o no se puede
     ahora, aplázalo con su razón (`larapack:realign defer <id> --note="…"`);
     si hacerlo o no es cosa del dueño —lo que no cabe en un hueco, por
     ejemplo—, pásaselo (`larapack:realign escalate <id> --note="…"`); la
     excepción (`except`) se pide sólo después de que él decida dejarlo así
     ([escalate o except](guided.md#lo-que-no-cabe-escalate-o-except)).
     Si cambia comportamiento (`tests.required`), `check` sólo lo da por
     hecho con su prueba (`--tested="<orden>"`); sin ella queda `untested` y
     `done` no termina: ver
     [sin pruebas](guided.md#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto).
   - `decision`: **no decidas tú**. Presenta `how.options` —y
     `how.instructions` si trae el playbook de la decisión— a quien tenga
     autoridad y, **mientras decide, sigue con `meanwhile`** (`larapack:realign
     next <meanwhile.id>`): lo que no depende de esa decisión. Las decisiones
     que condicionan lo guiado salen antes que él para que no se rehaga nada.
     Cuando decida, `larapack:realign decide <id> <opción> --note="…"`, o por
     elemento con `--target=<archivo>` (repetible). Decidido, `next` da lo que
     sigue (`how.then` y, si la opción lo trae, su playbook). Si lo decidido
     es dejarlo como está, pide la excepción (`larapack:realign except <id>
     --option reason="…"`) y **no la apruebes**: díselo al dueño
     (`larapack:hand-rolled approve`).
     Cuando lo que queda sea del dueño, no preguntes de una en una: genera
     el documento con todas (`larapack:realign brief`), avísale, sigue con
     lo que no depende de él y retoma con `status` cuando decida
     ([owner.md](owner.md)). Es el camino normal.
     Para `no-contract`, `unmanaged-models` y `unmanaged-http` puedes preparar
     el material de la decisión sin decidir nada: `larapack:realign infer <id>`
     escribe un borrador (`laraimport.inferred.json`) y su informe, y no toca
     el código ni el contrato ([infer.md](infer.md)).
4. `larapack:realign check <id> --format=json`. Si `done` es false, resuelve
   `remaining` y vuelve a comprobar.
5. Comitea el paso (un commit por paso, con el `.larapack/realign/plan.json`
   que lo marca hecho) y vuelve al 2.
6. Al terminar los pasos guiados de un ámbito —y siempre que hayas copiado lo
   regenerado o cambiado el contrato—, otra pasada: `diagnose`, `plan`,
   `apply --deterministic` (ver [el ciclo](#el-ciclo-completo)), y vuelve al 2.

Reglas que no se rompen aunque parezca que ayudan: no edites
`.larapack/hand-rolled.lock` ni `plan.json` a mano, no añadas `#[HandRolled]`
para que verify pase, no uses `--allow-dirty` para saltarte un árbol sucio que
no es tuyo, no cambies un nombre de ruta ni una URI que el contrato no
declare, no escribas ni reescribas migraciones, y no pongas en `--tested` una
orden que no corriste.

Para que un agente encuentre esta guía en un proyecto, `larapack:skill` instala
la guía del realineamiento (`.claude/skills/larapack-realign/SKILL.md`) y el
bloque de `AGENTS.md` y de las reglas de Cursor, Codex, Gemini y Copilot que
apunta a `larapack:realign status` y a esta documentación.

## Las guías de cada parte

Cada página tiene un propósito; lo que es de otra, lo enlaza.

| Guía | Qué explica |
|---|---|
| [Glosario](glossary.md) | Cada término, definido una vez: ámbito, contrato, manifiesto, hueco, paso, categoría, decisión, excepción, adoptar… |
| [¿Qué hago si…?](faq.md) | Los atascos reales y su salida: una decisión que no es tuya, no poder correr las pruebas, `untested`, `done` que no termina, lo que no cabe en un hueco, el árbol sucio, el paquete fuera de `vendor/`, un monorepo, un proyecto sin contrato. |
| [Diagnosticar](diagnose.md) | `diagnose`: cómo se compara el proyecto con su contrato, cómo leer el informe y qué significa cada clasificación. |
| [El plan y el estado](plan.md) | `plan`, `status`, `next`, `show`, `check`, `decide`, `defer`, `undefer`, `escalate` y `accept-untested`: los pasos, su orden, su comprobación de terminado y los formatos JSON de todo. |
| [Adoptar código existente](adopt.md) | `adopt`: que el manifiesto diga la verdad sin tocar un archivo. |
| [Correcciones automáticas](apply.md) | `apply`: lo determinista que reescribe código o el contrato, y el salto de versión mayor. |
| [Pasos guiados](guided.md) | Cómo hace una persona o un agente lo que no es determinista, con los playbooks del paquete; qué pasa cuando no se pueden correr las pruebas. |
| [Cuando el dueño no está](owner.md) | `brief`: todas las decisiones y excepciones que esperan al dueño en un documento, con la orden exacta de cada una, para que el agente siga mientras. |
| [Excepciones](exceptions.md) | `except` y `larapack:hand-rolled`: dejar algo como está, con la aprobación del dueño. |
| [Inferir el contrato](infer.md) | `infer`: un `laraimport.json` borrador desde el código de un proyecto sin contrato. |
| [El contrato ampliado](contract.md) | Las claves de 9.4.0 que permiten describir un proyecto real sin cambiarlo. |
| [Verificar el cumplimiento](verify.md) | `larapack:realign done`, `larapack:verify --strict`, `larapack:ci` y `larapack:guard`: cuándo se termina, qué garantiza y qué no. |
| [Limitaciones y riesgos](risks.md) | Por operación y por tema: datos, migraciones, el front, 403/422, Windows, monorepos, rendimiento. |
| [Los tres perfiles de proyecto](profiles.md) | El recorrido recomendado según de dónde parte el proyecto, con cifras de proyectos reales. |
