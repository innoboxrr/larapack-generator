# Verificar el cumplimiento

Parte de [`larapack:realign`](README.md). Un realineamiento termina cuando el
proyecto **cumple el contrato** y hay algo que lo comprueba en cada cambio
siguiente, para que no se vuelva a desviar. Aquí, qué orden lo dice, qué
garantiza cada una y qué no.

## Índice

- [Cuándo se termina](#cuándo-se-termina)
- [`larapack:verify` y `--strict`](#larapackverify-y---strict)
- [Lo que no cuenta como fallo](#lo-que-no-cuenta-como-fallo)
- [Acotar: `--package`, `--check`, `--path`](#acotar---package---check---path)
- [Que no se vuelva a desviar: `ci` y `guard`](#que-no-se-vuelva-a-desviar-ci-y-guard)
- [Qué garantiza y qué no](#qué-garantiza-y-qué-no)

## Cuándo se termina

1. `larapack:realign next` dice que no queda nada (`done: true`) después de
   diagnosticar y planificar otra vez.
2. `larapack:validate --strict` pasa en cada ámbito: el contrato es válido.
3. `larapack:verify --strict` sale con 0: no queda ni un error ni un aviso,
   salvo lo que cubre una excepción aprobada por el dueño.
4. Las pruebas del proyecto pasan (eso no lo comprueba LaraPack: ver
   [abajo](#qué-garantiza-y-qué-no)).

```bash
larapack:realign diagnose && larapack:realign plan
larapack:realign status --format=json        # steps.by_status: nada pendiente
larapack:validate --strict --format=json
larapack:verify --strict --format=json
```

Si queda algo que el proyecto decidió dejar fuera, sigue en el plan como un
paso hecho con su decisión, y en `verify` como `info` con su excepción: lo que
queda fuera de alcance está **declarado**, no olvidado.

**Un proyecto que se queda fuera de LaraPack** (sin contrato, decidido `keep`)
no tiene manifiesto, y `verify --strict` siempre dirá `empty-manifest`. Ése es
el único aviso que puede quedar, y es lo declarado fuera de alcance. El
cumplimiento de lo que sí vale sin contrato —la forma de los controladores y
las rutas— se comprueba acotando:

```bash
larapack:verify --strict --check=controller-shape --check=request-handle --check=controller-concern --check=route-closure --check=hand-rolled
```

## `larapack:verify` y `--strict`

```bash
larapack:verify                    # errores y avisos; sale con 1 si hay errores
larapack:verify --strict           # los avisos también son fallos
larapack:verify --format=json      # para un agente o la CI
```

`verify` compara el proyecto con su manifiesto y su contrato (`missing-file`,
`customised`, `inconsistent-entity`, `route-not-declared`, `endpoint-missing`,
`immutable-write`, `secret-exposed`, `endpoint-tests-outdated`,
`relation-missing`, `model-lists-outdated`, `route-prefix`) y mira todo el
código, lo haya generado LaraPack o no (`controller-shape`, `request-handle`,
`controller-concern`, `hand-rolled`, `route-closure`, `index-managed`,
`resource-appends`). La tabla de cada comprobación está en el README del
paquete («Verificar la arquitectura»); qué paso del realineamiento resuelve
cada una, en [diagnose.md](diagnose.md#los-hallazgos-de-verify).

`--strict` es la puerta: lo que usa `larapack:ci` en la rama protegida y lo
que exige el criterio de terminado de los pasos (`check` de un paso `verify`
cuenta errores **y** avisos).

## Lo que no cuenta como fallo

- **`customised`** es `info`: un archivo editado en sus huecos es lo correcto.
  Lo editado fuera lo encuentra el diagnóstico (`edited_outside`), no
  `verify`.
- **Una excepción `#[HandRolled]` aprobada** en `.larapack/hand-rolled.lock`
  es `info`.
- **Una excepción del realineamiento aprobada** (un closure de ruta legítimo,
  código que se queda fuera, un hallazgo aceptado) baja a `info` mientras el
  código que cubre no cambie; la salida JSON la lista en `exceptions` con su
  `status`. Una que ya no cubre nada es un aviso (`hand-rolled-stale`) hasta
  que el dueño la retira. Todo en [exceptions.md](exceptions.md#cómo-lo-ve-verify).

Lo que **sí** cuenta, aunque esté pedido: una excepción **pedida y sin aprobar**
no cubre nada. Un agente no puede dejar un proyecto «en verde» pidiendo
excepciones.

## Acotar: `--package`, `--check`, `--path`

Mientras se trabaja en un paso, se mira sólo lo suyo (es la orden que traen los
playbooks):

```bash
larapack:verify --format=json --package=app --check=controller-shape --check=request-handle --path=app/Http/Controllers/ReportController.php
larapack:verify --package=billing          # un paquete local (o `app`, el núcleo)
larapack:verify --no-packages              # sólo el manifiesto de la raíz
```

Acotado no es terminado: el cumplimiento se dice con `verify --strict` sobre
todo el proyecto.

## Que no se vuelva a desviar: `ci` y `guard`

Un proyecto realineado se vuelve a desviar en cuanto alguien —persona o
agente— escribe a mano lo que el contrato declara. Las capas que lo impiden, de
la más fuerte a la más débil:

| Capa | Orden | Qué hace |
|---|---|---|
| La rama protegida | `larapack:ci` | Escribe el workflow que corre `verify --strict` en cada PR y el ruleset que exige ese check; `larapack:ci --check` pregunta a GitHub si de verdad se exige. |
| El hook de git | `larapack:skill --hooks` | `pre-commit` y `pre-push` llaman a `larapack:guard --git`: frena el commit de cualquiera. |
| Los hooks del agente | `larapack:skill --hooks --agents=all` | Claude Code, Cursor, Codex, Gemini, Copilot y Windsurf llaman a `larapack:guard` tras cada edición y al terminar el turno. |
| Las instrucciones | `larapack:skill` | El skill y el bloque de `AGENTS.md`, que mandan a `larapack:realign status` si hay un realineamiento en curso. |

```bash
larapack:ci --dry-run                      # qué escribiría
larapack:ci --owner=@equipo/arquitectura
larapack:skill --hooks --agents=all
larapack:guard --doctor                    # qué capas tiene el proyecto y cuáles faltan
```

**Actívalas al final**, con `verify` limpio: con errores previos, cada edición
de esos archivos retendría a quien trabaja por algo que no causó. Durante el
realineamiento, `larapack:realign check` es el criterio; al terminar, la rama
protegida.

## Qué garantiza y qué no

**Garantiza**, al salir con 0 `verify --strict`:

- Lo generado está donde el manifiesto dice, sin borrar, y lo editado está
  registrado.
- Cada controlador, también los escritos a mano, sólo delega
  (`return $request->handle();`), y cada request tiene su `handle()`.
- Las rutas, métodos, requests y vistas son las que el contrato declara, y cada
  endpoint declarado tiene sus piezas.
- Lo inmutable no se escribe, lo secreto no se expone, los listados pasan por
  su ManagedFilter, los Resources no sirven lo que pide el cliente sin lista
  blanca.
- Las listas de cada modelo y sus relaciones dicen lo que el contrato.
- Lo que se dejó fuera está aprobado por el dueño, con su razón y su huella.

**No garantiza**:

- **Que el comportamiento no cambió.** Mover la validación a `rules()` y la
  autorización a `authorize()` hace que una petición sin permiso reciba 403 en
  vez de 422; regenerar un IndexRequest hace que un listado respete
  `managed`. Las pruebas del proyecto lo dicen; `verify`, no.
- **Que las pruebas pasen**, ni que existan para lo escrito a mano.
- **Que el contrato diga lo que hace la base de producción.** El contrato se
  compara con el código y las migraciones del repositorio, no con la base: un
  cambio hecho en la base sin migración no se ve
  ([risks.md](risks.md#migraciones-y-datos)).
- **Que el front siga funcionando.** El realineamiento conserva nombres de ruta
  y URIs, pero una respuesta con otra forma (un Resource regenerado) la ve sólo
  el front.
- **Nada de lo `out_of_scope`**: servicios, dominio, jobs, front propio.
- **Que nadie lo salte**: la guardia de un agente no es infranqueable. La
  barrera real es la revisión del dueño en la rama protegida.
