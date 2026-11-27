# Llevar la lógica del controlador a su FormRequest

Paso `{{id}}` · ámbito {{scope}}

Un controlador de este proyecto no tiene lógica: cada acción es
`return $request->handle();`. La autorización va en `authorize()`, la
validación en `rules()` y lo que la acción hace, en `handle()` de la misma
request, que delega en una operación del modelo (`Traits/Operations/`) o en
un servicio. Este paso lleva un controlador que se salió de esa forma de
vuelta a ella.

## Lo que marca verify

{{findings}}

Archivo:

{{files}}

## Cómo hacerlo, acción por acción

1. **Antes de tocar nada**, corre las pruebas del paquete y anota cuáles
   pasan. Si la acción no tiene prueba, escribe una que fije lo que responde
   hoy (código y forma de la respuesta): es lo que vas a conservar.
2. Por cada acción marcada:
   1. Crea (o usa la que ya existe) una request por acción en
      `Http/Requests/<Modelo o área>/<Acción>Request.php`, que extienda
      `FormRequest`.
   2. `authorize()`: lo que la acción comprobaba de permisos
      (`$this->user()->can(...)`, `Gate::allows`, `abort_unless`). Si no
      comprobaba nada, **no inventes** un permiso: escribe la política
      correspondiente o deja `return true;` con un comentario de por qué
      (y dilo en el informe del paso).
   3. `rules()`: el array de `validate()` / `Validator::make()`, tal cual.
   4. `handle()`: el cuerpo de la acción, sin la validación ni la
      autorización. Si es más de unas líneas, que delegue en una operación
      del modelo o en un servicio que ya exista.
   5. La firma de la acción pasa a recibir la request
      (`public function store(StoreRequest $request)`) y su cuerpo queda en
      `return $request->handle();`.
3. Conserva **exactamente** la URI, el nombre de la ruta y la forma de la
   respuesta. No toques el archivo de rutas salvo para cambiar el tipo del
   parámetro si hace falta.
4. Un cambio de comportamiento conocido: con la validación en `rules()` y la
   autorización en `authorize()`, Laravel autoriza **antes** de validar. Una
   petición sin permiso que antes recibía un 422 ahora recibe un 403. Si una
   prueba o el front dependía de ese orden, dilo; no lo escondas.
5. Si una acción **no puede** delegar —lee el cuerpo en bruto para comprobar
   una firma, responde con el formato de un protocolo (OAuth, xAPI), hace
   streaming—, no la fuerces: márcala con `#[HandRolled('razón')]` y déjala
   para que el dueño la apruebe (`larapack:hand-rolled approve`). Es una
   decisión, no parte de este paso.
6. Corre las pruebas del paquete otra vez. Las que pasaban tienen que seguir
   pasando, salvo el 403/422 del punto 4, que se anota.

## Riesgo

{{risk}}

## Comprobar

    {{verify}}

y al terminar:

    {{check}}

Está hecho cuando {{done_when}}
