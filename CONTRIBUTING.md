# Contribuir a LaraPack

Cómo preparar el entorno, probar, escribir un commit y publicar una versión del
generador. Cómo está hecho por dentro y dónde tocar para cada tipo de cambio
está en [ARCHITECTURE](ARCHITECTURE.md); cómo se usa, en el [README](README.md).

La regla que ordena todo lo demás: **LaraPack es la autoridad arquitectónica de
otros proyectos.** Un fallo aquí no rompe un proyecto, rompe todos los que
regeneran. Por eso casi todo lo de abajo existe para que un cambio no altere en
silencio lo que ya está generado.

## Índice

1. [Preparar el entorno](#preparar-el-entorno)
2. [Correr la suite](#correr-la-suite)
3. [Las fotos de lo generado](#las-fotos-de-lo-generado)
4. [Las pruebas de punta a punta](#las-pruebas-de-punta-a-punta)
5. [Formato y análisis estático](#formato-y-análisis-estático)
6. [Cómo se escribe aquí](#cómo-se-escribe-aquí)
7. [Commits](#commits)
8. [Versionar](#versionar)
9. [Publicar](#publicar)
10. [El ecosistema](#el-ecosistema)

---

## Preparar el entorno

| Qué | Versión | Para qué |
|---|---|---|
| PHP | ^8.3 (la CI prueba 8.3, 8.4 y 8.5) | Todo. Con `mbstring`, `intl`, `fileinfo`, `gd`, `zip` y `pdo_sqlite`. |
| Composer | 2 | Las dependencias. |
| Node y npm | >=22.12 | Opcional: compilar los módulos Vue y React generados y comprobar que su JS parsea. |
| Git | — | En Windows, `.gitattributes` mantiene el repositorio en LF. |

```
git clone https://github.com/innoboxrr/larapack-generator
cd larapack-generator
composer update
```

**`composer update`, no `install`.** El repositorio no versiona
`composer.lock`: es una biblioteca, y lo que importa es que funcione con lo que
resuelve cada versión de PHP. En 8.3 eso es `symfony/console` 7.4 y en 8.4 la
8.x, con firmas distintas; la matriz de la CI existe precisamente para cubrir
esa diferencia, y un lock la taparía.

`gd`, `zip` y `fileinfo` las exige `phpoffice/phpspreadsheet`, que llega con
`maatwebsite/excel` en `require-dev` (la suite instala de verdad la exportación
que se genera). `pdo_sqlite` la usan las pruebas de punta a punta para migrar.

## Correr la suite

```
vendor/bin/phpunit --no-progress                    # todo: Unit, Feature y EndToEnd
vendor/bin/phpunit --testsuite Unit                 # sólo una suite
vendor/bin/phpunit --filter VerifyControllerShapeTest
```

La suite entera tarda un par de minutos y tiene que acabar en verde antes de
cualquier commit que toque `src/`. Las tres suites son de
[`phpunit.xml.dist`](phpunit.xml.dist):

- **`tests/Unit/`** — piezas aisladas: el contrato del laraimport, los bloques
  de plantilla, los tokens, `PhpOutline`, que los documentos no mientan.
- **`tests/Feature/`** — los comandos de verdad sobre un proyecto falso en un
  directorio temporal
  ([`tests/Support/FakeProject.php`](tests/Support/FakeProject.php)), ejecutados
  como los ejecuta el binario
  ([`tests/Support/Larapack.php`](tests/Support/Larapack.php)).
- **`tests/EndToEnd/`** — lo generado se instala y se ejecuta.

Algunos tests usan Node si está (la sintaxis del JS y del JSX generados). Sin
él se **omiten**, no se aprueban: en el informe salen como *skipped*.

## Las fotos de lo generado

[`GeneratedOutputSnapshotTest`](tests/Feature/GeneratedOutputSnapshotTest.php)
genera cinco escenarios —un paquete con Vue y React, uno con relaciones, uno con
endpoints, una aplicación y un modelo suelto— y compara el hash de **cada
archivo** con la foto guardada en `tests/Fixtures/snapshots/`.

Es la prueba que hace posible cambiar plantillas sin miedo. Si falla, algo que
un proyecto existente regenera ha cambiado. Hay dos casos:

- **No querías cambiar lo generado** (añadiste una clave opcional, refactorizaste
  una herramienta): el fallo es el defecto. Arréglalo sin tocar las fotos.
- **Querías cambiarlo**: rehaz la foto y lee el diff.

  ```
  LARAPACK_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter GeneratedOutputSnapshotTest
  git diff tests/Fixtures/snapshots/
  ```

  Cada línea del diff es un archivo que alguien verá distinto al regenerar.
  Tiene que estar en el `CHANGELOG.md`, y si el proyecto debe hacer algo, en la
  guía de actualización del README.

Una foto rehecha sin leer su diff es una prueba desactivada.

## Las pruebas de punta a punta

Los demás tests leen lo generado; éstos lo ejecutan:

- [`GeneratedPackageTest`](tests/EndToEnd/GeneratedPackageTest.php) sigue el
  README al pie de la letra: `larapack:new`, un laraimport, `validate`,
  `import --vue --react`, y el paquete instalado en una aplicación Testbench que
  migra, autentica, autoriza y llama a cada ruta por el nombre que usa el front.
- [`GeneratedApplicationTest`](tests/EndToEnd/GeneratedApplicationTest.php),
  lo mismo dentro de una aplicación.
- [`SchemaChangeTest`](tests/EndToEnd/SchemaChangeTest.php) cambia el laraimport
  de una tabla ya migrada y comprueba la migración de alteración.
- [`GeneratedUiBuildsTest`](tests/EndToEnd/GeneratedUiBuildsTest.php) compila los
  módulos Vue y React generados con su propio `vite.config`.

**Nada de lo generado se retoca para que pasen.** Si hay que tocar un archivo
generado para que una de éstas funcione, el defecto está en el generador.

Variables de entorno:

| Variable | Efecto |
|---|---|
| `LARAPACK_SKIP_UI_BUILD=1` | No compila los módulos generados ni instala esbuild. Útil sin red o en un portátil con prisa; la CI no la pone. |
| `LARAPACK_UPDATE_SNAPSHOTS=1` | Rehace las fotos en lugar de compararlas. |
| `LARAPACK_PINT=<ruta>` | El Pint con el que se formatea lo generado cuando el proyecto destino no tiene `vendor/`. Las pruebas de punta a punta lo apuntan al de este repositorio. |

La primera compilación instala Vite y sus plugins en un directorio del temporal
del sistema (`larapack-ui-toolchain-*`, y `larapack-esbuild` para el JSX) y lo
reutiliza en las siguientes: hace falta red una vez.

### La del ecosistema publicado

Las de arriba usan el `vendor/` de este repositorio y una aplicación a medida.
Ninguna instala LaraPack como lo instala un cliente, junto a lo publicado, y
por eso el choque entre laravel-setup y la 9.0 lo descubrió un cliente:
`innoboxrr/laravel-setup` pedía `^7.10 || ^8.0` y Composer no dejaba instalar
la 9.

[`.github/scripts/ecosystem-e2e.sh`](.github/scripts/ecosystem-e2e.sh) hace lo
que haría quien empieza una aplicación, con la última laravel-setup publicada
y LaraPack **desde este checkout**:

1. `composer create-project laravel/laravel` (Laravel 13).
2. LaraPack como repositorio `path` hecho con `git archive` —como la dist de
   Packagist: lo que no está comiteado no entra— y con la versión de `VERSION`;
   laravel-setup fijada a su última versión estable, para que Composer no baje
   en silencio a una más vieja que sí lo admita.
3. `app:setup` (con `--react` según la interfaz) y `app:install --without-build`.
4. [`ecosystem-e2e.laraimport.json`](.github/scripts/ecosystem-e2e.laraimport.json)
   añadido al laraimport de laravel-setup: dos modelos con relación, un endpoint
   de registro, uno público y un `controllers[]`. `validate --strict` e `import`.
5. `migrate` en SQLite, `route:json`, `npm install` y `npm run build`.
6. `php artisan test`, contando por archivo que cada test generado se ejecutó
   (incluidos los de autorización de la 9.1) y que ninguno se salta.
7. `verify --strict --format=json`, `larapack:ci --dry-run` y la guardia de los
   hooks con el `artisan` de la aplicación. Estas tres se corren todas aunque
   falle una.

Si un paso falla, el script dice qué paquete y por qué: el choque que cita
Composer, los paquetes de `vendor/innoboxrr/` que aparecen en la traza, el
paquete npm que no resuelve, o, para cada hallazgo de `verify`, si el archivo
lo puso laravel-setup (está entre sus stubs) o LaraPack.

Corre en [`.github/workflows/ecosystem.yml`](.github/workflows/ecosystem.yml)
cada noche, a mano desde Actions y en un *pull request* que toque el workflow,
el script, su laraimport, `ecosystem.json` o `composer.json`; con Vue y con
React, PHP 8.4 y Node 22. Si falla, los registros de cada paso quedan como
artefacto del run.

En local, desde la raíz, con el cambio comiteado:

```
bash .github/scripts/ecosystem-e2e.sh vue      # o react
```

Tarda unos minutos y necesita red. Deja la aplicación en un directorio
temporal que se borra si todo pasa; `ECOSYSTEM_WORK=<dir>` lo fija y
`ECOSYSTEM_KEEP=1` lo conserva siempre. Otras variables, en la cabecera del
script: `ECOSYSTEM_LARAPACK_REF` (el commit que se instala),
`ECOSYSTEM_LARAPACK_VERSION` y `ECOSYSTEM_LARAVEL_SETUP` (para probar contra
otra versión).

Sin acceso a las dist de GitHub (un contenedor detrás de un proxy), Composer
puede bajar todo por git: se le da un `COMPOSER_HOME` propio cuyo
`config.json` diga `{"config": {"preferred-install": "source"}}`. El script no
cambia: lo que cambia cómo descarga Composer va en el entorno, no en él.

## Formato y análisis estático

```
vendor/bin/pint --test                                         # el formato (preset laravel)
vendor/bin/pint                                                # y lo arregla
vendor/bin/phpstan analyse --no-progress --memory-limit=1G     # Larastan, nivel 5 sobre src/
```

Los dos corren en el trabajo `quality` de la CI, igual que en cada paquete que
crea `larapack:new`. `tests/Fixtures` queda fuera de Pint a propósito: son
archivos con la forma exacta que se quiere probar, también la mala.

## Cómo se escribe aquí

- **Los identificadores, en inglés; los comentarios y la documentación, en
  español.** Los nombres de los tests, en español y describiendo el
  comportamiento (`test_un_endif_sin_if_es_un_error`): se leen en el informe
  como una especificación.
- **Los comentarios explican el porqué**, y casi siempre el fallo que lo
  motivó: «antes pasaba X, y por eso…». El código ya dice el qué. Los
  docblocks de las clases de `src/Support/` son la mejor documentación del
  diseño; mantenlos al día.
- **Sólo comprobaciones deterministas.** Un check de `verify` que dé un falso
  positivo acaba desactivado. Si algo no se puede decidir con certeza, se
  informa como propuesta, sin tocar el código de salida. Y el código de otros se
  lee con tokens ([`PhpOutline`](src/Support/PhpOutline.php)), nunca con una
  expresión regular ni cargándolo.
- **Lo que no usa una clave nueva genera lo mismo que antes, byte a byte.** Las
  fotos lo comprueban.
- **Una decisión, un sitio.** Si dos archivos dicen lo mismo, o se derivan de
  uno, o un test los compara.
- **Un test por cada fallo arreglado**, con el caso que lo reproducía. Y para un
  check nuevo, también los casos que **no** debe marcar.
- Las plantillas son `.txt` en `src/Stubs/`; lo que no está en
  [ARCHITECTURE](ARCHITECTURE.md#las-plantillas) sobre ellas lo dicen
  `StubBlocksTest` y `StubsAvoidDeprecatedApisTest` cuando fallan.

## Commits

Mira `git log`: el estilo es una frase en español que dice qué cambia, sin
prefijos de tipo (`feat:`, `fix:`), a veces con el área delante.

```
larapack:fix acota por paquete y reporta canView sin administrador
Skill: declarar el endpoint antes que escribir el controlador
CI Windows: activar las extensiones que phpspreadsheet exige
Un controllers[] no puede llamarse como un modelo ya generado
```

- **Un commit, un cambio conceptual.** El código, su test y su documentación
  van juntos; un refactor que no cambia el comportamiento va aparte.
- **El cuerpo explica el porqué**, igual que un comentario: qué pasaba, por qué
  importa y qué se decidió. Lo que el diff ya dice no hace falta repetirlo.
- **`VERSION` sólo se toca en el commit que publica.** Mientras tanto, lo que se
  acumula va en `CHANGELOG.md` bajo `## <versión> (sin publicar)`. Es la
  convención desde la 9.1.0: hasta la 9.0.0, `VERSION` y la entrada del
  `CHANGELOG.md` llegaban juntas en un mismo commit («Publicar la 7.10.3…»,
  «9.0.0: CHANGELOG, VERSION…»). Con varias ramas abiertas a la vez, cada una
  añade a la misma cabecera `(sin publicar)` en su subsección, sin abrir otra.
- Las ramas se integran con un merge; no se reescribe la historia de `master`.

## Versionar

Semver, leído desde quien usa el generador: **un cambio rompe si un proyecto
que actualiza dentro de la misma mayor tiene que hacer algo para seguir donde
estaba.** Aquí eso es:

### Qué cuenta como cambio que rompe

- **`verify` falla donde antes pasaba.** Un check nuevo de nivel `error`, o uno
  que pasa de aviso a error. Un aviso nuevo no rompe, salvo para quien corre
  `--strict`, y por eso se anota.
- **Lo generado cambia de forma que el proyecto tiene que tocar algo**: otra
  firma, otra ruta, un comportamiento distinto en lo regenerado (el Resource que
  deja de aceptar cualquier `appends`, las claves ajenas que dejan de ser
  `cascade`).
- **El laraimport rechaza algo que antes aceptaba**, o una clave cambia de
  significado o de valor por omisión.
- **Un comando o una opción desaparecen o cambian de sentido**, o el JSON de
  `--format=json` pierde o renombra una clave.
- **Sube la línea base** de `ecosystem.json` de forma que un paquete que
  pasaba `larapack:audit` deja de pasarlo (PHP, Laravel, Node, la cadena JS).

No rompe, y va en una menor: una clave nueva y opcional del laraimport, un
comando o una opción nuevos, un check nuevo de nivel `warning` o `info`, una
plantilla que sólo **añade** líneas a lo generado, y cualquier arreglo que deje
lo generado igual o más seguro sin pedir nada al proyecto. Un arreglo sin cambio
visible va en un parche.

Cada cambio que rompe se marca en el `CHANGELOG.md` con **CAMBIO QUE ROMPE**, y
una versión mayor lleva su sección «De X a Y» en la guía de actualización del
README, con los pasos en orden y su fila en la tabla «Dónde estás».

## Publicar

No se publica a mano: no se crean tags ni se sube nada a Packagist.

1. En una rama, sube [`VERSION`](VERSION) y cambia `## <versión> (sin publicar)`
   por `## <versión>` en [`CHANGELOG.md`](CHANGELOG.md). Si es mayor, la guía
   de actualización del README y el piso de
   `innoboxrr/larapack-generator` en `internal` de
   [`ecosystem.json`](ecosystem.json).
2. Integra en `master`.
3. El workflow `Tests`
   ([`.github/workflows/tests.yml`](.github/workflows/tests.yml)) corre la suite
   en la matriz de PHP, la guardia en Windows y `quality`.
4. Si pasa, `Release`
   ([`.github/workflows/release.yml`](.github/workflows/release.yml)) lee
   `VERSION` y crea ese tag si no existe. Packagist publica al recibir el tag.

`Release` se dispara cuando `Tests` termina, también si falla: la puerta es el
`if` de su job, que sólo deja pasar un `Tests` en verde o un lanzamiento a mano.
Está en este repositorio a propósito y no sólo en el workflow reutilizable de
`innoboxrr/.github` al que llama, que es quien lee `VERSION` y empuja el tag: lo
que ese reutilizable compruebe no se ve desde aquí y cambia sin pasar por este
repositorio. Así, un push con un test roto no publica nada, y un tag que ya
existe no se vuelve a crear: subir `VERSION` es la única forma de publicar, y la
única necesaria.
`release.yml` declara `permissions: contents: write` por sí mismo; sin esa
línea, en un repositorio con permisos de sólo lectura el workflow no arranca y
no deja log.

## El ecosistema

LaraPack no está solo, y un cambio aquí puede necesitar otro en otro
repositorio:

- **`innoboxrr/.github`** tiene los workflows reutilizables
  (`php-tests.yml`, `php-release.yml`) que usan este repositorio y cada paquete
  que crea `larapack:new`. Cambiar cómo se prueba o se publica todo el
  ecosistema es un cambio ahí, no aquí.
- **Los paquetes Composer internos** —`innoboxrr/traits`, `innoboxrr/support`,
  `innoboxrr/search-surge`— los usa el código generado. Sus versiones están en
  `internal` de [`ecosystem.json`](ecosystem.json).
- **Los paquetes npm `innoboxrr-*`** —`innoboxrr-form-core`,
  `innoboxrr-form-elements` y su gemelo React, las datatables de Vue y React,
  `innoboxrr-http-request`, `innoboxrr-i18n`, `innoboxrr-locale-generator`,
  `innoboxrr-js-validator`, `innoboxrr-route-resolver`— son de lo que vive el
  módulo de interfaz generado. Cada uno es su repositorio, con su CI, y
  `larapack:audit` también los audita. Una plantilla que use un componente
  nuevo necesita **antes** la versión publicada que lo trae, y su piso en
  `internalNpm`.
- **Las aplicaciones y paquetes que usan LaraPack** son la prueba real. Un
  cambio que rompe se prueba contra uno de verdad antes de publicarlo: la guía
  de actualización del README cita lo que salió al hacerlo con una aplicación
  de 65 paquetes.

Una versión del ecosistema —de PHP, de Laravel, de un paquete interno— se cambia
en `ecosystem.json` y en ningún otro sitio: las plantillas, `larapack:new` y
`larapack:audit` la leen de ahí, y un test falla si una plantilla dice otra
cosa.
