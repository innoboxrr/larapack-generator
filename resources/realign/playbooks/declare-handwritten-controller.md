# Declarar en el contrato controladores escritos a mano

Paso `{{id}}` · ámbito {{scope}}

**Objetivo.** Los controladores y rutas de abajo, escritos a mano, pasan a
estar declarados en `laraimport.json` —como `endpoints` de un modelo o como
`controllers[]`— **conservando el nombre de cada ruta y su URI**, y desde
entonces los genera LaraPack con sus pruebas y su autorización. Se decidió así
en este paso (opción `declare`).

## Lo que hay sin declarar

{{files}}

{{findings}}

## Precondiciones

- La decisión está registrada (`larapack:realign decide {{id}} declare`).
- **Cada controlador ya es delgado**: sus acciones hacen
  `return $request->handle();`. Si no, primero su paso `controller-shape`
  (playbook `controller-to-request`); declarar un controlador con lógica la
  perdería al regenerarlo.
- El árbol git está limpio.

## Pasos exactos

1. **Infiere el borrador**, si `infer` está disponible
   (`larapack:realign help` lo dice):

       larapack:realign infer {{id}} --dry-run
       larapack:realign infer {{id}}

   Escribe `laraimport.inferred.json` junto al contrato, con los endpoints
   cuya ruta se puede conservar y un informe
   (`.larapack/realign/infer/<ámbito>.json`) con lo que no. Sin `infer`, haz
   lo mismo a mano siguiendo los puntos 2 y 3.
2. **Sólo se declara lo que conserva su ruta.** LaraPack carga las rutas
   declaradas con el prefijo `api/<namespace>/<x>` y el nombre
   `api.<namespace>.<x>.`. Una ruta entra si su URI y su nombre **ya empiezan
   así**; lo que queda es su `uri` y su `route_name`:

       "controllers": [
           { "name": "Desk", "authorize": "authenticated", "endpoints": [
               { "name": "overview", "method": "get", "uri": "overview",
                 "route_name": "overview" }
           ] }
       ]

   Una ruta que cambiaría de URI o de nombre **no se declara**: se queda como
   código propio (sigue vigilada por `controller-shape`) o se cambia junto
   con el front, a propósito, en otro paso.
3. **La autorización no se adivina.** Un endpoint de modelo pregunta a la
   política con su `ability`, que nace denegando: escribe la habilidad
   (`ability`) que ya comprobaba la request, o la ruta se cerrará. En un
   `controllers[]`, `"authorize": "authenticated"` si basta la sesión, o la
   habilidad de Gate que ya existe. Una ruta pública (`"public": true`) sólo
   si ya lo era, y con su `throttle:`.
4. Pasa lo revisado del borrador a `laraimport.json` (o renómbralo si el
   ámbito no tenía contrato), y valida:

       larapack:validate --format=json

5. **Antes de importar**, mueve el `handle()` de cada request a mano a su
   hueco: en un endpoint de modelo, a la operación `<name>Model($request)`
   del trait `Operations`; en un `controllers[]`, a la clase de soporte que
   llame el `handle()` generado. El import no reescribe lo que ya existe y se
   editó, pero la request generada nace con `handle()` que lanza o
   `abort(501)`.
6. Importa en simulación, revisa qué escribiría, e importa:

       larapack:import --dry-run --format=json
       larapack:import

7. Borra el controlador y la ruta a mano que quedaron duplicados (la ruta
   ahora la carga `routes/api/models/<x>.php`), y adopta lo que el diagnóstico
   vea igual a lo generado (`larapack:realign diagnose && larapack:realign
   plan`, y el paso `adopt`).
8. Corre las pruebas del paquete: las generadas (`tests/Feature/Endpoints/`)
   y las tuyas. `route:list` antes y después tiene que dar los mismos nombres
   y URIs para estas rutas.

## Qué NO hacer

- No cambies un `route_name` ni una `uri` para que «encaje»: rompe al front.
- No declares una ruta con closure, `Route::match`/`any` o un `resource`: el
  borrador ya los deja fuera (`lost`); se resuelven con `closure-to-controller`
  o se quedan como excepción.
- No borres el código a mano antes de que el generado pase sus pruebas.

## Riesgo

{{risk}}

## Comprobar

    {{check}}

Está hecho cuando {{done_when}}
