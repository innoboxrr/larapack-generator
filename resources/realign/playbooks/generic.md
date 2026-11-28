# {{title}}

Paso `{{id}}` · ámbito {{scope}}

{{description}}

## Qué hay que resolver

Archivos:

{{files}}

Hallazgos de `verify`:

{{findings}}

## Cómo trabajar

1. Lee cada hallazgo y cada archivo de arriba **antes** de tocar nada. El
   mensaje de `verify` dice qué falta y, casi siempre, cómo se arregla.
2. Si un arreglo pide cambiar el contrato, cambia `laraimport.json`, corre
   `larapack:validate` y regenera con `larapack:import --force` (sólo
   reescribe lo que nadie editó). El código generado no se edita fuera de
   sus huecos.
3. Lo que no reconozcas con certeza **no lo toques**: anótalo y sigue. Es
   mejor un paso a medias que un arreglo adivinado.
4. Nunca reescribas una migración que ya existe ni cambies un nombre de ruta o
   una URI que el contrato no declare: el front y la base de datos de
   producción dependen de ellos.
5. Corre las pruebas del paquete antes y después.

## Riesgo

{{risk}}

## Comprobar

Mientras trabajas:

    {{verify}}

Cuando creas que está:

    {{check}}

Está hecho cuando {{done_when}}

Si no se puede hacer sin decidir algo que no te toca (aceptar una excepción,
cambiar un comportamiento), para y déjalo dicho: es una decisión, no un paso
guiado.
