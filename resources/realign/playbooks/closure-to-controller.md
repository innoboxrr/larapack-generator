# Llevar una ruta con closure a un controlador con su request

Paso `{{id}}` · ámbito {{scope}}

**Objetivo.** Cada ruta de abajo deja de llevar su acción en un closure: la
lleva un controlador cuya acción es `return $request->handle();`, con una
FormRequest propia (`authorize()`, `rules()`, `handle()`), **conservando la
URI, el verbo, el nombre de la ruta y el middleware**. Se decidió así en este
paso (opción `refactor`).

## Las rutas

{{findings}}

Archivos:

{{files}}

## Precondiciones

- La decisión está registrada (`larapack:realign decide {{id}} refactor`).
- El árbol git está limpio; el paso se comitea solo.

## Pasos exactos

1. **Fija lo que responde hoy.** Por cada ruta, una prueba HTTP con su código
   de estado y su respuesta (con y sin sesión si la ruta la exige). Debe pasar
   con el closure. Comítala aparte.
2. Por cada closure:
   1. Si sólo pinta una vista (`return view('x');`), no hace falta
      controlador: cámbialo por `Route::view('/uri', 'x')`, que verify no
      marca. Pasa al siguiente.
   2. Elige el controlador: uno que ya exista para esa área o uno nuevo en
      `Http/Controllers/<Área>Controller.php`. Un controlador por área, no uno
      por ruta.
   3. Crea `Http/Requests/<Área>/<Acción>Request.php`: `authorize()` con lo que
      el closure comprobaba de permisos (si no comprobaba nada, `return
      true;` con un comentario de por qué), `rules()` con lo que validaba, y
      `handle()` con el cuerpo del closure, devolviendo exactamente lo mismo.
      Lo que el closure recibía por `use (...)` (configuración, una variable
      del archivo de rutas) pasa a leerse en `handle()` de donde viene (`config()`,
      el contenedor).
   4. Cambia la ruta: `Route::get('/uri', [XController::class, 'accion'])`,
      **con el mismo `->name()`, el mismo middleware y en el mismo grupo**.
      Si el closure estaba dentro de un `Route::group`, la ruta nueva sigue
      dentro.
3. Corre la prueba del paso 1 y las del paquete.

## Casos especiales

- **Una ruta de la SPA según el Host o un `fallback()`** que devuelve el HTML
  de la aplicación: el fallback que escribía laravel-setup hasta la 7.2.0 lo
  corrige `larapack:fix route-closure` (SpaController y SpaRequest); copia esa
  forma para las demás.
- **Un `OPTIONS` de CORS** o un health check: si no tienen lógica propia, lo
  limpio es el middleware de CORS de Laravel o una ruta de salud en
  `bootstrap/app.php` (`health:`); si la tienen, un controlador como los demás.
  Si se decide que se quede como closure, no es este playbook: es una
  excepción (`larapack:realign except`).
- **`Route::match`/`any`** con closure: un controlador con una sola acción, la
  misma lista de verbos.

## Qué NO hacer

- No cambies el nombre de la ruta ni la URI: el front y los clientes los usan.
- No declares la ruta en el contrato en este mismo paso: si se quiere
  declarar, es el paso `unmanaged-http` (y el playbook
  `declare-handwritten-controller`).
- No dejes el closure comentado al lado.

## Riesgo

{{risk}}

## Comprobar

    {{verify}}

    {{check}}

Está hecho cuando {{done_when}}
