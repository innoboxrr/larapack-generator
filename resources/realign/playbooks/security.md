# Cerrar una escritura o una exposición que el contrato prohíbe

Paso `{{id}}` · ámbito {{scope}}

**Objetivo.** Lo que el contrato declara inmutable no se puede modificar por
ninguna ruta, y lo que declara secreto no sale en ninguna respuesta. Esto **es
un agujero**: arreglarlo cambia lo que el cliente ve o puede hacer, y es a
propósito.

## Lo que marca verify

{{findings}}

Archivos:

{{files}}

## Pasos exactos, según el hallazgo

- **`immutable-write`**: un modelo con `"immutable": true` tiene una acción,
  una ruta o un método que lo modifica o lo borra.
  1. Busca quién la usa (el front, otros servicios, pruebas). Si algo la usa,
     **para y dilo**: el contrato y el uso se contradicen, y eso lo decide el
     dueño (o el modelo deja de ser inmutable, o el uso cambia).
  2. Si nadie la usa: quita la acción del contrato si sigue declarada
     (`routes.except`), regenera con `--force` y borra lo que sobre (su
     request, su test, su método de política).
  3. Si la escritura está en código propio (un servicio, un listener), cámbiala
     por una fila nueva (un modelo inmutable se corrige con otro registro, no
     editando el que hay).
- **`secret-exposed`**: una columna `"secret": true` sale en un Resource, en una
  exportación o en un `toArray()`.
  1. Quítala de donde sale (el Resource es un hueco: edítalo; la exportación
     sale del contrato: regenera).
  2. Si el front la leía, **dilo**: es justo lo que el secreto prohíbe, y el
     front tiene que dejar de necesitarla.

Escribe una prueba que lo fije: que la ruta prohibida responda 404/405 (o no
exista) y que la respuesta no traiga la columna.

## Qué NO hacer

- No pidas una excepción para «salir del paso». Exceptuar un hallazgo de
  seguridad es aceptar el agujero; se puede (`larapack:realign except`), pero
  lo decide y lo aprueba el dueño, con la razón por escrito.
- No cambies el contrato para quitar `immutable` o `secret` sin que lo decida
  el dueño.

## Riesgo

{{risk}}

## Comprobar

    {{verify}}

    {{check}}

Está hecho cuando {{done_when}}
