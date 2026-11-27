# Changelog

## 9.3.1 (sin publicar)

### `verify` sin `--root` ya no mide el propio generador

**Qué pasaba.** Sin `--root`, la raíz se descubría subiendo desde donde está
instalado el generador hasta un `vendor/autoload.php`. Con el `builder` de un
clon —o con un `vendor/bin/builder` que lleva a un clon por un repositorio
`path` con enlace simbólico— ese directorio es el propio generador, que tiene
su vendor. `larapack:verify` corrido así desde el proyecto medía el generador y
devolvía `ok: true` con un solo aviso, `empty-manifest`: un falso verde. Lo
mismo valía para cualquier comando con `--root`, también para los generadores.

**A quién afectaba.** A quien corre el `builder` de un clon del generador, o
tiene el generador enlazado por un repositorio `path`, sin pasar `--root`. En
school-management el análisis de viabilidad lo encontró así.

**Cómo se corrige.** La raíz sale del directorio de trabajo: subiendo desde él,
el primer directorio con un `laraimport.json`, un `.larapack/manifest.json` o
un `composer.json` que requiere `innoboxrr/larapack-generator` (en `require` o
`require-dev`). Si no hay ninguno, la de siempre. Y todo comando con `--root`
(los de `TargetsProject`: los generadores, `import`, `validate`, `verify`,
`fix`, `ci`, `hand-rolled`, `skill`, `remove-full-model`) falla si la raíz
—indicada o descubierta— es el propio generador sin manifiesto ni laraimport,
diciendo qué buscó y que se le pase `--root`. Un `.larapack/` sin manifiesto
no cuenta como marca: uno así, con sólo un candado de `#[HandRolled]`, puede
quedar en `/` por un comando lanzado desde ahí y volvería «el proyecto» a todo
lo que cuelga de él.

**Al actualizar.** Nada que hacer si ya pasabas `--root` o corrías el comando
desde el proyecto con el generador instalado por Composer. Si un script corría
el `builder` desde un directorio que no es un proyecto y se fiaba de que
«saliera bien», ahora falla: pásale `--root=<proyecto>`. Desde un subdirectorio
del proyecto, o desde el de un paquete local, ahora se mide ese proyecto o ese
paquete.

### `verify` en la raíz lee los manifiestos de los paquetes locales

**Qué pasaba.** Un proyecto con paquetes locales tiene un manifiesto en la raíz
y otro en cada paquete (`packages/<p>/.larapack/manifest.json`). `verify` en la
raíz recorría el código de todos —controller-shape mira `packages/*`— pero sólo
leía el manifiesto de la raíz: un archivo generado que faltaba en un paquete,
o una request sin acción declarada, no salía.

**A quién afectaba.** A los proyectos con paquetes locales generados con
LaraPack. En school-management hubo que correr `verify` 81 veces, una por
paquete con su `--root`, y así salieron dos `missing-file` y tres
`route-not-declared` que la raíz no veía.

**Cómo se corrige.** Desde la raíz, además de lo de siempre, se corren las
comprobaciones del manifiesto (`missing-file`, `customised`,
`inconsistent-entity`, `route-prefix`, `route-not-declared`,
`endpoint-missing`, `immutable-write`, `secret-exposed`,
`endpoint-tests-outdated`) contra el manifiesto de cada paquete local que lo
tenga: los repositorios `path` del `composer.json` y `packages/*`, sólo los que
están dentro de la raíz (un `path` que apunta fuera es una dependencia). Las
del código no se repiten. En el JSON, cada hallazgo lleva `package` —su nombre
de Composer, o `null` si es del núcleo— y la ruta relativa a la raíz del
proyecto (`packages/billing/src/...`); el informe trae `summary`, una fila por
raíz (`package`, `path`, `manifest`, `errors`, `warnings`, `info`), cuyos
totales suman los de arriba. La salida de texto antepone `[paquete]` a cada
hallazgo de un paquete y termina con una línea por paquete. `--package`
(repetible) acota a uno —por su directorio, su ruta o su nombre de Composer— o
a `app`, el núcleo; uno que no existe falla diciendo los que hay.
`--no-packages` no lee sus manifiestos, como antes. Una raíz sin laraimport que
sólo agrupa paquetes no avisa de `empty-manifest` si se midió alguno. El
formato es compatible: sólo se añaden `package` y `summary`.

**Al actualizar.** `verify` en la raíz puede salir en rojo donde antes salía en
verde: son errores que ya estaban en los paquetes. Para la CI que corre
`verify --strict` en la raíz, o se arreglan, o se mide como antes con
`--no-packages` mientras tanto (y se dice por qué). Si la CI de cada paquete ya
corre su propio `verify`, sigue igual.

### Un paquete sin modelos es un laraimport válido

**Qué pasaba.** El esquema de 9.x exigía al menos un modelo (`minItems: 1`).
Un paquete que sólo integra —una pasarela de pago, un PAC, una terminal— nace
con `"models": []`, y `validate` lo rechazaba con «Array should have at least
1 items, 0 found»; `import` tampoco corría.

**A quién afectaba.** A los paquetes de integración. En school-management, a
doce: `school-pac-*` (4), `school-pay-*` (6) y `school-tpv-*` (2).

**Cómo se corrige.** `models` sigue siendo obligatoria, pero puede ir vacía.
`validate` acepta el archivo, `import` no genera nada de modelos y sale con 0,
y `verify` no avisa de `empty-manifest` cuando el laraimport de la raíz no
declara ni modelos, ni pivotes, ni `controllers` (si los declara y no hay
nada registrado, sigue avisando). Con sus proveedores registrados, se siguen
comprobando como antes.

**Al actualizar.** Nada. Si quitaste el laraimport de un paquete así para que
la CI pasara, puedes volver a ponerlo.

### `inconsistent-entity` ya no señala el `User` de Laravel

**Qué pasaba.** El `User` de una aplicación usa la migración de `users` y la
`UserFactory` que trae Laravel, y el generador, a propósito, no las toca (la
migración la salta con «no la generó LaraPack», la factory porque ya existe).
El modelo quedaba en el manifiesto sin `Migration` ni `Factory`, y
`inconsistent-entity` avisaba de que «no tiene componente Migration y todas las
demás entidades sí». Un falso positivo que había que aprender a ignorar.

**A quién afectaba.** A toda aplicación con un `User` en el laraimport. En
school-management eran los dos únicos avisos de esa comprobación.

**Cómo se corrige.** Un componente que falta en el manifiesto pero existe donde
el generador lo habría escrito cuenta como presente: la factory en
`database/factories/<Modelo>Factory.php` y la migración de creación en
`database/migrations/*_create_<tabla>_table.php`, con la tabla del `$table` del
modelo si lo declara (`table_prefix`). No es una lista de nombres: se mira el
archivo, y si desaparece el aviso vuelve. Sólo esos dos componentes, que son
los que el generador cede a un archivo que ya existe.

**Al actualizar.** Nada: los avisos desaparecen solos.

## 9.3.0

### La rama protegida: guiada hasta el final, y comprobada en cada corrida

La puerta de `larapack:ci` sólo vale si la rama **exige** el check «LaraPack
verify». Sin eso, el workflow corre, sale en rojo y el PR se fusiona igual. La
9.2 imprimía una orden de `gh` y ahí se quedaba: nada decía si el dueño la
había corrido. Proteger la rama sigue siendo del dueño —cambia quién puede
fusionar, y eso no lo decide un comando ni un agente—, pero ahora queda a un
paso y se comprueba.

- **`larapack:ci` escribe `.github/rulesets/larapack-verify.json`**, un ruleset
  de GitHub en el formato de exportación (el mismo que acepta
  `POST repos/{o}/{r}/rulesets`), para *Settings → Rules → Rulesets → New
  ruleset → Import a ruleset*. Va contra `~DEFAULT_BRANCH` y exige el check
  **fijado a GitHub Actions** (`integration_id` 15368: un estado con ese nombre
  publicado con otro token no cuenta, cosa que la orden clásica no impedía),
  un PR con la revisión de los dueños de código que se descarta si después se
  empuja otra cosa, y que nadie borre ni reescriba la rama. Sin
  `bypass_actors`; con `--no-enforce-admins`, el papel de administrador va ahí.
  El workflow y el ruleset se escriben juntos o ninguno: si uno difiere y no
  hay `--force`, no se toca nada.
- **La guía, paso a paso**: en la web con el ruleset (la recomendada), con
  `gh api --method POST … --input` (y `PUT …/rulesets/ID` para actualizarlo),
  o con la protección clásica de antes; lo que cuesta cada una, que en un
  repositorio privado de una cuenta Free GitHub no aplica ninguna, y cómo
  comprobarlo.
- **`larapack:ci --check`** pregunta a GitHub si la rama por defecto exige el
  check, sin escribir nada: exige (0), protegida sin exigirlo (1), sin
  protección (1) o **no se pudo saber (2), que nunca se da por protegida**.
  Lee las reglas de los rulesets (`GET repos/{o}/{r}/rules/branches/{rama}`,
  que basta con leer el repositorio, y trae también los de la organización) y
  la protección clásica, y enumera lo que deja abierta una rama que sí lo
  exige: un check sin fijar a una app, alguien con bypass, la cuenta que
  pregunta pudiendo saltárselo, la clásica sin los administradores, nadie que
  revise el candado. Lo que sólo ve un administrador queda «sin comprobar».
  Pregunta con `GH_TOKEN`/`GITHUB_TOKEN` (`GITHUB_API_URL`, `HTTPS_PROXY`) o, si
  no hay, con `gh`; el repositorio, de `--repo`, `origin` o
  `GITHUB_REPOSITORY`. `--format=txt|json|github`. Sin dependencias nuevas:
  `file_get_contents` con un contexto de flujo, o `gh api --include`.
- **El workflow lo comprueba en cada corrida.** `larapack.yml` y el job
  `verify` de los paquetes nuevos llevan `permissions: contents: read` y un
  último paso, `larapack:ci --check --format=github` con el token del job:
  mientras la rama no exija el check deja un `::warning` y, en el resumen del
  job, los pasos con el ruleset entero (un paquete nuevo no lo tiene escrito).
  No falla el job —`continue-on-error`: el check exigido es éste, y sería
  circular— y corre aunque `verify` falle.
- **`larapack:verify` no lo mira, a propósito.** Es la puerta y corre sin red;
  si dependiera de GitHub, un corte o un token sin permisos la pondrían en
  rojo, y con `--strict` una rama aún sin proteger fallaría siempre, incluido
  el PR que la protege.

**Al actualizar:** el `larapack.yml` generado cambia (los permisos y el último
paso). `larapack:ci` lo enseña como «difiere»; `larapack:ci --force` lo
regenera y crea el ruleset. Si lo editaste a mano, revisa el diff antes.

**Lo que queda, dicho:** el paso del workflow avisa, no bloquea; un dueño que
ignora el aviso tiene la puerta abierta, y eso no lo arregla ningún paquete.
En GitHub Enterprise Server el id de la app de Actions no es 15368: hay que
cambiar o quitar `integration_id` antes de importar. `BranchProtection` y los
clientes de la API no están todavía en el conjunto que mide Infection.

### Mutación: del 93,4 % al 96,6 %, con margen explícito y un informe accionable

El trabajo «Mutación» dejaba poco margen: las ocho piezas juntas en 93,38 %
frente a un mínimo de 93, y `Verifier` en 93,31 % con un suelo de 93, a un
mutante. Medido otra vez sobre la 9.2.0 con la configuración de la CI: 4361
mutantes, 289 sin detectar. Ahora: 4234, 144 sin detectar, **96,60 %**
(96,85 % de lo recorrido), y cada pieza a dos puntos o más de su suelo
anterior. Las cifras por pieza, en [docs/mutacion.md](docs/mutacion.md).

- **Pruebas de lo que se ve**, no de detalles internos: nombres que no son
  identificadores en un manifiesto editado a mano (con un `(` en el nombre de
  un endpoint o de un secreto, `verify` dejaba un aviso de `preg_match()`, y
  bajo Artisan un aviso es una excepción), un modelo sin `$hidden`, `showcase`
  frente a `show`, un espacio de nombres con «Ñ» en el prefijo de rutas, un
  contrato que falta delante de otro, `cat <<` al final de una orden, el
  directorio de trabajo de la guardia por un enlace, `#[HandRolled]` detrás
  de otro atributo y en un trait entero, un padre con
  `prepareForValidation()` privado, `{$appends` pegado a la llave, la sangría
  mezclada de tabuladores y espacios, lo que `fix` revisa acotado al paquete,
  una herencia en círculo, `'managed' =>` al final del archivo, un tipo
  `namespace\Tipo`, la línea de un closure partido en dos.
- **Equivalentes que eran una idea repetida, escrita una vez.**
  `PhpOutline::is()` concentra la comparación del tipo de un token, que
  estaba en 59 sitios donde `$token[0]` y `$token[1]` daban lo mismo; las
  lecturas de archivos pasan por `Files::read()`; y salen guardas que no
  guardaban nada (la lista, en docs/mutacion.md).
- **Cambios de comportamiento, cada uno con su prueba:**
  - Un archivo que existe y no se puede leer ya no se toma por vacío:
    `verify`, `ci` y `fix` fallan diciendo cuál. Después de Pint, uno que ya
    no se puede leer se devuelve como estaba («Después de formatearlo no se
    podía leer, y se devolvió el original.»).
  - La guardia lee como `-c` cualquier opción que empiece por `-…c` (`-c1`
    incluido). Un falso positivo posible; nunca uno negativo.
  - En el mensaje de la guardia, un `check` o un `message` que no es texto
    sale como `error` y vacío, no como «Array».
- **Los suelos tienen margen, y está escrito:** lo logrado, redondeado hacia
  abajo a la décima, menos un punto; también `--min-msi=95.6` y
  `--min-covered-msi=95.8`. Lo que ese punto vale en mutantes (de uno en
  `HandRolledLock` a ocho en `Guard`) lo imprime la comprobación. **No
  cubre** quitar una prueba que mate menos mutantes que esa holgura: en una
  pieza grande, eso pasa por debajo del suelo. Se ve igual, en el informe.
- **Un PR rojo dice qué hacer.** `mutation-floors.php` imprime la holgura de
  cada pieza y, con el catálogo de supervivientes conocidos
  (`.github/mutation-survivors.json`, regenerable con `--write-catalog`),
  cada mutante que escapó y no estaba, con `archivo:línea`, el mutador y la
  línea que quita y la que pone; también en verde. El informe de Infection
  queda como artefacto de la corrida.
- **Lo que no se cerró:** 144 supervivientes, casi todos equivalentes,
  apuntados por familias. Los que no lo son —el orden y los duplicados de lo
  que `fix` pasa a la auditoría, código que no es PHP en `LegacyFix`, un
  closure en el valor de una propiedad en `PhpOutline`— están en la lista de
  docs/mutacion.md, como tests que faltan. Y el MSI depende algo de la
  máquina: lo que agota los 30 s cuenta como detectado, y en dos corridas del
  mismo código `LegacySecurity` pasó de 98,08 % a 96,43 %. Los suelos salen
  de la más baja.

### La guardia deja de ser sólo de Claude Code

Hasta la 9.2 la guardia en vivo (`larapack:guard` en `.claude/settings.json`)
sólo la ejecutaba Claude Code. Con Cursor, Codex, Gemini CLI, Copilot o
Windsurf, lo único que frenaba un controlador con lógica era la CI. Ahora va
por capas, y todas llaman al mismo `larapack:guard`:

- **El hook de git, para cualquiera** (`GitHooks`). `larapack:skill --hooks`
  escribe además `.githooks/pre-commit` y `.githooks/pre-push` y pone
  `core.hooksPath`. Corren `larapack:guard --git=pre-commit|pre-push` sobre lo
  que se va a comitear o a empujar —sólo esos archivos, y sin correr el
  Verifier si ninguno es arquitectura— y frenan lo que `verify --strict`
  rechazaría: errores de esos archivos y una excepción `#[HandRolled]` sin
  aprobar. Un archivo vigilado preparado a medias no se da por bueno (la
  guardia lee el disco). Un cambio en `.larapack/hand-rolled.lock` lo confirma
  una persona escribiendo «apruebo» en `/dev/tty`; sin terminal —como corre un
  agente— no entra. El `pre-push` mira los commits que ningún remoto tiene
  todavía: frena lo que se comiteó con `--no-verify`. No pisa nada: un
  `core.hooksPath` ya puesto, hooks propios en `.git/hooks` o un
  `.githooks/pre-commit` ajeno se respetan y se dice qué línea añadir.
  `larapack:new` deja los scripts en un paquete que tendrá su propio
  repositorio, y dice cómo activarlos tras `git init`.
