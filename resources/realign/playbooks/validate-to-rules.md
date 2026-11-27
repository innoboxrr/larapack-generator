# Anexo: la validación del controlador pasa a `rules()`

Paso `{{id}}` · ámbito {{scope}}

verify dice que alguna acción de este controlador **valida en el
controlador** (`$request->validate([...])`, `$this->validate(...)`,
`validator(...)` o `Validator::make(...)`). Esto se suma a las instrucciones de
arriba.

## Pasos exactos

1. Copia el array de reglas, **tal cual**, a `rules()` de la request de esa
   acción. No «mejores» las reglas a la vez: una regla nueva es un cambio de
   comportamiento y va en otro commit.
2. Si las reglas usaban variables locales de la acción:
   - un parámetro de la ruta → `$this->route('nombre')` (un modelo enlazado
     es el modelo; un escalar, la cadena);
   - el usuario → `$this->user()`;
   - un valor calculado → muévelo a un método privado de la request.
3. Los mensajes y los nombres de atributo (`validate($rules, $messages,
   $attributes)`) van en `messages()` y `attributes()` de la request.
4. Si la acción leía el resultado de `validate()` (`$data = $request->validate(...)`),
   en `handle()` usa `$this->validated()`: devuelve lo mismo.
5. Si validaba **a mano** después de algo (`if (...) { $request->validate(...) }`,
   reglas que dependen de lo que ya se consultó), no lo fuerces a `rules()`:
   - las reglas condicionales van con `Rule::when(...)` o `sometimes`;
   - la validación que depende de una consulta va en `withValidator()` /
     `after()` de la request.
   Las dos se siguen ejecutando **antes** de `handle()`.
6. `Validator::make(...)->fails()` con una respuesta propia (un JSON con otra
   forma que el 422 de Laravel): la forma del 422 cambia. Si el cliente la
   lee, conserva la forma con `failedValidation()` en la request, lanzando la
   misma respuesta; si no, dilo en el informe.

## Qué NO hacer

- No dejes un `validate()` en el controlador «por si acaso»: verify lo sigue
  marcando y la validación corre dos veces.
- No cambies el orden de las reglas ni sus mensajes en el mismo commit.

Recuerda el 403 antes que 422 de arriba: con la validación en `rules()`, una
petición sin permiso ya no llega a validar.
