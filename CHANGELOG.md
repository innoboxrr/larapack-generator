# Changelog

## 9.1.0 (sin publicar)

### La barrera es la CI, y `#[HandRolled]` exige aprobación

En 9.0 la regla del controlador se cumplía mientras el agente tuviera los hooks
y la excepción `#[HandRolled]` la decidía quien escribía el atributo. Ninguna de
las dos cosas puede depender de que alguien se acuerde: ahora la rama protegida
exige `verify --strict`, y una excepción sólo cuenta si el dueño la aprobó en un
archivo que pasa por su revisión.

Nada rompe a un proyecto en 9.0: sin `.larapack/hand-rolled.lock`, `verify`
se comporta igual que antes, y el workflow y CODEOWNERS sólo se crean si se
pide.

**`.larapack/hand-rolled.lock` y `larapack:hand-rolled`.** El candado es una
lista JSON de `{target, reason}`, ordenada y estable byte a byte. `target` es
`Clase` o `Clase::metodo`, con el nombre completo:

```
php artisan larapack:hand-rolled list                          # estado de cada excepción
php artisan larapack:hand-rolled approve --all                 # aprueba las del código
php artisan larapack:hand-rolled approve TokenController::store
php artisan larapack:hand-rolled revoke 'App\Http\Controllers\InboundWebhookController'
```

Basta el final del nombre que lo distinga; si coincide con más de uno, no se
adivina. Aprobar es correr el comando, revisar el diff del candado y comitearlo.

| Comprobación | Nivel | Con candado |
| --- | --- | --- |
| `hand-rolled` | info | La excepción está aprobada con esa misma razón. `--strict` pasa. |
| `hand-rolled-unapproved` | error | No está en el candado, **o cambió la razón**: se aprobó otra cosa. También un candado ilegible, que deja todo sin aprobar. |
| `hand-rolled-stale` | aviso | El candado aprueba algo que ya no lleva `#[HandRolled]`. Se retira con `revoke`. |

Sin candado, `hand-rolled` sigue siendo aviso, como en 9.0.

**La guardia no deja que un agente apruebe.** `larapack:guard` bloquea con 2 la
edición del candado (`Write`, `Edit`, `MultiEdit`) y la orden de Bash que corre
`larapack:hand-rolled approve` o `revoke`, también por el alias `make:`, por una
abreviatura (`larapack:hand`) o dentro de `bash -c` y `eval`. La orden se lee
como la shell —separadores, comillas y heredocs—, sin ejecutarla: un `git
commit -m "approve"`, un `grep` o la documentación escrita con `cat <<'EOF'` no
bloquean; un heredoc que recibe una shell (`bash <<EOF`), sí. `larapack:skill --hooks` añade para eso un
hook **PreToolUse** (`Write|Edit|MultiEdit|Bash`, `larapack:guard --pre`): el
PostToolUse llega con el archivo ya escrito y su matcher no ve Bash. Como corre
en cada orden, la shell sólo despierta a PHP si la entrada contiene `approve`,
`revoke` o `hand-rolled`. Se fusiona como los demás y no se duplica. El
PostToolUse también bloquea y pide deshacerlo, para quien siga con los hooks de
9.0. No es infranqueable —un agente puede escribir el archivo por otra vía—: la
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
- Imprime lo que no puede hacer: proteger la rama exigiendo el check y *Require
  review from Code Owners*, con la orden `gh api --method PUT
  repos/{dueño}/{repo}/branches/{rama}/protection` para que la corra el dueño.
  Nunca la ejecuta.

**Los paquetes nuevos** (`larapack:new`) traen el mismo job «LaraPack verify»
en su `tests.yml`, y **`larapack:audit`** avisa con `verify-job` si la CI de un
paquete que usa LaraPack no corre `larapack:verify --strict`.

**`empty-manifest` ya no avisa a un paquete sin modelos que tiene registrados
sus proveedores** —una pasarela, un PAC: doce de los ochenta y un paquetes de
school-management—. Lo registrado se sigue comprobando. Con `verify --strict`
en la CI, ese aviso los habría dejado en rojo para siempre.

### Migrar: school-management

school-management llega con dos excepciones que el dueño ya aprobó en 9.0,
`InboundWebhookController` y `TokenController`. Una vez, **el dueño**:

```
composer require innoboxrr/larapack-generator:"^9.1" --dev
php artisan larapack:skill --force --hooks               # añade el hook PreToolUse
php artisan larapack:hand-rolled list                    # las dos, «sin candado»
php artisan larapack:hand-rolled approve --all           # crea .larapack/hand-rolled.lock
cat .larapack/hand-rolled.lock                            # revisa que sean esas dos y esas razones
php artisan larapack:verify --strict --format=json       # ok: true antes de exigirlo
php artisan larapack:ci --owner=@<dueño-o-equipo>
git add .larapack/hand-rolled.lock .github .claude/settings.json && git commit
```

Después, el workflow corre una vez y el dueño protege `master` con la orden que
imprime `larapack:ci`. Cada paquete de `packages/` que corre `verify --strict`
en su propia CI necesita su propio candado (`--root=packages/<paquete>`): el
candado es de la raíz sobre la que corre `verify`.

Si la CI de los paquetes corre `larapack:audit --strict`, el aviso `verify-job`
la pone en rojo hasta que cada paquete tenga el job: añádelo
(`larapack:ci --root=packages/<paquete>`) antes de subir a 9.1.

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
  petición, el registro aparece y luego desaparece: prueba que SearchSurge
  resuelve el `ManagedFilter` del modelo, que `IndexRequest` fuerza `managed` y
  que `canView()` se aplica. Qué deja ver `canView()` es un hueco y no se
  prueba; el comentario del test dice dónde hacerlo.

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

Ninguna sale `skipped` ni `incomplete`. Los tests de EndToEnd ejecutan las
nuevas en un paquete y en una aplicación generados, sin tocarlas, y pasan Pint
y Larastan.

**En un proyecto existente** no se pisa nada: `<Modelo>EndpointsTest` se crea
una sola vez y no se regenera nunca, y el test de un endpoint sólo se rehace con
`--force` si nadie lo editó. Para tener las pruebas nuevas en un modelo que ya
existía, borra su `EndpointsTest` (guarda antes lo que le añadiste) y vuelve a
importar. Usan `mockery/mockery`, que traen `laravel/laravel` y
`orchestra/testbench`.

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
