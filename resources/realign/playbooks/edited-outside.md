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
propiedad, la cabecera de la clase):

{{files}}

## Cómo hacerlo, archivo por archivo

1. Mira la diferencia con lo que generaría el contrato. Lo regenerado está en
   la caché del realineamiento (`.larapack/realign/cache/`), o regenera sólo
   ese modelo en un directorio aparte. No edites lo regenerado.
2. Por cada pieza que difiere, decide adónde va:
   - **Es lógica de negocio** (un cálculo, un efecto, una consulta): a
     `Traits/Operations/<Modelo>Operations.php` (o a un servicio), y la pieza
     generada vuelve a llamar a ella.
   - **Es autorización** (`authorize()` distinto): a la política del modelo
     (`Policies/<Modelo>Policy.php`), que es un hueco.
   - **Es una regla de validación**: a `rules()`, que es un hueco.
   - **Es una lista del modelo** (`$fillable`, `casts()`, `$export_cols`,
     `$loadable_relations`): sale del contrato. Cámbiala en
     `laraimport.json`, `larapack:validate`, y que la regenere el import.
   - **Es un `use` o una propiedad** que sólo usa tu código: muévela con él.
   - **Es la plantilla vieja** (el archivo se escribió con una versión
     anterior y la diferencia es de la plantilla, no tuya; el paso lo marca
     con `cause: edited_or_generator`): no hay nada que mover; la pieza
     vuelve a la forma actual al regenerar.
3. Cuando el archivo sólo difiera en sus huecos (o en nada), regenéralo
   para que vuelva a ser del generador: bórralo del disco y corre
   `larapack:import --force` en su ámbito, o deja que lo haga
   `larapack:realign apply` si el paso lo ofrece. Lo que quede en los huecos
   se vuelve a pegar en ellos.
4. Si una pieza **no cabe en ningún hueco** y no se puede declarar en el
   contrato, no la fuerces: es una excepción y la decide el dueño
   (`larapack:realign except`). Déjala anotada en el informe del paso.
5. Corre las pruebas del paquete antes y después.

## Riesgo

{{risk}}

## Comprobar

    {{check}}

Está hecho cuando {{done_when}}
