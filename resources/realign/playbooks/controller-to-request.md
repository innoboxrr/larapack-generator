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
  artisan test` en la raíz del ámbito). Si no puedes (sin `vendor`, sin base
  de datos), puedes preparar el cambio, pero **no lo des por hecho**: ver
  «Sin pruebas» en la guía de los pasos guiados (`docs/realign/guided.md`).

## Pasos exactos

1. **Fija lo que responde hoy, antes de tocar nada.** Por cada acción
   marcada, si no hay una prueba que la llame por HTTP, escribe una que fije
   el código de estado y la forma del JSON en tres casos: sin sesión (o sin
   permiso), con datos inválidos y con datos válidos. Si la acción carga un
   registro por su id (`findOrFail`, `firstOrFail`, una consulta acotada al
   usuario), añade un cuarto: **un id que no existe**, con y sin permiso
   (ver «La autorización que se queda en handle()»). Córrela y comprueba que
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
      cualquier usuario puede…») y dilo en el informe del paso. Si la
      comprobación depende de un registro que la acción carga, mira antes
      «La autorización que se queda en handle()».
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

## La autorización que se queda en handle()

`authorize()` corre **antes** que `handle()`. Una comprobación que hoy va
**después** de cargar el registro —

    $post = Post::findOrFail($id);
    abort_unless($request->user()->can('update', $post), 403);

— responde hoy **404** a un id que no existe, tenga o no permiso quien
pregunta. Si la llevas a `authorize()` sin cargar el registro allí, ese
mismo id responde **403** (o un error, si la política recibe `null`), y se
filtra o se esconde lo que antes no. Tres casos:

- **La ruta enlaza el modelo** (`{post}` con route model binding): el 404 lo
  da el enlace, que corre antes que la FormRequest. Lleva la comprobación a
  `authorize()` con `$this->route('post')`: el orden no cambia.
- **La acción carga el registro ella misma** y la prueba del paso 1 fija un
  404 para el id inexistente: **deja la comprobación en `handle()`**, justo
  después de cargar el registro, tal como estaba, y en `authorize()` un
  `return true;` con un comentario que diga dónde se autoriza y por qué
  («se autoriza en handle(), después de findOrFail, para conservar el 404»).
  Dilo en el informe del paso. Sigue siendo la forma correcta: el controlador
  queda en `return $request->handle();`.
- **Cargar el registro en `authorize()`** (`Post::findOrFail($this->route('id'))`)
  también conserva el 404, pero lo carga dos veces o obliga a guardarlo en la
  request: hazlo sólo si la request ya lo hace así en otras acciones del
  proyecto.

**verify no comprueba este orden.** `controller-shape` mira que el
controlador delegue; no sabe si la request autoriza antes o después de
cargar el registro, ni qué código de estado sale. Que `check` pase no dice que
el 404 se conserve: **la prueba del paso 1 con el id inexistente es
obligatoria** en toda acción que cargue un registro, y tiene que pasar antes
y después.

## Protocolos: OAuth2, RFC 7591, webhooks firmados, xAPI

Una acción que habla un protocolo que no es el de Laravel —los errores de
OAuth2 (RFC 6749), el registro dinámico de clientes (RFC 7591), un webhook
que comprueba una firma sobre el cuerpo en bruto, xAPI, SCIM— **se mueve
igual** si se puede conservar **exactamente** lo que ve el cliente. El
criterio es uno solo:

- **Muévela** si, con la request, se conservan **el código de estado y el
  formato de la respuesta, byte a byte en lo que el cliente lee**, en cada
  caso que fija la prueba del paso 1: también en los errores. En la práctica,
  casi siempre se puede:
  - el formato de error del protocolo (`{"error": "invalid_client_metadata",
    "error_description": "…"}` con 400, no el 422 de Laravel) se conserva
    sobrescribiendo `failedValidation()` (y, si hace falta,
    `failedAuthorization()`) en la request para que lance una
    `HttpResponseException` con esa respuesta;
  - `handle()` devuelve la misma respuesta que devolvía la acción (el mismo
    `response()->json(...)`, las mismas cabeceras, el mismo 201);
  - si el proyecto ya tiene una convención para esto (una request base
    propia, un `ActionRequest` o similar que ya hace lo anterior), **úsala**
    en vez de inventar otra.

  En un proyecto real, un controlador de registro RFC 7591 se movió así,
  con la request base del propio proyecto, sin que cambiara una respuesta.
- **No la muevas** si no se puede conservar alguna de las dos cosas: el
  cuerpo en bruto tiene que leerse antes de que nada lo toque (una firma
  HMAC sobre los bytes exactos), la respuesta es un streaming, o el protocolo
  exige un código o un formato que la FormRequest no deja dar. Entonces es
  una excepción y **la decide el dueño**: añade a la acción
  `#[HandRolled('…')]` con la razón concreta de por qué **no puede** delegar
  (no «es un protocolo»: «lee el cuerpo en bruto para comprobar la firma
  HMAC de X»), en un commit aparte, y díselo al dueño. El atributo es la
  **solicitud**: verify la sigue señalando hasta que el dueño la aprueba con
  `larapack:hand-rolled approve`, y el paso no está hecho mientras tanto
  (`docs/realign/exceptions.md`). Pasa al siguiente
  controlador.

En los dos casos, la prueba del paso 1 es la que decide: si fija el formato
del protocolo y pasa antes y después, la acción se movió bien.

## Qué NO hacer

- No marques la acción con `#[HandRolled]` para que verify pase, ni con una
  razón genérica: sólo cuando de verdad no puede conservar el código de
  estado o el formato de la respuesta (ver «Protocolos»), con la razón
  concreta. Es una solicitud que **sólo aprueba el dueño** del proyecto, y
  verify la sigue señalando hasta entonces.
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
Después de los pasos guiados, vuelve a pasar `diagnose`, `plan` y
`apply --deterministic`: un controlador que ya delega puede dejar
deterministas nuevos (sus requests, sus pruebas).
