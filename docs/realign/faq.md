# ¿Qué hago si…?

Parte de [`larapack:realign`](README.md). Los atascos que han tenido de verdad
las personas y los agentes que realinearon un proyecto, cada uno con su
salida. Cada respuesta es corta y enlaza a la página que lo explica entero;
las palabras están en el [glosario](glossary.md).

Si no sabes ni dónde estás: `larapack:realign status`.

## Índice

- [`next` me da una decisión y no soy el dueño](#next-me-da-una-decisión-y-no-soy-el-dueño)
- [No puedo correr las pruebas del proyecto](#no-puedo-correr-las-pruebas-del-proyecto)
- [`check` dice `untested`](#check-dice-untested)
- [`done` no termina](#done-no-termina)
- [Un paso no cabe en un hueco](#un-paso-no-cabe-en-un-hueco)
- [El árbol git está sucio](#el-árbol-git-está-sucio)
- [Trabajo con el paquete fuera de `vendor/`](#trabajo-con-el-paquete-fuera-de-vendor)
- [El proyecto es un monorepo](#el-proyecto-es-un-monorepo)
- [El proyecto no tiene contrato](#el-proyecto-no-tiene-contrato)
- [`apply` dice que no hay pasos pendientes y sale con error](#apply-dice-que-no-hay-pasos-pendientes-y-sale-con-error)
- [`apply` saltó algo](#apply-saltó-algo)
- [`next` dice que no hay nada que hacer ahora](#next-dice-que-no-hay-nada-que-hacer-ahora)
- [`status` dice que el diagnóstico está viejo](#status-dice-que-el-diagnóstico-está-viejo)
- [Hice el paso y sigue pendiente](#hice-el-paso-y-sigue-pendiente)
- [`except` se niega](#except-se-niega)
- [Soy el dueño y no tengo terminal](#soy-el-dueño-y-no-tengo-terminal)
- [Me equivoqué en un paso](#me-equivoqué-en-un-paso)

## `next` me da una decisión y no soy el dueño

No la tomes, aunque una opción parezca obvia. Haz tres cosas:

1. **Preséntasela**: las opciones y sus consecuencias están en `how.options`
   (y, si la decisión tiene playbook, cómo pensarla en `how.instructions`).
2. **Sigue con otra cosa**: `next` trae en `meanwhile` el primer paso que no
   espera a nadie.

   ```bash
   larapack:realign next <meanwhile.id>
   ```

3. **Si son varias o el dueño no está**, no preguntes de una en una: júntalas.

   ```bash
   larapack:realign brief      # escribe .larapack/realign/brief.md, con la orden exacta de cada cosa
   ```

Cuando el dueño decida (`larapack:realign decide <id> <opción> --note="…"`),
retoma con `larapack:realign status`. Detalle: [owner.md](owner.md).

Puedes **preparar** una decisión sin tomarla: `larapack:realign infer <id>
--dry-run` dice, por ejemplo, si las rutas de un controlador escrito a mano se
conservarían al declararlo ([infer.md](infer.md)).

## No puedo correr las pruebas del proyecto

Sin `vendor/`, sin base de datos, sin el entorno. Se puede seguir, diciendo la
verdad:

1. Escribe igualmente la prueba que pide el playbook y comítala aparte,
   diciendo que no se corrió.
2. Haz el cambio y comítalo solo: `realign: <id> (SIN PROBAR: <motivo>)`.
3. Corre `larapack:realign check <id>` **sin** `--tested`. El paso queda
   `untested`, que es lo que pasó.
4. Entrega la lista (`larapack:realign status` o `brief`) a quien sí pueda
   probar.

**Nunca** pongas en `--tested` una orden que no corriste, ni edites
`plan.json` a mano. Si prefieres no tocar el paso hasta poder probarlo,
aplázalo: `larapack:realign defer <id> --note="sin base de datos"`. Detalle:
[guided.md](guided.md#si-no-puedes-correrlas).

## `check` dice `untested`

No es un error: `check` sale con 0. Dice que el paso **cambia comportamiento**
y que nadie registró que las pruebas pasaran. El paso cuenta como hecho para
seguir con el plan (`next` da el siguiente), pero `larapack:realign done` no
termina mientras siga así. Tiene tres salidas:

| Situación | Qué se hace | Quién |
|---|---|---|
| Las pruebas se pueden correr y pasan | `larapack:realign check <id> --tested="<la orden que corriste>"` | Quien las corrió |
| El proyecto corre sus pruebas siempre en el mismo sitio | Registrar la orden una vez en `.larapack/realign/config.json` (`{"format_version": 1, "tests": "php artisan test"}`); `check` la corre él | Una persona |
| No se pueden correr y se acepta así | `larapack:realign accept-untested <id> --note="por qué y cuándo se probará"` | **Sólo el dueño**, en su terminal o en el workflow de aprobación ([exceptions.md](exceptions.md#aprobar-sin-terminal-el-workflow-de-aprobación)) |

Si las pruebas fallan, el paso está mal hecho: se arregla, o se revierte su
commit. Detalle: [guided.md](guided.md#el-estado-untested).

## `done` no termina

`larapack:realign done` sale con 1 y dice qué falta, elemento por elemento
(`missing` en JSON). Cada clase de cosa tiene su salida y su responsable:

| `done` dice | Qué es | Salida |
|---|---|---|
| pasos sin hacer (`steps`) | Un paso pendiente, **aplazado** o bloqueado. Aplazar no es terminar. | `larapack:realign next` (o `undefer <id>` y hacerlo). |
| decisiones sin tomar (`decisions`) | Una decisión del dueño. | El dueño: `decide`. Un agente: `brief` y esperar. |
| pasos sin probar (`untested`) | Un paso que cambia comportamiento sin su prueba. | [Arriba](#check-dice-untested). |
| excepciones (`exceptions`) | Una excepción pedida y sin aprobar, o aprobada sobre un código que después cambió. | El dueño: `larapack:hand-rolled approve` (o `revoke` de la que sobra). |
| `validate` | Un error **o un aviso** de `larapack:validate --strict` en el contrato de un ámbito. | Corregir el `laraimport.json` y, como cambió el contrato, otra pasada. |
| `verify` | Un error o un aviso de `larapack:verify --strict`. | El paso que lo resuelve; si no hay paso, `diagnose` y `plan` lo crean. |

Dos casos que sorprenden:

- **`verify --strict` ya estaba en verde y `done` falla.** `verify` no ve un
  archivo editado fuera de sus huecos; el diagnóstico sí. Por eso el criterio
  es `done`, no `verify`.
- **El plan no tenía pendientes y `done` encuentra pasos.** `done` diagnostica
  otra vez: encontró algo que cambió después del último diagnóstico.

Detalle: [verify.md](verify.md#cuándo-se-termina).

## Un paso no cabe en un hueco

Una pieza de código que no tiene hueco ni se puede declarar en el contrato, o
una acción que no puede delegar en su request sin cambiar lo que responde. No
la fuerces y no la exceptúes por tu cuenta: **dejarla así lo decide el dueño.**

```bash
larapack:realign escalate <id> --note="qué no cabe y por qué"
```

El paso pasa a ser una decisión con dos opciones: hacerlo como dice el
playbook (`refactor`) o dejarlo como está. Si el dueño elige dejarlo:

- en un **controlador**, se marca con `#[HandRolled('razón concreta')]` y lo
  aprueba él (`larapack:hand-rolled approve`);
- en **lo demás**, se pide la excepción (`larapack:realign except <id>
  --option reason="…"`) y la aprueba él.

Antes de pasarlo, mira si la plantilla de hoy ya trae eso que parece tuyo: en
ese caso lo correcto es copiar lo regenerado, no exceptuar. Detalle:
[guided.md](guided.md#lo-que-no-cabe-escalate-o-except).

## El árbol git está sucio

`adopt`, `apply`, `infer` y `except` se niegan con «El árbol git tiene cambios
sin comitear», y dicen cuáles. Lo exigen para que todo lo que escriban se
pueda deshacer con git.

- **Los cambios son tuyos**: comítalos (un commit por paso) o guárdalos
  (`git stash`), y repite la orden.
- **No son tuyos**: no los toques y pregunta. No uses `--allow-dirty` para
  saltártelos.
- **`--dry-run`** no lo exige: simular siempre se puede.
- Lo que hay en `.larapack/realign/` (el plan, el informe) no cuenta como
  sucio, y las acciones que sólo leen o sólo escriben el estado (`diagnose`,
  `plan`, `next`, `check`, `decide`…) no lo exigen.
- Fuera de un repositorio git también se niegan: `git init` y un primer
  commit.

Detalle: [README.md](README.md#seguridad-qué-garantiza).

## Trabajo con el paquete fuera de `vendor/`

El proyecto no tiene `vendor/innoboxrr/larapack-generator` (no se puede
instalar, o se quiere usar otra versión). Se usa un clon del generador:

```bash
git clone https://github.com/innoboxrr/larapack-generator <clon>
composer install --working-dir=<clon>                        # una vez
php <clon>/builder larapack:realign status --root=<proyecto>
```

- **`--root=<proyecto>` en cada orden.** Sin él, fuera de un proyecto, falla
  con «No se encontró el proyecto desde …».
- Donde la guía escribe `vendor/bin/builder larapack:…`, lee
  `php <clon>/builder larapack:… --root=<proyecto>`; donde escribe una ruta
  del paquete (`vendor/innoboxrr/larapack-generator/docs/realign/`), lee
  `<clon>/docs/realign/`.
- La versión que cuenta es la del clon: `diagnose` regenera con su generador.

Detalle: [README.md](README.md#cómo-se-invoca-con-el-paquete-en-vendor-o-desde-un-clon).

## El proyecto es un monorepo

Cada paquete local (`packages/*` y los repositorios `path` de Composer) con su
`laraimport.json` o su manifiesto es un **ámbito** aparte, igual que la raíz.
No hay que hacer nada especial: `diagnose` los encuentra todos y cada paso
dice de qué ámbito es (`…@packages/billing`).

- **Trabaja y comitea por paquete**: `--package=<paquete>` acota `diagnose`,
  `status`, `next`, `brief`, `done` y las operaciones. Vale el directorio, la
  ruta, el nombre de Composer o `app` para la raíz, y es repetible.

  ```bash
  larapack:realign next --package=billing
  larapack:realign apply --deterministic --package=billing
  larapack:realign done --package=billing
  ```

- **`diagnose` tarda minutos** la primera vez (regenera cada ámbito); la caché
  lo reduce después. `--jobs=8` regenera más ámbitos a la vez.
- **Un solo proceso a la vez por copia de trabajo.** Para repartir el trabajo
  entre varias consolas, cada una en su rama o worktree y con su `--package`;
  al juntar las ramas, `diagnose`, `plan` y `check` de lo hecho.
- **Terminado es todo el proyecto**: `done --package` sirve para cerrar un
  paquete, pero el criterio final es `done` sin acotar.

Detalle: [risks.md](risks.md#monorepos-y-trabajo-en-paralelo) y, si ningún
ámbito tiene contrato, [profiles.md](profiles.md#3-sin-contrato).

## El proyecto no tiene contrato

`diagnose` lo dice («sin contrato: no hay con qué comparar») y el plan trae
una decisión `no-contract` por ámbito, más el código escrito a mano
(`unmanaged-http`, `unmanaged-models`). Hay dos caminos, y **elegir es del
dueño**:

1. **Adoptarlo.** `infer` escribe un contrato borrador desde las migraciones,
   los modelos y las rutas, sin tocar el código:

   ```bash
   larapack:realign infer no-contract@. --dry-run    # qué inferiría y qué decisiones deja
   larapack:realign infer no-contract@.              # escribe laraimport.inferred.json y su informe
   ```

   Después se revisa el borrador, se completa lo que no se infiere (la
   interfaz, la autorización), se renombra a `laraimport.json` y se importa
   ([infer.md](infer.md#revisar-el-borrador-y-adoptarlo)).

2. **Dejarlo fuera de LaraPack.** El dueño decide `keep`, se pide la excepción
   y él la aprueba. Lo que sigue valiendo sin contrato es la forma de los
   controladores: los pasos `controller-shape` se hacen igual.

   ```bash
   larapack:realign decide no-contract@. keep --note="…"     # el dueño
   larapack:realign except no-contract@. --option reason="…"
   ```

En los dos casos `larapack:realign done` puede salir con 0. Detalle:
[profiles.md](profiles.md#3-sin-contrato).

## `apply` dice que no hay pasos pendientes y sale con error

«No hay pasos pendientes que `apply` sepa hacer» no es un fallo del proyecto:
es la señal de que **lo automático se acabó**. Sigue con `larapack:realign
next` (lo guiado y las decisiones). Lo mismo dice `adopt` cuando no tiene nada
que adoptar. Detalle: [apply.md](apply.md#correrla).

## `apply` saltó algo

Sale con error y lista lo saltado (`skipped`), cada cosa con su razón: es lo
que no reconoció con certeza y no quiso tocar. El paso sigue pendiente y lo
que falta se hace a mano con su playbook:

```bash
larapack:realign next <id>     # how.fallback trae el playbook rellenado
larapack:realign check <id>
```

Detalle: [apply.md](apply.md#lo-saltado).

## `next` dice que no hay nada que hacer ahora

«Nada que hacer ahora: quedan N aplazados, N en manos del dueño y N
bloqueados» (en JSON, `done: false`, `step: null` y `waiting`). Queda trabajo,
pero nada que puedas hacer tú en este momento:

- lo **aplazado** vuelve con `larapack:realign undefer <id>`;
- lo que está **en manos del dueño** espera su decisión o su aprobación:
  `larapack:realign brief` se lo prepara;
- lo **bloqueado** espera a uno de los anteriores.

No es haber terminado: `larapack:realign done` seguirá fallando. Detalle:
[plan.md](plan.md#next).

## `status` dice que el diagnóstico está viejo

El contrato o el manifiesto de un ámbito cambiaron después del último
diagnóstico (`stale`). El plan habla de un proyecto que ya no es así:

```bash
larapack:realign diagnose
larapack:realign plan
```

No se pierde nada: cada paso conserva su estado, su decisión y su historia
([plan.md](plan.md#volver-a-planificar)).

## Hice el paso y sigue pendiente

Hacer un paso no lo marca hecho: **sólo `larapack:realign check <id>`**, que
corre su criterio de terminado.

- Si `check` dice que falta algo, `remaining` dice qué y dónde.
- **`apply` y `adopt` tampoco lo marcan hecho.** Después de aplicarlos, sus
  pasos siguen pendientes —y lo que espera a ellos, bloqueado: «El paso …
  espera a: adopt@.»— hasta que los cierra `check <id>` o la pasada siguiente
  (`diagnose` y `plan` dan por resuelto lo que ya no encuentran).
- Si copiaste lo regenerado encima de un archivo, o cambiaste el contrato, el
  plan todavía no lo sabe: otra pasada (`diagnose`, `plan`,
  `apply --deterministic`). Un paso que ya no hace falta se cierra solo.
- En una **decisión**, decidir tampoco es hacer: falta lo que la opción pide
  y, si es dejarlo como está, que el dueño apruebe la excepción.

## `except` se niega

«… es una decisión que nadie ha tomado»: en una decisión, la excepción sólo se
puede pedir **después** de que el dueño elija la opción que deja las cosas
como están. Pedirla antes sería presentarle la solicitud como si ya hubiera
elegido. Preséntale la decisión; cuando la tome, repite el `except`.

Tampoco se exceptúa con `except` un controlador que no delega: eso es
`#[HandRolled]` en el código. Detalle: [exceptions.md](exceptions.md#el-ciclo).

## Soy el dueño y no tengo terminal

`larapack:hand-rolled approve` y `larapack:realign accept-untested` piden una
persona en su terminal: enseñan lo que se aprueba y esperan una frase escrita
a mano.

- **Para aprobar excepciones sin terminal** existe el workflow de aprobación:
  una corrida de GitHub Actions que lanzas tú a mano y que deja la aprobación
  a tu nombre, en un PR
  ([exceptions.md](exceptions.md#aprobar-sin-terminal-el-workflow-de-aprobación)).
- **Para aceptar un paso sin probar**, el workflow que genera `larapack:ci
  --approval` no trae esa orden: hoy se acepta desde una terminal. Lo
  habitual es no aceptarlo, sino que alguien con el entorno corra las pruebas
  y registre `check <id> --tested="…"`.

## Me equivoqué en un paso

Todo se deshace con git, y por eso se comitea paso a paso:

- un paso guiado: `git revert` de su commit (el `plan.json` del mismo commit
  vuelve con él);
- una corrida de `apply` o `adopt` sin comitear: `git restore .` y `git clean`
  para lo creado;
- una decisión: `decide` otra vez la sustituye, y queda en la historia;
- una excepción aprobada de más: el dueño la retira con
  `larapack:hand-rolled revoke`.

Después, `larapack:realign diagnose` y `plan`: el estado se recalcula del
código. Detalle: [risks.md](risks.md#por-operación).
