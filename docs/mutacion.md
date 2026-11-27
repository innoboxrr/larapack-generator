# Las pruebas de mutación

Qué mide [`infection.json5`](../infection.json5), cuánto daba antes y después
de la 9.2.0, y por qué los mutantes que sobreviven sobreviven. Cómo se corre,
en [CONTRIBUTING](../CONTRIBUTING.md#las-pruebas-de-mutación).

## Lo que se mide

Las ocho piezas de las que depende lo que LaraPack promete, cada una con los
tests que la ejercitan (el `--filter` de `infection.json5`). El MSI es la parte
de los mutantes que algún test detecta; aquí todos los mutantes caen en código
que la suite recorre, así que el MSI y el «covered MSI» coinciden.

| Pieza | Mutantes | MSI antes | MSI después |
|---|---:|---:|---:|
| `HandRolledLock` | 131 | 53,7 % | 97,7 % |
| `CiGate` | 442 | 79,7 % | 96,2 % |
| `Guard` | 812 | 75,0 % | 95,8 % |
| `ControllerShape` | 674 | 77,4 % | 95,6 % |
| `Verifier` | 475 | 82,7 % | 93,9 % |
| `LegacySecurity` | 371 | 74,5 % | 93,8 % |
| `PhpOutline` | 635 | 83,8 % | 91,2 % |
| `LegacyFix` | 747 | 72,5 % | 89,2 % |
| **Las ocho** | **4287** | **77,0 %** | **93,6 %** |

«Antes» es la suite de la 9.1.0 con la misma configuración (el número de
mutantes cambió un poco porque el código también: la guardia ganó la lectura
de `<<<`). «Después», la corrida entera tal como la hace la CI, que exige
`--min-msi=93 --min-covered-msi=93`: lo logrado, redondeado hacia abajo.

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

### Lo que sólo se ve en otra máquina, o en una carrera

- `str_replace('\\', '/')` y `rtrim('/')` sobre rutas que en Linux no tienen
  barras invertidas ni barra final (`CiGate`, `Guard`); el `PHP_OS_FAMILY` de
  `Guard::stateDirectory()` cuando hay `ext-posix`. La CI de Windows los
  ejercita fuera de Infection.
- Un `mkdir()` que falla porque otro proceso acaba de crear el directorio
  (`HandRolledLock::write()`, `Guard::stateDirectoryIn()`).
- Lo que `git` escribiría en la salida estándar al fallar, que es nada.

### Defensas que el resto del arreglo ya garantiza

`LegacyFix` comprueba que su resultado parsea y que `LegacySecurity`, leyéndolo,
ya no lo marca. Con las formas que reconoce, ninguna de las dos cosas puede
fallar: quitar la comprobación no cambia el resultado de ninguna prueba. Se
quedan, porque son lo que impide escribir un archivo roto el día que alguien
amplíe las formas reconocidas.

## Los que no son equivalentes y siguen vivos

Pocos, y apuntados para que no se confundan con los de arriba:

- `Guard`: el `$` del grupo de opciones de `bash -c` (`-c1` contaría). No hay
  opción de bash así.
- `Verifier`: la ruta `show` frente a `showcase` en las trazas de una acción no
  declarada (el `')` del final del nombre).
- `LegacyFix`: `review()` —qué IndexRequests y qué Resources pasan a
  `ManagedFilterAudit` y `AppendableProposals`— se prueba en `FixReviewTest`
  por lo que reporta, no por el orden ni los duplicados de lo que les pasa.

Si aparece uno que no es de ninguna familia, es un test que falta.
