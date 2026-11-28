# Trabajar con varias consolas a la vez

Diez o veinte agentes —o personas— sobre el mismo proyecto, cada uno en su
rama o en su worktree, que luego se integran. Esta guía dice qué garantiza
LaraPack en ese modo de trabajo, qué hace cada consola antes de empujar, qué
hace quien integra y qué **no** se puede garantizar desde el paquete.

Sale de la auditoría de enjambre de 9.4.0: un proyecto (una aplicación y dos
paquetes locales), diecisiete ramas con los cambios típicos y fusiones reales
en cinco órdenes distintos. Está hecha prueba en
[`SwarmMergeTest`](../tests/EndToEnd/SwarmMergeTest.php); las ramas las
describe [`SwarmProject`](../tests/Support/SwarmProject.php).

## Índice

- [La idea](#la-idea)
- [Qué se midió](#qué-se-midió)
- [Cada consola, antes de empujar](#cada-consola-antes-de-empujar)
- [Quien integra](#quien-integra)
- [`larapack:reconcile`](#larapackreconcile)
- [Lo que `verify` comprueba de una fusión](#lo-que-verify-comprueba-de-una-fusión)
- [La CI](#la-ci)
- [Que lo de todos se lea igual](#que-lo-de-todos-se-lea-igual)
- [Lo que no se puede garantizar desde el paquete](#lo-que-no-se-puede-garantizar-desde-el-paquete)

## La idea

Lo generado sale del contrato. Dos ramas que cambian el contrato no tienen
que fusionar lo generado: tienen que fusionar **el contrato** y volver a
generar. Todo lo demás —el manifiesto, los controladores, las requests, las
rutas, las listas del modelo— es una función del `laraimport.json`
fusionado, y no hay nada en ello que una persona tenga que decidir.

Lo que sí es de personas es lo que escribieron personas: los huecos (la
política, el trait de operaciones), los controladores a mano, las
migraciones a mano. Eso lo fusiona git, y si choca lo resuelve quien lo
escribió.

`larapack:reconcile` hace la primera parte y deja nombrada la segunda;
`larapack:verify --strict` falla mientras quede cualquiera de las dos.

## Qué se midió

Dos ramas que tocan el mismo ámbito (la aplicación, o el mismo paquete)
chocan casi siempre, aunque sus cambios sean independientes:

| Las dos ramas | Qué choca en `git merge` |
| --- | --- |
| Añaden cada una un modelo | `laraimport.json` (las dos escriben al final de `models`) y `.larapack/manifest.json` |
| Añaden cada una una columna al mismo modelo | Además, el modelo y su factory |
| Añaden la misma columna | Lo mismo, y quedan **dos migraciones** que la añaden |
| Declaran el mismo endpoint | Además, su request, su test y el archivo de rutas |
| Generan con interfaz (`--vue`, `--react`) | Además, los archivos de idioma (`resources/…/locales/*.json`): uno por ámbito, fuera del manifiesto, y cada rama les añade sus claves al final |
| Tocan ámbitos distintos (la aplicación y un paquete, o dos paquetes) | Nada |
| Una añade un modelo y la otra una columna a otro | Nada |

Y resolver ese conflicto a mano dejaba, sin que nada avisara:

- **Un manifiesto con los marcadores dentro** se leía como vacío: `verify`
  sólo avisaba de que «no hay nada registrado», y el siguiente
  `import --force` lo reescribía desde cero. Se perdía el registro de todo lo
  generado, y con él la posibilidad de regenerarlo.
- **Un manifiesto de un lado y un contrato del otro**, o los dos del mismo
  lado: los modelos de la otra rama quedaban en disco como código que «nadie
  generó», con sus rutas respondiendo.
- **Un laraimport cambiado sin volver a importar** —un `string` que pasa a
  `text`, un `nullable`— dejaba `validate` y `verify --strict` en verde.
- **Los archivos de idioma con los marcadores dentro** —JSON roto— no los
  veía nadie: no están en el manifiesto.

## Cada consola, antes de empujar

1. **Una rama o un worktree por tarea**, partiendo de la rama de integración
   al día. Dos consolas no comparten directorio de trabajo: el generador
   escribe en disco y el manifiesto es uno.
2. **El flujo de siempre, entero**: editar `laraimport.json`, `validate`,
   `import --force`, rellenar los huecos, `verify --strict`, las pruebas. El
   paso que más se salta es el `import` después de retocar el contrato:
   desde 9.4.0 es un error (`contract-outdated`), y el hook de git no deja
   comitear un `laraimport.json` así.
3. **No resolver a mano un conflicto en `laraimport.json` ni en
   `.larapack/manifest.json`**, ni al traer la rama de integración a la
   propia: `larapack:reconcile`.
4. **No editar lo generado que no es un hueco.** Además de ser deriva, es lo
   único que convierte una fusión que se resuelve sola en una que necesita a
   una persona.
5. **Declarar en vez de escribir a mano**: un endpoint va en `endpoints` o en
   `controllers[]`. Lo declarado se fusiona por nombre; lo escrito a mano,
   línea a línea.
6. **Pint** antes de comitear (`vendor/bin/pint --dirty`), si el proyecto lo
   usa: lo generado ya sale formateado, lo escrito a mano no.
7. **El realineamiento (`larapack:realign`) de un ámbito lo lleva una sola
   rama.** Su estado (`.larapack/realign/plan.json`, `report.json`) lleva
   fechas y el identificador del diagnóstico: dos ramas que diagnostican
   chocan siempre.
8. **Las aprobaciones (`.larapack/hand-rolled.lock`) son del dueño**, como
   siempre: una consola no aprueba.

## Quien integra

```
git merge --no-ff --no-commit <rama>      # con conflictos o sin ellos
php artisan larapack:reconcile            # (php vendor/bin/builder … en un paquete)
php artisan larapack:verify --strict
# las pruebas del proyecto
git add -A && git commit
```

De una en una: una fusión de varias ramas a la vez (octopus) no se
reconcilia. Si `reconcile` sale con error, dice qué queda y de quién es; se
resuelve y se vuelve a correr —da lo mismo correrlo una vez que tres—.

Con `--dry-run` dice lo que haría sin escribir. Con `--check` no escribe y
sale con error si queda algo por reconciliar: es lo que corre la CI.

Si la fusión ya se comiteó sin reconciliar —alguien resolvió a mano—,
`larapack:reconcile` sobre ese commit lo arregla igual, mientras sea el
último: lee las dos ramas de los padres del commit.

## `larapack:reconcile`

Por cada ámbito (la raíz y cada paquete local), en este orden:

1. **El contrato, por nombres.** Si `laraimport.json` quedó en conflicto (o
   fusionado pero inválido: la misma columna dos veces), se fusiona de tres
   vías por lo que significa: los modelos, las columnas, los endpoints, los
   `controllers[]` y los pivotes por su `name`; las listas de valores
   (`except`, `middleware`) como conjuntos. Queda en el orden de nuestra
   rama, con lo que añadió la otra detrás, y en un formato fijo (cuatro
   espacios, salto final).
   **Lo que las dos ramas declararon distinto no se decide**: un endpoint
   `close` que una declara `post` y otra `put` sale como pendiente, con su
   ruta (`/models/Ticket/endpoints/close/method`) y los dos valores, y no se
   importa nada hasta que una persona lo deje como debe quedar.
2. **El manifiesto, de las dos ramas.** Se recompone por entradas: lo que
   registró una sola rama es de esa rama; lo que registraron las dos, la
   unión. De un archivo que las dos volvieron a generar se mira si en cada
   rama estaba tal como salió del generador:
   - en las dos: no lleva trabajo de nadie. Se deja la versión nuestra y el
     paso 5 lo regenera con el contrato fusionado, aunque el archivo tenga
     marcadores de conflicto;
   - en una sola, o en ninguna: alguien lo editó. Se conserva como editado y
     se lista en «revisa».

   Si el manifiesto que hay se puede leer (git lo fusionó solo, o alguien ya
   lo resolvió), se parte de él y sólo se le devuelve lo que perdió.
3. **Los archivos de idioma** (`lang/<idioma>.json`,
   `resources/…/locales/<idioma>.json`) en conflicto, clave a clave: las
   traducciones de las dos ramas. Una clave que las dos tradujeron distinto
   la escribió una persona, y queda pendiente.
4. **Las migraciones repetidas.** Dos migraciones que crean la misma tabla o
   añaden la misma columna: se retira la que **trae la rama que entra**, si
   la generó LaraPack y nadie la editó. El paso 5 escribe una alteración con
   lo que falte. La de nuestra rama no se toca —puede haber corrido—, y una
   escrita a mano tampoco: si la repetida es una de esas, queda pendiente.
   `--keep-migrations` no retira ninguna.
5. **`larapack:import --force`** con el contrato fusionado, sólo en los
   ámbitos donde algo cambió o lo generado no corresponde al contrato.

Lo que deja pendiente, siempre con el archivo y el motivo: un conflicto en
código escrito a mano, lo que las dos ramas declararon o tradujeron distinto, una
migración repetida que no puede retirar, el estado del realineamiento o el
candado en conflicto.

No comitea. Del índice de git sólo toca lo que estaba en conflicto y
resolvió, para marcarlo como resuelto.

## Lo que `verify` comprueba de una fusión

| Comprobación | Nivel | Qué detecta |
| --- | --- | --- |
| `contract-outdated` | error | Lo generado de un modelo corresponde a otro contrato: su declaración —o algo de lo que depende— cambió y no se volvió a importar; el laraimport declara un modelo sin generar; o el manifiesto registra uno que el laraimport ya no declara. |
| `manifest-unreadable` | error | `.larapack/manifest.json` no se puede leer (marcadores de conflicto, JSON roto). Ya no se trata como vacío, y el generador no lo reescribe. |
| `merge-conflict` | error | Un conflicto de git sin resolver en el laraimport, en un archivo de estado, en algo generado o en el código del proyecto. |
| `migration-duplicate` | error | Dos migraciones que crean la misma tabla o añaden la misma columna, si al menos una la generó LaraPack. |
| `route-duplicate` | error | Un archivo de rutas generado con el mismo verbo y la misma URI dos veces. |

`contract-outdated` funciona con una huella por modelo que la importación
anota en el manifiesto (`"contract"`). Un manifiesto anterior a 9.4.0 no la
trae y no se compara hasta el primer `import --force`.

El hook de git (`.githooks/pre-commit`, `pre-push`) las aplica a lo que se
comitea: un `laraimport.json` cambiado sin importar, o un manifiesto con
marcadores, no salen de la consola.

## La CI

El workflow de `larapack:ci` corre, en cada pull request y en cada push a la
rama protegida:

- `larapack:verify --strict`. En un pull request GitHub lo corre sobre **la
  fusión** con la rama destino, no sobre la rama sola: lo que mide es el
  resultado de integrar.
- `larapack:reconcile --check`: que entre las dos ramas de esa fusión no
  quede nada por reconciliar. Ve lo que `verify` no puede ver mirando sólo
  el árbol —lo que una rama generó y la resolución a mano dejó sin
  registrar—. Necesita el historial (`fetch-depth: 0`, que el workflow ya
  pone).
- `vendor/bin/pint --test`, si el proyecto requiere `laravel/pint`.

Los proyectos que ya tienen el workflow lo actualizan con `larapack:ci`.

## Que lo de todos se lea igual

Lo que hoy es uniforme por construcción, haya escrito quien haya escrito:

- **Lo generado.** En los cinco órdenes de fusión ensayados, cada archivo
  generado quedó idéntico, byte a byte, a lo que el contrato fusionado
  genera de cero. Lo fuerzan `contract-outdated` y `reconcile`.
- **El formato de lo generado**: Pint, con el `pint.json` del proyecto o el
  preset `laravel`.
- **La forma de un controlador**, generado o a mano: delega en su request
  (`controller-shape`, `request-handle`), y lo que no, necesita una
  excepción aprobada por el dueño.
- **Los archivos de estado**: el manifiesto se escribe siempre ordenado y con
  las claves en el mismo orden; el laraimport reconciliado, en un formato
  fijo.

Los grados de libertad que quedan, y que dos consolas pueden usar distinto:

- **Un controlador escrito a mano que delega bien no se exige declarar.**
  Pasa `verify --strict` sin un aviso, con el nombre, la carpeta de requests
  y las pruebas que cada consola elija. La forma uniforme es declararlo
  (`endpoints`, `controllers[]`): se genera con su request, su ruta y su
  test. Hoy es una regla de la guía, no una comprobación.
- **El estilo de lo escrito a mano** (huecos, clases propias) sólo lo fuerza
  Pint, y sólo donde corre: en la CI de `larapack:ci` si el proyecto lo
  requiere, y en el workflow de un paquete de `larapack:new`. El hook de git
  no lo corre.
- **El formato de `laraimport.json`** cuando lo edita una consola: la
  sangría y el orden de las claves son libres. No cambia lo generado, pero
  dos formatos distintos son un conflicto de texto más (que `reconcile`
  resuelve).
- **El orden de los modelos y de las columnas** en el contrato fusionado
  depende del orden en que se fusionan las ramas (lo nuestro, y detrás lo
  que entra). Es el orden de las columnas en las listas del modelo y en los
  formularios.
- **Dentro de un hueco**, todo: cómo se reparte la lógica entre el trait de
  operaciones y otras clases, cómo se nombran, si llevan pruebas.
- **Las migraciones escritas a mano**: su nombre y su forma.

## Lo que no se puede garantizar desde el paquete

- **Que dos consolas no decidan cosas incompatibles.** Si una declara
  `close` como `post` y otra como `put`, o las dos escriben reglas distintas
  en la misma política, LaraPack lo detecta y se niega a elegir. Elegir es
  de una persona.
- **Que la lógica de los huecos sea correcta o coherente entre ramas.** Dos
  cambios que git fusiona sin conflicto pueden contradecirse; eso lo dicen
  las pruebas del proyecto, no `verify`.
- **Que una migración retirada no haya corrido ya** en una base compartida.
  `reconcile` sólo retira las que trae la rama que entra, y lo dice; si esa
  rama ya se desplegó en un entorno, ahí hay que deshacerla a mano
  (`migrate:rollback`).
- **Fusiones sin commit de fusión.** Con *squash* o *rebase* no hay dos
  padres que comparar: `reconcile --check` no tiene qué mirar en la rama
  destino (parado en un conflicto de rebase usa las mismas tres versiones,
  pero sólo el `merge` está cubierto por pruebas). Lo que
  `verify` ve en el árbol se sigue viendo; lo que una resolución a mano dejó
  sin registrar, no. Para un enjambre, fusionar con commit de fusión.
- **Fusiones de más de dos ramas a la vez** (octopus): de una en una.
- **Proyectos sin huella del contrato** (manifiesto anterior a 9.4.0):
  `contract-outdated` no compara hasta el primer `import --force`.
- **Que nadie se salte los hooks en su máquina.** Un hook de git se quita;
  la puerta que no se salta es la CI sobre la rama protegida
  (`larapack:ci`).
- **El estado del realineamiento en paralelo.** Dos ramas que realinean el
  mismo ámbito chocan en su estado y `reconcile` no lo fusiona.
