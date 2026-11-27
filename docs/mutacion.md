# Las pruebas de mutación

Qué mide [`infection.json5`](../infection.json5), cuánto daba antes y después
de la 9.3.0, con qué margen se fija el suelo de cada pieza, qué dice la CI
cuando sale en rojo y por qué los mutantes que sobreviven sobreviven. Cómo se
corre, en [CONTRIBUTING](../CONTRIBUTING.md#las-pruebas-de-mutación).

## Lo que se mide

Las ocho piezas de las que depende lo que LaraPack promete, cada una con los
tests que la ejercitan (el `--filter` de `infection.json5`). El MSI es la parte
de los mutantes que algún test detecta (los muertos, los que agotan el tiempo y
los que dan error).

**Con `--with-uncovered`.** Infection 0.31, por defecto, sólo muta las líneas
que algún test recorre: las demás no producen mutantes y no cuentan. Así se
midió primero, y por eso el MSI y el «covered MSI» salían iguales: no porque
todo estuviera cubierto, sino porque lo no cubierto no existía para la
medición. Desde la 9.2.0 la CI muta también lo que nadie recorre y lo cuenta
como no detectado: el MSI cuenta todo y el «covered MSI» sólo lo recorrido.

| Pieza | 9.2.0: mutantes | 9.2.0: MSI | Mutantes | Sin recorrer | MSI | MSI recorrido | Suelo | Holgura |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `HandRolledLock` | 140 | 97,86 % | 134 | 0 | 100,00 % | 100,00 % | 99,0 % | 1 |
| `ControllerShape` | 676 | 95,27 % | 652 | 0 | 98,31 % | 98,31 % | 97,3 % | 6 |
| `CiGate` | 443 | 95,94 % | 429 | 1 | 98,14 % | 98,36 % | 97,1 % | 4 |
| `Verifier` | 486 | 93,42 % | 478 | 0 | 97,91 % | 97,91 % | 96,9 % | 4 |
| `Guard` | 809 | 95,67 % | 799 | 1 | 97,62 % | 97,74 % | 96,6 % | 8 |
| `PhpOutline` | 642 | 90,34 % | 604 | 0 | 97,02 % | 97,02 % | 96,0 % | 6 |
| `LegacySecurity` | 375 | 92,80 % | 364 | 0 | 96,43 % | 96,43 % | 95,4 % | 3 |
| `LegacyFix` | 790 | 89,87 % | 774 | 9 | 91,60 % | 92,68 % | 90,6 % | 7 |
| **Las ocho** | **4361** | **93,37 %** | **4234** | **11** | **96,60 %** | **96,85 %** | **95,6 %** | |

«9.2.0» es la versión publicada medida otra vez con la misma configuración de
la CI (PHP 8.3, PHPUnit 12.5, Infection 0.31.9 con pcov y
`--with-uncovered`): 4361 mutantes, 289 sin detectar. La 9.3.0 deja 144. La
holgura es cuántos mutantes más pueden escapar en cada pieza sin que baje de
su suelo; la imprime la propia comprobación.

El número de mutantes baja porque cambió el código, no la medición: una
comparación que estaba repetida en sesenta sitios ahora está en uno, y las
conversiones y las guardas que no hacían nada ya no están (ver
[Lo que se cambió en vez de anotarlo](#lo-que-se-cambió-en-vez-de-anotarlo)).

La CI exige `--min-msi=95.6 --min-covered-msi=95.8` de las ocho juntas y, a
cada pieza, su suelo de
[`.github/mutation-floors.json`](../.github/mutation-floors.json). El umbral
conjunto solo dejaba que una pieza bajara mientras las demás la compensaban.

**9.3.1 (sin publicar).** `Verifier` y `LegacyFix` ganan código —llaman a
`ContractDrift` y a `RelationFix`, que no se mutan— y el `--filter` de
`infection.json5` suma las pruebas que lo recorren (`MissingRelationTest`,
`PreservedModelListsTest`, `RelationFixTest`, `ModelRegionsTest`). Medido sólo
sobre esas dos piezas: `Verifier` 480 mutantes, 97,92 %; `LegacyFix` 866,
91,57 %, sin ningún superviviente nuevo frente a la 9.3.0 medida igual. Los
suelos no cambian. `ModelRegions`, `ModelLists`, `ContractDrift`,
`RelationFix` y `Manifest` todavía no están en el conjunto que se mide.

## Los suelos y su margen

**El suelo es lo logrado, redondeado hacia abajo a la décima, menos un punto.**
Lo mismo para cada pieza y para los dos umbrales conjuntos. En la 9.2.0 era lo
logrado redondeado hacia abajo al entero, y eso dejaba un margen que dependía
del azar de los decimales: `Verifier` estaba en 93,31 % con un suelo de 93, a
un mutante; las ocho juntas, en 93,38 % con 93. Cualquier arreglo que
añadiera un mutante equivalente ponía la CI en rojo sin que nadie hubiera
quitado una prueba.

Un punto, y no más, porque lo que el suelo tiene que seguir detectando es que
alguien quite pruebas. Lo que ese punto vale en mutantes es la columna
«Holgura» de arriba: de uno en `HandRolledLock` (134 mutantes) a ocho en
`Guard` (799). Borrar el test que mata tres mutantes de `LegacySecurity` lo
pone en rojo; borrar uno que mata dos de `Guard`, no. **Eso es lo que este
margen no cubre, y no se disfraza**: en una pieza grande, quitar una prueba
pequeña pasa por debajo del suelo. Lo que sí hace la comprobación es decirlo
aunque pase: cada mutante que escapa y no está en el catálogo se lista en la
salida del trabajo, también en verde (ver abajo). Quien revisa el PR lo ve.

Y un punto, no menos, por dos cosas que se midieron:

- Un arreglo inocuo añade mutantes: un `?? ''` nuevo, una guarda. Uno o dos
  equivalentes no deberían poner la CI en rojo.
- **Lo que agota el tiempo cuenta como detectado, y depende de la máquina.**
  Infection da 30 s a cada mutante. En dos corridas seguidas del mismo código,
  con otra carga en la máquina, seis mutantes equivalentes de `LegacySecurity`
  pasaron de agotar el tiempo a escapar, y la pieza bajó de 98,08 % a 96,43 %.
  Los suelos de arriba salen de la corrida más baja de las dos.

Si una pieza sube, su suelo sube en el mismo cambio con la misma regla. Un
suelo no baja nunca para que algo pase: si una pieza baja, es un test que
falta, un fallo o un equivalente nuevo que se apunta aquí.

## Cuando la CI sale en rojo

El paso «El suelo de cada pieza» corre aunque el de Infection haya fallado, y
es el que dice por qué. Si `Guard` bajara a 96,50 %, por ejemplo:

```
Pieza               Mutantes      MSI   Suelo  Holgura
Guard                    799   96.50%   96.6%       -1
…

Guard: MSI 96.50 % por debajo de su suelo, 96.6 %: 0.10 puntos, 1 mutante detectado más.
  Escapan y no están en .github/mutation-survivors.json:
    src/Support/Guard.php:284 [PregMatchRemoveCaret, escapa]
      - $nested = $eval || preg_match('/^-[a-z]*c/i', $words[$index - 1] ?? '') === 1;
      + $nested = $eval || preg_match('/-[a-z]*c/i', $words[$index - 1] ?? '') === 1;
```

Qué pieza bajó, cuántos puntos, cuántos mutantes detectados le faltan y cuáles
son los nuevos, con su `archivo:línea`. Los demás supervivientes de la pieza
están en el catálogo y no salen: son los de las familias de abajo.

**El catálogo** es [`.github/mutation-survivors.json`](../.github/mutation-survivors.json):
los que sobrevivieron en la última medición. Un mutante se reconoce por su
pieza, su mutador y las líneas que quita y pone, sin la sangría; no por el
número de línea, para que añadir código encima no vuelva nuevo a un
equivalente de siempre. Se regenera desde la corrida:

```
php -d memory_limit=2G .github/scripts/mutation-floors.php --write-catalog .infection/infection.json .github/mutation-survivors.json
```

y sólo después de decidir, de cada mutante nuevo, que es equivalente y de qué
familia. Si un test mata uno del catálogo, la comprobación avisa de que el
catálogo se quedó viejo, sin fallar. El informe entero de Infection, con el
diff de cada superviviente, queda como artefacto `mutacion` de la corrida.

## Lo que se cambió en vez de anotarlo

Antes de apuntar un mutante como equivalente se miró si la equivalencia era
una idea repetida que se podía escribir una vez. Tres lo eran, y quitarlas
dejó el código más corto:

- **Un carácter suelto es su propio texto.** `PhpOutline` guarda cada token de
  un carácter (`(`, `;`, `{`…) como `[c, c, línea]`: su tipo y su texto son el
  mismo carácter, así que comparar `$token[0]` o `$token[1]` con `'('` daba lo
  mismo. Repartida por `PhpOutline`, `ControllerShape` y `LegacySecurity`,
  esa comparación eran 59 mutantes que ninguna prueba podía distinguir. Ahora
  todas pasan por `PhpOutline::is($token, ...$tipos)`, y el mutante que queda
  ahí se distingue: un `T_*` nunca es el texto de un token.
- **Un `(string)` sobre algo que aquí nunca es `null`.** Era la segunda
  familia (32). Las lecturas de un archivo que existe pasan por
  `Files::read()`, que lanza si no puede leer —antes, un archivo ilegible se
  tomaba por vacío—; lo que puede dar `null` (`preg_replace()`, una lectura
  opcional) lleva `?? ''`, y su mutante sí se ve.
- **Guardas que no guardaban nada:** el `return []` de la consistencia con un
  solo modelo, un `array_values()` delante de un `sort()`, un `is_dir()`
  delante de algo que ya devuelve vacío, el `new`/`::` delante de `class`
  (lo que lo sigue nunca es un nombre), el bool de `loadLock()` (sin candado
  legible no hay nada aprobado, ni nada que sobre), el `trim()` de una
  restricción que VersionParser ya rechaza, la comprobación de `git rev-parse`
  que repetía la de `git status`, un `mkdir()` cuyo fallo sólo distinguía una
  carrera.

## Los mutantes que sobreviven

Los que quedan son, casi todos, **equivalentes**: cambian el código sin cambiar
lo que hace, y ninguna prueba puede distinguirlos. No se excluyen de la
medición —siguen contando en contra— ni se esconden con un `ignore`: se
apuntan aquí por familias, con su razón, para que quien vea uno sepa si es de
éstos o uno nuevo. La lista exacta es el catálogo.

### Un `(string)` que sólo pide el tipo

Quedan pocos, y por una razón cada uno: el nombre de un paquete de
`composer.json` (una clave de array, `int|string`), el de un objetivo del
candado, lo que devuelve `realpath()` de la raíz de git, el texto saneado de
un mensaje de `ControllerShape`, el `ability` y el `operation` de un endpoint
del manifiesto. Quitar la conversión no cambia el valor.

### `isset()` no distingue `true` de `false`

Las visitas de un recorrido se apuntan con `$visited[$clase] = true`; cambiarlo
por `false` no cambia nada, porque `isset()` sólo mira si es `null`
(`LegacySecurity`, `ControllerShape`, la comprobación de después de `fix`).

### Lo que sólo ahorra trabajo

- `Verifier::run()` no corre las comprobaciones que `--check` no pide, pero el
  filtro final por `check` deja el mismo resultado si las corre.
- Las cachés de `ControllerShape` (`outlines`, `handles`) y la de manifiestos
  de `LegacyFix::apply()`: sin ellas se recalcula lo mismo.
- El `return []` de `checkEndpoints()` sin endpoints: el bucle no daría vueltas,
  pero leería archivos de cada modelo.

### Un `array_values()` cuando lo siguiente ya reindexa

Lo que se ordena con `sort()`, se expande con `...` o sólo se recorre con
`foreach` no depende de sus claves. Los que quedan los pide el tipo
(`list<string>`) para PHPStan.

### Límites que el código válido no alcanza

- `count($sentencia) > 4` frente a `>= 4` cuando la sentencia de cuatro
  tokens (`$this->x =`) no es PHP válido; lo mismo con `> 3` y `> 2`.
- Empezar a recorrer en `$i + 0` o `$i - 1` cuando esos tokens son el propio
  nombre (`merge`, `setAppends`), la llave o el paréntesis que lo precede, que
  no son ni paréntesis ni variables; acabar en `<=` cuando el último es la
  llave que cierra.
- Saltar un token de más o de menos (`$this->i += 1` en vez de `2`) cuando el
  que queda en medio lo vuelve a consumir el recorrido general sin efecto.
- `mb_strtolower()` frente a `strtolower()`… salvo en el prefijo de rutas, que
  sí lo distingue una prueba con «Ñ»: PHP admite identificadores UTF-8.
- En `LegacyFix::receiver()`, `count($texts) >= 5` (o `3`, o `7`) frente a
  `>`: la lectura que se reconoce acaba siempre en dos o cinco tokens
  (`'appends' )` o `'appends' , [ ] )`), así que la longitud mínima nunca es
  la exacta.
- El `?->` de la cadena `$this->resource->setAppends`: dentro de un método
  siempre hay cuatro tokens delante, y ninguno es `null`.
- `lineStart()` de un token de la primera línea: tras `<?php` no hay ninguno.

### Dos caminos al mismo sitio

- `Verifier::appDirectory()` desde el modelo o desde el controlador: los dos
  cuelgan del mismo `src/` o `app/`.
- Buscar el valor en `$matches[0]` o en `$matches[1]` de `$hidden = [...]`: el
  valor va entre comillas, y lo que `[0]` tiene de más no lo está.
- El `^` de un grupo opcional (`Guard::lexical()`): el primer intento ya es en
  la posición 0.
- El `ltrim('\')` del nombre de un `namespace`, que PHP no deja escribir con
  barra inicial; y el `return null` de `PhpOutline::literal()` sin tokens, que
  el final de la función repite.
- `PhpOutline` cuando se salta un `use` agrupado (`use A\{B, C}`) o un `;`:
  avanzar uno menos, o volver uno atrás (`$this->i--`), deja un token que el
  recorrido general vuelve a leer sin efecto.

### Lo que sólo se ve en otra máquina, o en una carrera

- `str_replace('\\', '/')` y `rtrim('/')` sobre rutas que en Linux no tienen
  barras invertidas ni barra final (`CiGate`, `Guard`); el `PHP_OS_FAMILY` de
  `Guard::stateDirectory()` cuando hay `ext-posix`. Sólo en parte los ejercita
  algo: el trabajo `windows-guard` de la CI corre `GuardTest`,
  `GuardHandRolledTest`, `AgentHooksTest` y `SkillTest` y lanza el hook real
  con la ruta de Windows (`guard-smoke.sh`), así que pasa por las barras de
  `Guard` en el camino del hook. **`CiGate` no corre en Windows en ninguna CI**,
  y `GuardInputsTest` tampoco: sus casos de rutas de Windows son cadenas y
  corren en Linux, pero lo que depende del sistema (`posix_geteuid()`, los
  permisos, los enlaces) no está escrito para Windows. Llevarlos a ese trabajo
  es un pendiente, no algo que hoy se compruebe.
- Un `mkdir()` que falla porque otro proceso acaba de crear el directorio
  (`Guard::stateDirectoryIn()`).
- Lo que `git` escribiría en la salida estándar al fallar, que es nada; y un
  `proc_open()` que no arranca `git`.

### Defensas que el resto del arreglo ya garantiza

`LegacyFix` comprueba, después de arreglar, que `LegacySecurity` ya no marca lo
escrito; y antes de leer los argumentos de un `setAppends`, que lo siga un
`(`. Con lo que `LegacySecurity` señala, ninguna de las dos puede fallar: sólo
señala un `setAppends` seguido de `(`, y el bloque que escribe el arreglo es
el que reconoce como limpio. Lo mismo con las excepciones internas de
`LegacyFix` —un paréntesis sin cerrar, una clase sin cuerpo o sin métodos—: lo
que llega ahí ya parseó, y `LegacySecurity` sólo marca clases con métodos.
Quitarlas no cambia ninguna prueba. Se quedan, porque son lo que impide
escribir algo a medias el día que alguien amplíe las formas reconocidas.

Las otras comprobaciones de después —que lo arreglado parsea, que sigue
parseando después de Pint y que se puede leer— sí se pueden ver fallar, y
tienen su prueba en `LegacyFixTest`: un archivo que ya no parseaba antes, y
un Pint de mentira (`LARAPACK_PINT`) que rompe lo que toca o se lo lleva.

## Los que no son equivalentes y siguen vivos

Apuntados para que no se confundan con los de arriba. Cada uno es un test que
falta, o una forma de código que nadie escribe; ninguno es equivalente.

- `LegacyFix::review()`: el orden y los duplicados de lo que pasa a
  `ManagedFilterAudit` y `AppendableProposals`, y si los Resource arreglados
  en la misma corrida llegan a la auditoría de los IndexRequest (no salen en
  el informe: la auditoría los ignora). Lo que sí se prueba, en
  `FixReviewTest`, es que lo revisado se acota al paquete.
- `LegacyFix`, código que no es PHP: una declaración `function nombre` al final
  del archivo, sin paréntesis, o un `class` como último token. Las guardas de
  `null` de `methods()` y `classBody()` cambiadas por `&&` dejarían un aviso de
  PHP en vez del mensaje; ningún test escribe un archivo así.
- `PhpOutline::stringArgument()`: `count($argumento) > 2` frente a `>= 2`,
  que sólo se distingue con un argumento que PHP rechaza (`reason:` sin
  valor).
- `PhpOutline`, un closure en el valor por defecto de una propiedad (PHP 8.5):
  sin el `return null` que lo descarta saldría un método llamado `(`. Lo
  prueba `LegacyFixTest` para `fix`, pero no hay un test de `PhpOutline` que
  mire la lista de métodos de esa clase. Falta ese test.

Los que estaban aquí y ya no: el `-c1` de la guardia (ahora cualquier `-c…` se
lee como `-c`: un falso positivo posible, nunca un falso negativo), `show`
frente a `showcase` en las trazas del `Verifier`, el `{$appends` pegado a la
llave y la sangría mezclada de `LegacyFix` (con su test en `LegacyFixTest`),
`revert()` con dos archivos, y las guardas de `fixAppends()`,
`appendsStatement()`, `receiver()` y `methods()` que ya tenían el suyo.

Si aparece uno que no es de ninguna familia, es un test que falta.
