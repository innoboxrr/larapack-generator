# Devolver a los huecos lo editado fuera de ellos

Paso `{{id}}` · ámbito {{scope}}

{{description}}

Estos archivos los generó LaraPack y alguien los editó **fuera** de los
huecos (los sitios hechos para escribir a mano, ver «Dónde va tu código» en
el README). Mientras sigan así, el generador no los puede regenerar: cada
cambio de plantilla o de contrato se queda fuera de ellos para siempre.

## Los archivos

Cada uno dice qué piezas difieren de lo que generaría hoy el contrato
(`method:<nombre>` o `outside` para lo que no es un método: un `use`, una
propiedad, la cabecera de la clase; `imports` son los `use`) y, en
`regenerado:`, **la ruta exacta** de lo que generaría hoy el contrato, en la
caché del realineamiento (`.larapack/realign/cache/<ámbito>/<clave>/…`,
relativa a la raíz del proyecto):

{{files}}

Para ver la diferencia, desde la raíz del proyecto:

    git diff --no-index <regenerado> <archivo>

(`diff -u` si no hay git.) Lo que sale con `+` es lo que tiene el proyecto y
no generaría el contrato: eso es lo que hay que llevar a su sitio. Lo que sale
con `-` es lo que el contrato generaría y el archivo ya no tiene.

Lo regenerado **no se edita**: es la referencia. La caché no se versiona y se
rehace sola. Si un archivo no trae `regenerado:` (un plan de una versión
anterior) o la ruta ya no existe (el contrato cambió desde el diagnóstico),
corre `larapack:realign diagnose` y `larapack:realign plan` y vuelve a pedir
`larapack:realign next`.

## Cómo hacerlo, archivo por archivo

1. **Lee el diff entero antes de mover nada.** Por cada bloque `+`, decide
   adónde va:
   - **Es lógica de negocio** (un cálculo, un efecto, una consulta): a
     `Traits/Operations/<Modelo>Operations.php` (o a un servicio), y la pieza
     generada vuelve a llamar a ella.
   - **Es autorización** (`authorize()` distinto): a la política del modelo
     (`Policies/<Modelo>Policy.php`), que es un hueco.
   - **Es una regla de validación**: a `rules()`, que es un hueco.
   - **Es una lista del modelo** (`$fillable`, `casts()`, `$export_cols`,
     `$loadable_relations`): sale del contrato. Cámbiala en
     `laraimport.json`, `larapack:validate`, y vuelve a diagnosticar: lo
     regenerado cambia con el contrato.
   - **Es un `use` o una propiedad** que sólo usa tu código: va con él (ver
     abajo).
   - **Es la plantilla vieja** (el archivo se escribió con una versión
     anterior y la diferencia es de la plantilla, no tuya; el paso lo marca
     con `cause: edited_or_generator`): no hay nada que mover; la pieza
     vuelve a la forma actual al copiar lo regenerado.
2. **Mueve el código con lo que necesita.** Al llevar un bloque a un hueco:
   - **Los `use`**: cada clase que nombra el código movido necesita su `use`
     en el archivo de destino. Añádelos allí. En el archivo de origen,
     quita los que ya nadie usa; los que estaban en lo regenerado se quedan.
     En un hueco de métodos (`rules()`, `canView()`), añadir los `use` de su
     código es parte de escribir en él y no cuenta como editar fuera.
   - **Los métodos auxiliares** (un `private function` que sólo llama el
     código movido): van con él al mismo destino —el trait de operaciones,
     la política, el servicio—. Un método auxiliar que se queda en el archivo
     generado, aunque sea privado, sigue siendo una pieza fuera de los huecos
     (`method:<nombre>`) y el paso no se da por hecho. Si lo usa también una
     pieza generada, está mal puesto: es lógica del modelo y va a
     `Operations`.
   - **Las propiedades** (`protected $algo`): igual que los auxiliares. Si
     es configuración que el contrato puede declarar, se declara.
   - **Lo que la pieza generada llama**: si el código movido estaba dentro de
     `handle()` o `authorize()`, comprueba en lo regenerado a qué llama hoy
     esa pieza (la operación del modelo, la habilidad de la política) y pon
     tu código **ahí**, no en un método nuevo que nadie llama.
