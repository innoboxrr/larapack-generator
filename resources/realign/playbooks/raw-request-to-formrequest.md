# Anexo: la acción recibe `Illuminate\Http\Request` (o su FormRequest base)

Paso `{{id}}` · ámbito {{scope}}

verify dice que alguna acción tipa su petición con `Illuminate\Http\Request`
o con `FormRequest` a secas: ninguna trae un `handle()` que llamar, así que la
validación y la autorización se quedan en el controlador. Esto se suma a las
instrucciones de arriba.

## Pasos exactos

1. Cambia el tipo del parámetro por la request propia de la acción
   (`StoreRequest $request`). El nombre de la variable puede quedarse.
2. **Los parámetros escalares de la ruta** (`public function show(Request
   $request, string $slug)`, `int $id`) no se pasan a `handle()`: la request
   los lee con `$this->route('slug')`. El nombre es el del segmento de la URI
   (`{slug}`), no el de la variable de la acción. Quita el parámetro de la
   firma de la acción si ya no lo usa nadie más.
   - Un modelo enlazado (`Post $post`) sí puede seguir en la firma y pasarse
     como `$request->handle($post)`; o la request lo lee con
     `$this->route('post')`. Las dos formas son válidas; elige la que ya use
     el resto del paquete.
   - `$request->input('x')`, `$request->query('x')`, `$request->file('x')`
     siguen funcionando dentro de la request como `$this->input('x')`…
3. Si la acción leía la sesión, cabeceras o el cuerpo en bruto
   (`$request->getContent()`), eso también va en `handle()` con `$this`. Un
   cuerpo en bruto que se firma (un webhook) **no** cambia de forma al pasar a
   una FormRequest, pero comprueba en la prueba que la firma sigue validando.
4. Si dos acciones compartían la misma `Request` genérica y la misma lógica,
   crea una request para cada una (pueden extender una base común en
   `Http/Requests/`), no una compartida.

## Qué NO hacer

- No tipes la acción con `FormRequest` a secas «para salir del paso»: verify
  lo marca igual (`tipa $request con …FormRequest, que no tiene handle()`).
- No uses `$this->route()` con el nombre de la variable de la acción si no es
  el del segmento de la URI: devuelve null sin avisar.