- **Los hooks de cada agente que los tiene de verdad** (`AgentAdapters`,
  `AgentIntegrations`). `larapack:skill --agents=cursor,codex,gemini,copilot,windsurf`
  (o `all`) escribe sus hooks y sus reglas: `.cursor/hooks.json` y
  `.cursor/rules/larapack.mdc`; `.codex/hooks.json` (y `AGENTS.md`);
  `.gemini/settings.json` y `GEMINI.md`; `.github/hooks/larapack.json` y
  `.github/copilot-instructions.md`; `.windsurf/hooks.json` y
  `.windsurf/rules/larapack.md`. `larapack:guard --agent=<agente>` traduce la
  entrada de cada uno a la de Claude Code y contesta en su idioma: negación
  antes de la herramienta (`permission: deny`, `permissionDecision: deny`,
  código 2), el motivo después de editar por el canal que llega al modelo
  (`additional_context`, `additionalContext`, `decision: block`) y otra vuelta
  al terminar (`followup_message`, `decision: block`, código 2). Codex edita
  con parches: sus rutas (`*** Update File:`) cuentan como archivos editados,
  también dentro de una orden de shell, y el parche deja de leerse como orden.
  Sin `--agent`, el agente se deduce de la entrada.
- **Saltarse el hook de git se frena** en todos los hooks de antes de la
  herramienta (`Guard::gitBypass`): `git commit --no-verify` (abreviado, o `-n`
  en un grupo como `-anm`), `git push`/`merge --no-verify`, y cambiar
  `core.hooksPath` con `git config` o `git -c`. Leerlo no cuenta, ni un mensaje
  que diga `-n`. El filtro de la shell deja pasar a PHP esas órdenes, y el
  PreToolUse de Claude Code que escribió la 9.2 —que no las habría visto— se
  pone al día al reinstalar con `--hooks`, sólo si es exactamente el suyo.
- **`larapack:guard --doctor`** (`GuardDoctor`) dice qué capas tiene el
  proyecto y cuáles faltan, con la orden de cada una (`--format=json` para un
  script). Sale con 1 si falta el hook de git activo o un workflow con
  `verify --strict`. Avisa de que Aider comitea con `--no-verify` salvo
  `git-commit-verify: true`.
- **Cursor importa los hooks de `.claude/settings.json`** con su propia
  entrada, y el de antes de la herramienta no veía la orden: dejaba pasar todo.
  Ahora la reconoce, y calla si el proyecto tiene además los de Cursor.

Pruebas: `AgentAdaptersTest` (la entrada de cada agente, en proceso),
`AgentIntegrationsTest` (instalar sin pisar, y el comando escrito ejecutado con
bash con las variables de cada agente), `GitHooksTest` (repositorios de verdad:
commit, push, candado sin terminal con `setsid` y con terminal con `script`),
`GitBypassTest` y `GuardDoctorTest`. `guard-smoke.sh` y el job de Windows
lanzan además los comandos de los cinco agentes y el hook de git desde Git
Bash. `infection.json5` corre también `GitBypassTest` y `AgentAdaptersTest`:
son las que matan los mutantes de lo nuevo de `Guard`, que mantiene su suelo
del 95 %.

Lo que queda abierto, y está en el README («Otros agentes, y el hook de git
que no depende de ninguno»): `core.hooksPath` es configuración de cada clon; un
hook de git se salta con `--no-verify` donde no hay hook de agente que lo
impida; la confirmación del candado no prueba identidad (la barrera sigue
siendo CODEOWNERS en la rama protegida); los hooks de los otros agentes son
POSIX sh y en Windows necesitan que el agente los ejecute con Git Bash (sin
variante `powershell` para Copilot); Codex y Gemini piden aceptar los hooks de
proyecto y `--doctor` no lo ve; y los adaptadores de Cursor y Windsurf siguen
la documentación de terceros, porque sus páginas oficiales no fueron accesibles
desde donde se escribió. Ninguna prueba corre los agentes de verdad: simulan su
entrada.

### `larapack:fix route-closure`: el fallback de la SPA de laravel-setup <= 7.2.0

Las aplicaciones creadas con laravel-setup hasta la 7.2.0 traen en
`routes/web.php` el fallback de la SPA en un closure con lógica
(`abort_if(request()->is('api/*') || request()->expectsJson(), 404); return
view('app');`). `verify` lo marca como `route-closure`, y con `--strict` —la
puerta que instala `larapack:ci`— nunca pasaban. laravel-setup 7.2.1 lo resolvió
para las nuevas; las existentes tenían que copiar dos archivos a mano.

Ahora `larapack:fix route-closure` lo corrige de forma determinista e
idempotente, como las demás correcciones (`--dry-run`, `--format=json`,
`fixed`/`skipped`/`unchanged`, `--path`, `--package`, devolución si Pint deja
algo sin parsear):

- Crea `App\Http\Controllers\SpaController` y `App\Http\Requests\SpaRequest`
  con el contenido de laravel-setup 7.2.1 si no existen. Si existen y no son
  esos (sin contar el espacio en blanco), no se sobrescriben y no se toca nada:
  `skipped` con la razón.
- Reescribe el fallback a `Route::fallback(SpaController::class)` con su `use`
  (en orden alfabético), y la raíz `Route::get('/', fn () => view('app'))->name('app')`
  a `Route::view('/', 'app')->name('app')`. El resto del archivo no se toca.
- Reconoce sólo ese closure, token a token (`SpaFallback`): pueden variar el
  espacio en blanco, las comillas y el orden de los dos operandos del `||`;
  cualquier otra lógica —un parámetro, otra vista, otro código, un comentario
  dentro, `static`— se salta. Sólo en el `routes/web.php` de la raíz.
- Cualquier otro closure de las rutas se salta con el mensaje de `verify`, y el
  comando sale con código distinto de cero: llevarlo a un controlador es lógica
  del proyecto.
- Los tres archivos se escriben juntos: si uno no parsea tras Pint, se devuelve
  el `routes/web.php` y se borran los creados.

`verify` usa el mismo reconocedor: cuando el aviso `route-closure` es ese
fallback, el mensaje dice «larapack:fix lo corrige» en lugar de pedir un
controlador. `ControllerShape` expone `routeClosures()`, `flaggedClosures()` y
`routeClosureMessage()` para eso, sin cambiar qué marca.

Probado con la forma de laravel-setup 7.2.0 tal cual (tras el arreglo,
`verify --strict` limpio en una aplicación con un modelo generado), con la de
expediente (la raíz en un controlador y otros closures: se corrige el fallback y
se saltan los demás) y con la de school-management (un fallback que recibe un
servicio: no se toca). Las pruebas nuevas (`FixSpaFallbackTest`,
`SpaFallbackTest`) entran en el filtro de Infection; con ellas, medido en local
sobre cada archivo, `LegacyFix` queda en 91 % (suelo 89) y `ControllerShape` en
96,6 % (suelo 95).

**Lo que no cierra.** El comentario de cabecera de `routes/web.php` se queda el
de 7.2.0: no se reescriben comentarios. Un fallback que no es exactamente el de
laravel-setup, y los closures propios de cada proyecto, siguen siendo trabajo
de una persona: el comando los lista con su razón y no los toca. Las líneas que
dan esos mensajes son las del archivo antes de corregirlo. `SpaFallback.php`
no entra en el alcance de Infection ni tiene suelo: medido aparte da 93 %, y
los mutantes que sobreviven son equivalentes o comprobaciones defensivas; meterlo
ahora movería el umbral global, que ya va justo.

### Lo que encontró la revisión antes de publicar

- **`larapack:fix route-closure` y `declare(strict_types=1)`.** En un `routes/web.php` sin ningún `use` y con
  `declare` al principio, el `use` de SpaController quedaba delante del `declare`. El archivo parsea, pero PHP lo
  rechaza al compilar y la aplicación deja de cargar. Ahora va detrás del `declare`; un `declare` que comparte línea
  con más código se salta con su razón. La prueba compila el resultado con `php -l`.
- **La guardia frena tres formas más de saltarse los hooks de git:** `core.hooksPath` dado por el entorno
  (`GIT_CONFIG_KEY_<n>` y `GIT_CONFIG_PARAMETERS`), `git commit-tree`, que crea un commit sin pasar por ningún hook,
  y un alias definido en la misma orden (`git -c alias.x=…`), cuyo contenido no se puede leer.
  **Límite, dicho:** un alias de la configuración de git (`git ci --no-verify`) o una orden armada con variables de
  la shell (`k=core.hooks; git config ${k}Path …`) no se leen; ahí la barrera es la CI.
- **`larapack:guard --doctor`** ya no da por encadenado un hook del proyecto que sólo menciona la carpeta
  `.githooks/` (un comentario, un `run-parts`): tiene que llamar a `.githooks/larapack-<hook>`.

## 9.2.0

### PHPStan en nivel 8, y lo que escondía

`phpstan.neon.dist` pasa del nivel 5 al 8 sobre `src/`, sin baseline ni
`ignoreErrors`: los 442 errores que daba el nivel 8 sobre 9.1 se arreglaron
uno a uno (335 del 6, casi todos tipos sin declarar en `src/Tools`; el resto,
nulos y `false` sin comprobar). Estos eran fallos de verdad, y tienen su
prueba en `StaticAnalysisRegressionTest`:

- **Un `composer.json` que no es JSON válido se reescribía.** `json_decode()`
  daba null, se le añadía la clave de los proveedores o del PSR-4 y se escribía
  encima un archivo con sólo eso. Ahora `composer.json` se lee y se valida en
  `Tool::init`, antes de escribir nada: todo comando que genera falla con un
  mensaje y no lo toca. `larapack:test`, que escribía `tests/TestCase.php` y
  `tests/User.php` antes de llegar a él, ya no deja nada a medias.
- **`larapack:test` borraba lo que hubiera en `autoload-dev`** si no traía
  `psr-4`: la sección se sustituía entera. Ahora se añade la entrada.
