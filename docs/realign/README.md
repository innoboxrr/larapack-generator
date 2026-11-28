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
> Sin plan, dice que hay que diagnosticar. Después, [la receta](#para-un-agente-la-receta).
> Si el paquete no está en `vendor/` del proyecto, mira primero
> [cómo se invoca](#cómo-se-invoca-con-el-paquete-en-vendor-o-desde-un-clon).

## Índice

- [Cuándo usarlo y cuándo no](#cuándo-usarlo-y-cuándo-no)
- [El modelo mental](#el-modelo-mental)
- [Las cuatro categorías](#las-cuatro-categorías)
- [Cómo se invoca: con el paquete en vendor o desde un clon](#cómo-se-invoca-con-el-paquete-en-vendor-o-desde-un-clon)
- [El ciclo](#el-ciclo)
- [El estado: reanudable por cualquiera](#el-estado-reanudable-por-cualquiera)
- [Seguridad: qué garantiza](#seguridad-qué-garantiza)
- [Las acciones](#las-acciones)
- [Para un agente: la receta](#para-un-agente-la-receta)
- [Para quien lo ejecuta por primera vez: un recorrido](#para-quien-lo-ejecuta-por-primera-vez-un-recorrido)

Las guías de cada parte:

| Guía | Qué explica |
|---|---|
| [Diagnosticar](diagnose.md) | `diagnose`: cómo se compara el proyecto con su contrato, cómo leer el informe y qué significa cada clasificación. |
| [El plan y el estado](plan.md) | `plan`, `status`, `next`, `check` y `decide`: los pasos, su orden, su comprobación de terminado y los formatos JSON. |
| [Adoptar código existente](adopt.md) | `adopt`: que el manifiesto diga la verdad sin tocar un archivo. |
| [Correcciones automáticas](apply.md) | `apply`: lo determinista que reescribe código o el contrato, y el salto de versión mayor. |
| [Pasos guiados](guided.md) | Cómo hace un agente lo que no es determinista, con los playbooks del paquete. |
| [Excepciones](exceptions.md) | `except` y `larapack:hand-rolled`: dejar algo como está, con la aprobación del dueño. |
| [Inferir el contrato](infer.md) | `infer`: un `laraimport.json` borrador desde el código de un proyecto sin contrato. |
| [El contrato ampliado](contract.md) | Las claves de 9.4.0 que permiten describir un proyecto real sin cambiarlo. |
| [Verificar el cumplimiento](verify.md) | `larapack:verify`, `--strict`, `larapack:ci` y `larapack:guard`: qué garantizan al terminar y qué no. |
| [Limitaciones y riesgos](risks.md) | Por operación y por tema: datos, migraciones, el front, 403/422, Windows, monorepos, rendimiento. |
| [Los tres perfiles de proyecto](profiles.md) | El recorrido recomendado según de dónde parte el proyecto, con cifras de proyectos reales. |

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
comprobándolos.

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
  ejecutado fuera de un proyecto falla y lo dice: no adivina el proyecto;
- `diagnose` regenera con el generador del clon, así que la versión que cuenta
  es la del clon (`cat <clon>/VERSION`).

Para ensayar sobre una copia del proyecto no hace falta `rsync` (no está en
todos los entornos): `git worktree add ../copia` si es un repositorio, o
`mkdir ../copia && tar -C <proyecto> -cf - . | tar -C ../copia -xf -`.

## El ciclo

```
diagnose → plan → apply --deterministic → diagnose → plan → apply --deterministic
        → next → (hacer el paso guiado) → check → next → …
        → diagnose → plan → apply --deterministic → next → … → nada pendiente
        → larapack:verify --strict
```

```bash
vendor/bin/builder larapack:realign diagnose     # o php artisan larapack:realign diagnose
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign next         # el siguiente paso, con todo lo necesario
# … hacer lo que dice el paso …
vendor/bin/builder larapack:realign check <id>   # ¿está hecho? lo marca, o dice qué falta
vendor/bin/builder larapack:realign status       # dónde se va
```

Todas aceptan `--root=<proyecto>` y `--format=json`. Las que leen el proyecto
aceptan además `--package` (repetible: el directorio en `packages/`, la ruta,
el nombre de Composer o `app` para el núcleo), igual que `larapack:verify`.
`larapack:realign help <acción>` explica cada una con su uso, lo que escribe y
su guía; `larapack:realign --help` las lista todas.

**Otra pasada `diagnose` → `plan` → `apply --deterministic`** hace falta en
tres momentos, y no es opcional:

1. **Después de cada `apply --deterministic`**: una corrección puede dejar un
   archivo igual a lo regenerado, y eso sale como `adopt` en la pasada
   siguiente.
2. **Después de copiar lo regenerado** encima de un archivo (lo que pide el
   playbook `edited-outside`) o de cambiar el contrato: el archivo copiado
   tiene que adoptarse para que el manifiesto diga la verdad, y un contrato
   distinto cambia lo regenerado.
3. **Después de completar pasos guiados**, al menos al terminar los de un
   ámbito: un controlador que ya delega o un archivo devuelto a sus huecos
   pueden dejar pasos deterministas nuevos, o cerrar otros.

`status` lo recuerda cuando el contrato o el manifiesto cambiaron (`stale`),
pero no ve un archivo copiado a mano: la pasada la decides tú. Repítela hasta
que `apply --deterministic` no tenga nada que aplicar. Cuando `plan` no
tiene nada pendiente, la prueba de que el proyecto cumple es
[`larapack:verify --strict`](verify.md).

## El estado: reanudable por cualquiera

El estado vive en el proyecto, en `.larapack/realign/`, no en la cabeza de
quien empezó:

```
.larapack/realign/
  report.json        el último diagnóstico (se puede versionar)
  plan.json          los pasos, su estado, sus decisiones y su historia (se versiona)
  exceptions.json    las excepciones pedidas, sin aprobar (se versiona)
  infer/<ámbito>.json el informe de cada inferencia
  .gitignore         deja fuera cache/
  cache/             lo regenerado para comparar (no se versiona)
```

Otra persona u otro agente que llegue mañana corre `status` y `next` y sigue.
Volver a diagnosticar y planificar **no pierde lo hecho ni lo decidido**: un
paso con el mismo id conserva su estado, su decisión y su historia. Los
formatos y lo que garantiza cada campo: [plan.md](plan.md#el-estado-en-disco-y-los-formatos-json).

**Versiona `plan.json` y `exceptions.json`** junto con el código: así el plan
viaja con la rama, y un `git revert` de un paso deja también su estado como
estaba.

## Seguridad: qué garantiza

Los principios, que valen para todas las acciones:

- **Lo que sólo lee es seguro siempre.** `diagnose`, `plan`, `status`, `next`,
  `check`, `decide`, `playbook` y `help` no escriben código: como mucho, su
  estado en `.larapack/realign/`.
- **Lo que escribe se puede simular y deshacer.** `adopt`, `apply`, `infer` y
  `except` aceptan `--dry-run` (no escribe nada y dice qué haría; `apply`, con
  el diff), y sin él **exigen un árbol git limpio** —fuera de
  `.larapack/realign/`— para que todo se deshaga con `git restore` o
  `git checkout`. Fuera de un repositorio git también se niegan.
  `--allow-dirty` lo salta, y es responsabilidad de quien lo pasa.
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
  ([exceptions.md](exceptions.md#por-qué-un-agente-no-puede-aprobar)).
- **Hacer un paso no lo da por hecho**: sólo `check`, que corre su criterio de
  terminado.

Lo que **no** garantiza —que el comportamiento siga igual, que las pruebas
pasen, que el contrato diga lo que hace la base de producción— está en
[risks.md](risks.md).

## Las acciones

| Acción | Escribe | Qué hace | Guía |
|---|---|---|---|
| `diagnose` | estado | Regenera el contrato aparte y clasifica cada archivo y cada hallazgo de verify. | [diagnose.md](diagnose.md) |
| `plan` | estado | Convierte el diagnóstico en pasos ordenados y reanudables. | [plan.md](plan.md) |
| `status` | nada | Dónde se va y qué sigue. | [plan.md](plan.md#status) |
| `next` | nada | El siguiente paso con todo lo necesario para hacerlo sin contexto. | [plan.md](plan.md#next) |
| `check` | estado | Corre el criterio de terminado de un paso y lo marca hecho. | [plan.md](plan.md#check) |
| `decide` | estado | Registra la opción elegida en una decisión. | [plan.md](plan.md#decide) |
| `playbook` | nada | Lista los playbooks o imprime uno sin rellenar. | [guided.md](guided.md) |
| `adopt` | manifiesto | Adopta, vuelve a enlazar y olvida, sin tocar un archivo. | [adopt.md](adopt.md) |
| `apply` | código, contrato y manifiesto | Los pasos deterministas que reescriben. | [apply.md](apply.md) |
| `infer` | un borrador | Infiere un `laraimport.json` desde el código. | [infer.md](infer.md) |
| `except` | solicitudes | Pide las excepciones que aprobará el dueño. | [exceptions.md](exceptions.md) |
| `help` | nada | Lista las acciones o explica una. | — |

```bash
larapack:realign <acción> [<id>…] [--package=…] [--dry-run] [--allow-dirty] [--option clave=valor]
larapack:realign apply --deterministic [--package=…] [--dry-run] [--allow-dirty]
```

Una opción que la acción no usa es un error, también una clave de `--option`
que la operación no lee: ignorarla haría creer que hizo algo.

## Para un agente: la receta

Esta es toda la lógica que hace falta; no hay que inventar nada por proyecto.

1. `larapack:realign status --format=json`. Si `plan` es null o `stale` no está
   vacío: `larapack:realign diagnose` y `larapack:realign plan`.
2. `larapack:realign next --format=json`. Si `done` es true, `diagnose` y
   `plan` otra vez para confirmar; si sigue sin nada, comprueba el
   cumplimiento ([verify.md](verify.md)) y se terminó.
3. Según `step.category`:
   - `deterministic`: si `how.available`, ejecuta `how.command`, primero con
     `--dry-run` y después sin él. Lo que la orden salte (`skipped`, con su
     razón) se hace a mano siguiendo `how.fallback.instructions`. Para ir más
     rápido, `larapack:realign apply --deterministic` hace todos los
     deterministas de una vez; después, `diagnose` y `plan` otra vez.
   - `guided`: sigue `how.instructions` al pie de la letra (el playbook del
     tipo y sus anexos), con las pruebas del proyecto antes y después. Si no
     puedes correr las pruebas, el paso no se da por hecho: ver
     [sin pruebas](guided.md#sin-pruebas-cuando-no-se-pueden-correr-las-del-proyecto).
   - `decision`: **no decidas tú**. Presenta `how.options` —y
     `how.instructions` si trae el playbook de la decisión— a quien tenga
     autoridad y, cuando decida, `larapack:realign decide <id> <opción>
     --note="…"`. Decidido, `next` da lo que sigue (`how.then` y, si la
     opción lo trae, su playbook). Si lo decidido es dejarlo como está,
     pide la excepción (`larapack:realign except <id> --option reason="…"`)
     y **no la apruebes**: díselo al dueño (`larapack:hand-rolled approve`).
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
   `apply --deterministic` (ver [el ciclo](#el-ciclo)), y vuelve al 2.

Reglas que no se rompen aunque parezca que ayudan: no edites
`.larapack/hand-rolled.lock` ni `plan.json` a mano, no añadas `#[HandRolled]`
para que verify pase, no uses `--allow-dirty` para saltarte un árbol sucio que
no es tuyo, no cambies un nombre de ruta ni una URI que el contrato no
declare, y no escribas ni reescribas migraciones.

Para que un agente encuentre esta guía en un proyecto, `larapack:skill` instala
la guía del realineamiento (`.claude/skills/larapack-realign/SKILL.md`) y el
bloque de `AGENTS.md` y de las reglas de Cursor, Codex, Gemini y Copilot que
apunta a `larapack:realign status` y a esta documentación.

## Para quien lo ejecuta por primera vez: un recorrido

En un proyecto con contrato (el detalle por perfil está en
[profiles.md](profiles.md)):

```bash
git switch -c realign                                  # una rama para el realineamiento
vendor/bin/builder larapack:realign diagnose           # minutos en un monorepo: regenera cada ámbito
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign apply --deterministic --dry-run   # qué haría, con diffs
vendor/bin/builder larapack:realign apply --deterministic
# corre las pruebas del proyecto y comitea
vendor/bin/builder larapack:realign diagnose
vendor/bin/builder larapack:realign plan               # la segunda pasada adopta lo que dejó igual la primera
vendor/bin/builder larapack:realign apply --deterministic
vendor/bin/builder larapack:realign status             # lo que queda: guiados y decisiones
vendor/bin/builder larapack:realign next               # uno por uno, con su playbook
vendor/bin/builder larapack:realign check <id>         # cada uno; un commit por paso
vendor/bin/builder larapack:realign diagnose           # tras los guiados (y tras copiar lo regenerado)
vendor/bin/builder larapack:realign plan
vendor/bin/builder larapack:realign apply --deterministic
vendor/bin/builder larapack:verify --strict            # al final: el cumplimiento
```

Sin contrato, el primer paso es `larapack:realign infer no-contract@.` y la
decisión de adoptar o no lo inferido ([infer.md](infer.md)).