3. **Cuando lo tuyo esté en su sitio, devuelve el archivo a lo generado**
   copiando lo regenerado encima, desde la raíz del proyecto:

       cp <regenerado> <archivo>

   y vuelve a pegar en sus huecos (`rules()`, `canView()`) lo que tenían. Es
   la forma de regenerar **un** archivo sin tocar los demás. **No** lo borres
   ni corras `larapack:import --force` para regenerarlo (lo mismo que dice
   `controller-to-request`): el import trabaja sobre el ámbito entero, no
   sobre este archivo —regenera todo lo demás que no se editó y puede
   escribir alteraciones de migración—, y deja en el commit del paso cambios
   que nadie pidió. Lo que el import haría en el resto del ámbito ya lo hacen
   los pasos deterministas (`regenerate`, `adopt`).
4. **Si una pieza no cabe en ningún hueco** y no se puede declarar en el
   contrato, no la fuerces ni la exceptúes tú: dejarla así lo decide el
   dueño. Pásaselo con `larapack:realign escalate {{id}} --note="qué no cabe
   y por qué"`: el paso pasa a ser una decisión (`refactor` o `except`). Si
   el dueño decide `except`, entonces pides la excepción
   (`larapack:realign except {{id}} --option reason="…"`) y él la aprueba.
   Déjala anotada en el informe del paso.
   **Antes, mira si la plantilla de hoy ya la trae**: entonces no es tuya,
   es la plantilla vieja, y lo correcto es copiar lo regenerado (paso 3), no
   exceptuar. El caso de siempre es la **protección contra la inyección de
   fórmulas de un Export** (un `bindValue` con `WithCustomValueBinder`,
   escrito a mano en proyectos de 7.x y 8.x): desde 9.0.0 la plantilla de
   Export la trae —hereda `DefaultValueBinder` y guarda como texto lo que
   empieza por `=`, que es lo único que PhpSpreadsheet convierte en fórmula
   en el xlsx que se exporta—. Exceptuar 60 Exports deja 60 aprobaciones que
   caducan al primer cambio y fuera del generador para siempre; copiar lo
   regenerado deja la misma protección y el archivo, otra vez, de LaraPack.
   Sólo si la tuya hace algo que la de la plantilla no (y que el xlsx
   necesita), es una excepción.
   Una excepción **aprobada** sobre el código de hoy da el archivo por
   hecho en `check` (y en `realign done`) aunque siga editado fuera de los
   huecos; si el archivo vuelve a cambiar, la aprobación caduca y el paso se
   reabre hasta que el dueño la apruebe otra vez.
5. **Corre las pruebas del proyecto antes y después.** Si no se pueden correr
   (sin `vendor`, sin base de datos), el paso no se da por hecho aunque
   `check` pase: ver «Sin pruebas» en la guía de los pasos guiados
   (`docs/realign/guided.md`).

## Cuándo copiar lo regenerado es la salida correcta, y cuándo no

A veces la diferencia es lo **contrario** de una edición: el archivo
**no tiene** algo que el contrato declara (salen bloques con `-` y ninguno con
`+`). Por ejemplo, una columna declarada en `laraimport.json` que falta en una
vista o en un formulario generados, una relación que el Resource no carga, un
campo de `rules()` que no está.

- **Copia lo regenerado** cuando lo que falta está declarado en el contrato y
  el archivo no tiene ningún `+` propio: el contrato es la fuente de verdad,
  y lo generado tiene que decir lo mismo. No hay forma de saber desde el
  código si alguien lo quitó a propósito; por eso, **dilo en el informe del
  paso** («la vista X vuelve a mostrar la columna Y, declarada en el
  contrato») para que el dueño lo vea en la revisión.
- **No lo copies, y pide la decisión**, cuando hay señales de que se quitó a
  propósito: un comentario, un commit que lo dice (`git log -p -- <archivo>`),
  una columna secreta (`hidden`, una contraseña, un token) o algo que cambia
  quién ve qué. Ahí la pregunta es si el **contrato** está mal: o se quita
  del contrato (y se vuelve a diagnosticar) o se exceptúa. Lo decide el
  dueño, no tú: `larapack:realign escalate {{id}} --note="…"`, como en el
  paso 4.
- **No lo copies tal cual** cuando el archivo tiene a la vez `+` propios: mueve
  primero lo tuyo (pasos 1 y 2) y copia después.

## Riesgo

{{risk}}

## Comprobar

    {{check}}

Está hecho cuando {{done_when}} Después de este paso, como de cualquier paso
guiado, vuelve a pasar `diagnose`, `plan` y `apply --deterministic`: copiar lo
regenerado deja archivos que la pasada siguiente adopta.