- **Sin un `autoload.psr-4` que apunte a `src/` (o `app/`) no hay namespace, y
  ahora se falla.** En 9.1 `get_namespace()` devolvía null: `larapack:factory`
  reventaba con un TypeError de `array_keys(null)` y lo demás, con otro en el
  manifiesto. Hacer que devolviera `''` habría sido peor —se probó—: el modelo
  salía en el namespace global (`namespace Models;`) y la factoría escribía
  `Database\Factories\` sin el prefijo del paquete en su `composer.json`, con
  el comando en 0. Ahora es una `MakerException` que dice qué falta. La
  entrada se lee como la lee Composer: la ruta sin barra final (`"src"`) y una
  lista de rutas (`["lib/", "src/"]`) valen —en 9.1 tampoco se reconocían—; el
  prefijo vacío (`""`) también lo acepta Composer, pero es el namespace global
  y se rechaza con su propio mensaje.
- **Un `composer.json` roto en una aplicación** se leía como vacío, es decir,
  como un paquete con destino `src/`. Ahora falla igual que en un paquete,
  antes de escribir nada.
- **Un modelo o un pivote que el laraimport no declara**, con el importador
  activo, acababa en un TypeError sin contexto; ahora dice cuál falta.

Y otros que eran frágiles. `revert()` de `larapack:fix` (`LegacyFix`) no
dependía de que `plan()` anote cada archivo una vez, y debía: quitaba por
índice mientras reindexaba, así que con dos arreglos del mismo archivo dejaba
el segundo en `fixed` al devolver el original. Hoy `plan()` anota cada archivo
una vez y el caso no se alcanza; ahora tampoco depende de ello. Las pruebas
están en `FixLegacySecurityTest`: la de los dos arreglos fuerza ese estado por
reflexión, y la de un Pint falso que rompe lo que toca cubre la reversión de
punta a punta, con un arreglo por archivo.

Además, un archivo que no se podía leer, o una expresión regular que fallaba,
se reescribía vacío; y donde una lectura decide algo —el manifiesto, el hash
de un archivo, el esquema, `ecosystem.json`— un `false` pasaba por `''`: un
manifiesto ilegible parecía vacío y un archivo ilegible, editado a mano. Ahora
esas lecturas van por `Files::read`, que falla con la ruta en el mensaje
(`FilesTest`: un archivo que no existe y un directorio).
`full-model`, `remove-full-model` y `providers` instanciaban las herramientas
por reflexión y llamaban a `create()` sobre un `object`; ahora las de un modelo
implementan `ModelArtifact` y las del paquete, `PackageArtifact`, y
`Artifacts` falla si un nombre de la lista no resuelve a una de ellas, en vez
de saltársela en silencio (`ArtifactsTest` recorre las tres listas, y prueba
un nombre que no existe y una clase que existe sin el contrato, de herramienta
y de proveedor).

Con un `composer.json` válido y su PSR-4, nada cambia en lo que se genera: las
fotos y la suite siguen idénticas.

**Por qué no el 9.** El nivel 9 da unos 600 errores (605 medidos con PHP 8.3
al cerrar la ronda), y casi todos vienen de lo mismo: el laraimport,
`ecosystem.json` y las opciones de consola llegan como `mixed`, y el código
los lee sabiendo que el esquema ya los validó. Subirlo de verdad es declarar la
forma del documento validado (`@phpstan-type` por modelo, propiedad, relación y
endpoint) en el único punto donde el esquema la garantiza; anotarlo línea a
línea sería callarlo.

`tests/` no entra en el análisis: en el nivel 5 da 41 errores y casi todos son
clases que sólo existen en tiempo de ejecución —lo que el generador escribe en
un proyecto temporal y luego se carga—, que habría que callar una a una.

### `larapack:audit` ya no pide `verify --strict` a quien no genera

El aviso `verify-job` se daba a todo paquete que tuviera LaraPack en
`composer.json`, y muchos lo tienen en `require-dev` sólo para correr
`larapack:audit`: no generan nada, y su `verify --strict` fallaría con
`empty-manifest`. Les pedía una puerta que estaría siempre en rojo. Ahora sólo
se pide a quien genera con LaraPack, y con la misma vara que usa verify para
no dar `empty-manifest`: su `.larapack/manifest.json` registra modelos o
archivos del paquete, o su `laraimport.json` declara algún modelo. Que el
archivo exista no basta: un manifiesto sin nada registrado o un laraimport sin
modelos también darían `empty-manifest`. Un laraimport con modelos que aún no
se importó sí recibe el aviso: ahí el rojo de verify es real. Está en
`AuditTest`. Cuántos paquetes del ecosistema dejan de recibirlo no se midió
aquí: el ecosistema no está en este repositorio.

### Pruebas de mutación de lo que sostiene la garantía

Que la suite pase por una línea no dice que compruebe lo que hace. Ahora se
mide: [`infection.json5`](infection.json5) muta las ocho piezas de las que
depende lo que LaraPack promete —`Verifier`, `ControllerShape`,
`LegacySecurity`, `PhpOutline`, `HandRolledLock`, `Guard`, `LegacyFix` y
`CiGate`— y el trabajo «Mutación» de la CI
([`mutation.yml`](.github/workflows/mutation.yml)) falla si el MSI de las ocho
juntas, o el de cualquiera, baja de lo logrado. Cómo correrlo en local, en
CONTRIBUTING («Las pruebas de mutación»); el MSI de cada pieza antes y después,
y los mutantes que sobreviven con su razón, en
[`docs/mutacion.md`](docs/mutacion.md).

MSI antes → después: `HandRolledLock` 53,7 → 97,9 %, `CiGate` 79,7 → 95,9 %,
`Guard` 75,0 → 95,7 %, `ControllerShape` 77,4 → 95,3 %, `Verifier` 82,7 →
93,3 %, `LegacySecurity` 74,5 → 92,8 %, `PhpOutline` 83,8 → 90,3 %,
`LegacyFix` 72,5 → 89,9 %; las ocho juntas, 77,0 → 93,4 %
(4335 mutantes; 93,9 % de los que algún test recorre). «Antes» se
midió sin `--with-uncovered`, sólo con lo que la suite de la 9.1.0 recorría;
«después», con él: lo que ningún test recorre también se muta y cuenta en
contra. Sin eso, Infection no muta lo no cubierto, y borrar el test que cubría
un mutante difícil subía el MSI. La CI exige `--min-msi=93` y
`--min-covered-msi=93`, y a cada pieza su suelo de
[`.github/mutation-floors.json`](.github/mutation-floors.json), que comprueba
`.github/scripts/mutation-floors.php` (con su test). Corre con PHP 8.3,
PHPUnit 12.5 e Infection 0.31.9, la configuración con la que se midió, sólo en
los pull requests que tocan `src/`, `tests/` o su configuración (y a mano), con
un límite de una hora y cancelando la corrida anterior de la misma rama. Hasta
que corra en un pull request, no se ha visto pasar en GitHub: lo de aquí está
medido en local con esa misma configuración.

Las pruebas nuevas comparan mensajes enteros y casos límite, y convierten los
avisos de PHP en excepciones como Laravel: un mutante que sólo deja un aviso
también cuenta.

Queda pendiente, y apuntado en `docs/mutacion.md`: los mutantes de las barras
invertidas de `CiGate` sólo los mataría una CI en Windows, y el trabajo
`windows-guard` no corre `CiGateTest` ni `GuardInputsTest` (lo que en ellos
depende del sistema —`posix_geteuid()`, permisos, enlaces— no está escrito para
Windows). Una primera versión de ese documento decía lo contrario.

Los mutantes que sobrevivían destaparon fallos, y se arreglan con su prueba:

- **`PhpOutline` llamaba `&` a un método que devuelve por referencia**
  (`public function &items()`). Desde PHP 8.1 el `&` es un token con nombre
  (`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`) y se comparaba con el carácter
  suelto. ControllerShape lo leía como una acción llamada `&`, y la huella de
  una excepción aprobada sobre ese método no nombraba el método. El `&` no se
  pierde: el esquema del método lo apunta (`byRef`) y la huella lo pone
  delante de la firma, como en el código, así que pasar un método aprobado a
  devolver por referencia (o al revés) deja la aprobación sin efecto. La
  huella de los métodos sin `&` no cambia.
- **La línea de un `;` o un `(` tras un salto dentro de un espacio** era la de
  la línea anterior. Afecta a la línea que citan los mensajes de la forma del
  constructor.
- **La guardia no veía `bash -ce '…'`**: sólo leía como orden el argumento de
  un grupo de opciones que *acaba* en `c` (`-c`, `-ec`). `bash -ce` ejecuta el
  texto igual.
- **La guardia leía `<<<` como un heredoc.** Su segundo `<` abría un heredoc
  con la cadena de delimitador, y las líneas siguientes —órdenes— se tomaban
  por su cuerpo: `cat <<< x` y debajo `larapack:hand-rolled approve` pasaba.
  Ahora `<<<` se salta entero, y la cadena que recibe una shell
  (`bash <<< '…'`) se lee como órdenes, igual que su heredoc.
- **La guardia dejaba pasar lo que no terminaba de leer.** Lee hasta tres
  shells dentro de la orden (`bash -c`, `eval`, un heredoc que recibe una
  shell); más adentro no leía nada y lo daba por bueno, así que
  `larapack:hand-rolled approve` dentro de cuatro `bash -c` pasaba. Una prueba
  de mutación lo fijaba como si fuera lo esperado. Ahora falla cerrado: lo que
  no se termina de leer cuenta como una aprobación y se bloquea. Al hook sólo
  llegan las órdenes que nombran `approve`, `revoke` o `hand-rolled`, así que
  lo único que se bloquea de más son cuatro shells anidadas que además nombran
  una de esas palabras.
- **`LegacySecurity` confundía `$Appends` con `$appends`**: las variables de
  PHP distinguen mayúsculas, y bajar todo a minúsculas avisaba de un
  setAppends() que recibía la variable limpia.
- Código muerto: `PhpOutline::skipTo()`, que saltaba hasta el `;` de un
  `use function` lo que `parseFile()` ya recorre sin registrar nada.

Al actualizar: un método que devuelve por referencia y estaba aprobado en
`.larapack/hand-rolled.lock` se aprobó como `Clase::&`. Ahora es
`Clase::metodo`, con otra huella: la aprobación vieja sale como sobrante y el
método vuelve a pedir la del dueño.

### El hook previo ya no escribe «Broken pipe»

El hook `PreToolUse` que instala `larapack:skill --hooks` le pasa la entrada a
PHP con `printf … |`, y comprobaba que existiera `vendor/bin/builder` (o
`vendor/autoload.php`) *dentro* de la tubería. Sin `vendor/`, el lado que lee
salía sin leer; si salía antes de que printf escribiera, la shell añadía
`printf: write error: Broken pipe` a la salida del hook. Con una orden corta
era una carrera —el test `AgentHooksTest` que fallaba de vez en cuando—, y con
una entrada mayor que el búfer de la tubería, siempre. Ahora la comprobación va
delante de la tubería, y el error de escritura de printf (que aquí sólo puede
ser esa tubería cerrada) va a `/dev/null`: lo que cuenta es lo que dice PHP y
su código de salida. El test nuevo lo prueba con 256 KiB de entrada, sin
builder y con uno que sale sin leer, y repite la orden corta veinticinco veces.

Al actualizar: un hook ya instalado se reconoce y no se reescribe. Para tener
el nuevo, quita el grupo `PreToolUse` de LaraPack de `.claude/settings.json` y
vuelve a correr `larapack:skill --hooks`.

### La CI prueba el ecosistema publicado, cada noche y antes de publicar

El choque entre `innoboxrr/laravel-setup` y la 9.0 —laravel-setup pedía
`^7.10 || ^8.0` y Composer no dejaba instalar la 9— lo descubrió un cliente: las
pruebas de punta a punta usan el `vendor/` de este repositorio y nunca instalan
LaraPack junto a lo publicado.

`.github/workflows/ecosystem.yml` corre cada noche, a mano, en los *pull
request* que tocan el workflow, su script, sus comprobaciones, su laraimport,
`ecosystem.json`, `composer.json` o `VERSION`, y **antes de publicar**: con Vue
y con React. Los pasos están en `.github/scripts/ecosystem-e2e.sh`, que se corre
igual en local: Laravel 13 recién creado con `create-project`, la última
laravel-setup publicada que cumpla `SETUP_CONSTRAINT` (`^7.2.1` por omisión)
fijada a su versión exacta, LaraPack desde este checkout como dist
(`git archive`, con la versión de `VERSION`), `app:setup` y `app:install`, un
laraimport de ejemplo (dos modelos con relación, endpoints de modelo y un
`controllers[]`), `validate --strict` e `import`, `migrate` en SQLite,
`npm run build`, `php artisan test` contando por archivo que cada test generado
se ejecute —incluidos los de autorización de la 9.1— y ninguno se salte,
`verify --strict --format=json`, `larapack:ci --dry-run` y la guardia de los
hooks con el `artisan` de la aplicación.

**La puerta de `Release`.** El incidente fue una subida de versión, así que la
prueba corre también donde se publica: `release.yml` mira si la versión de
`VERSION` ya tiene tag y, si no, llama a `ecosystem.yml` (ahora también
`workflow_call`) y sólo etiqueta si pasa. Una mayor que la laravel-setup
publicada no admita se queda sin publicar, en vez de descubrirse a la noche
siguiente con Packagist ya sirviéndola. El orden para una mayor queda en
CONTRIBUTING: primero laravel-setup amplía su restricción, después se sube
`VERSION`. Los PR que cambian `src/` no la disparan (45 minutos por interfaz):
los cubren la noche y esta puerta.

**Las comprobaciones pueden fallar.** Las lecturas del script —qué
laravel-setup, de dónde salió LaraPack, el informe JUnit, el de `verify`— están
en `.github/scripts/ecosystem-e2e-checks.php`. En la primera versión eran
`php -r` con `exit("mensaje")`, que en PHP imprime y sale con 0: la que cuenta
los tests generados no podía fallar ni con tests saltados, ni con un archivo
generado sin ejecutar, ni con el XML roto. Ahora cada fallo sale con 1 y el
motivo en stderr, y `EcosystemE2eChecksTest` lo comprueba con esos informes
rotos. `EcosystemE2eScriptTest` corre el principio del script con un `composer`
de mentira: qué laravel-setup elige, que falla si ninguna cumple la
restricción, que vuelve a mirar el origen de LaraPack y a quién le llega el
token.

Si un paso falla, el mensaje dice qué paquete y por qué: el choque que cita
Composer, los paquetes de `vendor/innoboxrr/` de la traza, el paquete npm que
no resuelve o, para cada hallazgo de `verify`, de dónde viene el archivo. Que
la ruta exista entre los stubs de laravel-setup no basta para culparla —LaraPack
regenera encima el User, su política o las rutas—: sólo un archivo idéntico al
stub se le atribuye; uno con otro contenido se dice «en sus stubs, pero
reescrito».

Además: el origen de LaraPack (el repositorio `path`, con la versión de
`VERSION`) se mira después del `require`, de `app:install` y de `import`, porque
`app:setup` reescribe su restricción en `composer.json` y `app:install` corre
`composer update`; y el token de GitHub llega como `ECOSYSTEM_COMPOSER_AUTH`,
que el script saca del entorno y sólo pasa a Composer y a `app:install`: ni
`npm install` ni los tests de la aplicación lo ven.

**Lo primero que encontró:** una aplicación recién hecha con laravel-setup 7.2.0
no pasa `larapack:verify --strict`, la puerta que pone `larapack:ci` desde la
9.1: el `Route::fallback()` de la SPA en `routes/web.php` (de los stubs de
laravel-setup) lleva lógica en un closure, y `verify` lo avisa como
`route-closure`. No se deja como hallazgo esperado: `verify --strict` tiene que
salir limpio, sin excepciones, porque un rojo conocido tapa al siguiente. El
arreglo es de laravel-setup (el fallback pasa a un controlador que sólo hace
`return $request->handle();`), sale en su 7.2.1, y por eso `SETUP_CONSTRAINT`
pide `^7.2.1`. Si ninguna versión publicada cumple la restricción, el workflow
falla en el paso que la busca con «ninguna versión publicada de
innoboxrr/laravel-setup cumple SETUP_CONSTRAINT=^7.2.1»: un rojo distinto del
de cualquier regresión. Al cerrar este cambio Packagist ya sirve la 7.2.1 (que
admite `^9.0`) y el script la elige. Con la 7.2.0, la primera versión del
script pasaba todo salvo `verify`, con Vue y con React.

Lo que no se ha podido probar aquí, y por qué: la corrida entera con la 7.2.1
y con este script. El contenedor donde se hizo el cambio no puede bajar las
dist de GitHub (el proxy las rechaza) y los clones de git con los que Composer
lo suple llenaron el disco a mitad del `create-project`. Lo nuevo del script se
probó por partes: las comprobaciones con `EcosystemE2eChecksTest`, el principio
del script con `EcosystemE2eScriptTest` y la elección de versión contra
Packagist de verdad. La primera noche o el primer PR que toque `VERSION` es la
corrida entera. La puerta de `Release` y el `workflow_call` sólo existen en
GitHub Actions: los tests comprueban la forma de los workflows, no una
ejecución.

Cómo correrlo en local, también sin acceso a las dist de GitHub, en
CONTRIBUTING.md.

### Al actualizar

- **Un proyecto sin `autoload.psr-4` hacia `src/` (o `app/`), o con un
  `composer.json` que no es JSON válido, ya no genera**: falla con un mensaje.
  En 9.1 tampoco generaba nada correcto (acababa en un TypeError); añade la
  entrada (`"Acme\\Blog\\": "src/"`) y vuelve a ejecutar. La ruta sin barra
  final (`"src"`) o dentro de una lista de rutas vale igual. **Si la única
  entrada que apunta a `src/` es la del prefijo vacío (`""`)**, también falla:
  antes se generaba en el namespace global; dale un namespace con nombre.
- **Rompe si extiendes `Tool` fuera del generador**, aunque sea una menor. Las
  herramientas no se pensaron como punto de extensión, pero nada lo impedía:
  los métodos de `Tool` llevan ahora tipos nativos y una subclase que
  sobrescriba uno sin ellos (`processFileWithJson(): void`, `init(): static`,
  `addProvidersToComposerJson(): void`…) no compila; `create()` de
  `EventsTool`, `RequestsTool` y `TestTool` devuelve `bool`, como las demás, y
  una subclase que lo declare sin tipo tampoco. Hay que añadir los mismos tipos
  a las firmas que sobrescribes.
- Si la CI de un paquete corre `larapack:audit --strict` y tenía el job de
  verify sólo por el aviso, sin generar nada, ese job puede irse.

## 9.1.0

Una menor: la CI pasa a ser la barrera de la arquitectura y las excepciones
`#[HandRolled]` necesitan la aprobación del dueño, los tests generados prueban
la autorización, y una segunda auditoría de seguridad prueba en masa el escapado
de los valores del laraimport. Además, documentación para quien mantiene el
generador. Lo que hay que hacer al actualizar está junto, al final.

### La barrera es la CI, y `#[HandRolled]` exige aprobación

En 9.0 la regla del controlador se cumplía mientras el agente tuviera los hooks
y la excepción `#[HandRolled]` la decidía quien escribía el atributo. Ninguna de
las dos cosas puede depender de que alguien se acuerde: ahora la rama protegida
exige `verify --strict`, y una excepción sólo cuenta si el dueño la aprobó en un
archivo que pasa por su revisión.

**`.larapack/hand-rolled.lock` y `larapack:hand-rolled`.** El candado es una
lista JSON de `{target, reason, fingerprint}`, ordenada y estable byte a byte.
`target` es `Clase` o `Clase::metodo`, con el nombre completo. `fingerprint` es
la huella del código aprobado —sha256 de sus tokens, sin espacios ni
comentarios—: de un método, su firma y su cuerpo; de una clase, todos sus
métodos, su padre y sus traits. Aprobar un nombre sería un cheque en blanco:
una clase aprobada cubriría cada acción que se le añadiera después. Con la
huella, añadir una acción, cambiar una sentencia o un parámetro deja la
excepción sin aprobar, y el diff del candado que la vuelve a aprobar es lo que
CODEOWNERS pide revisar. Reformatear o comentar no la cambia. Lo que el código
aprobado llama fuera de la clase (otra clase, el cuerpo de un trait) no entra
en la huella.

```
php artisan larapack:hand-rolled list                          # estado de cada excepción
php artisan larapack:hand-rolled approve --all                 # aprueba las del código
php artisan larapack:hand-rolled approve TokenController::store
php artisan larapack:hand-rolled revoke 'App\Http\Controllers\InboundWebhookController'
```

Basta el final del nombre que lo distinga; si coincide con más de uno, no se
adivina. Aprobar es correr el comando, revisar el diff del candado y comitearlo;
`approve` recalcula la huella. `list` marca `code-changed` lo que cambió desde
que se aprobó. Sin candado y sin excepciones en el código, `approve --all` no
crea uno vacío: el candado cambia el modo de `verify` (un `#[HandRolled]` nuevo
pasa de aviso a error), y eso no ocurre de rebote.

| Comprobación | Nivel | Con candado |
| --- | --- | --- |
| `hand-rolled` | info | La excepción está aprobada con esa misma razón. `--strict` pasa. |
| `hand-rolled-unapproved` | error | No está en el candado, **cambió la razón o cambió el código** (la huella no coincide): se aprobó otra cosa. También una entrada sin huella, y un candado ilegible, que deja todo sin aprobar. |
| `hand-rolled-stale` | aviso | El candado aprueba algo que ya no lleva `#[HandRolled]`. Se retira con `revoke`. |

Sin candado, `hand-rolled` sigue siendo aviso, como en 9.0.

**La guardia no deja que un agente apruebe.** `larapack:guard` bloquea con 2:

- La edición del candado (`Write`, `Edit`, `MultiEdit`), con la ruta
  normalizada antes de compararla: `//`, `/./`, `x/..`, relativa, con barras de
  Windows o un punto final.
- La orden de Bash que corre `larapack:hand-rolled approve` o `revoke`, también
  por el alias `make:`, por una abreviatura (`larapack:hand`, `l:h`, y con el
  espacio vacío, `:hand-rolled` o `:h`, que Symfony también acepta), con la
  palabra partida por comillas (`appr''ove`) o por una continuación de línea
  (barra invertida y salto), o dentro de `bash -c` y `eval`.
- La orden de Bash que escribe el candado sin el comando: una redirección (`>`,
  `>>`), `tee`, `cp`, `mv`, `install`, `ln`, `rsync`, `truncate`, `dd of=`,
  `sed -i`, `perl -i`, y el código en línea de `php -r`, `python -c`,
  `node -e`, `ruby -e` o `perl -e` que lo nombre. Leerlo (`cat`, `git diff`) o
  devolverlo con `git checkout --` no.

La orden se lee como la shell —separadores, comillas, continuaciones y
heredocs—, sin ejecutarla: un `git commit -m "approve"`, un `grep` o la
documentación escrita con `cat <<'EOF'` no bloquean; un heredoc que recibe una
shell (`bash <<EOF`), sí. `larapack:skill --hooks` añade para eso un hook
**PreToolUse** (`Write|Edit|MultiEdit|Bash`, `larapack:guard --pre`): el
PostToolUse llega con el archivo ya escrito y su matcher no ve Bash. Como corre
en cada orden, la shell sólo despierta a PHP si la entrada contiene `approve`,
`revoke` o `hand-rolled` —también quitando antes comillas y barras invertidas—,
o una continuación de línea. Se fusiona como los demás y no se duplica. Con los
hooks de 9.0, que sólo tienen el PostToolUse sobre `Write|Edit|MultiEdit`, la
guardia ve la edición del candado con esas herramientas —ya hecha: bloquea y
pide deshacerla—, pero ninguna orden de Bash. No es infranqueable —una
variable, un glob o un script propio escriben el archivo por otra vía—: la
barrera es la revisión del dueño en la rama protegida.

**`larapack:ci`** pone `verify --strict` como check de la rama en un proyecto
que ya existe:

```
php artisan larapack:ci --dry-run
php artisan larapack:ci --owner=@organizacion/arquitectura
```

- Crea `.github/workflows/larapack.yml` con el job **«LaraPack verify»**: Artisan
  en una aplicación, el builder en un paquete; PHP de `config.platform.php` o la
  mínima con la que se instala `composer.lock` (school-management pide `^8.3`
  y su lock exige 8.4); las `ext-*` de `composer.json`; `pull_request` y `push`
  a la rama por defecto de `origin`. No edita ningún otro workflow, y si el suyo
  difiere no lo pisa sin `--force`: enseña el diff y sale con 1.
- Con `--owner` (repetible) fusiona en el CODEOWNERS que GitHub ya lee el
  candado, el workflow y el propio CODEOWNERS: sin duplicar, sin tocar líneas
  ajenas, y sin cambiar un patrón que ya tiene otro dueño (lo dice). Sin
  `--owner` no inventa uno.
- `--runs-on` (repetible, o una lista JSON: `--runs-on='["self-hosted","linux"]'`)
  pone el job en los runners del proyecto. Sin él va a `ubuntu-latest`, y si
  otros workflows corren en otro sitio lo dice con la opción ya escrita: un job
  que no arranca deja el check exigido sin llegar y nada se fusiona, y en un
  monorepo donde `verify` tarda minutos, el runner alojado los cobra en cada PR.
- Si el workflow difiere y no hay `--force`, no escribe nada: tampoco
  CODEOWNERS, que enseña como «añadiría».
- Imprime lo que no puede hacer: proteger la rama exigiendo el check y *Require
  review from Code Owners*, con la orden `gh api --method PUT
  'repos/{dueño}/{repo}/branches/{rama}/protection'` para que la corra el dueño.
  Nunca la ejecuta. La rama sale del remoto: si tiene caracteres de shell, la
  orden lleva `RAMA` y avisa.
- **La puerta sólo vale si nadie entra por fuera de ella.** La orden pide
  `enforce_admins=true`: con `false`, quien empuja como administrador se salta
  el check y la revisión, y GitHub sólo lo anota. Es el caso de un orquestador
  que sube el SHA ya verificado directo a la rama con la cuenta del dueño: cada
  push pasaría sin `verify --strict`. El precio es que GitHub no deja aprobar
  un PR propio, así que los PR del agente tienen que salir de una cuenta que no
  sea la del único dueño de código (un bot sin administración). Con
  `--no-enforce-admins` la orden lleva `false`, y la salida avisa.

**Los paquetes nuevos** (`larapack:new`) traen el mismo job «LaraPack verify»
en su `tests.yml`, y **`larapack:audit`** avisa con `verify-job` si la CI de un
paquete que usa LaraPack no corre `larapack:verify --strict`. Un paquete en
`packages/` de un repositorio cuya raíz ya corre `verify --strict` no avisa:
el `verify` de la raíz recorre `packages/*` con un solo candado.

**`empty-manifest` ya no avisa a un paquete sin modelos que tiene registrados
sus proveedores** —una pasarela, un PAC: doce de los ochenta y un paquetes de
school-management—. Lo registrado se sigue comprobando. Con `verify --strict`
en la CI, ese aviso los habría dejado en rojo para siempre.

### Migrar: school-management

Hoy school-management pide `innoboxrr/larapack-generator` `^8.0` y no tiene
ningún `#[HandRolled]`. Esto es para **cuando el proyecto ya esté en 9.0** y el
dueño haya marcado sus excepciones; los nombres de abajo son ejemplos. Una vez,
**el dueño**:

```
composer require innoboxrr/larapack-generator:"^9.1" --dev
php artisan larapack:skill --force --hooks               # añade el hook PreToolUse
php artisan larapack:hand-rolled list                    # cada excepción, «sin candado»
php artisan larapack:hand-rolled approve --all           # crea .larapack/hand-rolled.lock con sus huellas
cat .larapack/hand-rolled.lock                            # revisa objetivos y razones
php artisan larapack:verify --strict --format=json       # ok: true antes de exigirlo (ver abajo)
php artisan larapack:ci --owner=@<dueño-o-equipo> --runs-on='["self-hosted","linux","x64","ovh"]'
git add .larapack/hand-rolled.lock .github .claude/settings.json && git commit
```

Para retirar una, el nombre completo: hay dos `InboundWebhookController`
(`App\Http\Controllers\InboundWebhookController` y
`App\Http\Controllers\Integrations\InboundWebhookController`), y el final del
nombre sólo vale si no se repite:

```
php artisan larapack:hand-rolled revoke 'App\Http\Controllers\Integrations\InboundWebhookController'
```

Después, el workflow corre una vez y el dueño protege `master` con la orden que
imprime `larapack:ci`. Tres cosas propias de ese proyecto:

- **El push directo a `master` se salta la puerta.** Su contrato de sesión sube
  el SHA verificado directo a `master` con la cuenta del dueño. Con
  `enforce_admins=true` ese push deja de entrar; con `false`, entra sin
  `verify --strict` en cada vez. Para que la puerta valga, el orquestador tiene
  que pasar por PR, o empujar con una cuenta que no sea de administración y sin
  permiso para saltarse la protección.
- **Runners propios.** Su CI corre en `[self-hosted, linux, x64, ovh]` porque
  se acabaron los minutos alojados, y `verify` tarda minutos en ese monorepo:
  sin `--runs-on`, el job gastaría minutos de pago en cada PR o no arrancaría.
- **Un solo candado.** El `verify` de la raíz recorre `packages/*`: el candado
  de la raíz aprueba también las excepciones de los paquetes, y `audit` no pide
  un job por paquete. Sólo un paquete que se publique y corra `verify --strict`
  en su propio repositorio necesita su candado (`--root=packages/<paquete>`).

Si la CI de los paquetes corre `larapack:audit --strict`, el aviso `verify-job`
la pone en rojo hasta que cada paquete tenga el job: añádelo
(`larapack:ci --root=packages/<paquete>`) antes de subir a 9.1.

Sus `<Modelo>EndpointsTest` son de antes de 9.1: `verify` avisará con
`endpoint-tests-outdated` por cada uno (unos 128), y con `--strict` no da
`ok: true` hasta regenerarlos. Las pruebas nuevas pueden destapar un
`authorize()` que no pregunta o un listado sin acotar: es lo que buscan. Y antes
de pasar a `controllers[]` sus controladores escritos a mano que dejan entrar a
cualquiera con sesión (`DeskLayout`, `Tours`, `Documentation*`), declara en
ellos `"authorize": "authenticated"`.

### Los tests generados prueban lo que cierra la autorización

Hasta ahora el test de cada modelo abría la autorización con
`Gate::before(fn () => true)` y probaba sólo el camino feliz: un `authorize()`
que devolviera `true`, un `IndexRequest` que dejara de forzar `managed` o un
listado que no pasara por el `ManagedFilter` salían en verde. Ahora cada
`<Modelo>EndpointsTest` trae, además, tres pruebas que pasan tal como salen y
no dependen de lo que decida la política del proyecto:

- **`test_<modelo>_cada_accion_requiere_sesion`**: sin sesión, 401 en cada
  acción declarada. Sustituye a `test_<modelo>_index_requiere_sesion`, que sólo
  miraba el listado.
- **`test_<modelo>_cada_accion_pregunta_a_la_politica`**: con sesión y la
  autorización negando, 403 en cada acción declarada; `policies` y `policy`
  responden 200 con todo en `false`. Para negar se cambia la puerta por una
  nueva cuyo único `before()` niega (`Gate::swap(new Gate(..., beforeCallbacks:
  [fn ($user = null) => false]))`): un `Gate::before(fn () => false)` no
  bastaría, porque decide el primer `before()` que responde y el `TestCase` (o
  el `setUp()`) ya abrió la puerta. La política ni se consulta, así que la
  prueba no cambia cuando se escribe.
- **`test_<modelo>_index_pasa_por_su_managed_filter`**: el `FilterRegistry` de
  SearchSurge se envuelve en el test para cambiar el `ManagedFilter` del modelo
  por uno que primero deja ver todo y después nada. Con `managed=false` en la
  petición —en la query, como el front, y en el cuerpo—, el registro aparece y
  luego desaparece: prueba que SearchSurge resuelve el `ManagedFilter` del
  modelo, que `IndexRequest` fuerza `managed` y que `canView()` se aplica. El
  test cuenta que se llame al `canView()` sustituto: SearchSurge lo invoca
  sobre su `managedFilterClass`, el primer filtro crítico, y un segundo filtro
  derivado de `Managed` ordenado antes lo dejaría sin efecto; así falla
  diciendo eso y no otra cosa. El estado del sustituto es nuevo en cada
  ejecución (la clase anónima se declara una vez por proceso). Qué deja ver
  `canView()` es un hueco y no se prueba; el comentario del test dice dónde
  hacerlo.

Las acciones salen de `routes.only`/`except` e `immutable`, como el resto del
test: un modelo de sólo lectura recorre `index` y `show`. Se agrupan en una
prueba por concepto, no una por acción, para no multiplicar por doce los tests
de una aplicación con cien modelos.

El test de cada endpoint declarado (`tests/Feature/Endpoints/<Modelo>/`) gana
**`test_con_sesion_pregunta_a_la_autorizacion`**: con sesión y la autorización
negando, 403, también en los públicos y en los `controllers[]`. El de un
registro lo crea y pone su identificador donde lo lee la request, en el cuerpo o
en la URI. En una aplicación, ese test usa ahora `RefreshDatabase` y su propio
`signInApplicationUser()`.

**`"authorize": "authenticated"` en un endpoint de `controllers[]`.** Un
endpoint abierto a cualquiera con sesión, o cuya `authorize()` decide sin Gate
—deja a `rules()` el 422 y responde 404 por lo ajeno, como el
`InstalledModulesRequest` de la skill—, fallaría siempre ese 403. Ahora se
declara: `authorize()` nace en `true` y su test pasa a ser
**`test_con_sesion_basta_la_sesion`**, que con la autorización negando espera
cualquier cosa menos 403; el 401 sin sesión se mantiene. No admite `ability` ni
`public` (`validate` lo rechaza) y en un endpoint de modelo no existe: ahí
«cualquiera con sesión» es el método de la política devolviendo `true`, y el
test, que no consulta la política, sigue pasando. Sin declararlo, todo sale
como antes y el manifiesto no cambia.

Ninguna sale `skipped` ni `incomplete`. Los tests de EndToEnd ejecutan las
nuevas en un paquete y en una aplicación generados, sin tocarlas, y pasan Pint
y Larastan. El paquete de prueba cubre ahora, ejecutándolos y no sólo en foto,
`routes.only` (un modelo de sólo `index`/`show` y otro de
`index`/`update`/`bulkUpdate`), un endpoint de registro con otro parámetro en la
URI (`{product_id}/move-to/{slot}`), uno de colección con `delete`, un
`controllers[]` con parámetro (`import/{batch}`) y uno con `authorize:
authenticated`.

**En un proyecto existente, `import --force` reescribe el test de cada
endpoint que nadie editó** (y `.larapack/manifest.json`) y le añade
`test_con_sesion_pregunta_a_la_autorizacion`. Ese test **falla** en un
`controllers[]` cuya `authorize()` se rellenó a mano para dejar pasar a
cualquiera con sesión o decidir sin Gate: es lo que hay que declarar con
`"authorize": "authenticated"` antes de regenerar. El test de endpoint editado
se conserva.

`<Modelo>EndpointsTest`, en cambio, se creó una vez y no se regenera nunca: no
está en el manifiesto, así que no hay hash con el que saber si alguien lo editó
y reescribirlo podría tirar pruebas escritas a mano. Por eso **`verify` avisa
con `endpoint-tests-outdated`** por cada uno al que le falte alguna de las tres
pruebas —la del `ManagedFilter`, sólo si el modelo tiene listado—, con el modelo
y lo que hay que correr: guardar lo añadido, borrarlo, `larapack:import` (sin
`--force` basta: crea el que falta) y volver a pegarlo. Es un aviso, así que
**con `--strict` pone `verify` en rojo** hasta que se haga. No se añadió un paso
en `larapack:fix`: sin hash no hay forma segura de saber que el archivo es el
generado, e injertar métodos en una clase editada (sus `use`, sus ayudas)
tampoco lo es. Las pruebas usan `mockery/mockery`, que traen `laravel/laravel`
y `orchestra/testbench`.

### Seguridad: segunda auditoría, con pruebas por propiedades

La auditoría de 9.0 escapó los valores libres del laraimport caso por caso.
Ésta los prueba en masa: 3000 cadenas hostiles de semilla fija (comillas, barra
final, NUL, saltos, U+2028/2029, `*/`, `?>`, `${`, `</script>`, `}}`, entidades,
BOM, marcas combinantes, control bidireccional…) por cada contexto donde un
valor acaba como código, y un `larapack:import` real con ellas en cada campo
libre —`default` de modelo y de pivote, `cast`, valores y etiquetas de `enum`,
claves y valores de `rules`—. La propiedad es siempre la misma: el archivo
generado se analiza, no sale del literal ni del modo PHP, y el valor se recupera
idéntico. Los escapes viven ahora en `Support\Literal`, uno por contexto.

Lo que salió, de más a menos grave:

- **Código en el formulario Vue a través de una etiqueta de `enum`** (media).
  Las etiquetas van en `{{ t('…') }}` y llevaban sólo el escape JS. El
  compilador de plantillas de Vue decodifica las entidades HTML de la
  interpolación antes de leer el JS: la etiqueta
  `&#39;) + t.constructor.constructor(&#39;…&#39;)() + (&#39;` llegaba como
  `t('') + t.constructor.constructor('…')() + ('')` y se ejecutaba en el
  navegador de quien abre el formulario. Una con `}}` dejaba la plantilla sin
  compilar. Ahora `&` y `}` van como escapes JS (`\x26`, `\x7d`). Comprobado con
  `@vue/compiler-sfc` 3.5.
- **El hook de la guardia repetía al modelo texto del proyecto con saltos de
  línea** (media). Un archivo de `Http/Controllers` con un salto en el nombre,
  o un `API_ROUTE_PREFIX` con saltos en el módulo JS, añadía líneas que parecían
  de LaraPack («LaraPack: antes de corregir nada, ejecuta …») a un mensaje que
  el modelo lee como salida de un hook del usuario. Ruta, comprobación y
  mensaje salen ahora en una línea, con cada carácter de control, de formato o
  separador como `\u{XXXX}` (`Guard::inline()`), también el mensaje de una
  excepción cuando la guardia falla; lo que no es UTF-8 se sustituye, y el JSON
  del recordatorio ya no puede salir vacío.
- **`larapack:skill` escribía a través de enlaces simbólicos** (media-baja). Un
  repositorio con `.claude/settings.json` (o `.claude`, o `AGENTS.md`) enlazado
  al `~/.claude/settings.json` de quien lo clona recibía los hooks de LaraPack
  en la configuración global: desde entonces cada edición, en cualquier
  proyecto, ejecutaba su `artisan` o su `vendor/bin/builder`. Ahora el destino
  real tiene que quedar dentro del proyecto; un enlace interno
  (`AGENTS.md` → `CLAUDE.md`) y un `--path` que sale por escrito siguen valiendo.
- **El estado de la sesión de la guardia, en un directorio compartido** (baja).
  Era `/tmp/larapack-guard`, creado 0777: otro usuario de la máquina podía
  crearlo antes con un enlace por sesión hacia un archivo de la víctima, y cada
  edición le añadía una línea. Ahora es `larapack-guard-<uid>`, 0700, y sólo se
  usa si es un directorio de verdad —no un enlace—, del usuario y que nadie más
  puede escribir. Sin `ext-posix` no se puede saber de quién es: en Windows,
  donde el temporal es del usuario, se usa `larapack-guard`; en Linux o macOS la
  guardia sigue sin estado de sesión, como con un directorio que no es de fiar,
  y el hook de fin de turno se apoya en git.
- **Un archivo vacío donde había datos** (baja). `json_encode()` devuelve
  `false`, y pegado a `"\n"` dejaba el archivo vacío: un archivo del proyecto
  en Latin-1 en el `src` del módulo vaciaba `locales/<idioma>.json` con todas
  las traducciones escritas a mano, y un `1e999` en `.claude/settings.json` lo
  vaciaba al añadir los hooks. Ahora la clave que no es UTF-8 se ignora y los
  dos se escriben con `JSON_THROW_ON_ERROR`.
- **Un endpoint que tumba la API** (baja). `{course_id}/copy/{course_id}` o un
  parámetro de más de 32 caracteres pasan el esquema, y el compilador de rutas
  lanza al compilarlas: un 500 en cada petición con ese verbo que llegue hasta
  ella —también un 404— y `route:cache` roto. Ahora es un error de validación.
- **Defaults numéricos en cadena** (baja). `is_numeric()` dejaba sin comillas
  `"08"` (un octal inválido: la migración no compilaba), `"007"` y `"01"` (los
  octales 7 y 1), `" 1"` o `"1e3"`. Ahora va sin comillas sólo la cadena con
  forma de número decimal sin ceros a la izquierda, sin exponente y sin
  espacios (`-?(0|[1-9][0-9]*)(\.[0-9]+)?`); `"10.00"`, `"0.00"` y `"1.10"`
  siguen saliendo `->default(10.00)` como en 9.0. Las demás van entre comillas.
  El pivote usa ahora el mismo literal (`Tool::defaultLiteral()`): antes
  escribía todo entre comillas, y `true` salía `'1'`, `false` salía `''`
  —inválido para un `boolean` en MySQL estricto— y `0` salía `'0'`.
- **Claves de traducción que nadie pide** (baja). `t('Dos\nlíneas')` daba la
  clave «Dosnlíneas»: ahora se decodifican los escapes de la cadena.
- **El `value` de una opción con un salto de línea** (baja): el analizador HTML
  convierte `\r` en `\n`. Los saltos van como entidades numéricas.
- **`larapack:fix --dry-run` sin memoria** (baja). El diff calculaba una tabla
  de n·m: dos cambios a 3000 líneas pedían 200 MB. Pasados 4 millones de celdas
  se parte por anclas, como el diff «patience» de git: las líneas que aparecen
  una sola vez en cada lado, en su orden común más largo, no cambiaron, y entre
  dos anclas queda un trozo pequeño. Dos cambios lejanos siguen saliendo en dos
  trozos y no como el archivo entero quitado y puesto; eso queda sólo para un
  archivo sin ninguna línea única. Memoria lineal, el mismo diff cada vez, y
  exacto: una prueba por propiedades lo aplica al original.
- **Mensajes**: una clave del laraimport que empieza por NUL o un número que
  JSON lee como INF daban «The data (null) must match the type: object». Se
  siguen rechazando, ahora diciendo por qué.

Revisado sin cambios: `larapack:fix --path` y `--package` sólo filtran la lista
de archivos del proyecto (`--path=..` no añade nada); `larapack:guard` resuelve
`file_path` con `realpath`, ignora una entrada que no es un objeto JSON y sólo
lee archivos con el tokenizador; los nombres, URIs, middleware y habilidades de
los endpoints están acotados por el esquema y la interfaz generada no pinta HTML
(`html: false`); los patrones del esquema terminan con `D` (Opis), así que un
salto de línea final no pasa; las expresiones regulares de `verify` y `fix` no
tienen cuantificadores anidados ambiguos, PhpOutline no es recursivo y la búsqueda
de `handle()` por padres y traits lleva la cuenta de lo visitado.

Queda anotado sin arreglar: un archivo de rutas con miles de closures anidados
hace que `route-closure` tarde decenas de segundos (cuadrático en la
profundidad); el hook vence por tiempo y deja pasar. Una clave de `enum`
`__proto__` no se recupera en el mapa de etiquetas de la tabla.

### Al actualizar

**La barrera de la CI.** Nada rompe a un proyecto en 9.0: sin
`.larapack/hand-rolled.lock`, `verify` se comporta igual que antes, y el
workflow y CODEOWNERS sólo se crean si se pide.

Regenerar cambia lo generado sólo donde había valores raros: etiquetas de enum
con `&` o `}` en el formulario Vue, valores de enum con saltos de línea, y
defaults en cadena que no son un número decimal normal.

**Los defaults que cambian, y la alteración que escriben.** Cambian sólo los
`default` de modelo en cadena con ceros a la izquierda (`"01"`, `"007"`), con
exponente (`"1e3"`) o con espacios (`" 1"`): la 9.0 los escribía sin comillas y
ahora van entre comillas. `"08"` no compilaba, así que no hay migración de 9.0
que lo tenga. `MigrationTool::alter()` compara las definiciones como texto: si
la migración de creación no se editó a mano, el siguiente `larapack:import`
escribe una `_alter_<tabla>_table` con `->change()` para esas columnas —y es lo
correcto: `->default(01)` era el entero 1—; si se editó, la conserva y pide
escribir la alteración. Ojo: `->change()` reescribe la definición entera de la
columna, así que una longitud o precisión cambiada a mano en otra migración
(una de invariantes, por ejemplo) se pierde; revisa la alteración antes de
migrar. En school-management, de 1328 defaults de modelo en sus 82
`laraimport.json` cambian dos, ambos de `packages/school-payroll`:
`PayrollFiscalProfile.contract_type` (`01` → `'01'`) y `regime_type`
(`02` → `'02'`), cuyas columnas lleva a `VARCHAR(2)` la migración
`2026_10_11_053000_payroll_imss_and_stamping_invariants.php`: la alteración
generada las devolvería a `VARCHAR(255)`. Los pivotes no tienen alteración: sólo
cambia la migración de un pivote nuevo (en school-management ningún pivote
declara `default`).

La guardia guarda su estado en otro directorio:
el de una sesión abierta antes de actualizar se pierde, y el hook de fin de
turno sigue con git.

### Para quien mantiene el generador

- **`ARCHITECTURE.md`**: el recorrido completo de `laraimport.json` a la
  guardia —esquema, valores por omisión, validación semántica, importación,
  herramientas, plantillas, formateo, manifiesto, `verify`, hooks y `fix`—,
  cómo se registra un comando, los principios de diseño, el mapa de directorios
  y qué tocar para cada tipo de cambio (un tipo de columna, una clave, una
  plantilla, una acción, un check, una opción). Hasta ahora eso sólo se sabía
  leyendo los docblocks de `src/Support/`, y quien no fuera su autor no tenía
  por dónde empezar. `Tool::generate` es el camino principal para escribir un
  archivo, no el único: una tabla dice qué copias van por su cuenta (el
  andamiaje de `larapack:new`, los tests y `phpunit.xml`, los traits de huecos,
  las migraciones pivote, `alter` y `drop`) y qué no reciben de `generate()`
  —bloques, manifiesto, `preserved`, el informe—.
- **`CONTRIBUTING.md`**: preparar el entorno, la suite, las fotos
  (`LARAPACK_UPDATE_SNAPSHOTS`), las pruebas de punta a punta
  (`LARAPACK_SKIP_UI_BUILD`, `LARAPACK_PINT`), Pint y Larastan, la convención
  de commits, qué cuenta como cambio que rompe y cómo se publica. La cabecera
  `(sin publicar)` y el «`VERSION` sólo en el commit que publica» son una
  convención nueva, adoptada en esta versión.
- **`ArchitectureDocTest`** comprueba que cada enlace relativo, cada ruta del
  repositorio y cada `Clase::miembro` que citan los dos documentos sigan
  existiendo. Un mapa que ya no corresponde con el código es peor que no tener
  mapa, y sin el test envejecería en silencio. Comprueba también cada `#ancla`
  con el slug de GitHub, que cada test nombrado exista bajo `tests/`, el nombre
  de un método con sus mayúsculas y que un enlace con una clase apunte a su
  archivo; lo que va en un bloque de código, también sangrado, no se comprueba.
- **`release.yml`** sólo publica si `Tests` terminó en verde (o se lanza a
  mano). Se dispara con `workflow_run` de tipo `completed`, que también llega
  cuando `Tests` falla, y la puerta sólo podía estar en el workflow reutilizable
  de `innoboxrr/.github`, que no se ve desde aquí.

No cambia nada de lo que se genera ni de lo que comprueban los comandos.

## 9.0.0

Los agentes respetaban `return $request->handle();` en los controladores que
genera LaraPack y lo perdían en cuanto tenían que escribir uno que el generador
no sabía hacer: un escritorio, un asistente, el canje de un token, un webhook.
`verify` no lo veía nunca, porque sólo miraba el manifiesto. En un sistema real
de 65 paquetes salieron cuatro controladores con validación, autorización y
consultas dentro, y diecinueve traits de autorización en `Http/Controllers`.

Esta versión ataca la deriva por tres lados: **lo que no es CRUD se declara y se
genera**, **`verify` revisa todos los controladores** y **la regla se ejecuta
como hook** en cada edición del agente. Además trae una auditoría de seguridad
de lo generado.

Es mayor por tres cambios que rompen: `verify` falla donde antes pasaba, el
Resource generado filtra `appends[]` y los comandos validan el nombre del
modelo. Los pasos para un proyecto existente están al final y en «De 8.0 a 9.0»
de la guía de actualización del README.

### CAMBIO QUE ROMPE: `verify` revisa la forma de todos los controladores

Recorre cada clase de cualquier `Http/Controllers` de la raíz y de los paquetes
locales (repositorios `path` del `composer.json` y `packages/*`), los haya
generado LaraPack o no, y aunque no haya manifiesto. Lee los archivos con el
tokenizador: nunca carga ni ejecuta código del proyecto.

| Comprobación | Nivel | Qué detecta |
| --- | --- | --- |
| `controller-shape` | error | Una acción que hace algo más que `return $request->handle();`: recibe `Illuminate\Http\Request` o `FormRequest` base, valida con `validate()`, pasa una expresión a `handle()`. También un `__call`, un constructor que hace algo más que asignar lo inyectado y registrar middleware, y un `middleware()` estático con un closure. |
| `request-handle` | error | La request que recibe la acción no define `handle()`, ni en sí ni en un trait o un padre. Una de `vendor/` sólo se informa. |
| `controller-concern` | error / aviso | Un trait con lógica en `Http/Controllers`. Error si lo usa un controlador; aviso si sólo lo usan requests: su sitio es `Http/Requests/Concerns`. |
| `hand-rolled` | aviso | Una acción marcada `#[HandRolled('razón')]`. Sin razón literal es error y no excusa nada. |
| `route-closure` | aviso | Un closure con cuerpo como acción de una ruta en `routes/`. No cuentan los de `group()` ni el `return view('...')` con el que nace `routes/web.php`. |

**`controller-shape` es el único dueño de «el controlador hace algo más que
`return $request->handle();`»**, en los generados y en los escritos a mano. No
hay una comprobación aparte para los generados: el mismo problema saldría dos
veces y con dos reglas.

**`#[HandRolled]` no es una salida.** La excepción legítima —un webhook que
comprueba la firma sobre el cuerpo en bruto, un canje OAuth que responde con los
errores de OAuth— se declara en la clase o en la acción:

```php
use Innoboxrr\LarapackGenerator\Attributes\HandRolled;

#[HandRolled('La firma se calcula sobre el cuerpo en bruto.')]
class StripeWebhookController { ... }
```

`verify` la lista como **aviso** en cada ejecución, y con `--strict` falla. La
aprueba el dueño del proyecto, no quien escribe el controlador: los mensajes de
`controller-shape` ya no la ofrecen como arreglo, y un agente no debe añadirla
para que `verify` pase. La razón sale sin caracteres de control.

**`--check=<nombre>`**, repetible, corre sólo esas comprobaciones.
`--format=json` sigue siendo un único documento, y un nombre desconocido falla.

### CAMBIO QUE ROMPE: el Resource sólo añade los `appends` que declara

El Resource generado hacía `setAppends($request->get('appends'))` sin filtrar:
el cliente decidía qué accessor se ejecutaba, en cada fila del listado —una URL
firmada, un dato descifrado, una consulta cara—, y un nombre que no existía
daba un 500. Ahora tiene `protected array $appendable = []`: sólo se añade lo
que esté en esa lista, **sumado** a los `$appends` que ya declara el modelo, que
`setAppends()` habría reemplazado.

**Rompe** a quien pedía appends desde el cliente: decláralos en `$appendable`.
Ni la interfaz generada ni las aplicaciones conocidas los piden.

### CAMBIO QUE ROMPE: el nombre del modelo se valida

`larapack:remove-full-model ..` (o `.`, o vacío) borraba recursivamente las
carpetas de requests, filtros, eventos y notificaciones de **todos** los
modelos, y `larapack:model ../../X` escribía fuera de la raíz. Los comandos
rechazan ahora un nombre que no sea un identificador (`^[A-Za-z][A-Za-z0-9_]*`,
sin salto de línea al final), también con `--format=json`. `Post` y `blog_post`
siguen valiendo; un nombre con `-`, espacios o rutas, que ya generaba basura,
falla. Borrar ya no sigue enlaces simbólicos, tampoco si la carpeta del modelo
es uno.

### Seguridad

- **El IndexRequest generado fuerza `managed`.** `ManagedFilter::canView()`
  sólo acotaba si el cliente mandaba `managed`: quien lo omitía listaba todas
  las filas. `prepareForValidation()` lo fija, mande lo que mande la petición.
  `canView()` acota a todos, administrador incluido —el `before()` de la
  política no lo salta—, así que tiene que cubrir el caso administrador él
  mismo.
- **La exportación no convierte en fórmula lo que escribió un usuario.** Un
  título `=HYPERLINK("https://…?d="&B2,"Ver")` llegaba al xlsx del
  administrador como fórmula. La clase Export hereda el binder de Laravel Excel
  y guarda como texto lo que empieza por `=`.
- **Los valores libres del laraimport se escapan** al pegarlos en PHP y JS:
  `default`, `cast` y `constraint` en las migraciones y el modelo, etiquetas y
  valores de `enum` en las vistas. `"default": "it's"` ya no deja una migración
  que no compila, y un valor con `'); …` ya no mete código. Lo que ya era
  correcto sale idéntico. El esquema exige que `load_relations[].namespace` sea
  un namespace PHP.
- **Proyectos generados antes.** Las plantillas sólo arreglan lo que se genera
  desde ahora. Dos comprobaciones nuevas, como **aviso** (con `--strict`
  fallan), encuentran lo viejo y lo que un agente copie de ello:
  `index-managed`, una request que usa el `Builder` de SearchSurge sin forzar
  `managed` (`mergeIfMissing` no cuenta), y `resource-appends`, un
  `setAppends()` alimentado por la petición sin pasar por una lista blanca.
  Como `controller-shape`, no dependen del manifiesto.
- **`larapack:fix index-managed resource-appends`** las arregla de forma
  determinista, con el mismo cambio en cada archivo y sacado de la plantilla:
  `$this->merge(['managed' => true])` al principio de `prepareForValidation()`
  (lo crea si falta, llamando al heredado si lo hay) y el bloque de
  `$appendable` en el Resource, con la propiedad si falta. Trabaja sobre
  tokens; lo que no reconoce con certeza lo deja y lo lista como `skipped` con
  la razón (y sale con código distinto de cero). Nada cuenta como arreglado
  hasta que parsea y `verify` ya no lo marca, así que es idempotente.
  `--dry-run` enseña el diff unificado, `--format=json` da `fixed`, `skipped` y
  `unchanged` por comprobación y `--path` acota a un subdirectorio. Pasa el Pint
  del proyecto si lo tiene. En una copia de school-management arregló los 524
  IndexRequests y los 691 Resources sin saltarse ninguno.
- **`larapack:fix --package=<x>`** (repetible) acota a un paquete local por el
  nombre de su directorio en `packages/` o su nombre de Composer, o a `app`, el
  núcleo (lo que no cuelga de ningún paquete). Se combina con `--path`; un
  nombre que no existe falla con la lista de los que hay.
- **`larapack:fix` sólo toca lo que viene del generador**: reescribe
  `prepareForValidation()` y el bloque `setAppends()` cuando tienen la forma
  generada; si no, `skipped`. Una prueba fija que un IndexRequest con lógica
  propia alrededor no pierde nada.
- **`index-managed` reporta `admin_path_missing`**, sin arreglar nada: los
  `ManagedFilter` de los IndexRequest arreglados o ya correctos cuyo
  `canView()` no tiene camino para el alcance sin restricción. Con `managed`
  forzado, al administrador le saldría el listado vacío. Cuenta como camino un
  `return $query;` en cualquier rama (también el cuerpo de la plantilla) o un
  nombre de `ManagedFilterAudit::UNRESTRICTED_MARKERS` (`unrestricted`,
  `isUnrestricted`, `viewAny`, `isAdmin`), también en los soportes del proyecto
  que `canView()` llama estáticamente, hasta tres saltos. El filtro se localiza
  como SearchSurge: `filtersNamespace` literal de las opciones del Builder,
  `<espacio del modelo>\Filters\<Modelo>\ManagedFilter` o
  `App\Models\Filters\...`; lo que no se encuentra sale en
  `managed_filter_unresolved`. Es una heurística de lectura, no una prueba: se
  revisa a mano y no cambia el código de salida. En school-management, los 682
  filtros de sus IndexRequests tienen camino (los 626 que delegan en
  `AccessScope`/`PolicyScope`, por `$reach->unrestricted` e `isAdmin()`).
- **`resource-appends` reporta `appendable_proposals`**: por Resource, los
  accessors que el front pide con `appends` (`appends[]=x`, `appends=x,y`,
  `appends: [...]`, `params.appends = [...]`, `appends.push(...)`) en
  `resources/` y en `resources/{react,js,vue}` de cada paquete, asociados por el
  modelo o la ruta que el archivo menciona cerca, y con `exists` según el
  modelo y sus traits declaren el accessor. Lo que no se asocia sale «sin
  asociar» con su archivo:línea. Son propuestas sin confirmar: `$appendable`
  sigue vacío, porque una lista mal hecha deja de mostrar un dato en pantalla.

### Endpoints y controladores declarados (SPEC 02)

Para un endpoint que no era una de las doce acciones no había nada que declarar,
así que se escribía a mano y sin plantilla. Ahora:

- **`endpoints`, en un modelo**: `name`, `method`, `uri`, `target`
  (`record` o `collection`), `ability`, `public` y `middleware`.
- **`controllers`, en la raíz**, para lo que no tiene modelo: un escritorio, un
  asistente, un webhook. Su `ability` es una habilidad de Gate; sin ella,
  `authorize()` nace en `false`.

Cada endpoint genera el método del controlador —sólo
`return $request->handle();`—, la ruta con nombre (`importRoster` →
`import.roster`, `importCSV` → `import.csv`), `<Name>Request` que pregunta a la
política y delega en `<name>Model($request)` del trait `Operations`, la
habilidad en la política naciendo en `false` y un test. Un endpoint `public`
sale de `auth:sanctum` sólo para ese método y autoriza con
`$this->user('sanctum')`, que ve el token Bearer; un invitado no pasa por
`before()`, un administrador con sesión sí. Sin las dos claves se genera
exactamente lo mismo que antes.

`validate` rechaza un nombre CRUD o reservado, una `uri` repetida o que tapa a
otra, un controlador que se llama como un modelo —también uno ya generado por
otro laraimport— y una habilidad CRUD que el modelo no tiene; avisa de un
endpoint público sin `throttle:`. En `verify`, **`endpoint-missing`** (error)
dice qué pieza le falta a un endpoint declarado y qué escribir, y
**`route-not-declared`** ve además métodos, rutas y requests que nadie declaró,
y distingue lo escrito a mano de lo que se retiró del laraimport.
`larapack:full-model` suelto conserva los endpoints que guarda el manifiesto.

**Con `--vue` o `--react`, también su interfaz.** Cada endpoint tiene su función
en el contrato JS del modelo (`publishModel(data, { confirm })`, que llama a la
ruta por nombre con el verbo declarado; `data.id` es el registro). Los que no son
`get` salen como acción y preguntan antes, como borrar: los de `collection` en la
barra de la tabla (`crudActions()`), los de `record` en el menú de cada fila (el
Resource los añade a `actions`) y en el de la ficha (`recordActions()`, que lee
`ModelCard`). Las habilita la política por `policies`, que ahora responde con la
habilidad que pregunta cada endpoint (`archive` → `update`). Un `controllers[]`
sólo recibe su contrato, `src/controllers/<nombre>/index.js` con
`<name>Endpoint()`, sin pantalla. Un endpoint con parámetros en la URI además del
registro no sale en la interfaz —`import` lo avisa—, y ninguno trae formulario:
el esquema no declara campos de endpoint. `verify` añade a `endpoint-missing` la
función que falta en un contrato y a `route-prefix` el prefijo del contrato de
un controlador. Sin endpoints, la interfaz sale igual que antes.

Las reglas declaradas quedan pendientes: `rules()` es un hueco. El detalle está
en `docs/specs/SPEC-02-acciones-declaradas.md`.

### La guía como hook: `larapack:guard`

Una regla escrita se olvida a mitad de una sesión larga; un hook que devuelve el
error en cada edición, no.

- **`larapack:guard`** lee el JSON del hook de Claude Code. Si el archivo
  editado es un controlador, una request, una ruta, una política, una migración
  o algo generado que no es un hueco, le pasa las comprobaciones de `verify` y,
  si hay **errores en ese archivo**, sale con 2: Claude Code bloquea y le
  devuelve al modelo el motivo y la regla. A un controlador escrito a mano le
  recuerda que DEBE seguir el patrón. Lo de `vendor/` y `node_modules/` se
  ignora.
- **`larapack:guard --stop`**, al terminar el turno, verifica el proyecto y
  retiene sólo por los errores de lo que tocó la sesión (lo que pasó por el hook
  de edición y lo que git ve sin confirmar); lo demás lo dice sin bloquear, para
  que una consola no quede retenida por lo que otra dejó a medias. Respeta
  `stop_hook_active`.
- **`larapack:skill --hooks`** fusiona en `.claude/settings.json` los hooks
  PostToolUse (`Write|Edit|MultiEdit`) y Stop, sin duplicar ni tocar los demás.
  Mientras no exista `vendor/`, el hook no hace nada en vez de fallar.
- **`larapack:skill` escribe un bloque en `AGENTS.md`**, entre
  `<!-- LARAPACK:BEGIN -->` y `<!-- LARAPACK:END -->`, para Codex y otros
  agentes. Lo de fuera de los marcadores no se toca y `--no-agents` lo omite.
  Con `--path=AGENTS.md` no se añade: el skill entero ya es ese archivo.
- **`larapack:new`** crea el paquete con el bloque y los hooks puestos.
- **La skill** explica cuándo declarar un endpoint, el patrón de los que se
  escriben a mano, la guardia y la lista «Antes de terminar una tarea».

### Dependencias

- **Pisos de los paquetes `innoboxrr-*` del módulo generado**, en
  `ecosystem.json` y en los `package.json`, a las versiones que migraron a
  Vite 8 y Vitest 5: `innoboxrr-form-core ^2.9.0`, `innoboxrr-form-elements
  ^6.9.0`, `innoboxrr-react-form-elements ^3.9.0`, `innoboxrr-vue-datatable
  ^3.2.0`, `innoboxrr-react-datatable ^3.2.0`, `innoboxrr-http-request ^2.1.0`,
  `innoboxrr-i18n ^1.3.0`, `innoboxrr-locale-generator ^2.1.0`,
  `innoboxrr-js-validator ^2.1.0` e `innoboxrr-route-resolver ^2.1.0`.
- **El módulo Vue acepta `vue-router ^4.5.0 || ^5.0.0` y `pinia ^3.0.0 ||
  ^4.0.0`.** La 5 instala desde `innoboxrr-vue-datatable` 3.2.0, que amplió su
  peer; con la 3.1.1 npm fallaba con ERESOLVE.
- **`php.matrix` documenta 8.3, 8.4 y 8.5**, como la CI.
- **El Resource lee `appends` con `input()`**, no con `Request::get()`, que
  Laravel 13 marca obsoleto. `StubsAvoidDeprecatedApisTest` impide que una
  plantilla vuelva a usarlo.
- **CAMBIO QUE ROMPE (para los paquetes npm del ecosistema): la línea base JS
  pasa a Vite 8 y Vitest 5, y `node.engines` a `>=22.12`.** Vitest 5 exige Node
  `^22.12 || >=24`, y Node 20 es EOL desde 2026-04-30. Los diez paquetes
  `innoboxrr-*` ya migraron (versiones de arriba) y su CI prueba Node 22 y 24
  con `node-versions: '["22", "24"]'`. Un paquete npm propio que siga en
  `vitest ^3` o `engines >=20` sale ahora en `larapack:audit`: sube sus
  `devDependencies`, su `engines` y el `node-versions` de su `tests.yml`.
- **`ecosystem.json` pide `innoboxrr/larapack-generator ^9.0`.**

### Otros cambios

- **`verify` lee el manifiesto una sola vez.** En un proyecto de 128 modelos
  pasó de más de dos minutos a menos de un segundo; sin eso ningún hook era
  viable.
- **La lectura sin cargar aguanta** un archivo a medio escribir —el que un
  agente deja entre dos ediciones— y los ganchos de propiedad de PHP 8.4.
- **Una sola definición de qué código se recorre.** `controller-shape` y las
  comprobaciones de seguridad buscaban la raíz, los repositorios `path`,
  `packages/*` y sus PSR-4 con dos copias del mismo código; ahora las dos usan
  `ProjectSources`, y un paquete que vea una lo ve la otra. Livewire, Filament
  y los controladores fuera de `Http/Controllers` siguen fuera a propósito: no
  reciben un FormRequest ni delegan en su `handle()`.

### QUÉ HACER AL ACTUALIZAR

Lo generado sólo cambia en el IndexRequest, el Resource y la exportación. Lo que
cambia es lo que `verify` deja pasar. En una aplicación como school-management:

1. **Actualiza y corre `verify` antes de tocar nada:**
   `composer require innoboxrr/larapack-generator:"^9.0" --dev`,
   `php artisan larapack:skill --force` (sin `--hooks` todavía) y
   `php artisan larapack:verify --format=json`.
2. **Va a fallar en los controladores escritos a mano.** En school-management,
   `controller-shape` en `InstalledModulesController`, `TokenController`
   (OneRoster), `RegistrationController` (LTI) e `InboundWebhookController`, y
   `controller-concern` en los traits de `Http/Controllers/Concerns`
   (`AuthorizesInScope` y compañía).
3. **Arréglalos moviendo la lógica, no marcándolos.** O se declaran como
   `endpoints` de su modelo o en `controllers[]` y se generan, o el controlador
   escrito a mano recibe un `FormRequest` propio cuyo `authorize()`, `rules()` y
   `handle()` hacen el trabajo; los errores de protocolo, con
   `failedValidation()` y `failedAuthorization()`. Los traits pasan a
   `Http/Requests/Concerns`. `#[HandRolled('razón')]` sólo si el dueño del
   proyecto lo aprueba.
4. **Regenera lo que no editaste** con `larapack:import --force` para recibir el
   IndexRequest, el Resource y la exportación nuevos. En los editados,
   `index-managed` y `resource-appends` dicen cuáles faltan, y
   `larapack:fix index-managed resource-appends` los arregla; lo que salte, a
   mano: fuerza `managed` en `prepareForValidation()` y filtra `appends` con
   `$appendable`.
5. **Activa la guardia cuando `verify` esté limpio**, con
   `php artisan larapack:skill --hooks`. Antes, cada edición de uno de esos
   archivos y cada fin de turno que los toque retendrían al agente por errores
   que no causó.

El paso a paso, con ejemplos, está en «De 8.0 a 9.0» de la guía de
actualización del README.

## 8.0.0

Dos cambios en las tablas que genera: un paquete puede declarar el prefijo de
las suyas, y **las claves ajenas dejan de salir en cascada**.

### CAMBIO QUE ROMPE: `restrict` por omision en las claves ajenas

Hasta aqui el generador emitia esto en TODA clave ajena, sin forma ninguna de
declarar otra cosa:

```php
->constrained('categories')->onUpdate('cascade')->onDelete('cascade')
```

**Eso no es lo que hace Laravel.** `->constrained()` a secas deja la accion a la
base, que en MySQL es `RESTRICT`. El generador la pisaba, y con tres
consecuencias:

1. **Borrar una fila padre se llevaba en silencio la historia que colgaba de
   ella.** En un control escolar real, borrar un plan de estudios se habria
   llevado el kardex de los egresados.
2. **Impedia expresar integridad.** MySQL prohibe un `CHECK` (error 3823) y una
   columna generada `STORED` (error 1215) sobre una columna con accion
   referencial. Con cascada, media integridad del esquema es inexpresable.
3. **En una clave ajena nullable casi siempre esta mal.** Una columna
   `decided_by_user_id` es nullable porque puede que no se sepa quien; con
   cascada, borrar el usuario borra el registro de auditoria.

En el proyecto donde se detecto costo **siete migraciones** escritas en parte
para deshacerlo, y **259 claves ajenas reconstruidas a mano**.

**Ahora:** `restrict` por omision en los modelos, `cascade` por omision en los
**pivotes** —la unica excepcion, y ahi si es correcta: una fila pivote no
significa nada sin sus dos lados—. Y dos claves nuevas por propiedad:

```json
{ "name": "enrollment_id", "type": "foreignId", "constraint": "enrollments",
  "on_delete": "cascade" }
```

Los valores son los cuatro de SQL: `cascade`, `restrict`, `set null` y
`no action`. **La accion se escribe siempre en la migracion, aunque sea la que
haria la base**, para que quien la lea no tenga que saberse el valor por omision
de MySQL de memoria.

**Un `set null` sobre una columna que no admite nulos ya no llega a MySQL.** Lo
rechazaba con un 1215 pelado, sin decir cual de las claves era; ahora se dice al
validar, con el nombre de la columna delante.

**QUE HACER AL ACTUALIZAR.** Las tablas que ya existen **no se tocan**: el
generador reutiliza la migracion de creacion que ya esta escrita y, cuando
detecta que una clave ajena cambio, deja un comentario diciendo que se escriba
la alteracion a mano en vez de alterar un esquema desplegado. El cambio afecta a
las tablas **nuevas**. Si tu proyecto dependia de la cascada, declarala:
`"on_delete": "cascade"`.

### `table_prefix`: varios paquetes en una sola base de datos

**Un laraimport sin la clave nueva genera exactamente lo mismo que antes.**

- **`table_prefix` en la raiz del laraimport.** El nombre de la tabla salia solo
  del nombre del modelo: `Student` daba `students`. En un ecosistema donde varios
  paquetes comparten base, `students`, `groups`, `subjects`, `programs` o `terms`
  son los primeros nombres que se le ocurren a cualquiera, y el segundo paquete
  que los quiera choca con el primero. La unica salida era prefijar el nombre de
  la CLASE, que resuelve la colision a costa de tartamudear en cada `use`, cada
  relacion y cada prueba.

  ```json
  { "table_prefix": "academics_", "models": [ { "name": "Student", ... } ] }
  ```

  La clase sigue siendo `Student`; la tabla es `academics_students`.

- **Que lleva prefijo.** `Schema::create` y el resto de la migracion · el
  `$table` del modelo y el de su `Meta` · el `->constrained()` de una clave ajena
  que apunte a una tabla **de este mismo archivo** · el nombre del archivo de
  migracion, que es lo que guarda la tabla `migrations` · la tabla de metas y las
  pivote declaradas aqui.

- **Que NO lo lleva.** Una clave ajena hacia fuera —`users`, `parties`, la tabla
  de otro paquete— se deja intacta: prefijarla crearia una restriccion contra una
  tabla que no existe, y el fallo saldria en `migrate`, lejos de su causa.
  Tampoco lo llevan el nombre de la clase, del controlador, de la ruta ni del
  modulo de interfaz, ni la variable de Blade y la clave de array de la
  exportacion a Excel, que usaban el mismo marcador sin nombrar una tabla.

- **El `constraint` se sigue escribiendo sin prefijo**, igual que el nombre del
  modelo al que apunta. Lo resuelve el generador, asi que un archivo puede
  mezclar claves hacia dentro y hacia fuera sin pensar en ello.

- **La barra baja final es opcional.** `"academics"` y `"academics_"` dan lo
  mismo: escribir el primero y acabar con `academicsstudents` seria una trampa
  silenciosa.

- **La migracion de metas nombra su tabla CUANDO hay prefijo.** Tenia
  `->constrained()` sin argumento y Laravel deducia la tabla del nombre de la
  columna; esa deduccion no conoce el prefijo. Con prefijo se escribe explicita;
  sin el, la linea sale exactamente como salia. Escribirla explicita SIEMPRE
  producia el mismo esquema pero distinto texto, y `GeneratedOutputSnapshotTest`
  lo caza en sus dos casos importados: es justo la garantia que se queria.

- **`@larapack:if` admite la barra baja** en el nombre de la condicion. No la
  admitia, asi que `table_prefix` se leia como `table` y el stub moria con
  «condicion desconocida».

- **Aviso cuando una regla nombra una tabla sin prefijo.** Una regla
  `unique:students` o `exists:students` escrita a mano en `requests[]` es el
  unico sitio que el prefijo no puede resolver solo: es una cadena libre y
  reescribirla seria adivinar —puede apuntar a otra tabla del anfitrion llamada
  igual—. Ahora se avisa con el nombre correcto al lado. Sin el aviso, la
  validacion consultaria una tabla que no existe y el error saldria en
  produccion con un SQLSTATE.

- **`TablePrefixTest`** fija lo que puede romperse en silencio: que una clave
  ajena hacia fuera no se prefije, que sin la clave no cambie nada, y que el
  prefijo no sobreviva a la orden —es estado estatico, y colarse en la
  importacion siguiente seria invisible—.


## 7.10.3

Un agente que parsea `--format=json` ya puede decodificar la salida entera, y el
módulo generado deja de mandar el token CSRF en la query. Lo que se genera en PHP
no cambia.

- **`--format=json` es un único documento.** `larapack:import` escribía su
  progreso ("Processing model: ...") y los avisos del laraimport antes del
  informe, y el JSON no se podía decodificar. Ahora los avisos van dentro, en
  `findings`, y el progreso no se escribe: tampoco en stderr, que un agente suele
  leer junto con la salida.
- **Los errores también son JSON.** Con `--format=json`, un nombre inválido en
  `larapack:new`, una ruta que no existe en `larapack:audit`, `--only` junto a
  `--except` en `larapack:full-model`, un laraimport inválido, una `--root` que
  no existe o un argumento que falta responden `{"ok": false, "error": "..."}`
  con código de salida distinto de cero, en lugar de texto o de una salida
  vacía. El informe de los generadores lleva `"ok": true`, como ya llevaban
  `validate`, `verify` y `audit`.
- **Sin `_token` en las peticiones GET del módulo generado.** `getPolicies`,
  `getPolicy`, `indexModel` y `showModel` lo mandaban, e `innoboxrr-http-request`
  pone los datos de GET y HEAD en la query: el token acababa en la URL, en el
  historial y en los logs del servidor. Las escrituras lo siguen mandando. Es lo
  mismo que hicieron `innoboxrr-vue-datatable` e `innoboxrr-react-datatable`
  3.1.1 con las peticiones de la tabla.
- **`JsonOutputTest`** ejecuta con `--format=json` cada comando que lo acepta, al
  generar, al simular y al fallar, y decodifica la salida entera.
  `ForeignCreateMigrationTest` ya no busca dónde empieza el JSON.

### Para proyectos existentes

Lo que leía la salida de LaraPack recortando el texto antes del JSON ya no lo
necesita. El contrato del modelo se regenera con `larapack:import --vue --force`
(o `--react`); si lo editaste, quita `_token: csrfToken(),` de sus cuatro
llamadas GET. Ver «De 7.10.2 a 7.10.3» en la guía de actualización del README.

## 7.10.2

Lo que encontró el piloto de la aplicación base al exportar dentro de una
aplicación, y un aviso falso en cada importación. Lo que se genera en un paquete
no cambia.

- **La exportación renderiza en una aplicación.** Pedía su vista como
  `app::excel.<modelo>` con `config('app.excel_view')`: nadie registra las vistas
  `app::` y cada exportación fallaba con "No hint path defined for [app]". Ahora
  pide `excel.<modelo>`, la vista que genera en `resources/views/excel`.
- **La configuración de LaraPack no va dentro de la de Laravel.** En una
  aplicación, la exportación y su notificación leen `larapack.excel_view`,
  `larapack.notification_via` y `larapack.export_disk`, y `larapack:config`
  escribe `config/larapack.php`. Antes todo apuntaba a `config/app.php`. En un
  paquete la clave y el archivo siguen siendo los del paquete.
- **La migración de `users` de Laravel ya no se da por editada a mano.** Una
  migración de creación que LaraPack no generó se omite («no la generó
  LaraPack»), en lugar de conservarse con el consejo de escribir una alteración
  que quitaría `remember_token` o el índice único de `email`. Una que generó
  LaraPack y se editó sigue pidiendo la alteración.
- **`GeneratedApplicationTest` exporta de verdad**, sin simular la notificación,
  y comprueba que el archivo queda en el disco local.
- **La guía nombra bien el archivo de rutas:** `routes/api/models/<snake>.php`
  (`order_line.php`), no `<kebab>`.

### Para proyectos existentes

Una aplicación generada con 7.10.0 o 7.10.1 tiene que regenerar con `--force` los
archivos de exportación de `app/Exports` y `app/Notifications`, o cambiar a mano
el prefijo de la vista (`app::excel.` por `excel.`) y la clave de configuración
(`app.` por `larapack.`). Ver «De 7.10.1 a 7.10.2» en la guía de actualización
del README. Un paquete no tiene que hacer nada.

## 7.10.1

Lo que encontró el piloto de la aplicación base al ejecutar los tests que LaraPack
genera dentro de una aplicación: fallaban 23 de 25. Lo que se genera en un paquete
no cambia.

- **Factories en `Database\Factories`.** Dentro de una aplicación se generaban en
  `App\Database\Factories`, que el `composer.json` de Laravel no carga, y el
  modelo las importaba desde ahí: ningún modelo encontraba su factory.
- **Tests en `Tests\`.** Se generaban en `App\Tests\Feature\Models`. Y si faltaba
  `tests/TestCase.php` se creaba el de un paquete, que arranca Testbench; ahora es
  el de Laravel.
- **Rutas por nombre.** Los tests llamaban a `/api/<modelo>/...` y el
  `RouteServiceProvider` registra `api/app/<modelo>/...`: cada llamada daba 404.
  Ahora usan `route('api.app.<modelo>.*')`, como los de un paquete y el front.
- **Con sesión y autorización.** Las rutas piden `auth:sanctum` y los tests de una
  aplicación nunca iniciaban sesión. Ahora crean el usuario con la factory de
  `auth.providers.users.model`, abren la autorización en `setUp()` y prueban lo
  mismo que los de un paquete, acciones masivas incluidas.
- **La edición en lote aparta las columnas únicas.** Ponía el mismo `email` a dos
  usuarios, y `users.email` es único en la migración de Laravel.
- **`GeneratedApplicationTest`** ejecuta los tests generados en una aplicación
  Laravel simulada, como `GeneratedPackageTest` en un paquete. La guía pide ahora
  registrar también el `EventServiceProvider` en una aplicación: sin él la
  exportación no avisa.

### Para proyectos existentes

Una aplicación generada con 7.10.0 tiene que regenerar sus factories y sus tests,
o corregir el namespace a mano. Las factories y los modelos se regeneran con
`larapack:import --force`. Los tests no se pisan nunca, tampoco con `--force`: hay
que borrarlos antes de volver a importar. Ver «De 7.10.0 a 7.10.1» en la guía de
actualización del README. Un paquete no tiene que hacer nada.

## 7.10.0

El usuario que inicia sesión, y lo necesario para generar dentro de una
aplicación y no sólo en un paquete.

- **`authenticatable`.** Un modelo con `"authenticatable": true` hereda de
  `Illuminate\Foundation\Auth\User` con `Notifiable`, `HasApiTokens` e
  `isAdmin()` (lee `config('auth.admins')`). `password` y `remember_token` van a
  `$hidden` y fuera de la tabla y la exportación, y `email_verified_at` y
  `password` se castean a `datetime` y `hashed`. `larapack:validate` exige
  `email` y `password`.
- **Módulos dentro de una aplicación.** El módulo de la interfaz ya no escribe su
  `package.json` ni su `vite.config.js` cuando el proyecto es una aplicación:
  pisaban los de la aplicación.
- **El proveedor de eventos generado no lee la caché al arrancar.** Con
  `CACHE_STORE=database`, lo de Laravel 13, rompía `migrate` en una aplicación
  nueva, y la clave `events_and_listeners` era la misma en todos los paquetes.
- **La línea base pide `innoboxrr/larapack-generator ^7.10`.** Un paquete nuevo
  nace con ella, y `larapack:audit` avisa (sin fallar) a quien sigue en `^7.0`.

### Para proyectos existentes

Ver «De 7.9 a 7.10» en la guía de actualización del README.

## 7.9.0

Lo que faltaba para trabajar como en una aplicación de escritorio, y la puerta de
calidad en la CI.

- **Acciones masivas.** Dos acciones nuevas, `bulkUpdate` y `bulkDelete`, con
  ruta, método, request y test: cada registro pasa por la política de la acción
  individual, todo va en una transacción y un id que no existe no deja nada a
  medias. `bulkUpdate` valida con las reglas de `UpdateRequest`, sólo los campos
  que llegan. `immutable` las quita, quitar `update` o `delete` quita su masiva,
  y pedirla con `only` sin la individual es un error de `larapack:validate`.
- **La tabla generada selecciona filas**: borrar las seleccionadas y, por cada
  columna con `enum`, ponerles un valor.
- **Edición en la celda** para las columnas de texto de una línea que el
  formulario edita: guarda sólo ese campo y enseña el error de la regla.
- **Exportar avisa** al pedirse, y **la paleta** ofrece exportar.
- **Los formularios escriben las metas editables**, y la edición las rellena
  desde `payload`.
- **Migraciones de alteración.** Reimportar con columnas distintas escribe
  `*_alter_<tabla>_table.php` con lo añadido, cambiado y quitado, y su `down()`.
  La migración de creación no se toca. Una llave foránea que cambia y una
  migración editada a mano se dejan para escribirlas a mano.
- **Pint y Larastan** en la CI de LaraPack y en cada paquete de `larapack:new`
  (`pint.json`, `phpstan.neon.dist` nivel 5 y el trabajo `quality`). Lo generado
  sale formateado y pasa el análisis.
- **Vite 8** en el módulo generado (`vite ^8`, `@vitejs/plugin-react ^6`), y
  `innoboxrr-vue-datatable`/`innoboxrr-react-datatable` 3.1,
  `innoboxrr-form-elements` 6.7 e `innoboxrr-react-form-elements` 3.7.
- **La comprobación del JSX generado corre en la CI**: instala esbuild si no lo
  encuentra, en lugar de saltarse.

### Para proyectos existentes

Ver «De 7.8 a 7.9» en la guía de actualización del README.

## 7.8.0

La interfaz generada nombra a cada registro por su columna. La ficha, las migas y
el título de la pestaña leían siempre `.name`: un modelo sin esa columna —un
producto con `title`, un pedido con `number`— se enseñaba con el nombre del
modelo en lugar del registro, como pasó en el piloto.

- **Clave nueva y opcional del contrato: `display`**, la columna que nombra al
  registro.
- **Sin declararla se elige sola**: `name`, luego `title`, luego la primera
  columna de texto que no sea secreta (antes las que salen en la tabla), y `id`
  si no hay ninguna.
- **`larapack:validate` rechaza** un `display` que no es una columna o que es
  `secret`, porque nunca saldría por la API.
- Un modelo con `name`, o generado sin laraimport, sale igual que antes.

### Para proyectos existentes

Ver «De 7.7 a 7.8» en la guía de actualización del README.

## 7.7.1

Lo que encontró un piloto: una aplicación Laravel 13 nueva, un paquete creado con
`larapack:new` y generado siguiendo el README al pie de la letra, recorrido en un
navegador contra la API real.

- **El administrador salía sin estilos.** El `package.json` del módulo generado no
  declaraba `src/theme.js` con efectos, y Vite descartaba `import
  'modulo/src/theme.js'` entero, con la hoja de estilos.
- **`larapack:import --dry-run` escribía todo** y `--force` no forzaba: importar
  no pasaba las opciones a cada modelo, y cada modelo las reiniciaba. Los traits,
  las pivotes, el test y `composer.json` tampoco respetaban la simulación.
- **Volver a importar duplicaba las migraciones de creación**, porque el nombre
  lleva la hora; `migrate` y los tests del paquete fallaban con "table already
  exists". Ahora se reutiliza la que ya existe, también las de metas y pivotes.
- **`larapack:verify` avisaba en falso** cuando unos modelos tienen metas y otros
  no.
- **Exportar funciona recién instalado.** El paquete generado pide
  `maatwebsite/excel`, guarda en el disco `local`, avisa por correo con un enlace
  firmado a la descarga, y un fallo enseña un mensaje claro. Antes el usuario veía
  `Class "Maatwebsite\Excel\Facades\Excel" not found`, el disco era S3 y el enlace
  apuntaba a una ruta que no existía.
- **La pantalla:** la ficha enseña la fecha legible, la tabla la etiqueta de un
  enum en lugar de su valor, y borrar desde la fila avisa.
- **El README** dice qué versiones de `vue-router` y `pinia` instalar, que hace
  falta `statefulApi()`, cómo comprobar que Sanctum quedó instalado y que los
  formularios no traen campos para las metas.

### Para proyectos existentes

Ver «De 7.7.0 a 7.7.1» en la guía de actualización del README.

## 7.7.0

`metas: true` genera un modelo que guarda y lee sus metas de verdad. Antes se
generaban la tabla y el modelo Meta, y nada más: no había relación `metas()`,
`updateModelMetas()` y `updatePayload()` salían comentados, `protected_metas` no
se leía en ningún sitio y `payload` era una columna que el cliente podía
escribir y que se exportaba.

- **La relación `metas()`** se genera en `Traits/Relations`. Sin ella
  `MetaOperations` no podía leer ni escribir nada.
- **Crear y actualizar guardan las metas.** `updateModelMetas()` aplana los grupos
  anidados del formulario con `RequestFormater` de `innoboxrr/support`
  (`seo.title` → `seo_title`), guarda las de `editable_metas` y rehace `payload`.
- **`payload` se construye a partir de las metas** con `buildPayload()`, cuya
  forma decide cada modelo, y se guarda sin disparar eventos.
- **`payload` lo escribe el sistema.** Con metas se añade solo si no se declara, y
  nunca se asigna desde la petición ni se exporta; la factory no lo inventa.
- **`protected_metas`** es una clave nueva del contrato: metas que sólo escribe
  el código. El formulario no las toca aunque lleguen.
- **`larapack:validate` avisa** de metas declaradas sin `metas: true`, de una
  meta editable y protegida a la vez, y de un `payload` que pide escribirse.
- **`larapack:full-model --metas`** deja el modelo conectado igual que el
  importador.
- **El modelo Meta pasa por el generador**: se anota en el manifiesto y respeta
  `--dry-run`, `--force` y lo editado a mano.
- **Quitar un modelo borra su modelo Meta.** Se buscaba otro nombre de archivo, y
  la migración de borrado no encontraba su plantilla.
- La migración de metas ya no lleva `softDeletes()`, que el trait no usaba, y su
  rollback conserva el índice único.
- El README y el skill explican las metas, `payload`, las metas protegidas y
  cuándo usar cada cosa.

La suite EndToEnd lo ejecuta en una aplicación Laravel: grupos anidados, una meta
protegida que llega en la petición, un valor vacío que borra y `payload`.

Necesita `innoboxrr/traits` 2.1, que hace cumplir las metas protegidas, e
`innoboxrr/support` 2.1, que aplana sin perder los grupos vacíos. El módulo
generado pide también `innoboxrr-form-core` ^2.8, `innoboxrr-form-elements` ^6.6
e `innoboxrr-react-form-elements` ^3.6, con el teléfono que sigue el tema.

### Para proyectos existentes

Los traits `Relations`, `Storage` y `Operations` no se reescriben al regenerar: en
un modelo con metas hay que añadir lo nuevo a mano. Está en la guía de
actualización del README, «De 7.6 a 7.7».

## 7.6.0

La interfaz generada habla un solo idioma: el de la aplicación. Antes las vistas
usaban claves en inglés que nadie traducía, las rutas y las acciones de cada fila
venían en español fijo, los datatables también, y la notificación de exportación
mezclaba los dos, así que la pantalla salía mezclada en cualquier idioma.

- **Todo texto visible es una clave en inglés**: rutas, migas, paleta de
  comandos, drawer, tabla, menú de acciones, confirmaciones y avisos. Los títulos
  de ruta se traducen cuando el router los lee, no al importar el módulo.
- **El módulo trae sus traducciones.** `src/locales/es.json` y `en.json` se
  generan con los textos de LaraPack ya traducidos al español, y el módulo los
  exporta como `translations`. Al volver a generar se suman las claves que falten
  y nunca se toca una traducción escrita.
- **El nombre del modelo y de sus campos** se escriben como claves legibles
  («Audit event», no «AuditEvent») y quedan pendientes en `es.json`; mientras lo
  estén se ve la clave.
- **Laravel también:** las acciones del Resource y la notificación de
  exportación usan `__()`, con `lang/es.json` generado y cargado por el proveedor.
- **Los eventos ya no cambian el idioma de la petición.** Valían `'en'` y
  llamaban a `App::setLocale`: cada alta, edición o borrado pasaba a inglés el
  resto de la petición.
- El script `locale` declara `innoboxrr-locale-generator`; antes llamaba con
  `npx` a un paquete que no existe con ese nombre.

Necesita `innoboxrr-i18n` 1.2, que suma traducciones de varios orígenes, trata
una traducción vacía como pendiente y elige el idioma por el nombre del archivo.

### Para proyectos existentes

Regenerar con `--force`, cargar las traducciones del módulo en la aplicación y
mover las claves que cambian. Todo está en la guía de actualización del README,
«De 7.5 a 7.6».

## 7.5.1

El módulo generado pide `innoboxrr-form-core` ^2.7,
`innoboxrr-form-elements` ^6.5 e `innoboxrr-react-form-elements` ^3.5. Con
ellas, los formularios que genera LaraPack se ven igual sin Tailwind en la
aplicación: las etiquetas, las ayudas, el grupo repetible, la zona de archivos,
las casillas del código y el avatar salen del tema, en claro y en oscuro. Antes
una parte de esos campos solo tenía forma si la aplicación compilaba Tailwind, y
en React la zona de archivos y el código no tenían estilo en ningún caso.

### Para proyectos existentes

No hay que regenerar: basta con subir esas tres dependencias en
`resources/<ui>/package.json`.

## 7.5.0

Los módulos Vue y React que genera LaraPack se usan como una aplicación de
escritorio, y lo hacen igual en los dos frameworks:

- **La tabla no se va.** El alta se abre en un drawer encima del índice y, al
  guardar, la tabla se recarga en su sitio con `refresh()`, sin perder página,
  orden ni filtros. Antes el alta era otra página y, al volver, la tabla se
  remontaba desde la primera página y sin filtros.
- **La edición también es un drawer**, sobre la ficha del registro. Las rutas
  conservan sus nombres: un enlace a `AdminCreate…` o `AdminEdit…` abre el
  drawer que toca. Los formularios ya no navegan al guardar: avisan, y decide la
  vista que abrió el drawer.
- **El detalle enseña la forma del registro mientras llega**, en vez de quedarse
  en blanco o enseñar el registro anterior.
- **Crear, guardar y borrar lo confirman con un aviso**, y un borrado que falla
  dice por qué.
- **Las acciones de un registro son un menú desplegable** de verdad, y el índice
  tiene paleta de comandos con Ctrl+K.
- **Borrar y exportar preguntan con la confirmación del tema** (`confirmAction`
  de `innoboxrr-form-core`), no con un SweetAlert cuyos colores estaban escritos
  en el contrato y no seguía el modo oscuro. Cancelar sigue rechazando con
  `RequestCancelledError`, así que nadie avisa de lo que no se hizo.
- La ficha y el detalle dejan de usar clases de Tailwind.

La aplicación anfitriona tiene que montar una vez `ToastRegionComponent` y
`ConfirmHostComponent`: es donde se pintan los avisos y la confirmación. Está en
la tabla «Lo que el paquete espera de la aplicación» del skill.

El módulo generado pide ahora `innoboxrr-form-core` ^2.6,
`innoboxrr-form-elements` ^6.4, `innoboxrr-react-form-elements` ^3.4 y los
datatables ^3.0, que van sobre TanStack Table con selección de filas, permisos
resueltos antes de abrir cada menú y errores que se ven.

### Para proyectos existentes

Regenerar con `--force` cambia las vistas, los widgets, `ActionMenu` y el
contrato `index.js`. Los formularios, las rutas y los stores salen igual. Los
datatables 3.0 no son compatibles con la 2: su README explica qué cambia.

## 7.4.0

`larapack:new` crea un paquete desde cero, listo para generar, probar y publicar:

```
php vendor/bin/builder larapack:new acme/catalogo
```

Hasta ahora LaraPack generaba dentro de un proyecto que alguien había montado
copiando otro paquete, y lo que ese montaje decidía era justo lo que cada paquete
tenía distinto: la restricción de PHP, las versiones internas, si había tests,
cómo se publicaba y cómo lo descubría Laravel.

El paquete que sale:

- **Pasa `larapack:audit` sin un solo hallazgo.** El `composer.json` sale de
  `ecosystem.json`, que gana una sección `generated` con lo que el código
  generado usa fuera de la línea base: Sanctum y Excel.
- **Tiene CI desde el primer push**: los `tests.yml` y `release.yml` del
  ecosistema, `phpunit.xml.dist`, el `TestCase` y un `PackageBootsTest` que
  comprueba que los proveedores arrancan y las migraciones corren.
- **Se publica desde `VERSION`**, que empieza en 0.1.0.
- **Laravel lo descubre solo**: los proveedores quedan en
  `extra.laravel.providers`.
- **Un agente encuentra la guía**: instala el skill y deja un `AGENTS.md` que
  apunta a ella.

No sobrescribe nada y no trabaja sobre un directorio con `composer.json`.
`--dry-run` construye el paquete en un directorio temporal y dice exactamente
qué crearía.

La suite EndToEnd parte ahora de `larapack:new`: el paquete que instala y recorre
es el que obtendría cualquiera empezando desde cero.

### Correcciones

- Volver a ejecutar `larapack:providers` declaraba cada proveedor otra vez en
  `composer.json`, y Laravel lo registraba dos veces.
- Con `--dry-run`, `larapack:providers` escribía igualmente en `composer.json` y
  el generador de tests copiaba `TestCase.php` y `phpunit.xml`. Ahora una
  simulación no escribe nada, y el informe menciona esos archivos.
- Si el proyecto tiene `phpunit.xml.dist`, ya no se crea además un
  `phpunit.xml` que lo tapaba.

## 7.3.0

Lo generado ya no sólo compila: arranca y funciona. Hasta ahora los tests de
LaraPack leían los archivos que produce; ninguno los ejecutaba. Ahora hay una
suite `EndToEnd` que hace lo que haría quien sigue la guía: escribe un
laraimport, valida, genera API, interfaz, proveedores y configuración, instala
el paquete en una aplicación Laravel y lo usa. Migra, llama a cada ruta con el
nombre que usa el front, autentica, autoriza, crea, lee, modifica, borra,
exporta, corre `larapack:verify` y corre los tests que genera el propio paquete.

La primera vez pasaron 4 de 13. Todo lo que sigue salió de ahí.

### Lo que no funcionaba

- **El paquete no creaba sus tablas.** El `AppServiceProvider` generado traía
  comentadas la carga de migraciones, vistas y configuración.
- **Cualquier petición autorizada daba 500.** La Policy tipaba el usuario como
  `App\Models\User` y llamaba a `isAdmin()`. Un paquete no conoce el usuario de
  quien lo instala: ahora tipa el contrato `Authenticatable` y consulta
  `isAdmin()` sólo si existe.
- **Las factories no insertaban.** Sólo cuatro tipos de columna tenían valor; un
  `decimal` o un `boolean` salían `null` y la clave foránea valía siempre `1`.
  Ahora cada tipo del esquema tiene uno, y un `foreignId` hacia un modelo del
  mismo archivo usa la factory de ese modelo.
- **El borrado permanente mentía.** `forceDeleteModel()` hacía `abort(403)`
  escondido en el modelo, mientras la API de políticas le decía al front que el
  administrador podía. Sigue naciendo apagado, pero en la Policy
  (`$exceptAbilities`), que es donde se ve y donde se enciende.
- **Los tests generados no podían pasar.** El `TestCase` no registraba el
  paquete, usaba un token de ejemplo y un `Models\User` que no existe; las URLs
  se escribían a mano y no coincidían con las rutas de un modelo de dos
  palabras; y el de inmutabilidad dependía del reloj. Ahora el `TestCase` lee
  los proveedores del `composer.json`, migra, autentica con Sanctum y abre la
  autorización, y las rutas se llaman por nombre. En un paquete se genera además
  `tests/User.php`.
- **El módulo Vue no compilaba fuera de su aplicación.** Sus rutas importaban
  `@router/middleware`, un alias que sólo existe en la aplicación que lo define.
  Ahora declaran `meta: { auth: true }`, lo mismo que las de React ya llevaban
  en `handle`, y es el router del anfitrión el que lo lee. **Si tu aplicación
  protegía las rutas del módulo leyendo `meta.middleware`, pasa a leer
  `meta.auth`.**

La suite también compila los módulos Vue y React generados con su propio
`vite.config`: lo que aporta el anfitrión queda externo, y todo lo demás tiene
que resolver. Con el stub de rutas anterior, esa prueba falla justo en
`@router/middleware`; se comprobó antes de corregirlo.

Y la aplicación de la prueba es la que describe la guía, incluido
`JsonResource::withoutWrapping()`: el índice tiene que llegar con `data`, `meta`
y `links` en la raíz, que es lo que lee el datatable.

### Si ya tienes un paquete generado

Nada se sobrescribe sin `--force`, y lo editado a mano se conserva siempre. Para
recibir los arreglos de un archivo que no tocaste, regenera con `--force`.
Revisa a mano, si los editaste, `AppServiceProvider`, las Policies, las
factories, `tests/TestCase.php` y los tests de endpoints.

## 7.2.0

`larapack:audit` detecta el `composer.json` que fija su propia `version`, y lo
trata como error.

Composer compara ese campo con cada tag del repositorio y descarta los que no
coinciden. Con el modelo de publicación desde `VERSION`, el tag sale bien pero el
`composer.json` sigue diciendo la versión vieja, así que la publicación existe en
GitHub y no existe para Composer. Pasó de verdad: `seguropro/core` se etiquetó
2.0.0 diciendo 1.0.5, y un `seguropro/core: ^2.0` no resolvía. Veintitrés
paquetes del ecosistema tenían el campo.

Un paquete que hoy pase el audit con el campo presente dejará de pasarlo al
actualizar; el arreglo es borrar la línea.

## 7.1.0

Tres claves nuevas en el laraimport para las tablas que no se administran desde
un formulario. Son retrocompatibles: **un archivo que no las declara genera
exactamente lo mismo que con la 7.0**, y hay un test que lo comprueba archivo
por archivo contra una foto tomada antes del cambio.

### El problema

LaraPack genera diez acciones por modelo, que es la forma correcta para algo
que una persona administra desde una pantalla. Pero una bitácora sólo se
agrega, un catálogo sólo se lee, una concesión se otorga y se revoca sin
editarse, y una credencial se lista sin que su secreto salga nunca. Medido en un
sistema real, 46 de 69 tablas no tenían forma en el generador: se declaraban
normales y se borraba a mano lo que sobraba, lo que dejaba esos archivos
marcados como editados para siempre.

### Qué se declara

- **`routes`** (`only` o `except`): qué acciones genera el modelo. Quitar una
  quita todo lo que cuelga de ella — ruta, request, método del controlador,
  evento y listeners, habilidad de la política, test de endpoint, exportación, y
  en la interfaz su formulario, su vista, su entrada en la tabla y su función
  del contrato. Sin `delete`, `restore` ni `forceDelete`, el modelo deja de usar
  `SoftDeletes` y la migración deja de crear `deleted_at`.
- **`immutable`**: la fila no cambia una vez creada. Quita las cuatro
  escrituras y el modelo registra una guarda en `booted()` que lanza ante
  cualquier `save()` o `delete()`: quitar la ruta impide entrar por HTTP, pero no
  que el propio paquete modifique la fila. **Crear sigue permitido**, porque una
  fila inmutable nace; si tampoco debe crearse por la API, se quita con `routes`.
- **`secret`**, por propiedad: la columna va a `$hidden` y no sale en la
  exportación ni en la tabla.

`larapack:full-model` acepta `--only`, `--except` e `--immutable`, y
`larapack:validate` acepta `--vue` y `--react` para mostrar lo que sólo importa
si se genera la interfaz.

### `larapack:verify`

- **`route-not-declared`**, **`immutable-write`** y **`secret-exposed`**, las
  tres con error. Leen la forma que el manifiesto guarda al generar.
- **`inconsistent-entity`** compara cada modelo sólo con los que declararon su
  misma forma. Antes, un modelo de sólo lectura habría salido marcado una vez por
  cada componente que no debe tener.

### Cambios respecto a la propuesta

- `immutable` no quita `create`, por lo dicho arriba.
- `restore` o `forceDelete` sin `delete` es aviso y no error: una fila que borra
  un proceso en segundo plano y se restaura desde la API es legítima.
- `secret` no se excluye del Resource sino que va a `$hidden`: el Resource
  generado hace `parent::toArray()` y no enumera campos, y `$hidden` protege
  además cualquier otra serialización.
- `immutable-write` no busca llamadas a `update()` en todo el paquete. Resolver
  a qué clase apunta `$x->update()` sin un resolutor de tipos o se deja casos o
  marca cada llamada del paquete; la guarda del modelo lo garantiza mejor,
  porque la llamada falla.
- Las vistas necesitan `index` y `policies`, no sólo `index` o `show`: cuelgan
  de la ruta del índice, y la tabla consulta las políticas para decidir qué
  acciones ofrecer.

## 7.0.0

Salto de mayor: el módulo generado deja de depender de frameworks que nadie
declaraba. Ya no hace falta UIkit, ni Tailwind, ni Font Awesome para que se
vea bien, y por eso mismo un módulo generado con la 6.x **no** se actualiza a
la 7.0 cambiando el número: su aspecto venía de otro sitio.

### El aspecto ya no es implícito

El módulo pedía prestadas 305 clases de UIkit, 551 de Tailwind y los iconos de
Font Awesome sin declarar ninguno de los tres. Se veía bien sólo dentro de una
aplicación que ya los trajera cargados; fuera de ella, o en un proyecto nuevo,
salía sin estilo y sin iconos, y nada lo avisaba.

Ahora todo sale de `innoboxrr-form-core`, que trae su propia hoja de estilos, y
`resources/<ui>/src/theme.js` es el único sitio del módulo donde se declara el
aspecto:

```js
import 'innoboxrr-form-core/styles'
```

Colores, formas y densidad son variables CSS —`--fe-primary`, `--fe-radius`,
`--fe-density`— y el modo oscuro responde a la preferencia del sistema y a un
`data-theme="dark"` en la raíz. `setTheme` queda para lo que siempre debió
ser: apuntar un token a las clases de otro sistema visual, si la aplicación ya
tiene el suyo.

Los comportamientos que ponía el JS de UIkit ya no necesitan UIkit: los
tooltips son CSS, el menú de acciones usa la Popover API con posicionamiento de
Floating UI, y el panel de filtros se abre con estado del componente —que además
era la causa de que se comportara distinto en Vue y en React.

### Los iconos se piden por nombre, no por clase

`'fa-eye'` era a la vez el icono y la librería que lo dibuja. Ahora se pide
`show`, y el mapa decide de qué colección sale:

```js
setIcons({ plus: 'lucide:plus', delete: 'lucide:trash-2' })
```

Son más de 200.000 iconos de más de 150 colecciones bajo un solo esquema, así
que darle personalidad propia a una aplicación es cambiar el mapa, no tocar los
componentes. Un nombre de colección entero también vale donde haga falta uno
suelto: `<IconComponent name="mdi:home" />`.

Esto alcanza al PHP: un `Resource` emite `'icon' => 'show'`, no `'fa-eye'`.
**Si tienes Resources escritos a mano con clases de Font Awesome, hay que
traducirlos.**

### Componentes que el módulo daba por supuestos

`BreadcrumbsComponent` y `DropdownButtonComponent` se usaban en las vistas
generadas y no estaban definidos en ningún paquete: eran globales que cada
aplicación registraba por su cuenta, o no. Ahora el módulo genera su
`Breadcrumbs` y su `ActionMenu`, en Vue y en React, y hay un test que falla si
vuelve a aparecer un componente fantasma, una clase `uk-*` o una `fa-*` en algo
generado.

### La línea base del ecosistema

`ecosystem.json` declara en un solo archivo las versiones que rigen a todos los
paquetes propios: PHP, Laravel, testbench, las dependencias internas de Composer
y las de npm que el módulo generado pide. Estaba escrito en veintitantos sitios
y ninguno coincidía.

`larapack:audit` la exige en vez de sólo enunciarla:

```
larapack:audit                    # el paquete actual
larapack:audit packages --all     # todos los de un directorio
larapack:audit --format=json --strict
```

Sale con código distinto de cero cuando encuentra un incumplimiento, así que
sirve igual en CI que a mano.

### La publicación pasa por los tests

`bump-patch.yml` etiquetaba y publicaba en cada push a master sin correr un solo
test, y ordenaba los tags por fecha en vez de por semver. Se sustituye por el
par `tests.yml` + `release.yml`, que llaman a los workflows reutilizables de
`innoboxrr/.github`: la versión la declara el archivo `VERSION`, los tests son
la puerta, y el tag se deriva. Un push con un test roto ya no publica nada.

### Requisitos

- La matriz de CI cubre **PHP 8.3, 8.4 y 8.5**: 8.3 resuelve
  `symfony/console` 7.4 y 8.4+ resuelve 8.x, que tienen firmas distintas en
  `Command::configure()`.
- El módulo generado pide `innoboxrr-form-core ^2.3.0`,
  `innoboxrr-form-elements ^6.3.0` / `innoboxrr-react-form-elements ^3.3.0` y
  `innoboxrr-vue-datatable ^2.3.0` / `innoboxrr-react-datatable ^2.3.0`.

#major
## 6.0.0

Salto de mayor: el paquete pasa a Laravel 13 y PHP 8.3+, los comandos cambian
de nombre y el módulo de interfaz cambia de sitio. Nada de esto es compatible
con la 5.x.

### Requisitos

- **PHP `^8.3`** (antes `^7.2`).
- **`illuminate/support ^13.0`**, `symfony/console ^7.4 || ^8.0`.
- **`innoboxrr/search-surge ^3.0`** e **`innoboxrr/traits ^1.7`**. Las versiones
  anteriores no declaraban ningún `require`, así que Composer no tenía con qué
  detectar un conflicto.

### Comandos

Todos viven ahora en el espacio `larapack:`:

```
make:full-model   ->  larapack:full-model
json:importer     ->  larapack:import
remove:full-model ->  larapack:remove-full-model
```

En Artisan no se registran con los nombres antiguos porque `make:model`,
`make:policy`, `make:factory` y `make:observer` son de Laravel. El binario
`builder` los mantiene como alias.

Comandos nuevos:

| | |
|---|---|
| `larapack:validate` | Valida un `laraimport.json` sin generar nada. |
| `larapack:schema` | Emite el JSON Schema del contrato. |
| `larapack:verify` | Busca desviaciones respecto al manifiesto. |
| `larapack:skill` | Instala las instrucciones para un agente. |
| `larapack:react-view` | Genera el módulo React de un modelo. |

Todos aceptan `--format=json` y `--root=<ruta>`; los generadores, además,
`--force` y `--dry-run`.

### El contrato

`laraimport.json` es ahora un **JSON Schema versionado**
(`schema/laraimport.schema.json`). Sólo `models[].name`, `props[].name` y
`props[].type` son obligatorios; el resto tiene valor por defecto. El
importador valida —forma y coherencia— **antes de tocar el disco**: un archivo
incompleto ya no deja el proyecto a medio generar.

Se resuelven solos el orden de migración (topológico por claves foráneas) y el
namespace de las relaciones.

`assignments` y `filters` quedan marcadas obsoletas.

### Interfaz

La UI vive dentro del paquete, en `resources/<vue|react>/`, y se publica como
su propio npm. `--vue` y `--react` no son excluyentes.

**`models/<entidad>/index.js` es el mismo archivo en los dos frameworks.**

Las clases CSS salen de un tema (`innoboxrr-form-core`), no de un mixin global:
un formulario generado no lleva ninguna clase.

### Correcciones

- `RequestsTool` reescribía el cuerpo de `rules()` con un `preg_replace` cuyo
  `[^}]+` no equilibra llaves: una regla con cuantificador se llevaba por
  delante `messages()`, `attributes()` y `handle()`.
- El trait de relaciones emitía siempre `use App\Models\X`, una clase que en
  modo paquete no existe.
- Las migraciones salían en el orden del array, así que un `constrained()`
  hacia una tabla creada después hacía fallar `migrate`.
- `larapack:remove-full-model` añadía `ModelView` a la lista y luego iteraba
  otra, así que `--vue` no borraba nunca el módulo.
- Los formularios generados leían `validator.status`, que empieza en `false`:
  el primer envío siempre se descartaba y había que pulsar dos veces.
- Los `sleep` de las migraciones desaparecen; el orden lo garantiza un contador
  monotónico.

### Regenerar

`.larapack/manifest.json` registra cada archivo generado con su hash. Sin
`--force` no se toca nada existente; con `--force` se regenera **salvo** lo que
se haya editado a mano.

#major
