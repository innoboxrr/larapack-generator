# Changelog

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
