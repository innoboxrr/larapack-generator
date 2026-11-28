# {{title}}

Paso `{{id}}` · ámbito {{scope}}

{{description}}

Primero, siempre: `larapack:realign apply {{id}} --dry-run` y después sin
`--dry-run`. La orden añade a `$hidden`, `$export_cols`, las listas de carga y
`casts()` lo que declara el contrato y les falta, igual que
`larapack:import --force` con un modelo editado. **Nunca quita ni cambia**
nada, y **nunca añade a `$fillable`, `$creatable` ni `$updatable`**: eso abre
la asignación en masa de una columna. Este playbook es para lo que la orden
**salte**: cada salto trae la orden manual exacta.

## Lo que hay que resolver

{{findings}}

## A mano

0. **Una columna que falta en `$fillable`, `$creatable` o `$updatable`** no la
   añadas sin mirar por qué falta: `git log -p -- <el modelo>`, un comentario,
   una prueba que la excluya, una columna que decide permisos, saldos o
   estados. Si se sacó a propósito, el contrato está mal: declárala con
   `fillable: false` (o `creatable`/`updatable`) y corre `larapack:realign
   diagnose`. Si debe escribirse por la API, añádela tú y dilo en el informe
   del paso. Si no lo sabes, pregúntalo al dueño (`larapack:realign escalate`).
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
