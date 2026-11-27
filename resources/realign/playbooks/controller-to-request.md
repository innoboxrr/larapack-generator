# Llevar la lógica del controlador a su FormRequest

Paso `{{id}}` · ámbito {{scope}}

**Objetivo.** Cada acción de este controlador queda en
`return $request->handle();`: la autorización va en `authorize()`, la
validación en `rules()` y lo que la acción hace en `handle()` de una request
propia, que delega en una operación del modelo (`Traits/Operations/`) o en un
soporte. El comportamiento que ve el cliente —código de estado, forma de la
respuesta, URI, nombre de la ruta— **no cambia**, salvo lo que se dice abajo
y se anota.

## Lo que marca verify

{{findings}}

Archivo:

{{files}}

## Precondiciones

- El paso no está bloqueado (`larapack:realign status`): el manifiesto de su
  ámbito ya dice la verdad.
- El árbol git está limpio: este paso se comitea solo, para poder
  deshacerlo con `git revert`.
- Puedes correr las pruebas del paquete (`vendor/bin/phpunit` o `php
  artisan test` en la raíz del ámbito).

## Pasos exactos

1. **Fija lo que responde hoy, antes de tocar nada.** Por cada acción
   marcada, si no hay una prueba que la llame por HTTP, escribe una que fije
   el código de estado y la forma del JSON en tres casos: sin sesión (o sin
   permiso), con datos inválidos y con datos válidos. Córrela y comprueba que
   pasa **con el código de hoy**. Comítala aparte («pruebas antes de mover
   X»). Sin esto no hay forma de saber qué cambió.
2. Por cada acción marcada:
   1. Usa la request que ya tenga la acción, o crea una por acción en
      `Http/Requests/<Modelo o área>/<Acción>Request.php` que extienda
      `Illuminate\Foundation\Http\FormRequest`. Una request por acción: dos
      acciones que comparten request comparten reglas, y eso se rompe.
   2. `authorize()`: lo que la acción comprobaba de permisos
      (`$this->authorize(...)`, `Gate::allows(...)`, `$user->can(...)`,
      `abort_unless(...)`), escrito con `$this->user()`. Si la acción **no
      comprobaba nada**, no inventes un permiso: deja `return true;` con un
      comentario de por qué (por ejemplo, «la ruta ya exige sesión y
      cualquier usuario puede…») y dilo en el informe del paso.
   3. `rules()`: el array de `validate()` / `Validator::make()`, tal cual.
      Las reglas que dependen del registro (`unique:x,y,'.$id`) leen el
      registro de la ruta con `$this->route('param')`.
   4. `handle()`: el cuerpo de la acción, sin la validación ni la
      autorización, devolviendo **exactamente** lo que devolvía (el mismo
      `response()->json(...)`, el mismo Resource, el mismo código). Si son
      más de unas líneas, que delegue en una operación del modelo o en un
      soporte que ya exista; no dupliques una consulta que ya está en otro
      sitio.
   5. La acción recibe la request tipada y su cuerpo queda en una sola línea:

          public function store(StoreRequest $request)
          {
              return $request->handle();
          }

      Un modelo enlazado por la ruta se puede seguir recibiendo como
      parámetro y pasarse a `handle($post)`; cualquier otra expresión dentro
      de `handle(...)` vuelve a ser lógica en el controlador.
3. Conserva **exactamente** la URI, el nombre de la ruta, el verbo y el
   middleware. No toques el archivo de rutas salvo, si hace falta, el tipo de
   un parámetro.
4. Corre las pruebas del paso 1 y las del paquete. Las que pasaban tienen que
   seguir pasando, salvo el cambio de orden del punto siguiente.

## El cambio de comportamiento que sí ocurre: 403 antes que 422

Laravel resuelve una FormRequest en este orden: **primero `authorize()`,
después `rules()`**. Si la acción validaba antes de comprobar permisos, una
petición **sin permiso y con datos inválidos** recibía un 422 y ahora recibe
un 403. Pasó en school-management (el bloque «CON», unas quince oleadas a
mano) y quedó documentado. No es un error: es el orden correcto, porque no
dice a quien no tiene permiso qué campos espera la acción. Pero:

- si una prueba fija el 422 en ese caso, cámbiala **en un commit aparte que
  lo diga**; no la borres;
- si un front o un cliente distingue 422 de 403 en esa ruta, dilo en el
  informe del paso para que alguien lo revise.

## Qué NO hacer

- No marques la acción con `#[HandRolled]` para que verify pase: es una
  excepción que **sólo aprueba el dueño** del proyecto, y verify la sigue
  señalando hasta entonces. Si de verdad no puede delegar (lee el cuerpo en
  bruto para comprobar una firma, responde con el formato de un protocolo como
  OAuth o xAPI, hace streaming), déjala como está, dilo en el informe y pasa
  al siguiente controlador: es una decisión, no parte de este paso.
- No muevas la lógica a un trait de `Http/Controllers/Concerns`: es la misma
  lógica en el controlador por la puerta de atrás (verify lo señala como
  `controller-concern`).
- No cambies nombres de ruta, URIs, verbos ni la forma de la respuesta.
- No reescribas migraciones ni toques modelos generados fuera de sus huecos.
- No corras `larapack:import --force` para «arreglar» el controlador: si es
  generado, el import no toca lo editado; si es a mano, no lo conoce.

## Riesgo

{{risk}}

## Comprobar

Mientras trabajas, sólo este controlador:

    {{verify}}

Cuando creas que está:

    {{check}}

Está hecho cuando {{done_when}}

Comita el paso solo («realign: {{id}}»), con las pruebas antes y después.
