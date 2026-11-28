# Decidir: ¿es legítima esta excepción #[HandRolled]?

Paso `{{id}}` · ámbito {{scope}}

Esto es una **decisión** y la toma el dueño del proyecto. Si eres un agente:
prepara la información de abajo para cada excepción, preséntala y **no
decidas ni apruebes**: ni `larapack:hand-rolled approve` ni editar
`.larapack/hand-rolled.lock` (la guardia lo impide, y el hook de git pide
confirmarlo en la terminal).

## Las excepciones sin aprobar

{{findings}}

## Cómo pensarla

Una acción escrita a mano es legítima cuando **no puede** ser
`return $request->handle();` sin dejar de hacer su trabajo. Casos que sí
suelen serlo:

- Comprueba una **firma sobre el cuerpo en bruto** antes de que nada lo toque
  (webhooks de pagos, de GitHub).
- Responde con el **formato de un protocolo** que no es el de Laravel (los
  errores de OAuth2 —RFC 6749—, el registro de clientes de RFC 7591, xAPI,
  SCIM) **y** ese formato o su código de estado no se pueden conservar con
  una request. Casi siempre se pueden: `failedValidation()` y
  `failedAuthorization()` de la request devuelven el error del protocolo, y
  `handle()` la misma respuesta. Si la razón sólo dice «es un protocolo»,
  la opción es `refactor` (el criterio completo está en el playbook
  `controller-to-request`, «Protocolos»).
- Hace **streaming** o devuelve un archivo por partes.

Casos que **no** lo son, aunque lo parezcan:

- «Es más rápido así», «es una sola línea», «sólo lo usa el admin».
- Valida o autoriza de una forma especial: eso cabe en `rules()`,
  `withValidator()` y `authorize()`.
- Comparte lógica con otras acciones: va en una request base o en un soporte.

Para cada una, mira: la razón del atributo (¿dice por qué **no puede**
delegar?), el cuerpo de la acción (¿hace lo que dice la razón?) y si hay una
prueba que la ejerza.

## Las opciones

- **approve** — Queda en el candado con su razón y la huella de su código. Si
  el código o la razón cambian, vuelve a pedir aprobación. Lo corre el dueño:
  `larapack:hand-rolled list`, `larapack:hand-rolled approve <objetivo>` (o
  `--all` después de leerlas todas), revisa el diff del candado y lo comitea.
- **refactor** — Se quita el atributo y la acción va a su request
  (playbook `controller-to-request`).

Registra la decisión con una nota que diga por qué:

    larapack:realign decide {{id}} <approve|refactor> --note="…"

## Riesgo

{{risk}}

Está hecho cuando {{done_when}}
