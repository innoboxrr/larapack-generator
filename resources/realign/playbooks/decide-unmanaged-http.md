# Decidir: ¿se declaran en el contrato estos controladores a mano?

Paso `{{id}}` · ámbito {{scope}}

Esto es una **decisión**. Si eres un agente, prepara la información y
preséntala; no decidas.

## Lo que hay

{{files}}

## Cómo pensarla

Declarar un controlador en el contrato (`endpoints` de un modelo o
`controllers[]`) le da pruebas generadas, autorización preguntada a una
política o a Gate y su contrato JS, y lo pone bajo la misma vigilancia que lo
generado. **No puede** cambiar el nombre de una ruta ni su URI: sólo entra lo
que ya empieza por `api/<namespace>/<x>` y `api.<namespace>.<x>.`. Por cada
archivo, mira:

1. ¿Sus acciones ya delegan (`return $request->handle();`)? Si no, primero
   su paso `controller-shape`. Declarar no arregla la forma.
2. ¿Sus rutas se pueden conservar? `larapack:realign infer {{id}} --dry-run`
   lo dice ruta por ruta (`route-not-preservable` es que no).
3. ¿Es de un modelo (publicar, importar) o no tiene modelo (un escritorio, un
   asistente, un webhook)? Lo primero va en `endpoints`; lo segundo en
   `controllers[]`.

Dejarlo como código propio es legítimo si delega en su request: sigue bajo
`controller-shape`, sólo que sin pruebas generadas.

## Las opciones

- **declare** — `larapack:realign infer {{id}}` escribe el borrador con lo
  que conserva su ruta, y el playbook `declare-handwritten-controller` dice
  cómo declararlo e importarlo.
- **keep** — Se pide una excepción **por archivo**:

      larapack:realign except {{id}} --option reason="…"

  Vale mientras el archivo no cambie: un controlador que se sigue editando
  vuelve a pedir aprobación cada vez, y eso es a propósito (cada cambio es
  código nuevo sin pruebas generadas). La aprueba el dueño
  (`larapack:hand-rolled approve`).

      larapack:realign decide {{id}} <declare|keep> --note="…"

## Riesgo

{{risk}}

Está hecho cuando {{done_when}}
