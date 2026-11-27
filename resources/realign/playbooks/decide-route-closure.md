# Decidir: ¿se quedan estas rutas con closure?

Paso `{{id}}` · ámbito {{scope}}

Esto es una **decisión**. Si eres un agente, prepara la información y
preséntala; puedes **pedir** la excepción si te lo indican
(`larapack:realign except`), pero **no la apruebas**: eso lo hace el dueño del
proyecto con `larapack:hand-rolled approve`.

## Las rutas

{{findings}}

## Cómo pensarla

Una ruta con closure no pasa por una FormRequest: ni `authorize()` ni
`rules()`, y nada de lo que verify comprueba en los controladores la alcanza.
Suele ser legítima cuando:

- Es **la raíz de la SPA según el Host** o el `fallback()` que devuelve el
  HTML de la aplicación (si es el de laravel-setup <= 7.2.0, ni siquiera es
  esta decisión: lo corrige `larapack:fix route-closure`).
- Es un **`OPTIONS` de CORS** o un **health check** (`/ci/test-health`) sin
  datos de nadie.
- Recibe los **informes de CSP** (`/csp-report`): un cuerpo en un formato fijo
  que no valida nadie.

Y no lo es cuando lee o escribe datos de la aplicación, depende del usuario o
valida algo: ahí falta la request.

Para cada closure mira qué hace su cuerpo, si tiene middleware (sesión,
`throttle`) y si alguna prueba lo llama.

## Las opciones

- **except** — Se pide una excepción **por closure** con su razón:

      larapack:realign except {{id}} --option reason="Raíz de la SPA según el Host; sin datos."

  La excepción nombra el closure por su llamada y su URI
  (`realign:route-closure:routes/web.php#get('/csp-report')`) y guarda la
  huella de su código: si alguien cambia el closure, deja de cubrirlo. No
  cuenta hasta que el dueño la aprueba (`larapack:hand-rolled approve`).
- **refactor** — Cada ruta pasa a un controlador con su request
  (playbook `closure-to-controller`).

Se pueden mezclar: decide `refactor` para las que tienen lógica y pide la
excepción sólo para las legítimas; el paso queda hecho cuando verify ya no
encuentra ninguna sin resolver.

    larapack:realign decide {{id}} <except|refactor> --note="…"

## Riesgo

{{risk}}

Está hecho cuando {{done_when}}
