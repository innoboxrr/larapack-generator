# Las pruebas de mutación

Qué mide [`infection.json5`](../infection.json5), cuánto daba antes y después
de la 9.2.0, y por qué los mutantes que sobreviven sobreviven. Cómo se corre,
en [CONTRIBUTING](../CONTRIBUTING.md#las-pruebas-de-mutación).

## Lo que se mide

Las ocho piezas de las que depende lo que LaraPack promete, cada una con los
tests que la ejercitan (el `--filter` de `infection.json5`). El MSI es la parte
de los mutantes que algún test detecta (los muertos, los que agotan el tiempo y
los que dan error).

**Con `--with-uncovered`.** Infection 0.31, por defecto, sólo muta las líneas
que algún test recorre: las demás no producen mutantes y no cuentan. Así se
midió primero, y por eso el MSI y el «covered MSI» salían iguales: no porque
todo estuviera cubierto, sino porque lo no cubierto no existía para la
medición. `LegacyFix` tenía entonces 33 de sus 371 líneas sin recorrer (hoy,
18), y borrar el test que cubría un mutante difícil *subía* el MSI. Ahora la CI muta
también lo que nadie recorre y lo cuenta como no detectado: el MSI cuenta todo
y el «covered MSI» sólo lo recorrido.

| Pieza | Antes: mutantes | Antes: MSI | Mutantes | Sin recorrer | MSI | MSI recorrido | Suelo |
|---|---:|---:|---:|---:|---:|---:|---:|
| `HandRolledLock` | 121 | 53,7 % | 140 | 0 | 97,9 % | 97,9 % | 97 % |
| `CiGate` | 439 | 79,7 % | 443 | 1 | 95,9 % | 96,2 % | 95 % |
| `Guard` | 776 | 75,0 % | 809 | 2 | 95,7 % | 95,9 % | 95 % |
| `ControllerShape` | 667 | 77,4 % | 676 | 2 | 95,3 % | 95,5 % | 95 % |
| `Verifier` | 457 | 82,7 % | 478 | 3 | 93,3 % | 93,9 % | 93 % |
| `LegacySecurity` | 372 | 74,5 % | 375 | 3 | 92,8 % | 93,5 % | 92 % |
| `PhpOutline` | 637 | 83,8 % | 642 | 5 | 90,3 % | 91,1 % | 90 % |
| `LegacyFix` | 724 | 72,5 % | 772 | 10 | 89,9 % | 91,1 % | 89 % |
| **Las ocho** | **4193** | **77,0 %** | **4335** | **26** | **93,4 %** | **93,9 %** | **93 %** |

«Antes» es la suite de la 9.1.0 con la configuración de entonces: sin
`--with-uncovered`, así que sólo cuenta lo que esa suite recorría y no se
compara uno a uno con lo de ahora. Por eso cambia el número de mutantes de
piezas cuyo código no cambió (`Verifier`, `ControllerShape`, `CiGate`,
`LegacyFix`): los tests nuevos recorren líneas que antes no
recorría nadie, y esas líneas ahora producen mutantes. En `Guard`,
`PhpOutline`, `HandRolledLock` y `LegacySecurity` cambió además el código (la
lectura de `<<<` y la profundidad de las shells, el `&` de un método y su
huella, las variables con mayúsculas). «Después» es la corrida entera tal como la hace la CI.

La CI exige `--min-msi=93 --min-covered-msi=93` de las ocho juntas y, a cada
pieza, su suelo de
[`.github/mutation-floors.json`](../.github/mutation-floors.json): lo logrado,
redondeado hacia abajo. El umbral conjunto solo dejaba que una pieza bajara
mientras las demás la compensaban.

La mitad de lo que se ganó son pruebas que fijan el texto entero de un
mensaje, el segundo de dos hallazgos o el caso en otra caja. La otra mitad
salió de convertir los avisos de PHP en excepciones en las pruebas nuevas,
como hace Laravel (`error_reporting(-1)` y un manejador que lanza): un mutante
que lee fuera de un array, o que pasa `null` a `strtolower()`, sólo deja un
aviso, y bajo Artisan ese aviso tumba `verify`. Sin eso, la configuración de
PHP de cada máquina decidía qué se veía.

## Los mutantes que sobreviven

Los que quedan son, casi todos, **equivalentes**: cambian el código sin cambiar
lo que hace, y ninguna prueba puede distinguirlos. No se excluyen de la
medición —siguen contando en contra— ni se esconden con un `ignore`: se
apuntan aquí por familias, con su razón, para que quien vea uno sepa si es de
éstos o uno nuevo. La lista exacta la da la propia orden con
`--show-mutations`.

### Un carácter suelto es su propio texto

`PhpOutline` guarda cada token de un carácter (`(`, `;`, `{`…) como
`[c, c, línea]`: su id y su texto son el mismo carácter. Comparar `$token[0]`
o `$token[1]` con `'('` da lo mismo, y un token con nombre nunca tiene por
texto un paréntesis o un punto y coma. Es la familia más numerosa: unas
cuarenta en `PhpOutline`, unas quince en `ControllerShape` y una decena en
`LegacySecurity`. Lo mismo vale para `T_AMPERSAND_…` frente a `'&'` *después*
del arreglo de la 9.2.0: antes no era equivalente, y era un fallo (ver
[CHANGELOG](../CHANGELOG.md)).

### Un `(string)` sobre algo que aquí nunca es `null`

`file_get_contents()` tras un `is_file()`, `preg_replace()` con un patrón fijo
y válido, `realpath()` o `getcwd()` de un directorio que existe, el
`$attribute['extends']` que ya se comprobó. Quitar la conversión no cambia el
valor. Están en todas las piezas.

### `isset()` no distingue `true` de `false`

Las visitas de un recorrido se apuntan con `$visited[$clase] = true`; cambiarlo
por `false` no cambia nada, porque `isset()` sólo mira si es `null`
(`LegacySecurity`, `ControllerShape`).

### Lo que sólo ahorra trabajo

- `Verifier::run()` no corre las comprobaciones que `--check` no pide, pero el
  filtro final por `check` deja el mismo resultado si las corre.
- Las cachés de `ControllerShape` (`outlines`, `handles`) y la de manifiestos
  de `LegacyFix::apply()`: sin ellas se recalcula lo mismo.
- Un `return []` delante de un bucle que, sin él, no da ninguna vuelta.

### Un `array_values()` cuando lo siguiente ya reindexa

Lo que se ordena con `sort()`, se expande con `...` o sólo se recorre con
`foreach` no depende de sus claves.

### Límites que el código válido no alcanza

- `count($sentencia) > 4` frente a `>= 4` cuando la sentencia de cuatro
  tokens (`$this->x =`) no es PHP válido.
- Empezar a recorrer en `$i + 0` o `$i - 1` cuando esos tokens son el propio
  nombre (`merge`, `setAppends`) o la flecha que lo precede, que no son ni
  paréntesis ni variables.
- Saltar un token de más o de menos (`$this->i += 1` en vez de `2`) cuando el
  que queda en medio lo vuelve a consumir el recorrido general sin efecto.
- Un `preg_quote()` sobre un identificador (`[A-Za-z0-9_]`): no tiene nada que
  escapar.
- `mb_strtolower()` frente a `strtolower()` sobre un espacio de nombres, que es
  ASCII.
- En `LegacyFix::receiver()`, `count($texts) >= 5` (o `3`, o `7`) frente a
  `>`: la lectura que se reconoce acaba siempre en dos o cinco tokens
  (`'appends' )` o `'appends' , [ ] )`), así que la longitud mínima nunca es
  la exacta.
- El `?->` de la cadena `$this->resource->setAppends`: dentro de un método
  siempre hay cuatro tokens delante, y ninguno es `null`.
- En `LegacyFix::classBody()`, descartar un `class` precedido de `::` o `new`:
  lo que lo sigue nunca es el nombre de la clase, y eso ya lo descarta la
  comprobación siguiente.

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
  (`HandRolledLock::write()`, `Guard::stateDirectoryIn()`).
- Lo que `git` escribiría en la salida estándar al fallar, que es nada.

### Defensas que el resto del arreglo ya garantiza

`LegacyFix` comprueba, después de arreglar, que `LegacySecurity` ya no marca lo
escrito; y antes de leer los argumentos de un `setAppends`, que lo siga un
`(`. Con lo que `LegacySecurity` señala, ninguna de las dos puede fallar: sólo
señala un `setAppends` seguido de `(`, y el bloque que escribe el arreglo es
el que reconoce como limpio. Quitarlas no cambia ninguna prueba. Se quedan,
porque son lo que impide escribir algo a medias el día que alguien amplíe las
formas reconocidas.

Las otras dos comprobaciones de después —que lo arreglado parsea, y que sigue
parseando después de Pint— sí se pueden ver fallar, y tienen su prueba en
`LegacyFixTest`: un archivo que ya no parseaba antes, y un Pint de mentira
(`LARAPACK_PINT`) que rompe lo que toca.

## Los que no son equivalentes y siguen vivos

Apuntados para que no se confundan con los de arriba. Cada uno es un test que
falta, o una forma de código que nadie escribe; ninguno es equivalente.

- `Guard`: el `$` del grupo de opciones de `bash -c` (`-c1` contaría). No hay
  opción de bash así.
- `Verifier`: la ruta `show` frente a `showcase` en las trazas de una acción no
  declarada (el `')` del final del nombre).
- `LegacyFix::review()` —qué IndexRequests y qué Resources pasan a
  `ManagedFilterAudit` y `AppendableProposals`— se prueba en `FixReviewTest`
  por lo que reporta, no por el orden ni los duplicados de lo que les pasa.
- `LegacyFix::revert()`: el `array_values()` que reindexa la lista de
  arreglados después de quitar uno. Con un solo archivo arreglado, que es lo
  que prueba `LegacyFixTest` con el Pint de mentira, da lo mismo; con dos, y
  devolviendo el primero, la lista saldría con un hueco. Falta ese test.
- `LegacyFix`, código sin espacio tras la llave del método
  (`{$appends = 1; …`): empezar a recorrer el cuerpo un token más allá
  saltaría ese `$appends` y no se vería que el bloque de la plantilla lo pisa.
  Con cualquier espacio o salto de línea detrás de la llave, que es lo que
  escribe todo el mundo, es equivalente.
- `LegacyFix`, sangría mezclada: la sangría de un miembro se calcula como la de
  la clase más un nivel, y escribir el nivel delante o detrás sólo da lo mismo
  si los dos son del mismo carácter. Con la clase sangrada con tabuladores y
  el miembro con espacios, no.
- `LegacyFix`, código que no es PHP: una declaración `function nombre` al final
  del archivo, sin paréntesis, o un `class` como último token. Las guardas de
  `null` de `methods()` y `classBody()` cambiadas por `&&` dejarían un aviso de
  PHP en vez del mensaje; ningún test escribe un archivo así.

Los que estaban aquí y ya no: las guardas de `fixAppends()` y
`appendsStatement()` (un `?->` o un `::` sobre `$this->resource`, un `if` sin
llaves, un método repetido y abstracto), las de `receiver()` (una variable
invocada como el helper, `$request('appends')`), la de `methods()` ante un
closure en una propiedad (PHP 8.5) y las comprobaciones de después del arreglo
(un archivo que no parseaba, lo que deja Pint) tienen su test en
`LegacyFixTest`, con el mensaje entero.

Si aparece uno que no es de ninguna familia, es un test que falta.
