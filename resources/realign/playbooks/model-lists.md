# {{title}}

Paso `{{id}}` · ámbito {{scope}}

{{description}}

Primero, siempre: `larapack:realign apply {{id}} --dry-run` y después sin
`--dry-run`. La orden añade a `$fillable`, `$creatable`, `$updatable`,
`$export_cols` y `casts()` lo que declara el contrato y les falta, igual que
`larapack:import --force` con un modelo editado. **Nunca quita ni cambia**
nada. Este playbook es para lo que la orden **salte**: cada salto trae la
orden manual exacta.

## Lo que hay que resolver

{{findings}}

## A mano

1. Para cada región saltada, haz lo que dice su «A mano». Suele ser una lista
   escrita con algo que no es una cadena literal (una constante, un spread,
   `array()`): conviértela en una lista literal o añade la entrada donde
   corresponda.
2. Lo que el contrato **ya no declara** y sigue en una lista no se quita
   solo: si la columna ya no existe, quítala tú; si existe, declárala en el
   contrato.
3. Un `cast` que existe **con otro valor** no se cambia: pudo cambiarlo una
   persona. Decide cuál es el correcto y cuadra el contrato o el modelo.
4. Corre las pruebas del paquete: cambiar `$fillable` cambia qué se asigna en
   masa.

## Riesgo

{{risk}}

## Comprobar

    {{verify}}
    {{check}}

Está hecho cuando {{done_when}}
